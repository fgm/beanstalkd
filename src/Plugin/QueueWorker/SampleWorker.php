<?php

/**
 * @file
 * Contains SampleWorker.
 */

namespace Drupal\beanstalkd\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Pretend to perform some work.
 *
 * @QueueWorker(
 *   id = "beanstalk_example",
 *   title = @Translation("Beanstalkd example worker"),
 *   cron = {"time" = 60}
 * )
 */
class SampleWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $context = [
      'data' => var_export($data, TRUE),
    ];

    \Drupal::logger('beanstalkd')->debug(t('Processed {data}.'), $context);
  }

}
