<?php

/**
 * @file
 * Tests for Drupal Queue. Run with Simpletest module.
 * http://drupal.org/project/simpletest
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Test the basic queue functionality.
 *
 * @group beanstalkd
 */
class BeanstalkdTest extends WebTestBase {

  public static $modules = ['beanstalkd'];

  /**
   * Queues and de-queues a set of items to check the basic queue functionality.
   */
  function testQueue() {
    // Create two queues.

    // Set random queues to use beanstalkd
    $name1 = $this->randomMachineName();
    $name2 = $this->randomMachineName();
    // FIXME find a replacement for these settings, which are not config.
    // variable_set('queue_class_' . $name1, 'QueueBeanstalkd');
    // variable_set('queue_class_' . $name2, 'QueueBeanstalkd');

    /** @var \Drupal\Core\Queue\QueueFactory $factory */
    $factory = \Drupal::service('queue');
    $queue1 = $factory->get($name1);
    $queue1->createQueue();
    $queue2 = $factory->get($name2);
    $queue2->createQueue();

    // Create four items.
    $data = array();
    for ($i = 0; $i < 4; $i++) {
      $data[] = array($this->randomMachineName() => $this->randomMachineName());
    }

    // Queue items 1 and 2 in the queue1.
    $queue1->createItem($data[0]);
    $queue1->createItem($data[1]);

    // Retrieve two items from queue1.
    $items = array();
    $new_items = array();

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    // First two de-queued items should match the first two items we queued.
    $this->assertEqual($this->queueScore($data, $new_items), 2, t('Two items matched'));

    // Add two more items.
    $queue1->createItem($data[2]);
    $queue1->createItem($data[3]);

    $this->assertTrue($queue1->numberOfItems(), t('Queue 1 is not empty after adding items.'));
    $this->assertFalse($queue2->numberOfItems(), t('Queue 2 is empty while Queue 1 has items'));

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    // All de-queued items should match the items we queued exactly once,
    // therefore the score must be exactly 4.
    $this->assertEqual($this->queueScore($data, $new_items), 4, t('Four items matched'));

    // There should be no duplicate items.
    $this->assertEqual($this->queueScore($new_items, $new_items), 4, t('Four items matched'));

    // Delete all items from queue1.
    foreach ($items as $item) {
      $queue1->deleteItem($item);
    }

    // Check that both queues are empty.
    $this->assertFalse($queue1->numberOfItems(), t('Queue 1 is empty'));
    $this->assertFalse($queue2->numberOfItems(), t('Queue 2 is empty'));
  }

  /**
   * This function returns the number of equal items in two arrays.
   *
   * @param mixed[] $items
   * @param mixed[] $new_items
   *
   * @return int
   */
  function queueScore($items, $new_items) {
    $score = 0;
    foreach ($items as $item) {
      foreach ($new_items as $new_item) {
        if ($item === $new_item) {
          $score++;
        }
      }
    }
    return $score;
  }
}
