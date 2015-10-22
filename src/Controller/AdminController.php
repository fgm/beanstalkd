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
   * Dispatch statistics to bins by statistic type.
   *
   * @param array $stat_types
   *   The statistic types, indexed by regex.
   * @param array $stats
   *   The statistics to dispatch.
   *
   * @return array
   *   An array of stats grouped by statistic type.
   */
  protected function dispatchStatsToBins(array $stat_types, array $stats) {
    $bins = [];
    foreach (array_keys($stat_types) as $regex) {
      $bins[$regex] = [];
    }

    ksort($stats);
    foreach ($stats as $stat => $value) {
      // No need to clean the key, Twig will take care of it.
      // Depending on the key, format the value as appropriate.
      $value = $this->formatValue($stat, $value);
      foreach ($bins as $regex => &$data) {
        if (preg_match($regex, $stat)) {
          $data[] = [
            ['data' => $stat],
            ['data' => $value],
          ];
          break;
        }
      }
    }

    return $bins;
  }

  /**
   * Build the flat structure of table rows from statistics bins.
   *
   * @param array $bins
   *   The stats-by-type bins.
   *
   * @return array
   *   The tables rows.
   */
  protected function buildTableRows(array $bins) {
    $max_height = max(array_map(function (array $column) {
      return count($column);
    }, $bins));

    foreach ($bins as &$column) {
      $column = array_pad($column, $max_height, ['', '']);
    }

    $rows = [];
    for ($row_index = 0; $row_index < $max_height; $row_index++) {
      $row = [];
      foreach ($bins as $stats) {
        list($name, $stat) = $stats[$row_index];
        $row[] = $name;
        $row[] = $stat;
      }
      $rows[] = $row;
    }

    return $rows;
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

      $server = $this->serverFactory->get($alias);
      $stats = $server->stats('global')->getArrayCopy();

      if ($stats === FALSE) {
        $section[$alias . '-stats'] = [
          '#markup' => t('Error obtaining statistics from server @server', [
            '@server' => $alias,
          ]),
        ];
      }
      $stat_types = [
        '/^cmd-/' => ['data' => t('Command counts'), 'colspan' => 2],
        '/^current-/' => ['data' => t('Current state'), 'colspan' => 2],
        '/.*/' => ['data' => t('Miscellaneous'), 'colspan' => 2],
      ];
      $header = array_values($stat_types);

      $bins = $this->dispatchStatsToBins($stat_types, $stats);
      $rows = $this->buildTableRows($bins);

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
