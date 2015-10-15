<?php

/**
 * @file
 * Contains Item.
 */

namespace Drupal\beanstalkd\Queue;

/**
 * Class Item is a strongly typed implementation of the Queue API stdClass item.
 *
 * As such is bundles the actual item payload, along with QueueAPI-specific
 * properties.
 *
 * @see \Drupal\beanstalkd\Server\Payload
 */
class BeanstalkdQueueItem {
  /**
   * The same as what what passed into createItem().
   *
   * @var mixed
   *
   * @see \Drupal\Core\Queue\QueueInterface::claimItem()
   */
  public $data;

  /**
   * The unique ID returned from createItem().
   *
   * @var int
   *
   * @see \Drupal\Core\Queue\QueueInterface::claimItem()
   *
   * Name does not honor standard naming conventions, because it is required by
   * the QueueInterface::claimItem() specification.
   */
  public $item_id;

  /**
   * Timestamp when the item was put into the queue.
   *
   * @var int
   *
   * @see \Drupal\Core\Queue\QueueInterface::claimItem()
   */
  public $created;

  /**
   * Constructor.
   *
   * @param int $item_id
   *   The Pheanstalk job id, used as the item id.
   * @param mixed $data
   *   The job data: raw or Payload.
   * @param int $created
   *   The timestamp at which the job was put in the queue.
   */
  public function __construct($item_id, $data, $created) {
    $this->item_id = $item_id;
    $this->data = $data;
    $this->created = $created;
  }

}
