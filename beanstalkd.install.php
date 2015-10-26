<?php

/**
 * @file
 * Installation requirements for beanstalkd.
 */

use Pheanstalk\Pheanstalk;

/**
 * Helper for Pheanstalk requirements.
 *
 * @param array $requirements
 *   A hook_requirements() requirements array.
 *
 * @return array<string,array>
 *   As per hook_requirements().
 */
function _beanstalkd_requirements_library(array $requirements) {
  $requirements['pheanstalk'] = [
    'title' => t('Beanstalkd: Pheanstalk library'),
    'severity' => REQUIREMENT_OK,
  ];

  if (!class_exists('Pheanstalk\Pheanstalk', TRUE)) {
    $requirements['pheanstalk'] = [
        'value' => t('Not found. Please ensure that Pheanstalk 3.x is installed.'),
        'severity' => REQUIREMENT_ERROR,
      ] + $requirements['pheanstalk'];

    return $requirements;
  }

  $requirements['pheanstalk'] += [
    'value' => Pheanstalk::VERSION,
  ];

  return $requirements;
}

/**
 * Helper for Beanstalkd server requirements.
 *
 * @param array $requirements
 *   A hook_requirements() requirements array.
 *
 * @return array<string,array>
 *   As per hook_requirements().
 */
function _beanstalkd_requirements_servers(array $requirements) {

  /* @var \Drupal\beanstalkd\Server\BeanstalkdServerFactory $factory */
  $factory = \Drupal::service('beanstalkd.server.factory');
  $servers = array_keys($factory->getServerDefinitions());

  $offset = 0;
  foreach ($servers as $alias) {
    $key = 'beanstalkd-' . $offset;

    $requirements[$key] = [
      'title' => t('Beanstalkd: %alias server', ['%alias' => $alias]),
    ];
    $server = $factory->get($alias);
    $stats = $server->stats('global');

    if (isset($stats['version'])) {
      $requirements[$key] = [
          'severity' => REQUIREMENT_OK,
          'value' => $stats['version'],
        ] + $requirements[$key];
    }
    else {
      $requirements[$key] = [
          'severity' => REQUIREMENT_ERROR,
          'value' => t('Server response does not provide version.'),
        ] + $requirements[$key];
    }

    $offset++;
  }

  return $requirements;
}

/**
 * Implements hook_requirements().
 */
function beanstalkd_requirements($phase) {
  $requirements = [];

  // Neither driver nor server is necessary for install/uninstall.
  if ($phase !== 'runtime') {
    return $requirements;
  }

  $requirements = _beanstalkd_requirements_library($requirements);
  $requirements = _beanstalkd_requirements_servers($requirements);

  return $requirements;
}
