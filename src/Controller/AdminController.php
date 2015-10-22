<?php

/**
 * @file
 * Provides any admin-specific functionality.
 */

namespace Drupal\beanstalkd\Controller;

use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Class AdminController contains the report controller.
 */
class AdminController implements ContainerInjectionInterface {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The Beanstalkd server factory service.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory
   */
  protected $serverFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\beanstalkd\Server\BeanstalkdServerFactory $server_factory
   *   The Beanstalkd server factory service.
   */
  public function __construct(DateFormatterInterface $date_formatter, BeanstalkdServerFactory $server_factory) {
    $this->dateFormatter = $date_formatter;
    $this->serverFactory = $server_factory;
  }

  /**
   * Injects date formatter service.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The DIC.
   *
   * @return static
   *   The new controller instance.
   */
  public static function create(ContainerInterface $container) {
    /* @var \Drupal\Core\DateTime\DateFormatterInterface $formatter */
    $formatter = $container->get('date.formatter');

    /* @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory $server_factory */
    $server_factory = $container->get('beanstalkd.server.factory');

    return new static($formatter, $server_factory);
  }

  /**
   * Helper for statistics table.
   *
   * @param string $stat
   *   The name of a statistic.
   * @param mixed $value
   *   A raw statistic.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   A statistic formatted for human usage.
   */
  protected function formatValue($stat, $value) {
    switch ($stat) {
      case 'uptime':
        // Format 'interval' keys.
        $value = $this->dateFormatter->formatInterval($value);
        break;

      // Format 'data size' keys.
      case 'binlog-max-size':
      case 'max-job-size':
        $value = format_size($value);
        break;

      // Format 'short duration' keys.
      case 'rusage-stime':
      case 'rusage-utime':
        $value = t('@seconds sec', ['@seconds' => $value]);
        break;

      // Default to a clean value: Twig will sanitize it.
      default:
        break;
    }

    return $value;
  }

  /**
   * BeanstalkD Queue Stats Callback.
   */
  public function adminStats() {
    $result = [];

    $servers = $this->serverFactory->getServerDefinitions();

    foreach ($servers as $alias => $definition) {
      $section = [];

      $definition['persistent'] = $definition['persistent'] ? t('Yes') : t('No');
      $header = array_keys($definition);
      $rows = [array_values($definition)];

      $section[$alias . '-definition'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];

      $server = $this->serverFactory->get("z" . $alias);
      $stats = $server->stats('global')->getArrayCopy();

      if ($stats === FALSE) {
        $section[$alias . '-stats'] = [
          '#markup' => t('Error obtaining statistics from server @server', [
            '@server' => $alias,
          ]),
        ];
      }
      $header = [
        t('Property'),
        t('Value'),
      ];
      $rows = [];
      ksort($stats);
      foreach ($stats as $stat => $value) {
        // No need to clean the key, Twig will take care of it.
        // Depending on the key, format the value as appropriate.
        $value = $this->formatValue($stat, $value);
        $rows[] = [
          ['data' => $stat],
          ['data' => $value],
        ];
      }

      $section[$alias . '-stats'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];

      $result[$alias . '-section'] = [
        '#type' => 'details',
        '#title' => $alias,
        '#open' => TRUE,
        'definition' => $section,
      ];
    }

    return $result;
  }

}
