<?php

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
  $items['beanstalkd-server-stats'] = array(
    'callback' => 'drush_beanstalkd_server_stats',
    'description' => 'Return the beanstalkd server stats',
    'arguments' => array(
      'server' => 'Specify the server to query',
    ),
    'aliases' => array('server-stats'),
  );
  $items['beanstalkd-queue-list'] = array(
    'callback' => 'drush_beanstalkd_queue_list',
    'description' => 'Print a list of all Beanstalkd queues',
    'aliases' => array('queue-list'),
  );
  $items['beanstalkd-queue-stats'] = array(
    'callback' => 'drush_beanstalkd_queue_stats',
    'description' => 'Display the stats for the specified queue',
    'arguments' => array(
      'queue' => 'specify the name of the queue',
    ),
    'aliases' => array('queue-stats'), 
  );
  $items['beanstalkd-item-stats'] = array(
    'callback' => 'drush_beanstalkd_item_stats',
    'description' => 'Displays stats for a specified job in the queue',
    'arguments' => array(
      'item id' => 'Item id to display the stats for.',
    ),
    'options' => array(
      'host' => 'Specify the host of the beanstalkd server',
      'port' => 'Specify the port of the beanstalkd server',
      'queue' => 'Specify the queue which the job exists.',
    ),
    'aliases' => array('item-stats'),
  );
  $items['beanstalkd-peek-ready'] = array(
    'arguments' => array(
      'queue' => 'Queue to inspect for ready items',
    ),
    'callback' => 'drush_beanstalkd_peek_ready',
    'description' => 'Display the next job which is ready to be run.',
    'options' => array(
      'host' => 'Specify the host of the beanstalkd server',
      'port' => 'Specify the port of the beanstalkd server',
    ),
    'aliases' => array('peek-ready'),
  );
  $items['beanstalkd-peek-buried'] = array(
    'arguments' => array(
      'queue' => 'Queue to inspect for buried items',
    ),
    'callback' => 'drush_beanstalkd_peek_buried',
    'description' => 'Display the next job which has been buried.',
    'options' => array(
      'host' => 'Specify the host of the beanstalkd server',
      'port' => 'Specify the port of the beanstalkd server',
    ),
    'aliases' => array('peek-buried'),
  );
  $items['beanstalkd-peek-delayed'] = array(
    'arguments' => array(
      'queue' => 'Queue to inspect for delayed items',
    ),
    'callback' => 'drush_beanstalkd_peek_delayed',
    'description' => 'Display the next job which has been delayed.',
    'options' => array(
      'host' => 'Specify the host of the beanstalkd server',
      'port' => 'Specify the port of the beanstalkd server',
    ),
    'aliases' => array('peek-delayed'),
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

function drush_beanstalkd_server_stats($host = NULL) {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();
  $queues = beanstalkd_get_host_queues();
  
  if ($host) {
    $hostinfo = parse_url($host) + array('port' => Pheanstalk::DEFAULT_PORT);
    if (!isset($hostinfo['host']) && isset($hostinfo['path'])) {
      $hostinfo['host'] = $hostinfo['path'];
      unset($hostinfo['path']);
    } 
    $host = $hostinfo['host'] . ':' . $hostinfo['port'];
  }
  
  if (count($queues) > 1) {
    $options = drupal_map_assoc(array_keys($queues));
    $host = drush_choice($options, 'Select a host to query');
  }
  elseif (!$host) {
    $host = reset(array_keys($queues));
  }

  if ($host && isset($queues[$host])) {
    // Grab the first queue for the hostname and port
    $info = reset($queues[$host]);
    $hostinfo = parse_url($host);

    $queue = new BeanstalkdQueue(NULL);
    $queue->createConnection($hostinfo['host'], $hostinfo['port']);
    
    $stats = $queue->stats();
    
    $rows = array();
    foreach ($stats as $key => $stat) {
      $rows[] = array(
        drupal_ucfirst(str_replace('-', ' ', $key)),
        $stat,
      );
    }
    
    drush_print_table($rows);
  }
  elseif ($host) {
    drush_log(dt('Invalid server !server', array('!server' => $host)), 'error');
  }
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

function drush_beanstalkd_queue_stats($name = NULL) {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();

  $names = drupal_map_assoc(beanstalkd_get_queues());

  if (!$name) {
    $name = drush_choice($names, 'Select a queue to query');
  }
  
  if ($name && isset($names[$name])) {
    $queue = DrupalQueue::get($name);
    $stats = $queue->statsTube($name);
    
    $rows = array();
    foreach ($stats as $key => $stat) {
      $rows[] = array(
        drupal_ucfirst(str_replace('-', ' ', $key)),
        $stat,
      );
    }
    
    drush_print_table($rows);
  }
}

function drush_beanstalkd_item_stats($item_id = NULL) {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();
  $queues = beanstalkd_get_host_queues();
  
  if ($name = drush_get_option('queue', NULL)) {
    $info = beanstalkd_get_host_queues(NULL, $name);
    $host = $info['options']['host'];
    $port = $info['options']['port'];
  }
  else {
    $host = drush_get_option('host', 'localhost');
    $port = drush_get_option('port', Pheanstalk::DEFAULT_PORT);
  }
  
  $hostname = $host . ':' . $port;

  if (isset($queues[$hostname])) {
    if ($item_id) {
      $queue = new BeanstalkdQueue(NULL);
      $queue->createConnection($host, $port);

      try {
        $item = $queue->peek($item_id);
        $stats = $queue->statsJob($item);
        $rows = array();
        foreach ($stats as $key => $stat) {
          $rows[] = array(
            drupal_ucfirst(str_replace('-', ' ', $key)),
            $stat,
          );
        }

        drush_print_table($rows);
      }
      catch (Exception $e) {
        drush_log($e->getMessage(), 'error');
      }
    }
    else {
      drush_log(dt('No item id specified.'), 'error');
    }
  }
  else {
    drush_log(dt('!host is not a valid hostname', array('!host' => $hostname)), 'error');
  }
}

function drush_beanstalkd_peek_ready($name = NULL) {
  drush_beanstalkd_peek_items('ready', $name);
}

function drush_beanstalkd_peek_buried($name = NULL) {
  drush_beanstalkd_peek_items('buried', $name);
}

function drush_beanstalkd_peek_delayed($name = NULL) {
  drush_beanstalkd_peek_items('delayed', $name);
}

function drush_beanstalkd_peek_items($type, $name) {
  beanstalkd_load_pheanstalk();
  drupal_queue_include();
  $queues = beanstalkd_get_host_queues();
  
  if ($name = drush_get_option('queue', NULL)) {
    $info = beanstalkd_get_host_queues(NULL, $name);
    $host = $info['options']['host'];
    $port = $info['options']['port'];
  }
  else {
    $host = drush_get_option('host', 'localhost');
    $port = drush_get_option('port', Pheanstalk::DEFAULT_PORT);
  }

  $hostname = $host . ':' . $port;

  if (isset($queues[$hostname])) {
    $queue = new BeanstalkdQueue(NULL);
    $queue->createConnection($host, $port);

    $names = drupal_map_assoc(beanstalkd_get_queues($hostname));
    _drush_beanstalkd_filter_type($queue, $type, TRUE);
    $names = array_filter($names, '_drush_beanstalkd_filter_type');

    if (empty($names)) {
      drush_log(dt('There a currently no queues with !type jobs', array('!type' => $type)), 'error');
      return;
    }

    if (!$name && count($names) > 1) {
      if (!$name = drush_choice($names, 'Select a queue to query')) {
        return;
      }
    }
    elseif (!$name && !empty($names)) {
      $name = reset($names);
    }

    try {
      $queue->useTube($name);
      $item = $queue->{'peek' . drupal_ucfirst($type)}();
      $stats = $queue->statsJob($item);
      $rows = array();
      foreach ($stats as $key => $stat) {
        $rows[] = array(
          drupal_ucfirst(str_replace('-', ' ', $key)),
          $stat,
        );
        
        if ($key == 'id') {
          $info = beanstalkd_get_host_queues(NULL, $item->name);
          if (isset($info['description callback']) && function_exists($info['description callback'])) {
            $rows[] = array(
              'Description',
              $info['description callback']($item->data),
            );
          }
        }
      }

      drush_print_table($rows);
    }
    catch (Exception $e) {
      drush_log($e->getMessage(), 'error');
    }
  }
  else {
    drush_log(dt('!host is not a valid hostname', array('!host' => $hostname)), 'error');
  }
}

function _drush_beanstalkd_filter_type($a, $b = NULL, $init = FALSE) {
  static $queue, $type;
  
  if ($init) {
    $queue = $a;
    $type = $b;
    return;
  }
  
  try {
    $stats = $queue->statsTube($a);
    return $stats['current-jobs-' . $type] > 0 ? TRUE : FALSE;
  }
  catch (Exception $e) {
    return FALSE;
  }
}