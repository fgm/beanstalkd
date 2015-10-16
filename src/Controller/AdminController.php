<?php

/**
 * @file
 * Provides any admin-specific functionality.
 */

namespace Drupal\beanstalkd\Controller;

use Drupal\beanstalkd\Queue\QueueBeanstalkd;
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
   * Constructor.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(DateFormatterInterface $date_formatter) {
    $this->dateFormatter = $date_formatter;
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
    return new static($formatter);
  }

  /**
   * BeanstalkD Queue Stats Callback.
   */
  public function adminStats() {
    /* @method stats $queue */
    $queue = new QueueBeanstalkd(NULL, TRUE);
    if (!$error = $queue->getError()) {
      // Generate an array of stats.
      // PheanstalkInterface supports stats() via __call().
      /* @var \Pheanstalk_PheanstalkInterface[] $stats */
      $stats_array = $queue->stats();

      /* @var \ArrayObject $stats */
      $stats = reset($stats_array);
      $stats->getArrayCopy();

      // Define the base variables for theme_table
      $ret = array(
        '#type' => 'table',
        '#header' => array(
          array('data' => t('Property')),
          array('data' => t('Value')),
        ),
        'rows' => array(),
      );

      // Loop over each stat and build it into the $variables['rows'] array.
      foreach ($stats as $key => $value) {
        // No need to clean the key, Twig will take care of it.
        // Depending on the key, format the value as appropriate.
        switch ($key) {
          // Format 'interval' keys.
          case 'uptime':
            $value = $this->dateFormatter->formatInterval($value);
            break;

          // Format 'data size' keys.
          case 'binlog-max-size':
          case 'max-job-size':
            $value = format_size($value);
            break;

          // Default to a clean value: Twig will sanitize it.
          default:
            break;
        }

        // Add the rows.
        $ret['#rows'][] = array(
          'data' => array(
            array('data' => $key),
            array('data' => $value),
          ),
        );
      }

      return $ret;
    }
    else {
      $message = t('Unable to connect to Beanstalkd: @error', [
        '@error' => $error->getMessage(),
      ]);
      drupal_set_message($message, 'error');
      return ['#markup' => ''];
    }
  }

}
