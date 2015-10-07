<?php
/**
 * @file
 * BeanstalkdServerTest.php
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Drupal\Tests\libraries\Kernel\KernelTestBase;

/**
 * Class BeanstalkdServerTest
 *
 * @group Beanstalkd
 */
class BeanstalkdServerTest extends KernelTestBase {

  public static $modules = ['beanstalkd'];

  /**
   * Tests tube flushing.
   *
   * @covers \Drupal\beanstalkd\Server\BeanstalkdServer
   */
  public function testFlush() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory $factory */
    $factory = $this->container->get('beanstalkd.server.factory');
    $queue = BeanstalkdServerFactory::DEFAULT_QUEUE_NAME;
    $server = $factory->get(BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);
    $server->addQueue($queue);
    $server->flushTube($queue);
    $actual = $server->getTubeItemCount($queue);
    $this->assertEquals(0, $actual, "Tube is empty after flush");
  }
}
