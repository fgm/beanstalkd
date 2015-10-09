<?php

/**
 * @file
 * Contains BeanstalkdServerTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\Tests\libraries\Kernel\KernelTestBase;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Pheanstalk\Job;

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

  /**
   * Test creating an item on an un-managed queue.
   */
  public function testCreateSad() {
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->releaseTube($tube);

    $job_id = $server->createItem($tube, 'foo');
    $this->assertEquals(0, $job_id, 'Creating an item in an unhandled queue does not return a valid job id.');

    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual, 'Creating an item in an unhandled queue does not actually submit it');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item deletion.
   */
  public function testDelete() {
    list($server, $tube, $start_count) = $this->initServerWithTube();

    // Avoid any "ground-effect" during tests with counts near 0.
    $create_count = 5;

    $job_id = 0;
    for ($i = 0; $i < $create_count; $i++) {
      $job_id = $server->createItem($tube, 'foo' . $i);
    }

    $expected = $start_count + $create_count;
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals($expected, $actual);

    // This should not do anything, since the queue name is incorrect.
    $server->deleteItem($tube . $tube, $job_id);
    $this->assertEquals($expected, $actual);

    $server->deleteItem($tube, $job_id);
    $expected = $start_count + $create_count - 1;
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals($expected, $actual, 'Deletion actually deletes jobs.');

    $this->cleanUp($server, $tube);
  }

  /**
   * Tests tube flushing.
   */
  public function testFlush() {
    list($server, $tube,) = $this->initServerWithTube();
    $item = 'foo';
    $server->createItem($tube, $item);
    $server->flushTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals(0, $actual, 'Tube is empty after flushTube');

    $server->removeTube($tube);
    $this->assertEquals(0, $actual, 'Tube is empty after removeTube');

    $this->cleanUp($server, $tube);
  }

  /**
   * Tests flushing an un-managed queue: should not error, and should return 0.
   */
  public function testFlushSad() {
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->createItem($tube, 'foo');

    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($actual, $expected, 'Tube is not empty before flush');

    $server->releaseTube($tube);

    // Flush should pretend to succeed on a unmanaged queue.
    $server->flushTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals(0, $actual, 'Tube is shown as empty after flushing an unmanaged tube');

    // But it should not actually have performed a flush.
    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual, 'Tube is actually not empty after flushing an unmanaged tube.');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item release.
   */
  public function testRelease() {
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->createItem($tube, 'foo');
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual);

    // Just-submitted job should be present.
    $job = $server->claimItem($tube);
    $this->assertTrue(is_object($job) && $job instanceof Job, 'ClaimItem returns a Job');

    // Claiming an item removes it from the visible count.
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual);

    // Releasing it makes it available again.
    $server->releaseItem($tube, $job);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual);

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item release sad: releaseItem() on a un-managed queue does nothing.
   */
  public function testReleaseSad() {
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $item = 'foo';
    $server->createItem($tube, $item);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual);

    // Just-submitted job should not be available from an un-managed queue.
    $server->releaseTube($tube);
    $job = $server->claimItem($tube);
    $this->assertSame(FALSE, $job, 'ClaimItem returns nothing from an un-managed queue');

    // But it should still be there.
    $server->addTube($tube);
    $job = $server->claimItem($tube);
    $this->assertTrue(is_object($job) && $job instanceof Job, 'ClaimItem returns a Job');

    // And it should not be included in the visible count.
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual);

    // Releasing it does not makes it available if the queue is not managed.
    $server->releaseTube($tube);
    $server->releaseItem($tube, $job);
    // Queue is re-handled to get the actual available count.
    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual);

    $this->cleanUp($server, $tube);
  }

}
