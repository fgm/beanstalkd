<?php

/**
 * @file
 * Drush plugin for Beanstalkd.
 */

use Drupal\beanstalkd\Queue\QueueBeanstalkd;
use Drupal\Component\Utility\Unicode;

/**
 * Implements hook_drush_command().
 */
function beanstalkd_drush_command() {
  $items = array();
  // ---- New-style commands ---------------------------------------------------
  $items['beanstalkd-drupal-queues'] = [
    'descriptions' => 'List configured queue settings',
    'aliases' => ['btdq'],
    'options' => [
      'all' => 'Also list workers not configured for Beanstalkd handling.',
    ],
  ];

  // ---- Old commands below ---------------------------------------------------
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
  $items['beanstalkd-kick'] = array(
    'arguments' => array(
      '' => 'number of items to kick to allow them to be reprocessed.',
    ),
    'callback' => 'drush_beanstalkd_kick',
    'description' => 'Kick n items so that they will be reprocessed',
    'options' => array(
      'host' => 'Specify the host of the beanstalkd server',
      'port' => 'Specify the port of the beanstalkd server',
      'queue' => 'Specify the queue to kick the items on.',
    ),
    'aliases' => array('kick'),
  );
  return $items;
}

/**
 * Drush callback for beanstalkd-drupal_queues.
 */
function drush_beanstalkd_drupal_queues() {
  /* @var \Drupal\beanstalkd\WorkerManager $manager */
  $manager = \Drupal::service('beanstalkd.worker_manager');

  $all = !!drush_get_option('all');

  $queues = $all
    ? array_keys($manager->getWorkers())
    : $manager->getBeanstalkdQueues();

  drush_print_r($queues);
}

// ==== Old callbacks below ====================================================
/**
 * Drush callback for beanstalkd-servers.
 */
function drush_beanstalkd_servers() {
  // beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  drush_print('Available beanstalkd servers:');
  drush_print("\n" . implode("\n", array_keys($queues)));
}

/**
 * Drush callback for beanstalkd-server-stats.
 *
 * @param string $host
 *   The Beanstalkd host.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_server_stats($host = NULL) {
  beanstalkd_load_pheanstalk();

  $queues = beanstalkd_get_host_queues();

  if ($host) {
    $host_info = parse_url($host) + array('port' => \Pheanstalk_PheanstalkInterface::DEFAULT_PORT);
    if (!isset($host_info['host']) && isset($host_info['path'])) {
      $host_info['host'] = $host_info['path'];
      unset($host_info['path']);
    }
    $host = $host_info['host'] . ':' . $host_info['port'];
  }

  $queue_ids = array_keys($queues);
  if (count($queues) > 1) {
    $options = array_combine($queue_ids, $queue_ids);
    $host = drush_choice($options, 'Select a host to query');
  }
  elseif (!$host) {
    $host = reset($queue_ids);
  }
  unset($queue_ids);

  if ($host && isset($queues[$host])) {
    $host_info = parse_url($host);

    $queue = new QueueBeanstalkd(NULL);
    $queue->createConnection($host_info['host'], $host_info['port']);

    $stats = $queue->stats();

    $rows = array();
    foreach ($stats as $key => $stat) {
      $rows[] = array(
        Unicode::ucfirst(str_replace('-', ' ', $key)),
        $stat,
      );
    }

    drush_print_table($rows);
  }
  elseif ($host) {
    drush_log(dt('Invalid server !server', array('!server' => $host)), 'error');
  }
}

/**
 * Drush callback for beanstalkd-queue-list.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_queue_list() {
  beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  $names = array();
  foreach ($queues as $hostname => $settings) {
    $names = array_merge($names, array_keys($settings));
  }

  drush_print('Available beanstalkd queues:');
  drush_print("\n" . implode("\n", $names));
}

/**
 * Drush callback for beanstalkd-queue-stats.
 *
 * @param string $name
 *   The name of the Beanstalkd queue for which to get Beanstalks information.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_queue_stats($name = NULL) {
  beanstalkd_load_pheanstalk();

  $queues = beanstalkd_get_queues();
  $names = array_combine($queues, $queues);

  if (!$name) {
    $name = drush_choice($names, 'Select a queue to query');
  }

  if ($name && isset($names[$name])) {
    $queue = new QueueBeanstalkd($name);
    $stats = $queue->statsTube($name);

    $rows = array();
    foreach ($stats as $key => $stat) {
      $rows[] = array(
        Unicode::ucfirst(str_replace('-', ' ', $key)),
        $stat,
      );
    }

    drush_print_table($rows);
  }
}

/**
 * Drush callback for beanstalkd-item-stats.
 *
 * @param mixed $item_id
 *   The item for which to get Beanstalkd information.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_item_stats($item_id = NULL) {
  beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  if ($name = drush_get_option('queue', NULL)) {
    $info = beanstalkd_get_host_queues(NULL, $name);
    $host = $info['options']['host'];
    $port = $info['options']['port'];
  }
  else {
    $host = drush_get_option('host', 'localhost');
    $port = drush_get_option('port', \Pheanstalk_PheanstalkInterface::DEFAULT_PORT);
  }

  $hostname = $host . ':' . $port;

  if (isset($queues[$hostname])) {
    if ($item_id) {
      $queue = new QueueBeanstalkd(NULL);
      $queue->createConnection($host, $port);

      try {
        $item = $queue->peek($item_id);
        $stats = $queue->statsJob($item);
        $rows = array();
        foreach ($stats as $key => $stat) {
          $rows[] = array(
            Unicode::ucfirst(str_replace('-', ' ', $key)),
            $stat,
          );
        }

        drush_print_table($rows);
      }
      catch (\Exception $e) {
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

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param string $name
 *   The name of the queues on which to peek for ready items.
 */
