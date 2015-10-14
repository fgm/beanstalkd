<?php
/**
 * @file
 * Contains BeanstalkdQueueTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\beanstalkd\Queue\BeanstalkdQueue;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;

/**
 * Class BeanstalkdQueueTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdQueueTest extends BeanstalkdTestBase {

  /**
   * The default queue, handled by Beanstalkd.
   *
   * @var \Drupal\beanstalkd\Queue\BeanstalkdQueue
   */
  protected $queue;

  /**
   * The queue factory service.
   *
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

  /**
   * Test queue registration.
   */
  public function testCreateQueue() {
    $this->initServerWithTube();
  }

}
