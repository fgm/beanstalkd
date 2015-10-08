<?php
/**
 * @file
 * WorkerManager.php
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Site\Settings;

/**
 * Class WorkerManager.
 */
class WorkerManager {
  const SERVICE = 'queue.beanstalkd';

  /**
   * The plugin.manager.queue_worker service.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $baseManager;

  /**
   * The name of the default queue service.
   *
   * @var string
   */
  protected $defaultService;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The settings service.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $base_manager
   *   The plugin.manager.queue_worker service.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory service.
   */
  public function __construct(QueueWorkerManagerInterface $base_manager,
    Settings $settings, QueueFactory $queue_factory) {
    $this->baseManager = $base_manager;
    $this->queueFactory = $queue_factory;
    $this->settings = $settings;

    $this->defaultService = $this->settings->get('queue_default', 'queue.database');
  }

  /**
   * Get a list of all queue workers on the site.
   *
   * @return array
   *   A hash of worker definitions by worker id.
   */
  public function getWorkers() {
    $definitions = $this->baseManager->getDefinitions();
    return $definitions;
  }

  /**
   * Get the name of the factory service for a given queue.
   *
   * @param string $queue_name
   *   The name of a queue for which to return the factory service.
   *
   * @return string
   *   The name of the factory service for the queue.
   */
  protected function getQueueService($queue_name) {
    return $this->settings->get('queue_service_' . $queue_name, $this->defaultService);
  }

  /**
   * Gets the names of queues configured to be handled by Beanstalkd.
   *
   * @return string[]
   *   A possibly empty array of strings.
   */
  public function getBeanstalkdQueues() {
    $all_queues = array_keys($this->getWorkers());
    $that = $this;
    $queues = array_filter($all_queues, function ($queue_name) use($that) {
      return static::SERVICE === $that->getQueueService($queue_name);
    });

    return $queues;
  }

}
