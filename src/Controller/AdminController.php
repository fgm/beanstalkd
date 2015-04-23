<?php

/**
 * @file
 * Provides any admin-specific functionality
 */

namespace Drupal\beanstalkd\Controller;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\beanstalkd\Queue\QueueBeanstalkd;
use Drupal\Core\Datetime\DateFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

class AdminController implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  public function __construct(DateFormatter $dateFormatter) {
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * Injects date formatter service.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      \Drupal::service('date.formatter')
    );
  }

  /**
   * BeanstalkD Queue Stats Callback
   */
  public function adminStats() {
    /** @method stats $queue */
    $queue = new QueueBeanstalkd(NULL, TRUE);
    if (!$error = $queue->getError()) {
      // Generate an array of stats

      // PheanstalkInterface supports stats() via __call().
      /** @var \Pheanstalk_PheanstalkInterface[] $stats */
      /** @noinspection PhpUndefinedMethodInspection */
      $statsArray = $queue->stats();

      /** @var \Zend\Stdlib\ArrayObject $stats */
      $stats = reset($statsArray);
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

      // Loop over each stat result and build it into the $variables['rows'] array
      foreach ($stats as $key => $value) {
        // For safety, clean the key
        $key = SafeMarkup::checkPlain($key);

        // Depending on the key, format the value as appropriate
        switch ($key) {
          // Format 'interval' keys
          case 'uptime' :
            $value = $this->dateFormatter->formatInterval($value);
            break;

          // Format 'data size' keys
          case 'binlog-max-size' :
          case 'max-job-size' :
            $value = format_size($value);
            break;

          // Default to a clean value
          default :
            $value = SafeMarkup::checkPlain($value);
        }

        // Add the the rows
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
