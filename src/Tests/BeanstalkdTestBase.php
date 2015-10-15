<?php

/**
 * @file
 * Contains BeanstalkdTestBase.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Queue\BeanstalkdQueueFactory;
use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Drupal\KernelTests\KernelTestBase;

/**
 * Class BeanstalkdTestBase is a base class for Beanstalkd tests.
 */
abstract class BeanstalkdTestBase extends KernelTestBase {

  public static $modules = ['beanstalkd'];

  /**
   * Server factory.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory
   */
  protected $serverFactory;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    // Override the database queue to ensure all requests to it come to us.
    $this->container->setAlias('queue.database', BeanstalkdQueueFactory::SERVICE_NAME);
    $this->serverFactory = $this->container->get('beanstalkd.server.factory');
  }

  /**
   * Initialize a server and tube.
   *
   * @return array
   *   - server instance
   *   - tube name
   *   - initial count of items in the tube.
   */
  protected function initServerWithTube() {
    $tube = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->addTube($tube);
    $start_count = $server->getTubeItemCount($tube);
    return [$server, $tube, $start_count];
  }

  /**
   * Clean up after a test.
   *
   * @param \Drupal\beanstalkd\Server\BeanstalkdServer $server
   *   The server to cleanup.
   * @param string $tube
   *   The name of the tube to cleanup.
   */
  protected function cleanUp(BeanstalkdServer $server, $tube) {
    $server->removeTube($tube);
  }

}
