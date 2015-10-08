<?php

/**
 * @file
 * Contains BeanstalkdServerTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\Tests\libraries\Kernel\KernelTestBase;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;

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
   * Test item deletion.
   */
  public function testDelete() {
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->addQueue($queue);
    $start_count = $server->getTubeItemCount($queue);

    // Avoid any "ground-effect" during tests with counts near 0.
    $create_count = 5;

    $job_id = 0;
    for ($i = 0; $i < $create_count; $i++) {
      $job_id = $server->createItem($queue, 'foo' . $i);
    }

    $expected = $start_count + $create_count;
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals($expected, $actual);

    // This should not do anything, since the queue name is incorrect.
    $server->deleteItem($queue . $queue, $job_id);
    $this->assertEquals($expected, $actual);

    $server->deleteItem($queue, $job_id);
    $expected = $start_count + $create_count - 1;
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals($expected, $actual, 'Deletion actually deletes items.');

    $server->removeQueue($queue);
  }

  /**
   * Tests tube flushing.
   */
  public function testFlush() {
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->addQueue($queue);
    $item = 'foo';
    $server->createItem($queue, $item);
    $server->flushTube($queue);
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals(0, $actual, 'Tube is empty after flushTube');

    $server->removeQueue($queue);
    $this->assertEquals(0, $actual, 'Tube is empty after removeQueue');
  }

  /**
   * Tests flushing an un-managed queue: should not error, and should return 0.
   */
  public function testSadFlush() {
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $this->serverFactory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->createItem($queue, 'foo');
    $server->flushTube($queue);
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals(0, $actual, 'Tube is empty after flush');
  }

}
