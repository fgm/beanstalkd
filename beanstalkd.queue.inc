<?php

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
        $this->createConnection($this->beanstalkd_params['host'], $this->beanstalkd_params['port']);
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
      $put_commands = array('put', 'putInTube');
      $tube_commands = array('watch', 'ignore', 'statsTube', 'pauseTube', 'useTube');
      $job_commands = array('bury', 'delete', 'release', 'statsJob', 'touch');
      $job_id_commands = array('peek');

      $ret = array();

      // Commands: put, putInTube
      if (in_array($name, $put_commands)) {
        // If we're putting - shift the current tube name into the front of the arguments
        if ($name == 'put') {
          array_unshift($arguments, array($this->tube));
          $name = 'putInTube';
        }

        // Force the tubes into an array
        $tubes = is_array($arguments[0]) ? $arguments[0] : array($arguments[0]);

        // Now rebuild argument 1 (which should be $data) into a serialized object
        $record = new stdClass();
        $record->data = $arguments[1];

        // Now overlay some default parameters
        $arguments += array(
          2 => $this->beanstalkd_params['priority'],
          3 => $this->beanstalkd_params['delay'],
          4 => $this->beanstalkd_params['ttr'],
        );

        foreach ($tubes as $tube) {
          $tube_name = $this->_tubeName($tube);
          $arguments[0] = $tube_name;

          $record->name = $tube;
          $arguments[1] = serialize($record);
          
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }
      }
      // Commands: watch, ignore, statsTube, pauseTube, useTube
      elseif (in_array($name, $tube_commands)) {
        $tubes = is_array($arguments[0]) ? $arguments[0] : array($arguments[0]);

        foreach ($tubes as $tube) {
          $tube = $this->_tubeName($tube);
          $arguments[0] = $tube;
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }        
      }
      // Commands: bury, delete, release, statsJob, touch
      elseif (in_array($name, $job_commands)) {
        $items = is_array($arguments[0]) ? $arguments[0] : array($arguments[0]);

        foreach ($items as $item) {
          $arguments[0] = $item->beanstalkd_job;
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }
      }
      // Commands: peek
      elseif (in_array($name, $job_id_commands) && is_array($arguments[0])) {
        $ids = $arguments[0];
        foreach ($ids as $id) {
          $arguments[0] = $id;
          $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
        }
      }
      // Else all other commands
      else {
        $ret[] = call_user_func_array(array($this->beanstalkd_queue, $name), $arguments);
      }
      
      foreach ($ret as $id => $object) {
        if (is_object($object) && is_a($object, 'Pheanstalk_Job')) {
          $item = unserialize($object->getData());
          $item->id = $object->getId();
          $item->beanstalkd_job = $object;
          $ret[$id] = $item;
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

    return (bool)$this->put($data);
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
      $stats = $this->statsTube($this->tube);
    }
    else {
      $stats = $this->stats();
    }
    $stats = reset($stats);
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
  public function claimItem($lease_time = 3600) {
    if (!$this->beanstalkd_queue) {
      return FALSE;
    }

    $jobs = $this->reserve(0);
    if (!empty($jobs)) {
      // We should only ever get one job, but if we have somehow reserved more than 1, the additional jobs will timeout and get put back onto the list. So it shouldn't get lost.
      return reset($jobs);
    }
    return FALSE;
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

    $this->delete($item);
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
    
    return $this->release($item->beanstalkd_job, $this->beanstalkd_params['priority'], $this->beanstalkd_params['release_delay']) ? TRUE: FALSE;
  }

  public function createConnection($host, $port) {
    $this->beanstalkd_queue = new Pheanstalk($host, $port);
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
