<?php

/**
 * @file
 * Contains BeanstalkdServer.
 *
 * @todo Consider whether to include reserved items in counts/flushes.
 */

namespace Drupal\beanstalkd\Server;

use Pheanstalk\Command\PeekCommand;
use Pheanstalk\Exception\ConnectionException;
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
   * The default timeout for claimJob().
   *
   * @TODO make this configurable.
   */
  const DEFAULT_CLAIM_TIMEOUT = 3600;

  const TUBE_STATS_CURRENT_PREFIX = 'current-jobs-';

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
  protected $tubeNames;

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
    $this->tubeNames = array_combine($queue_names, $queue_names);
  }

  /**
   * Add a Drupal queue to this server.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function addTube($name) {
    $this->tubeNames[$name] = $name;
  }

  /**
   * Reserve the next ready item from a tube.
   *
   * @param string $name
   *   The name of a tube.
   *
   * @return false|\Pheanstalk\Job
   *   A job submitted to the queue, or FALSE if an error occurred.
   */
  public function claimJob($name) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->tubeNames[$name])) {
      return FALSE;
    }

    /** @var \Pheanstalk\Job $job */
    $job = $this->driver->reserve(static::DEFAULT_CLAIM_TIMEOUT);
    // @TODO Implement specific handling for jobs containing a Payload object,
    // like the ability to interact with TTR.
    return $job;
  }

  /**
   * List tube names on the server.
   *
   * @return array
   *   An array of tube names.
   */
  public function listTubes() {
    try {
      $tubes = $this->driver->listTubes();
    }
    catch (ConnectionException $e) {
      $tubes = [];
    }
    return $tubes;
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
   *   The id of the created job. 0 indicates an error.
   */
  public function putData($name, $data) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->tubeNames[$name])) {
      return 0;
    }

    $payload = new Payload($data);
    $id = $this->driver->putInTube($name, $payload->__toString());
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
  public function deleteJob($name, $id) {
    // Do not do anything on tube not controlled by this instance.
    if (!isset($this->tubeNames[$name])) {
      return;
    }

    // Pheanstalk delete() only uses $job->getId(), not the data.
    $job = new Job($id, NULL);
    $this->driver->delete($job);
  }

  /**
   * Release a job obtained from claimJob().
   *
   * @param string $name
   *   The tube name.
   * @param \Pheanstalk\Job $job
   *   A job obtained from claimJob().
   */
  public function releaseJob($name, Job $job) {
    // Do not do anything on invalid job, or tube not controlled.
    if ($job->getId() <= 0 || !isset($this->tubeNames[$name])) {
      return;
    }

    // @TODO implement support for non-default priority/delay for Payload jobs.
    $this->driver->release($job);
  }

  /**
   * Remove a Drupal queue from this server: empty it and un-register it.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function removeTube($name) {
    $this->flushTube($name);
    $this->releaseTube($name);
  }

  /**
   * Stop handling a queue.
   *
   * This method is a test helper only. In normal situations, use removeTube()
   * instead to ensure handling consistency.
   *
   * @param string $name
   *   The name of a queue to stop handling.
   */
  public function releaseTube($name) {
    unset($this->tubeNames[$name]);
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
    if (!isset($this->tubeNames[$name])) {
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
    if (!isset($this->tubeNames[$name])) {
      return 0;
    }

    $count = 0;
    try {
      $stats = $this->driver->statsTube($name);
    }
    catch (ServerException $e) {
      $stats = [];
      foreach (static::JOB_STATES as $state) {
        $stats[static::TUBE_STATS_CURRENT_PREFIX . $state] = 0;
      }
    }

    foreach (static::JOB_STATES as $state) {
      $state_name = strtolower($state);
      $key = static::TUBE_STATS_CURRENT_PREFIX . $state_name;
      $count += $stats[$key];
    }

    return $count;
  }

  /**
   * Unprotected stats.
   *
   * @return object
   *   A Pheanstalk statistics response.
   */
  protected function statsGlobal() {
    $stats = $this->driver->stats();
    return $stats;
  }

  /**
   * Unprotected stats-tube.
   *
   * @param string $name
   *   The name of the tube.
   *
   * @return object
   *   A Pheanstalk statistics response.
   */
  protected function statsTube($name) {
    $stats = $this->driver->statsTube($name);
    return $stats;
  }

  /**
   * Unprotected stats-job.
   *
   * @param string $name
   *   The name of the tube. Not used, but needed for signature consistency.
   * @param \Pheanstalk\Job $job
   *   The queried job.
   *
   * @return object
   *   A Pheanstalk statistics response.
   */
  protected function statsJob($name, Job $job) {
    $stats = $this->driver->statsJob($job);
    return $stats;
  }

  /**
   * Return Beanstalkd statistics from the server.
   *
   * @param string $type
   *   One of "global", "tube", and "job".
   * @param string $name
   *   The name of the tube. Not used for "global".
   * @param null|\Pheanstalk\Job $job
   *   The queried job, Only used for "job".
   *
   * @return false|\Pheanstalk\Response\ArrayResponse
   *   The statistics about the tube, or false if it could not be found.
   */
  public function stats($type, $name = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME, Job $job = NULL) {
    $types = ['global', 'tube', 'job'];
    if (!in_array($type, $types)) {
      throw new \InvalidArgumentException(t('Invalid statistics type: @type', [
        '@type' => $type,
      ]));
    }

    if ($type !== 'global') {
      // Do not do anything on tube not controlled by this instance.
      if (!isset($this->tubeNames[$name])) {
        echo "Not handling $name\n";
        return FALSE;
      }

      if ($type === 'job') {
        if ($job === NULL) {
          return FALSE;
        }
      }
    }

    try {
      $method = 'stats' . ucfirst($type);
      /* @var \ArrayObject $stats */
      $stats = $this->{$method}($name, $job);
    }
    catch (ServerException $e) {
      $stats = FALSE;
    }
    catch (ConnectionException $e) {
      $stats = FALSE;
    }

    return $stats;
  }

}
