<?php
/**
 * @file
 * Contains the Beanstalkd CLI Runner.
 */

namespace Drupal\beanstalkd;

use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Psr\Log\LoggerInterface;

/**
 * Class Runner contains code needed for CLI (Drush) operations.
 */
class Runner {

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
   * @param \Drupal\beanstalkd\Server\BeanstalkdServerFactory $server_factory
   *   The Beanstalkd server factory service.
   * @param \Drupal\beanstalkd\WorkerManager $manager
   *   The Beanstalkd worker manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Beanstalkd logger channel service.
   */
  public function __construct(BeanstalkdServerFactory $server_factory, WorkerManager $manager, LoggerInterface $logger) {
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
    }, ARRAY_FILTER_USE_BOTH);
    $result = array_keys($queues);
    return $result;
  }

}
