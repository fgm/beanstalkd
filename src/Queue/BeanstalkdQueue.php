<?php
/**
 * @file
 * Contains BeanstalkdQueue.
 */

namespace Drupal\beanstalkd\Queue;

use Drupal\Core\Queue\QueueInterface;
use Drupal\beanstalkd\Server\BeanstalkdServer;
use Pheanstalk\Job;

/**
 * Class BeanstalkdQueue is a QueueInterface implementation for Beanstalkd.
 */
class BeanstalkdQueue implements QueueInterface {

  /**
   * The queue name.
   *
   * @var string
   */
  protected $name;

  /**
   * The server handling this queue.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServer
   */
  protected $server;


  /**
   * Constructor.
   *
   * @param string $name
   *   The queue name.
   * @param \Drupal\beanstalkd\Server\BeanstalkdServer $server
   *   The server handling this queue.
   */
  public function __construct($name, BeanstalkdServer $server) {
    $this->name = $name;
    $this->server = $server;

    $this->createQueue();
  }

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    $this->server->putData($this->name, $data);
  }

  /**
   * Name getter.
   *
   * @return string
   *   The queue name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * {@inheritdoc}
   */
  public function numberOfItems() {
    $count = $this->server->getTubeItemCount($this->name);
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function claimItem($lease_time = 3600) {
    // @TODO Implement specific handling for jobs containing a Payload object,
    // like the ability for $lease_time to interact with TTR.
    $job = $this->server->claimJob($this->name);
    if ($job === FALSE) {
      return FALSE;
    }

    $stats = $this->server->stats('job', $this->name, $job);

    // Return the Epoch if age is unknown, to ensure "created" will be 0..
    $age = $stats['age'] ?: REQUEST_TIME;

    $created = REQUEST_TIME - $age;
    $item = new BeanstalkdQueueItem($job->getId(), $job->getData(), $created);
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItem($item) {
    if (!isset($item->item_id)) {
      throw new \InvalidArgumentException('Item to delete does not appear to come from claimItem().');
    }

    $this->server->deleteJob($this->name, $item->item_id);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseItem($item) {
    if (!isset($item->item_id)) {
      throw new \InvalidArgumentException('Item to release does not appear to come from claimItem().');
    }

    $job = new Job($item->item_id, $item->data);
    $this->server->releaseJob($this->name, $job);
  }

  /**
   * {@inheritdoc}
   */
  public function createQueue() {
    $this->server->addTube($this->name);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteQueue() {
    $this->server->removeTube($this->name);
  }

}
