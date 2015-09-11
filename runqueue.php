#!/usr/bin/env php
<?php
/**
 * @file
 *   CLI tool for working with queues.
 */
use Drupal\beanstalkd\Cli\Bootstrap;
use Drupal\beanstalkd\Queue\QueueBeanstalkd;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Drupal shell execution script
 */
require_once __DIR__ . '/src/Cli/Bootstrap.php';

$boot = new Bootstrap(getcwd());
$boot->parseEnvironment();
$kernel = $boot->bootstrapDrupal();
$kernel->handle($boot->request, HttpKernelInterface::MASTER_REQUEST, FALSE);

$start_memory = memory_get_usage();

beanstalkd_load_pheanstalk();

if (isset($args['c']) || isset($args['host'])) {
  $conf['beanstalkd_host'] = isset($args['c']) ? $args['c'] : $args['host'];
}

if (isset($args['p']) || isset($args['port'])) {
  $conf['beanstalkd_port'] = isset($args['p']) ? $args['p'] : $args['port'];
}

$hostname = Settings::get('beanstalkd_host', 'localhost')
  . ':' . Settings::get('beanstalkd_port', \Pheanstalk_Pheanstalk::DEFAULT_PORT);
$names = beanstalkd_get_queues($hostname);

if (isset($args['l']) || isset($args['list'])) {
  if (!empty($names)) {
    echo (t("Available beanstalkd queues:\n\n@queues\n", array('@queues' => implode("\n", $names))));
  }
  else {
    echo (t("No queues available\n"));
  }
  exit();
}

if (isset($args['q']) || isset($args['queue'])) {
  $filter_queues = explode(',', (isset($args['q']) ? $args['q'] : $args['queue']));

  $new_queues = array_intersect($names, $filter_queues);
  $missing_queues = array_diff($filter_queues, $new_queues);

  if (!empty($missing_queues)) {
    echo (t("Queues @queues are missing.\n", array('@queues' => implode(', ', $missing_queues))));
    exit();
  }
  $names = $new_queues;
}

// Make sure all the tubes are created
// Note: With Beanstalkd this doesn't do anything, as queues are created dynamically.
/** @var \Drupal\Core\Queue\QueueFactory $factory */
$factory = \Drupal::service('queue');
foreach ($names as $name) {
  $factory->get($name)->createQueue();
}

$queue = new QueueBeanstalkd(NULL);

if (empty($names)) {
  echo "Exiting: No queues available.\n";
  exit(1);
}

if (isset($args['x'])) {
  beanstalkd_log('Collecting job ' . $args['x']);
  $item = reset($queue->peek($args['x']));
  if ($item) {
    if (beanstalkd_process_item($item)) {
      $options = beanstalkd_get_queue_options($item->name);
      if ($options['forked_extra_timeout'] || $options['forked_extra_items']) {
        $queue->watch($item->name);
        $queue->ignore('default');

        beanstalkd_log(t('Processing additional items while forked on queue: @name', array('@name' => $item->name)));
        beanstalkd_process(FALSE, $options['forked_extra_timeout'], $options['forked_extra_items']);
      }
      beanstalkd_log('Item processing complete.');
      register_shutdown_function('beanstalkd_shutdown');
      exit(0);
    }
  }
  exit(1);
}

$queue->watch($names);
beanstalkd_log(t("Watching the following queues: @queues", array('@queues' => implode(", ", $names))));
$queue->ignore('default');
beanstalkd_log(t("Ignoring default queue"));

beanstalkd_process();

exit();