function drush_beanstalkd_peek_ready($name = NULL) {
  drush_beanstalkd_peek_items('ready', $name);
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param string $name
 *   The name of the queues on which to peek for buried items.
 */
function drush_beanstalkd_peek_buried($name = NULL) {
  drush_beanstalkd_peek_items('buried', $name);
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param string $name
 *   The name of the queues on which to peek for buried items.
 */
function drush_beanstalkd_peek_delayed($name = NULL) {
  drush_beanstalkd_peek_items('delayed', $name);
}

/**
 * FIXME probably incorrect logic: $name argument is overwritten in both cases.
 *
 * @param string $type
 *   The type of items to peek at.
 * @param string $name
 *   The name of the queue in which to peek.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_peek_items($type, $name) {
  beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  if ($name = drush_get_option('queue', NULL)) {
    $info = beanstalkd_get_host_queues(NULL, $name);
    $host = $info['options']['host'];
    $port = $info['options']['port'];
  }
  else {
    $host = drush_get_option('host', 'localhost');
    $port = drush_get_option('port', \Pheanstalk_PheanstalkInterface::DEFAULT_PORT);
  }

  $hostname = $host . ':' . $port;

  if (isset($queues[$hostname])) {
    $queue = new QueueBeanstalkd(NULL);
    $queue->createConnection($host, $port);

    $queues = beanstalkd_get_queues($hostname);
    $names = array_combine($queues, $queues);
    _drush_beanstalkd_filter_type($queue, $type, TRUE);
    $names = array_filter($names, '_drush_beanstalkd_filter_type');

    if (empty($names)) {
      drush_log(dt('There is currently no queues with !type jobs', array('!type' => $type)), 'error');
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
      $item = $queue->{'peek' . Unicode::ucfirst($type)}();
      $stats = $queue->statsJob($item);
      $rows = array();
      foreach ($stats as $key => $stat) {
        $rows[] = array(
          Unicode::ucfirst(str_replace('-', ' ', $key)),
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
    catch (\Exception $e) {
      drush_log($e->getMessage(), 'error');
    }
  }
  else {
    drush_log(dt('!host is not a valid hostname', array('!host' => $hostname)), 'error');
  }
}

/**
 * Callback for array_filter() in drush_beanstalkd_{kick|_peek_items}().
 *
 * @param string $a
 *   A queue name.
 * @param mixed $b
 *   Unused.
 * @param bool $init
 *   - NULL if $init,
 *   - TRUE if queue is available and contains at least one item
 *   - FALSE otherwise.
 *
 * @return bool|null
 *   As per array_filter().
 */
function _drush_beanstalkd_filter_type($a, $b = NULL, $init = FALSE) {
  static $queue, $type;

  if ($init) {
    $queue = $a;
    $type = $b;
    return NULL;
  }

  try {
    $stats = $queue->statsTube($a);
    return $stats['current-jobs-' . $type] > 0 ? TRUE : FALSE;
  }
  catch (\Exception $e) {
    return FALSE;
  }
}

/**
 * Drush callback for beanstalkd-kick.
 *
 * @param array|int $items
 *   An array of item ids to kick, or a single item id.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too ?
 */
function drush_beanstalkd_kick($items = NULL) {
  if (!is_numeric($items)) {
    drush_log(dt('@items is not a numeric value', array('@items' => $items)), 'error');
    return;
  }
  elseif (!$items) {
    drush_log(dt('@items needed to be a valid number greater than 0', array('@items' => $items)), 'error');
    return;
  }

  beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  if ($name = drush_get_option('queue', NULL)) {
    $info = beanstalkd_get_host_queues(NULL, $name);
    $host = $info['options']['host'];
    $port = $info['options']['port'];
  }
  else {
    $host = drush_get_option('host', 'localhost');
    $port = drush_get_option('port', \Pheanstalk_PheanstalkInterface::DEFAULT_PORT);
  }

  $hostname = $host . ':' . $port;

  $name = drush_get_option('queue', NULL);

  if (isset($queues[$hostname])) {
    $queue = new QueueBeanstalkd(NULL);
    $queue->createConnection($host, $port);

    $queues = beanstalkd_get_queues($hostname);
    $names = array_combine($queues, $queues);
    _drush_beanstalkd_filter_type($queue, 'buried', TRUE);
    $names = array_filter($names, '_drush_beanstalkd_filter_type');

    if (!$name) {
      if (empty($names)) {
        drush_log(dt('There is currently no queues with buried jobs'), 'error');
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
    }
    elseif (in_array($name, $names) === FALSE) {
      drush_log(dt('There are currently buried items on queue @name', array('@name' => $name)), 'error');
      return;
    }

    $queue->useTube($name);
    $items_kicked = $queue->kick($items);

    drush_log(\Drupal::translation()->formatPlural($items_kicked, '@count item kicked', '@count items kicked'), 'info');
  }
}
