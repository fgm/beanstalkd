<?php
// $Id$

/**
 * @file
 */

/**
 * Implementation of hook_drush_command().
 */
function beanstalkd_drush_command() {
  $items = array();

  $items['beanstalkd-servers'] = array(
    'callback' => 'drush_beanstalkd_servers',
    'description' => 'List of all the beanstalkd servers',
  );
  $items['beanstalkd-queue-list'] = array(
    'callback' => 'drush_beanstalkd_queue_list',
    'description' => 'Print a list of all Beanstalkd queues',
    'aliases' => array('queue-list'),
  );

  return $items;
}

function drush_beanstalkd_servers() {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();
  $queues = beanstalkd_get_host_queues();
  
  drush_print('Available beanstalkd servers:');
  drush_print("\n" . implode("\n", array_keys($queues)));
}

function drush_beanstalkd_queue_list() {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();
  $queues = beanstalkd_get_host_queues();

  $names = array();
  foreach ($queues as $hostname => $settings) {
    $names = array_merge($names, array_keys($settings));
  }

  drush_print('Available beanstalkd queues:');
  drush_print("\n" . implode("\n", $names));
}
