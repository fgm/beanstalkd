<?php

/**
 * @file
 * Provides any admin-specific functionality
 */

namespace Drupal\beanstalkd\Controller;

use Drupal\Core\Controller\ControllerInterface;
use Drupal\beanstalkd\Queue\QueueBeanstalkd;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

class AdminController implements ContainerInjectionInterface {

  /**
   * Injects BookManager Service.
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }

  /**
   * BeanstalkD Queue Stats Callback
   */
  public function adminStats() {
    if ($queue = new QueueBeanstalkd(NULL, TRUE)) {
      // Generate an array of stats

      $stats = $queue->stats();
      $stats = reset($stats)->getArrayCopy();

      // Define the base variables for theme_table
      $variables = array(
        'header' => array(
          array('data' => t('Property')),
          array('data' => t('Value')),
        ),
        'rows' => array(),
      );

      // Loop over each stat result and build it into the $variables['rows'] array
      foreach ($stats as $key => $value) {
        // For safety, clean the key
        $key = check_plain($key);

        // Depending on the key, format the value as appropriate
        switch ($key) {
          // Format 'interval' keys
          case 'uptime' :
            $value = format_interval($value);
            break;

          // Format 'data size' keys
          case 'binlog-max-size' :
          case 'max-job-size' :
            $value = format_size($value);
            break;

          // Default to a clean value
          default :
            $value = check_plain($value);
        }

        // Add the the rows
        $variables['rows'][] = array(
          'data' => array(
            array('data' => $key),
            array('data' => $value),
          ),
        );
      }

      // Return a themed table of data
      return theme('table', $variables);
    }
    else {
      drupal_set_message(t('Unable to connect to Beanstalkd'));
    }

    return '';
  }
}
