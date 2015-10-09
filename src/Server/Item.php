<?php

/**
 * @file
 * Contains Item.
 */

namespace Drupal\beanstalkd\Server;

/**
 * Class Item is a strongly typed implementation of the Queue API stdClass item.
 */
class Item {
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

}
