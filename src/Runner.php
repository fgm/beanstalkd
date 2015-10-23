<?php
/**
 * @file
 * Contains the Beanstalkd CLI Runner.
 */

namespace Drupal\beanstalkd;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

/**
 * Class Runner contains code needed for CLI (Drush) operations.
 */
class Runner {

  /**
   * The core queue worker manager service.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $coreManager;

  /**
   * The Beanstalkd logger channel service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Beanstalkd server factory service.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory
   */
  protected $serverFactory;

  /**
   * The Beanstalkd worker manager service.
   *
   * @var \Drupal\beanstalkd\WorkerManager
   */
  protected $workerManager;

  /**
   * Service constructor.
   *
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $core_manager
   *   The core queue worker manager service.
   * @param \Drupal\beanstalkd\Server\BeanstalkdServerFactory $server_factory
   *   The Beanstalkd server factory service.
   * @param \Drupal\beanstalkd\WorkerManager $manager
   *   The Beanstalkd worker manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Beanstalkd logger channel service.
   */
  public function __construct(QueueWorkerManagerInterface $core_manager, BeanstalkdServerFactory $server_factory,
    WorkerManager $manager, LoggerInterface $logger) {
    $this->coreManager = $core_manager;
    $this->logger = $logger;
    $this->serverFactory = $server_factory;
    $this->workerManager = $manager;
  }

  /**
   * Return the combined list of queue workers and queues with a mapping.
   *
   * @param null|string $name
   *   If passed, the only queue exposed in the results.
   *
   * @return array
   *   A queue-name-indexed hash of BeanstalkdServer instances.
   */
  public function getQueues($name = NULL) {
    $mappings = array_flip(array_keys($this->serverFactory->getQueueMappings()));

    $drupal_queues = array_flip($this->workerManager->getBeanstalkdQueues());

    if (!empty($name)) {
      $server = $this->serverFactory->getQueueServer($name);
      $queue_servers = [$name => $server];
    }
    else {
      $names = array_keys($mappings + $drupal_queues);
      $queue_servers = [];

      foreach ($names as $name) {
        $queue_servers[$name] = $this->serverFactory->getQueueServer($name);
      }
    }

    return $queue_servers;
  }


  /**
   * Helper for Drush commands taking an optional server alias argument.
   *
   * @param null|string $alias
   *   A server alias.
   * @param bool $include_objects
   *   Include server objects in the results.
   *
   * @return array
   *   An alias-indexed hash of server definitions, possibly including server
   *   objects.
   */
  public function getServers($alias = NULL, $include_objects = FALSE) {
    $all_servers = $this->serverFactory->getServerDefinitions();

    if (isset($alias)) {
      if (isset($all_servers[$alias])) {
        $servers = array_intersect_key($all_servers, [$alias => NULL]);
      }
      else {
        $this->logger->error('@host is not a known server.', ['@host' => $alias]);
        return [$alias => FALSE];
      }
    }
    else {
      $servers = $all_servers;
    }

    if ($include_objects) {
      foreach ($servers as $alias => &$definition) {
        $definition['server'] = $this->serverFactory->get($alias);
      }
    }
    return $servers;
  }

  /**
   * Gets the names of queues configured to be served by a given server.
   *
   * @param \Drupal\beanstalkd\Server\BeanstalkdServer $requested_server
   *   The server.
   *
   * @return array<integer|string>
   *   An array of queue names for this server.
   */
  public function getServerTubeNames(BeanstalkdServer $requested_server) {
    $all_queues = $this->getQueues();
    $queues = array_filter($all_queues, function ($server) use($requested_server) {
      return $server === $requested_server;
    });
    $result = array_keys($queues);
    return $result;
  }

  /**
   * Validation helper for runServer().
   *
   * @param null|string $alias
   *   The alias for the server from which to fetch jobs.
   *
   * @return array
   *   - the alias
   *   - the server instance
   *   - the tubes array
   */
  public function runServerPreValidate($alias = NULL) {
    if (!isset($alias)) {
      $alias = BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS;
    }

    $server = $this->serverFactory->get($alias);

    $tubes = $this->getServerTubeNames($server);
    if (empty($tubes)) {
      drush_set_error('DRUSH_NO_TUBE', dt('This server contains no queue on which to wait.'));
    }

    return [$alias, $server, $tubes];
  }

  /**
   * Drush callback for 'beanstalkd-run-server'.
   *
   * @param null|string $alias
   *   The alias for the server from which to fetch jobs.
   */
  public function runServer($alias = NULL) {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($alias, $server, $tubes) = $this->runServerPreValidate($alias);

    $time_limit = intval(drush_get_option('time-limit'), NULL);
    $verbose = !!drush_get_option('verbose');
    if ($verbose) {
      $names = implode(', ', $tubes);
      drush_print(dt('Handling tubes: @tubes', ['@tubes' => $names]));
    }

    $start = time();
    $end = $time_limit ? time() + $time_limit : PHP_INT_MAX;

    $server->addWatches($tubes);
    $count = $this->runServerMainLoop($alias, $end, $server, $tubes);

    $elapsed = microtime(TRUE) - $start;
    $level = drush_get_error() ? LogLevel::WARNING : LogLevel::INFO;
    $this->logger->log($level, 'Processed @count items from the @name server in @elapsed sec.', [
      '@count' => $count,
      '@name' => $alias,
      '@elapsed' => round($elapsed, 2),
    ]);
  }

  /**
   * Main loop of runServer() method.
   *
   * @param string $alias
   *   The alias for the server on which to service jobs.
   * @param int $end
   *   The timestamp until which to service jobs.
   * @param \Drupal\beanstalkd\Server\BeanstalkdServer $server
   *   The Beanstalkd server instance.
   * @param array $tubes
   *   The name of tubes to service.
   *
   * @return int The number of serviced jobs.
   *   The number of serviced jobs.
   */
  protected function runServerMainLoop($alias, $end, BeanstalkdServer $server, array $tubes) {
    // Provide a default tube for exception recovery.
    $tube = reset($tubes);

    $count = 0;
    while ((($remaining = $end - time()) > 0) && ($job = $server->claimJobFromAnyTube($remaining))) {
      try {
        $stats = $server->statsJob(NULL, $job);
        $tube = $stats['tube'];
        $job_info = [
          '@name' => $alias,
          '@id' => $job->getId(),
          '@tube' => $tube,
        ];
        $this->logger->info('Processing item @name/@tube/@id.', $job_info);

        try {
          $worker = $this->coreManager->createInstance($tube);
          $worker->processItem($job->getData());
        }
        catch (PluginNotFoundException $e) {
          // This is a known exception pointing to a settings error.
          $this->logger->error($e->getMessage());
          $worker = NULL;
        }

        // If there is no worker for this job, there is no point in keeping it.
        $server->deleteJob($tube, $job->getId());
        $count++;
      }
      catch (SuspendQueueException $e) {
        // If the worker indicates there is a problem with the whole queue,
        // release the item and skip to the next queue.
        $server->releaseJob($tube, $job);
        drush_set_error('DRUSH_SUSPEND_QUEUE_EXCEPTION', $e->getMessage());
      }
      catch (\Exception $e) {
        // In case of any other kind of exception, log it and leave the item
        // in the queue to be processed again later.
        drush_set_error('DRUSH_QUEUE_EXCEPTION', $e->getMessage());
      }
    }

    return $count;
  }

}
