<?php
/**
 * @file
 * Contains BeanstalkdServerTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Drupal\Tests\libraries\Kernel\KernelTestBase;

/**
 * Class BeanstalkdServerTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdServerTest extends KernelTestBase {

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
    $this->serverFactory = $this->container->get('beanstalkd.server.factory');
  }

  /**
   * Tests tube flushing.
   *
   * @covers \Drupal\beanstalkd\Server\BeanstalkdServer
   */
  public function testFlush() {
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->addQueue($queue);
    $item = "foo";
    $server->createItem($queue, $item);
    $server->flushTube($queue);
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals(0, $actual, "Tube is empty after flushTube");

    $server->removeQueue($queue);
    $this->assertEquals(0, $actual, "Tube is empty after removeQueue");
  }

  /**
   * Tests flushing an unmanaged queue: should not error, and should return 0.
   *
   * @covers \Drupal\beanstalkd\Server\BeanstalkdServer
   */
  public function testSadFlush() {
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->createItem($queue, "foo");
    $server->flushTube($queue);
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals(0, $actual, "Tube is empty after flush");
  }

}
