<?php

/**
 * @file
 * Contains SampleWorker.
 */

namespace Drupal\beanstalkd\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pretend to perform some work.
 *
 * @QueueWorker(
 *   id = "beanstalk_example",
 *   title = @Translation("Beanstalkd example worker"),
 *   cron = {"time" = 60}
 * )
 */
class SampleWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The beanstalkd logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The Beanstalkd logger service.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $logger = $container->get('logger.channel.beanstalkd');
    return new static($logger);
  }

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
