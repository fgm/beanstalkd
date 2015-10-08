<?php

/**
 * @file
 * Installation requirements for beanstalkd.
 */

use Pheanstalk\Pheanstalk;

/**
 * Implements hook_requirements().
 */
function beanstalkd_requirements($phase) {
  $requirements = [];

  // Neither driver nor server is necessary for install/uninstall.
  if ($phase === 'install') {
    return $requirements;
  }

  $requirements['pheanstalk'] = [
    'title' => t('Pheanstalk library'),
    'severity' => REQUIREMENT_OK,
  ];

  if (!class_exists('Pheanstalk\Pheanstalk', TRUE)) {
    $requirements['pheanstalk'] = [
      'value' => t('Not found. Please ensure that Pheanstalk 3.x is installed.'),
      'severity' => REQUIREMENT_ERROR,
    ] + $requirements['pheanstalk'];

    return $requirements;
  }

  /* @var \Drupal\beanstalkd\PheanstalkFactory $factory */
  $factory = \Drupal::service('beanstalkd.pheanstalk.factory');
  $driver = $factory->create();
  $requirements['pheanstalk'] += [
    'value' => Pheanstalk::VERSION,
  ];

  $stats = $driver->stats();
  $requirements['beanstalkd'] = [
    'title' => t('Beanstalkd server'),
  ];
  if (isset($stats['version'])) {
    $requirements['beanstalkd'] = [
      'severity' => REQUIREMENT_OK,
      'value' => $stats['version'],
    ] + $requirements['pheanstalk'];
  }
  else {
    $requirements['beanstalkd'] = [
      'severity' => REQUIREMENT_ERROR,
      'value' => t('Server response does not provide version.'),
    ] + $requirements['pheanstalk'];
  }

  return $requirements;
}
