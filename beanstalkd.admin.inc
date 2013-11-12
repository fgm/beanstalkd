<?php

/**
 * @file
 * Provides any admin-specific functionality.
 */

/**
 * BeanstalkD Queue Stats Callback.
 */
function beanstalkd_admin_stats() {
  if ($queue = new BeanstalkdQueue(NULL, TRUE)) {
    // Generate an array of stats.
    $stats = $queue->stats();
    $stats = reset($stats)->getArrayCopy();

    // Define the base variables for theme_table.
    $variables = array(
      'header' => array(
        array('data' => t('Property')),
        array('data' => t('Value')),
      ),
      'rows' => array(),
    );

    // Loop over each stat result and build it into the $variables['rows'] array.
    foreach ($stats as $key => $value) {
      // For safety, clean the key
      $key = check_plain($key);

      // Depending on the key, format the value as appropriate.
      switch ($key) {
        // Format 'interval' keys.
        case 'uptime':
          $value = format_interval($value);
          break;

        // Format 'data size' keys.
        case 'binlog-max-size':
        case 'max-job-size':
          $value = format_size($value);
          break;

        // Default to a clean value.
        default:
          $value = check_plain($value);
      }

      // Add the the rows.
      $variables['rows'][] = array(
        'data' => array(
          array('data' => $key),
          array('data' => $value),
        ),
      );
    }

    // Return a themed table of data.
    return theme('table', $variables);
  }
  else {
    drupal_set_message(t('Unable to connect to Beanstalkd'));
  }

  return '';
}
