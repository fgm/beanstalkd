<?php

/**
 * @file
 * Drush plugin for Beanstalkd.
 */

use Drupal\Component\Utility\Unicode;
use Drupal\beanstalkd\Queue\BeanstalkdQueue;
use Symfony\Component\Yaml\Yaml;

/**
 * Implements hook_drush_command().
 */
function beanstalkd_drush_command() {
  $file = preg_replace('/(inc|php)$/', 'yml', __FILE__);
  $config = Yaml::parse(file_get_contents($file));
  $items = $config['commands'];
  return $items;
}

/**
 * Drush callback for 'beanstalkd-drupal-queues'.
 */
function drush_beanstalkd_drupal_queues() {
  /* @var \Drupal\beanstalkd\WorkerManager $manager */
  $manager = \Drupal::service('beanstalkd.worker_manager');

  $all = !!drush_get_option('all');

  $queues = $all
    ? array_keys($manager->getWorkers())
    : $manager->getBeanstalkdQueues();

  drush_print(Yaml::dump($queues));
}

/**
 * Drush callback for beanstalkd-tube-stats.
 *
 * @param null|string $name
 *   The name of the Beanstalkd queue for which to get Beanstalkd information.
 */
function drush_beanstalkd_queue_stats($name = NULL) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');

  $queue_servers = $runner->getQueues($name);

  $result = [];
  /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
  foreach ($queue_servers as $name => $server) {
    $stats = $server->stats('tube', $name);
    $stats = empty($stats) ? [] : $stats->getArrayCopy();
    $result[$name] = $stats;
  }
  drush_print(Yaml::dump($result));
}

/**
 * Drush callback for 'beanstalkd-run-server'.
 *
 * @param null|string $alias
 *   The alias for the server from which to fetch jobs.
 */
function drush_beanstalkd_run_server($alias = NULL) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');
  $runner->runServer($alias);
}

/**
 * Drush callback for 'beanstalkd-servers'.
 */
function drush_beanstalkd_servers() {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');
  $servers = $runner->getServers(NULL, FALSE);
  drush_print(Yaml::dump($servers));
}

/**
 * Drush callback for beanstalkd-server-queues.
 *
 * @param null|string $alias
 *   A server alias.
 */
function drush_beanstalkd_server_queues($alias = NULL) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');
  $servers = $runner->getServers($alias, TRUE);

  $names = [];
  foreach ($servers as $alias => $info) {
    /** @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    $server = $info['server'];
    $names[$alias] = $server->listTubes();
  }

  drush_print(Yaml::dump($names));
}

/**
 * Drush callback for beanstalkd-server-stats.
 *
 * @param null|string $alias
 *   The Beanstalkd host alias.
 */
function drush_beanstalkd_server_stats($alias = NULL) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');
  $servers = $runner->getServers($alias, TRUE);

  $result = [];
  foreach ($servers as $name => $info) {
    $stats = $info['server']->stats('global');
    $result[$name] = ($stats instanceof \ArrayObject)
      ? $stats->getArrayCopy()
      : [];
  }

  drush_print_r(Yaml::dump($result));
}

// ==== Old callbacks below ====================================================
/**
 * Drush callback for beanstalkd-item-stats.
 *
 * @param mixed $item_id
 *   The item for which to get Beanstalkd information.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too.
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
      $queue = new BeanstalkdQueue('default');
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
 * @param null|string $name
 *   The name of the queues on which to peek for ready items.
 */
function drush_beanstalkd_peek_ready($name = NULL) {
  drush_beanstalkd_peek_items('ready', $name);
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param null|string $name
 *   The name of the queues on which to peek for buried items.
 */
function drush_beanstalkd_peek_buried($name = NULL) {
  drush_beanstalkd_peek_items('buried', $name);
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param null|string $name
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
 *   When connection cannot be established. Maybe other cases too.
 */
function drush_beanstalkd_peek_items($type, $name) {
  beanstalkd_load_pheanstalk();
  $queues = beanstalkd_get_host_queues();

  if ($name_option = drush_get_option('queue', NULL)) {
    $name = $name_option;
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
 * @param string $name
 *   A queue name.
 * @param string|null $type_filter
 *   Unused.
 * @param bool $init
 *   - NULL if $init,
 *   - TRUE if queue is available and contains at least one item
 *   - FALSE otherwise.
 *
 * @return bool|null
 *   As per array_filter().
 */
function _drush_beanstalkd_filter_type($name, $type_filter = NULL, $init = FALSE) {
  static $queue, $type;

  if ($init) {
    $queue = $name;
    $type = $type_filter;
    return NULL;
  }

  try {
    $stats = $queue->statsTube($name);
    return $stats['current-jobs-' . $type] > 0 ? TRUE : FALSE;
  }
  catch (\Exception $e) {
    return FALSE;
  }
}

/**
 * Drush callback for beanstalkd-kick.
 *
 * @param int|null|string[] $items
 *   An array of item ids to kick, or a single item id, or NULL for all items.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too.
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
