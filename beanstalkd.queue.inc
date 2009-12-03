<?php
// $Id$

/**
 * @file
 */

class BeanstalkdQueue implements DrupalQueueInterface {
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
  public function __construct($name) {
    $this->tube = $name;
    if (file_exists(drupal_get_path('module', 'beanstalkd') . '/pheanstalk/classes/Pheanstalk/ClassLoader.php')) {
      module_load_include('php', 'beanstalkd', 'pheanstalk/classes/Pheanstalk/ClassLoader');
      Pheanstalk_ClassLoader::register(drupal_get_path('module', 'beanstalkd') . '/pheanstalk/classes');
      
      $this->beanstalkd_queue = new Pheanstalk(variable_get('beanstalkd_host', 'localhost'), variable_get('beanstalkd_port', Pheanstalk::DEFAULT_PORT));
      if ($name) {
        // If a queue name  is past then set this tube to be used and set it to be the 
        // only tube to be watched.
        $tube = $this->_tubeName($name);
        $this->beanstalkd_queue
          ->useTube($tube)
          ->watch($tube)
          ->ignore('default');
      }
    }
    else {
      $this->beanstalkd_queue = FALSE;
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
    $record = new stdClass();
    $record->name = $this->tube;
    $record->data = $data;
    $record = serialize($record);
    
    return $this->beanstalkd_queue->put($record) ? TRUE : FALSE;
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
    $stats = $this->beanstalkd_queue->statsTube($this->_tubeName($this->tube));
    
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
    $job = $this->beanstalkd_queue->reserve(0);
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
   */
  public function claimItemBlocking() {
    $job = $this->beanstalkd_queue->reserve();
    if ($job) {
      $item = unserialize($job->getData());
      $item->id = $job->getId();
      $item->beanstalkd_job = $job;
      return $item;
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
   * Delete a queue and every item in the queue.
   */
  public function deleteQueue() {
    
  }
  
  /**
   * watch message queue
   */
  public function watch($names) {
    $names = is_string($names) ? array($names) : $names;
    $names = array_map(array($this, '_tubeName'), $names);
    
    $tubes = $this->beanstalkd_queue->listTubes();
    $names = array_intersect($tubes, $names);
    
    foreach ($names as $name) {
      $this->beanstalkd_queue->watch($name);
    }
  }
  
  /**
   * Queues to ignore
   */
  public function ignore($names) {
    $names = is_string($names) ? array($names) : $names;
    $names = array_map(array($this, '_tubeName'), $names);
    
    $tubes = $this->beanstalkd_queue->listTubes();
    $names = array_intersect($tubes, $names);
    
    foreach ($names as $name) {
      $this->beanstalkd_queue->ignore($name);
    }
  }
  
  private function _tubeName($name) {
    return str_replace('_', '-', $name);
  }
}