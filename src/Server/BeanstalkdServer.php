<?php

/**
 * @file
 * Contains BeanstalkdServer.
 *
 * @todo Consider whether to include reserved items in counts/flushes.
 */

namespace Drupal\beanstalkd\Server;

use Drupal\Component\Utility\Unicode;
use Pheanstalk\Command\PeekCommand;
use Pheanstalk\Exception\ConnectionException;
use Pheanstalk\Exception\ServerException;
use Pheanstalk\Job;
use Pheanstalk\PheanstalkInterface;

/**
 * Class BeanstalkdServer wraps a Pheanstalk facade and Drupal queue names.
 */
class BeanstalkdServer {

  /**
   * The default timeout for claimJob().
   *
   * @TODO make this configurable.
   */
  const DEFAULT_CLAIM_TIMEOUT = 3600;

  /**
   * The prefix used to build count commands for various item states.
   *
   * @see \Drupal\beanstalkd\Server\BeanstalkdServer::getTubeItemCount()
   */
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
   * List job states.
   *
   * This is a static function because array constants are not supported in
   * PHP5.5, and in 2015 some sites need the module to work on 5.5.
   *
   * @XXX Revisit at some point after Drupal 8.1.0.
   *
   * @return array
   *   An array of job states.
   */
  public static function jobStates() {
    $result = [
      PeekCommand::TYPE_READY,
      PeekCommand::TYPE_DELAYED,
      PeekCommand::TYPE_BURIED,
    ];

    return $result;
  }

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
   * Watch additional tubes.
   *
   * @param string[] $names
   *   The name of additional tubes to watch.
   */
  public function addWatches(array $names) {
    foreach ($names as $name) {
      $this->addTube($name);
      $this->driver->watch($name);
    }
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
    $job = $this->driver->reserveFromTube($name, static::DEFAULT_CLAIM_TIMEOUT);
    // @TODO Implement specific handling for jobs containing a Payload object,
    // like the ability to interact with TTR.
    return $job;
  }

  /**
   * Reserve the next ready item from a tube on the server watch list.
   *
   * This mechanism is not a standard Queue API feature, and needs an up-to-date
   * watch list. Do not mix with claimJob(), which resets the watch list.
   *
   * The result retyping is here because Pheanstalk phpdoc lacks precision.
   *
   * @param int|null $timeout
   *   The number of seconds to wait for a job to come in.
   *
   * @return false|\Pheanstalk\Job
   *   A job submitted to the queue, or FALSE if an error occurred.
   */
  public function claimJobFromAnyTube($timeout = NULL) {
    /* @var false|\Pheanstalk\Job $job */
    $job = $this->driver->reserve($timeout);
    return $job;
  }

  /**
   * Unprotected kick method.
   *
   * This is not compatible with normal Queue API use.
   *
   * The drush plugin needs it to be public, in order to perform operations
   * without a queue, which have no direct support in Queue API.
   *
   * @param null|string $tube
   *   The name of the tube at which to peek.
   * @param int $max
   *   The maximum number of items to kick from the tube.
   *
   * @return int
   *   The number of items kicked.
   */
  public function kick($tube, $max) {
    try {
      $this->driver->useTube($tube);
      $result = $this->driver->kick($max);
    }
    catch (ServerException $e) {
      $result = 0;
    }

    return $result;
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
   * Unprotected generic command proxy.
   *
   * This is not safe, and not compatible with normal Queue API use.
   *
   * Caveat emptor: tt does not catch underlying exceptions.
   *
   * The drush plugin needs it to be public, in order to perform unsupported
   * operations.
   *
   * @param string $command
   *   A Pheanstalk method. The next undeclared parameters will be its own.
   *
   * @return mixed
   *   Depends on the called method.
   *
   * @see drush_beanstalkd_peek_ready()
   */
  public function passThrough($command) {
    $arguments = array_slice(func_get_args(), 1);
    $result = call_user_func_array([$this->driver, $command], $arguments);
    return $result;
  }

  /**
   * Unprotected peek method.
   *
   * This is not compatible with normal Queue API use.
   *
   * The drush plugin needs it to be public, in order to perform operations
   * without a queue, which have no direct support in Queue API.
   *
   * @param string $type
   *   One of 'ready', 'delayed', 'buried'.
   * @param null|string $tube
   *   The name of the tube at which to peek.
   *
   * @return array|false
   *   The next job, or false if none is available.
   */
  public function peek($type, $tube = NULL) {
    assert('in_array($type, ["buried", "delayed", "ready"])');
    $method = 'peek' . Unicode::ucfirst($type);
    try {
      /* @var \Pheanstalk\Job $job */
      $job = $this->driver->{$method}($tube);
    }
    catch (ServerException $e) {
      $job = FALSE;
    }

    $result = ($job === FALSE) ? FALSE : [
      'id' => $job->getId(),
      'data' => $job->getData(),
    ];
    $result = ['job' => $result];
    return $result;
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

    $method_name = 'peek' . Unicode::ucfirst($state);

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
      foreach (static::jobStates() as $job_state) {
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
      foreach (static::jobStates() as $state) {
        $stats[static::TUBE_STATS_CURRENT_PREFIX . $state] = 0;
      }
    }

    foreach (static::jobStates() as $state) {
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
   * This is not compatible with normal Queue API use. Use stats() instead.
   * The drush plugin needs it to be public, in order to perform multi-queue
   * runs and job stats without a queue, which have no direct support in Queue
   * API.
   *
   * @param string $name
   *   The name of the tube. Not used, but needed for signature consistency.
   * @param \Pheanstalk\Job $job
   *   The queried job.
   *
   * @return \ArrayObject
   *   A Pheanstalk statistics response.
   *
   * @see drush_beanstalkd_run_server()
   * @see drush_beanstalkd_item_stats()
   *
   * @throws \Pheanstalk\Exception\ServerException
   *   When the job is not found on the server.
   */
  public function statsJob($name, Job $job) {
    /* @var \ArrayObject $stats */
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
   * @return false|\ArrayObject
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
        return FALSE;
      }

      if ($type === 'job') {
        if ($job === NULL) {
          return FALSE;
        }
      }
    }

    try {
      $method = 'stats' . Unicode::ucfirst($type);
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
