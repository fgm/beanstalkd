<?php

/**
 * @file
 * Drush plugin for Beanstalkd.
 */

use Drupal\Component\Utility\Unicode;
use Drupal\beanstalkd\Queue\BeanstalkdQueue;
use Drupal\beanstalkd\Server\BeanstalkdServerFactory;
use Pheanstalk\Exception\ServerException;
use Pheanstalk\Job;
use Pheanstalk\PheanstalkInterface;
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
 * Drush callback for beanstalkd-item-stats.
 *
 * @param mixed $item_id
 *   The id of the item for which to get Beanstalkd information.
 *
 * @throws \Exception
 *   When connection cannot be established. Maybe other cases too.
 */
function drush_beanstalkd_item_stats($item_id = NULL) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');

  $alias = drush_get_option('alias', BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);

  $definition_list = $runner->getServers($alias, TRUE);
  if ($definition_list[$alias] === FALSE) {
    return;
  }

  /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
  $server = $definition_list[$alias]['server'];
  $job = new Job($item_id, NULL);
  try {
    $stats = $server->statsJob('job', $job)->getArrayCopy();
  }
  catch (ServerException $e) {
    $stats = FALSE;
  }

  $typed = $stats === FALSE ? FALSE : array_map(function ($element) {
    // All numeric item statistics in Beanstalkd are integers.
    $result = is_numeric($element) ? intval($element) : $element;
    return $result;
  }, $stats);
  $result = ['stats' => [$item_id => $typed]];
  drush_print(Yaml::dump($result, 3));
}

/**
 * Helper for drush beanstalkd-peek-* commands.
 *
 * Uses CLI options:
 * - alias
 * - tube
 *
 * @param string $type
 *   The type of item to peek at.
 */
function _drush_beanstalkd_peek($type) {
  /* @var \Drupal\beanstalkd\Runner $runner */
  $runner = \Drupal::service('beanstalkd.runner');

  $alias = drush_get_option('alias', BeanstalkdServerFactory::DEFAULT_SERVER_ALIAS);

  $definition_list = $runner->getServers($alias, TRUE);
  if ($definition_list[$alias] === FALSE) {
    return;
  }
  /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
  $server = $definition_list[$alias]['server'];

  $tube = drush_get_option('tube', NULL);
  try {
    $result = $server->peek($type, $tube);
  }
  catch (ServerException $e) {
    $result = ['job' => FALSE];
  }

  return $result;
}

/**
 * Drush callback for beanstalkd-peek-delayed.
 *
 * Known Beanstalkd bug: in some server versions, peeking at a delayed item will
 * reset its delay before scheduling, without making this visible in the item
 * stats delay field, but making it visible as "time-left" with a high value.
 * Once the delay has elapsed without a peek, the time-left returns to 0, and
 * the job will actually be provided to reserve calls.
 *
 * @param null|string $name
 *   The name of the queues on which to peek for buried items.
 *
 * @FIXME the peek commands only work on one queue, not across queues. Change
 * the function to always take a queue, or loop on the server queues.
 */
function drush_beanstalkd_peek_delayed($name = NULL) {
  $info = _drush_beanstalkd_peek('delayed');
  drush_print(Yaml::dump($info));
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param null|string $name
 *   The name of the queus on which to peek for ready items.
 *
 * @FIXME the peek commands only work on one queue, not across queues. Change
 * the function to always take a queue, or loop on the server queues.
 */
function drush_beanstalkd_peek_ready($name = NULL) {
  $info = _drush_beanstalkd_peek('ready');
  drush_print(Yaml::dump($info));
}

/**
 * Drush callback for beanstalkd-peek-ready.
 *
 * @param null|string $name
 *   The name of the queues on which to peek for buried items.
 *
 * @FIXME the peek commands only work on one queue, not across queues. Change
 * the function to always take a queue, or loop on the server queues.
 */
function drush_beanstalkd_peek_buried($name = NULL) {
  $info = _drush_beanstalkd_peek('buried');
  drush_print(Yaml::dump($info));
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
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    $server = $info['server'];
    $stats = $server->stats('global');
    $result[$name] = ($stats instanceof \ArrayObject)
      ? $stats->getArrayCopy()
      : [];
  }

  drush_print_r(Yaml::dump($result));
}

// ==== Old callbacks below ====================================================

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
    $port = drush_get_option('port', PheanstalkInterface::DEFAULT_PORT);
  }

  $hostname = $host . ':' . $port;

  $name = drush_get_option('queue', NULL);

  if (isset($queues[$hostname])) {
    $queue = new BeanstalkdQueue(NULL);
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
