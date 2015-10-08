<?php

/**
 * @file
 * Contains BeanstalkdServer.
 *
 * @todo Consider whether to include reserved items in counts/flushes.
 */

namespace Drupal\beanstalkd\Server;

use Pheanstalk\Command\PeekCommand;
use Pheanstalk\Exception\ServerException;
use Pheanstalk\Job;
use Pheanstalk\PheanstalkInterface;

/**
 * Class BeanstalkdServer wraps a Pheanstalk facade and Drupal queue names.
 */
class BeanstalkdServer {
  const JOB_STATES = [
    PeekCommand::TYPE_READY,
    PeekCommand::TYPE_DELAYED,
    PeekCommand::TYPE_BURIED,
  ];

  /**
   * The wrapped Pheanstalk.
   *
   * @var \Pheanstalk\PheanstalkInterface
   */
  protected $driver;

  /**
   * The Drupal queue names this server is designated to serve.
   *
   * @var array
   */
  protected $queueNames;

  /**
   * Constructor.
   *
   * @param \Pheanstalk\PheanstalkInterface $driver
   *   The wrapped Pheanstalk.
   * @param string[] $queue_names
   *   An array of the names of the Drupal queues assigned to this server.
   */
  public function __construct(PheanstalkInterface $driver, array $queue_names = []) {
    $this->driver = $driver;
    $this->queueNames = array_combine($queue_names, $queue_names);
  }

  /**
   * Add a Drupal queue to this server.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function addQueue($name) {
    $this->queueNames[$name] = $name;
  }

  /**
   * Add data for a job to a tube.
   *
   * To match the Drupal Queue API, this method does not support delayed jobs.
   *
   * @param string $name
   *   The tube name.
   * @param mixed $data
   *   The job workload.
   *
   * @return int
   *   The id of the created job item. 0 indicates an error.
   */
  public function createItem($name, $data) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->queueNames[$name])) {
      return 0;
    }

    $item = new Item($data);
    $id = $this->driver->putInTube($name, $item->__toString());
    return $id;
  }

  /**
   * Delete a job by its id.
   *
   * @param string $name
   *   The tube name.
   * @param int $id
   *   The job id.
   */
  public function deleteItem($name, $id) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->queueNames[$name])) {
      return;
    }

    // Pheanstalk delete() only uses $job->getId(), not the data.
    $job = new Job($id, NULL);
    $this->driver->delete($job);
  }

  /**
   * Remove a Drupal queue from this server: empty it and unregister it.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function removeQueue($name) {
    $this->flushTube($name);
    unset($this->queueNames[$name]);
  }

  /**
   * Delete a number of jobs in a given state on a tube.
   *
   * @param string $name
   *   The name of a valid tube.
   * @param string $state
   *   The type of jobs to delete, per static::JOB_STATES.
   * @param int $limit
   *   The maximum number of jobs to delete.
   *
   * @return int
   *   The number of jobs deleted during this method call.
   *
   * @internal Use flushTube() instead, in most cases. No parameter checks.
   *
   * @see \Drupal\beanstalkd\Server\BeanstalkdServer::flushTube()
   */
  protected function flush($name, $state, $limit) {
    $jobs = 0;

    // Delayed jobs cannot be deleted: they need to be readied first.
    if ($state === PeekCommand::TYPE_DELAYED) {
      $this->driver->kick($limit);
      $state = PeekCommand::TYPE_READY;
    }

    $method_name = 'peek' . ucfirst($state);

    do {
      try {
        /* @var \Pheanstalk\Job $job */
        $job = $this->driver->{$method_name}($name);
      }
      /* This is an "expected" exception, telling there are no items to peek at.
       * In this case there is no reason to continue asking for more
       */
      catch (ServerException $e) {
        break;
      }

      try {
        $this->driver->delete($job);
      }
      /* This is an "expected" exception, typically happening because the item
       * has already been deleted. In this case, there is no reason not to look
       * for the next item.
       */
      catch (ServerException $e) {
        continue;
      }

      $jobs++;
    } while ($jobs < $limit);

    return $jobs;
  }

  /**
   * Fakes the missing flush-tube command.
   *
   * This does not remove reserved jobs.
   *
   * @param string $name
   *   The name of a tube.
   */
  public function flushTube($name) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->queueNames[$name])) {
      return;
    }

    // @TODO make this configurable.
    $max_passes = 10;
    $max_jobs_per_pass = 10000;
    $delay = 60;
    $pass = 0;
    $driver = $this->driver;

    // Since we are emptying it, prevent new work being reserved from it.
    $driver->pauseTube($name, $delay);

    do {
      $has_done_work = FALSE;
      foreach (static::JOB_STATES as $job_state) {
        if ($this->flush($name, $job_state, $max_jobs_per_pass)) {
          $has_done_work = TRUE;
        }
      }

      $pass++;
    } while ($has_done_work && $pass < $max_passes);

    // Tube is now hopefully empty, someone else may need to use it.
    $driver->resumeTube($name);
  }

  /**
   * Return the number of ready + delayed + buried items in a queue.
   *
   * This does not take into account reserved jobs.
   *
   * @param string $name
   *   The name of a tube.
   *
   * @return int
   *   The number of jobs accumulated by the method. It may very well not be
   *   accurate because of race conditions on active tubes.
   */
  public function getTubeItemCount($name) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->queueNames[$name])) {
      return 0;
    }

    $count = 0;
    $stats = $this->driver->statsTube($name);
    foreach (static::JOB_STATES as $state) {
      $state_name = strtolower($state);
      $key = 'current-jobs-' . $state_name;
      $count += $stats[$key];
    }

    return $count;
  }

}
