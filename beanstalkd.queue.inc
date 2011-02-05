<?php
// $Id$

/**
 * @file
 */

class BeanstalkdQueue implements DrupalReliableQueueInterface {
  /**
   * 
   */
  protected $tube;
  /**
   * The pheanstalk object which connects to the beanstalkd server.
   */
  protected $beanstalkd_queue;

  /**
   * Start working with a queue.
   *
   * @param $name
   *   Arbitrary string. The name of the queue to work with.
   */
  public function __construct($name, $force_connection = FALSE) {
    $this->tube = $name;
    if (beanstalkd_load_pheanstalk()) {
      $this->beanstalkd_params = beanstalkd_get_queue_options($name);

      try {
        $this->beanstalkd_queue = new Pheanstalk($this->beanstalkd_params['host'], $this->beanstalkd_params['port']);
        if ($name) {
          // If a queue name  is past then set this tube to be used and set it to be the 
          // only tube to be watched.
          $tube = $this->_tubeName($name);
          $this->beanstalkd_queue
            ->useTube($tube)
            ->watch($tube)
            ->ignore('default');
        }
        elseif ($force_connection) {
          // be sure to establish the connection so that we can catch any 
          // errors
          $this->beanstalkd_queue
            ->stats();
        }
      }
      catch (Exception $e) {
        $this->beanstalkd_queue = FALSE;
        $this->lastError = $e;
        watchdog('beanstalk', '%message in %file on line %line.', array('%error' => $e->getCode(), '%message' => $e->getMessage(), '%file' => $e->getFile(), '%line' => $e->getLine()), WATCHDOG_ERROR);
      }
    }
    else {
      $this->beanstalkd_params = array();
      $this->beanstalkd_queue = FALSE;
    }
  }

  /**
   * Use Method Overloading to allow unknown methods to be past to Pheanstalk object
   */
  function __call($name, $arguments) {
    if (!$this->beanstalkd_queue) {
      return FALSE;
    }
    
    if (method_exists($this->beanstalkd_queue, $name)) {
      $tube_commands = array('watch', 'ignore', 'statsTube', 'pauseTube');
      $job_commands = array('bury', 'delete', 'peek', 'release', 'statsJob', 'touch');
      if (in_array($name, $tube_commands) && is_array($arguments[0])) {
        $ret = array();
        $tubes = $arguments[0];
        foreach ($tubes as $tube) {
          $tube = $this->_tubeName($tube);
          $arguments[0] = $tube;
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }        
      }
      elseif (in_array($name, $tube_commands)) {
        $arguments[0] = $this->_tubeName($arguments[0]);
        $ret = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
      }
      elseif (in_array($name, $job_commands) && is_array($arguments[0])) {
        $ret = array();
        $jobs = $arguments[0];
        foreach ($jobs as $job) {
          $arguments[0] = $job;
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }
      }
      else {
        $ret = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
      }
      
      if (is_array($ret)) {
        foreach ($ret as $id => $object) {
          if (is_object($object) && is_a($object, 'Pheanstalk_Job')) {
            $item = unserialize($object->getData());
            $item->id = $object->getId();
            $item->beanstalkd_job = $object;
            $ret[$id] = $item;
            return $item;
          }
        }
      }
      else {
        if (is_object($object) && is_a($object, 'Pheanstalk_Job')) {
          $item = unserialize($object->getData());
          $item->id = $object->getId();
          $item->beanstalkd_job = $object;
          $ret[$id] = $item;
          return $item;
        }
      }
      return $ret;
    }
    else {
      throw new Exception(t('Method doesn\'t exist'));
    }
  }

  /**
   * Add a queue item and store it directly to the queue.
   *
   * @param $data
   *   Arbitrary data to be associated with the new task in the queue.
   * @return
   *   TRUE if the item was successfully created and was (best effort) added
   *   to the queue, otherwise FALSE. We don't guarantee the item was
   *   committed to disk, that your disk wasn't hit by a meteor, etc, but as
   *   far as we know, the item is now in the queue.
   */
  public function createItem($data) {
    if (!$this->beanstalkd_queue) {
      return FALSE;
    }

    $record = new stdClass();
    $record->name = $this->tube;
    $record->data = $data;
    $record = serialize($record);

    return $this->beanstalkd_queue->put($record, $this->beanstalkd_params['priority']) ? TRUE : FALSE;
  }

