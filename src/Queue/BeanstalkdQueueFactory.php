<?php

/**
 * @file
 * Contains BeanstalkdQueueFactory.
 */

namespace Drupal\beanstalkd\Queue;

use Drupal\beanstalkd\Server\BeanstalkdServerFactory;

/**
 * Class BeanstalkdQueueFactory.
 */
class BeanstalkdQueueFactory {
  const SERVICE_NAME = 'queue.beanstalkd';

  /**
   * The server factory service.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory
   */
  protected $serverFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\beanstalkd\Server\BeanstalkdServerFactory $server_factory
   *   The server factory service.
   */
  public function __construct(BeanstalkdServerFactory $server_factory) {
    $this->serverFactory = $server_factory;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the Queue holding key and value pairs.
   *
   * @return \Drupal\beanstalkd\Queue\BeanstalkdQueue
   *   the BeanstalkdQueue object
   */
  public function get($name) {
    $server = $this->serverFactory->getQueueServer($name);
    $queue = new BeanstalkdQueue($name, $server);
    return $queue;
  }

}
