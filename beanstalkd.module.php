<?php

/**
 * @file
 * Provide core implementation of beanstalkd support for Queues.
 *
 * @TODO
 * - Consider whether reimplementing a mechanism like the "description callback"
 *   on queues for peek* commands in Drupal 7 is worth it and, if so, find how
 *   to implement it.
 */

use Drupal\Core\Site\Settings;
use Pheanstalk\PheanstalkInterface;

/**
 * Get Queue Parameters.
 *
 * @param string $name
 *   Queue name.
 *
 * @return mixed
 *   Type depends on the option requested.
 */
function beanstalkd_get_queue_options($name) {
  static $options = array();

  if (!isset($options[$name])) {
    $options[$name] = Settings::get('beanstalk_queue_' . $name, array());
    $defaults = Settings::get('beanstalk_default_queue', array()) + array(
      'host' => Settings::get('beanstalkd_host', 'localhost'),
      'port' => Settings::get('beanstalkd_port', PheanstalkInterface::DEFAULT_PORT),
      'fork' => FALSE,
      'reserve_timeout' => 0,
      'ttr' => PheanstalkInterface::DEFAULT_TTR,
      'priority' => PheanstalkInterface::DEFAULT_PRIORITY,
      'release_delay' => PheanstalkInterface::DEFAULT_DELAY,
      'forked_extra_timeout' => FALSE,
      'forked_extra_items' => FALSE,
      'delay' => PheanstalkInterface::DEFAULT_DELAY,
    );
    $options[$name] += $defaults;
  }

  return $options[$name];
}

/**
 * Get a list of all queues which have drush enabled.
 *
 * @param string|null $host
 *   The list of all the queues which are using this specified host. If null,
 *   report for the default server.
 * @param string|null $queue_name
 *   Queue name. If null, report for the default queue.
 *
 * @return array
 *   If a host is specified then it returns an array of all the settings for the
 *   queue. Otherwise if no host is specified then it will return an array keyed
 *   by host for all with the settings for all queues.
 */
function beanstalkd_get_host_queues($host = NULL, $queue_name = NULL) {
  static $queue_list;

  if (!isset($queue_list)) {
    $queue_list = array();
    /* @var \Drupal\Core\Queue\QueueWorkerManagerInterface $worker_manager */
    $worker_manager = \Drupal::service('plugin.manager.queue_worker');
    $queue_definitions = $worker_manager->getDefinitions();

    foreach ($queue_definitions as $queue => $definition) {
      $name = 'queue_service_' . $queue;
      $options = beanstalkd_get_queue_options($queue);
      if (Settings::get($name, Settings::get('queue_default', 'queue.system')) == 'queue.beanstalkd') {
        $definition['options'] = $options;
        $queue_list[$options['host'] . ':' . $options['port']][$queue] = $definition;
      }
    }
  }

  if (!empty($queue_name)) {
    $options = beanstalkd_get_queue_options($queue_name);
    $host = $options['host'] . ':' . $options['port'];
    return isset($queue_list[$host][$queue_name]) ? $queue_list[$host][$queue_name] : array();
  }
  if (!empty($host)) {
    return isset($queue_list[$host]) ? $queue_list[$host] : array();
  }
  return $queue_list;
}

/**
 * Get the queue settings for a server.
 *
 * @param string|null $hostname
 *   The host name of the Beanstalk server on which to list queues.
 *
 * @return array
 *   A hash of queue settings by queue names.
 */
function beanstalkd_get_queues($hostname = NULL) {
  $queues = beanstalkd_get_host_queues($hostname);

  if (isset($hostname)) {
    $names = array_keys($queues);
  }
  else {
    $names = array();
    foreach ($queues as $hostname => $settings) {
      $names = array_merge($names, array_keys($settings));
    }
  }

  return $names;
}
