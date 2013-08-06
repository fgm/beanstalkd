<?php

namespace Drupal\beanstalkd\Queue;

class QueueBeanstalkdFactory {
  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the Queue holding key and value pairs.
   *
   * @return \Drupal\beanstalkd\Queue\QueueBeanstalkd
   *   the beanstalk Queue object
   */
  public function get($name) {
    return new QueueBeanstalkd($name);
  }
}