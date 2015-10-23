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
 *   id = "beanstalkd_example",
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
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Beanstalkd logger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.beanstalkd');
    return new static($configuration, $plugin_id, $plugin_definition, $logger);
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
