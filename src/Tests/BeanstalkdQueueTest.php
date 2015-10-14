<?php
/**
 * @file
 * Contains BeanstalkdQueueTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Queue\BeanstalkdQueue;
use Drupal\beanstalkd\Queue\BeanstalkdQueueFactory;
use Drupal\beanstalkd\Server\BeanstalkdServer;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\libraries\Kernel\KernelTestBase;

/**
 * Class BeanstalkdQueueTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdQueueTest extends BeanstalkdTestBase {

  /**
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $queue_factory = $this->container->get('queue');
    $this->queueFactory = $queue_factory;
    $this->queue = $this->queueFactory->get(BeanstalkdServerFactory::DEFAULT_QUEUE_NAME);
    $this->assertTrue($this->queue instanceof BeanstalkdQueue, "Queue API settings point to Beanstalkd");
  }

  public function testCreateQueue() {
    list($server, $tube, $start_count) = $this->initServerWithTube();
  }

}