  /**
   * Retrieve the number of items in the queue.
   *
   * This is intended to provide a "best guess" count of the number of items in
   * the queue. Depending on the implementation and the setup, the accuracy of
   * the results of this function may vary.
   *
   * e.g. On a busy system with a large number of consumers and items, the
   * result might only be valid for a fraction of a second and not provide an
   * accurate representation.
   *
   * @return
   *   An integer estimate of the number of items in the queue.
   */
  public function numberOfItems() {
    if (!$this->beanstalkd_queue) {
      return;
    }

    if ($this->tube) {
      $stats = $this->beanstalkd_queue->statsTube($this->_tubeName($this->tube));
    }
    else {
      $stats = $this->beanstalkd_queue->stats();
    }
    return $stats['current-jobs-ready'];
  }

  /**
   * Claim an item in the queue for processing.
   *
   * @param $lease_time
   *   How long the processing is expected to take in seconds, defaults to an
   *   hour. After this lease expires, the item will be reset and another
   *   consumer can claim the item. For idempotent tasks (which can be run
   *   multiple times without side effects), shorter lease times would result
   *   in lower latency in case a consumer fails. For tasks that should not be
   *   run more than once (non-idempotent), a larger lease time will make it
   *   more rare for a given task to run multiple times in cases of failure,
   *   at the cost of higher latency.
   * @return
   *   On success we return an item object. If the queue is unable to claim an
   *   item it returns false. This implies a best effort to retrieve an item
   *   and either the queue is empty or there is some other non-recoverable
   *   problem.
   */
  public function claimItem($lease_time = 3600, $timeout = TRUE) {
    if (!$this->beanstalkd_queue) {
      return FALSE;
    }

    $timeout = ($timeout === TRUE) ? $this->beanstalkd_params['reserve_timeout'] : $timeout;

    $job = $this->beanstalkd_queue->reserve($timeout);
    if ($job) {
      $item = unserialize($job->getData());
      $item->id = $job->getId();
      $item->beanstalkd_job = $job;
      return $item;
    }
    return FALSE;
  }

  /**
   * Claim the next item on any of the tubes which are being watched. Since this is a blocking
   * method it will not return until and item is claimed.
   *
   * This method has been depreciated. Use $this->claimItem(3600, NULL)
   */
  public function claimItemBlocking() {
    return $this->claimItem(3600, NULL);
  }

  /**
   * Delete a finished item from the queue.
   *
   * @param $item
   *   The item returned by DrupalQueueInterface::claimItem().
   */
  public function deleteItem($item) {
    if (!$this->beanstalkd_queue) {
      return;
    }

    $this->beanstalkd_queue->delete($item->beanstalkd_job);
  }

  /**
   * Create a queue.
   *
   * Called during installation and should be used to perform any necessary
   * initialization operations. This should not be confused with the
   * constructor for these objects, which is called every time an object is
   * instantiated to operate on a queue. This operation is only needed the
   * first time a given queue is going to be initialized (for example, to make
   * a new database table or directory to hold tasks for the queue -- it
   * depends on the queue implementation if this is necessary at all).
   */
  public function createQueue() {

  }

  /**
   * Delete a finished item from the queue.
   *
   * @param $item
   *   The item returned by DrupalQueueInterface::claimItem().
   */
  public function deleteQueue() {

  }

  /**
   * Release an item that the worker could not process, so another
   * worker can come in and process it before the timeout expires.
   *
   * @param $item
   * @return boolean
   */
  public function releaseItem($item) {
    if (!$this->beanstalkd_queue) {
      return FALSE;
    }
    
    return $this->beanstalkd_queue->release($item->beanstalkd_job) ? TRUE: FALSE;
  }

  public function getError() {
    if (isset($this->lastError)) {
      return $this->lastError;
    }
  }

  private function _tubeName($name) {
    return variable_get('beanstalkd_prefix', '') . $name;
  }
}