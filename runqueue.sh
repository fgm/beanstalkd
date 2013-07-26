#!/usr/bin/env php
<?php

/**
 *
 */
function beanstalkd_get_php() {
  static $php_exec;
  
  if (!isset($php_exec)) {
    if (isset($_ENV['_'])) {
      $php_exec = $_ENV['_'];
    }
    elseif (isset($_SERVER['_'])) {
      $php_exec = $_SERVER['_'];
    }
    else {
      exec('which php', $output, $retval);
      if (!$retval) {
        $php_exec = reset($output);
      }
    }
  }
  
  return $php_exec;
}

function beanstalkd_log($string, $noeol = FALSE) {
  global $_verbose_mode;

  if (!$_verbose_mode) {
    return;
  }

  echo format_date(time(), 'custom', 'd M Y H:i:s') . "\t" . $string . ($noeol ? '' : "\n");
}

function beanstalkd_process($allow_forking = TRUE, $process_time = FALSE, $process_items = FALSE) {
  global $queue, $start_memory;
  
  $start_time = time();
  $process_count = 0;

  while (1) {
    $items = $queue->reserve(0);
    $item = reset($items);
    
    if (!$item) {
      if ($process_time === FALSE && $process_items === FALSE) {
        beanstalkd_log(t("Waiting for next item to be claimed"));
        $items = $queue->reserve(NULL);
        $item = reset($items);
      }
      else {
        // There are no more items, and as we have limits we just want to return.
        return;
      }
    }
    if ($item) {
      $process_count++;
      $queue_defaults = beanstalkd_get_queue_options($item->name);
      $process_function = ($allow_forking && $queue_defaults['fork']) ? 'beanstalkd_execute' : 'beanstalkd_process_item';
      $_SERVER['REQUEST_TIME'] = time();

      if ($process_function($item)) {
        beanstalkd_log(t('Deleting job @id', array('@id' => $item->id)));
        
        // This should never happen but sometimes it does.
        try {
          $queue->delete($item);
        }
        catch (Exception $e) {
          NULL;
        }
      }
    }
    else {
      if ($process_time === FALSE && $process_items === FALSE) {
        sleep(5); // sleep for 5 seconds and try again.
      }
      else {
        // There are no more items, and as we have limits we just want to return.
        return;
      }
    }

    drupal_get_messages(); // Clear out the messages so they don't take up memory
    drupal_static_reset(NULL);
    beanstalkd_log(t('Total Memory Used: @memory, @bootstrap since bootstrap', array('@memory' => format_size(memory_get_usage()), '@bootstrap' => format_size(memory_get_usage() - $start_memory))));
    
    // Check to see if the limits have been exceeded and return.
    if ($process_time && $start_time+$process_time < time()) {
      beanstalkd_log(t('Processing time limit of @seconds seconds exceeded.', array('@seconds' => $process_time)));
      return;
    }
    if ($process_items && $process_items < $process_count) {
      beanstalkd_log(t('Processing limit of @items jobs exceeded.', array('@items' => $process_items)));
      return;
    }
  }
}

function beanstalkd_process_item($item) {
  global $queue;

  $info = beanstalkd_get_host_queues(NULL, $item->name);

  if (!empty($info)) {
    $function = $info['worker callback'];

    try {
      beanstalkd_log(t("Processing job @id for queue @name", array('@id' => $item->id, '@name' => $item->name)));
      if (isset($info['description callback']) && function_exists($info['description callback'])) {
        beanstalkd_log($info['description callback']($item->data));
      }

      ini_set('display_errors', 0);
      timer_start('beanstalkd_process_item');
      $function($item->data);
      $timer = timer_read('beanstalkd_process_item');
      ini_set('display_errors', 1);
      
      watchdog('beanstalkd', 'Processed job @id for queue @name taking @timerms<br />@description',  array('@id' => $item->id, '@name' => $item->name, '@timer' => $timer, '@description' => (isset($info['description callback']) && function_exists($info['description callback']) ? $info['description callback']($item->data) : '')), WATCHDOG_NOTICE);

      return TRUE;
    }
    catch (Exception $e) {            
      beanstalkd_log(t("Exception caught: @message in @file on line @line.\n@trace", array('@message' => $e->getMessage(), '@file' => $e->getFile(), '@line' => $e->getLine(), '@trace' => $e->getTraceAsString())));
      watchdog('beanstalkd', 'Job @id - @name: Exception caught: @message in @file on line @line.<br/><pre>@trace</pre>', array('@id' => $item->id, '@name' => $item->name, '@message' => $e->getMessage(), '@file' => $e->getFile(), '@line' => $e->getLine(), '@trace' => $e->getTraceAsString()), WATCHDOG_ERROR);
      $stats = $queue->statsJob($item);
      $queue_defaults = beanstalkd_get_queue_options($item->name);
      if ($stats['releases'] < $queue_defaults['retries']) {
        $queue->release($item, $queue_defaults['priority'], $queue_defaults['release_delay']);
      }
      else {
        $queue->bury($item);
      }
      return FALSE;
    }
  }
}

function beanstalkd_execute($item) {
  global $args, $script_name, $_verbose_mode, $hostname;

  $parts = parse_url($hostname);
  $php_exec = beanstalkd_get_php();

  $cmd = escapeshellarg($php_exec) . ' ' . (in_array(basename($php_exec), array('php', 'PHP.EXE', 'php.exe')) ? $script_name : '') . ' -r ' . escapeshellarg(realpath(getcwd())) . ' -s ' . $_SERVER['HTTP_HOST'] . ' -x ' . $item->id . ' -c ' . $parts['host'] . ' -p ' . $parts['port'];

  if ($_verbose_mode) {
    $cmd .= ' -v';
  }

  beanstalkd_log('Executing: ' . $cmd);
  passthru($cmd, $retval);

  beanstalkd_log('Return Val: ' . $retval);

  return $retval == 0;
}

function beanstalkd_shutdown() {
  beanstalkd_log('Shutdown complete.');
}

/**
 * Drupal shell execution script
 */

$script = basename(array_shift($_SERVER['argv']));
$script_name = realpath($script);

$shortopts = 'hr:s:vlx:c:p:q:';
$longopts = array('help', 'root:', 'site:', 'verbose', 'list', 'host:', 'port:', 'queue:');

$args = @getopt($shortopts, $longopts);

if (isset($args['h']) || isset($args['help'])) {
  echo <<<EOF

Beanstalkd Queue manager.

Usage:        {$script} [OPTIONS]
Example:      {$script} 

All arguments are long options.

  -h, --help  This page.

  -r, --root  Set the working directory for the script to the specified path.
              To execute Drupal this has to be the root directory of your
              Drupal installation, f.e. /home/www/foo/drupal (assuming Drupal
              running on Unix). Current directory is not required.
              Use surrounding quotation marks on Windows.

  -s, --site  Used to specify with site will be used for the upgrade. If no
              site is selected then default will be used.

  -l, --list  List available beanstalkd queues

  -c, --host  Specify host of the beanstalkd server.

  -p, --port  Specify port of the beanstalkd server.
  
  -q , --queue Specify a comma specated list of queues to watch.

  -v, --verbose This option displays the options as they are set, but will
              produce errors from setting the session.

To run this script without --root argument invoke it from the root directory
of your Drupal installation with

  ./{$script}

\n
EOF;
  if (version_compare(phpversion(), '5.3.0', 'le')) {
    echo "Warning: This version of PHP doesn't support long options\n";
  }
  exit;
}

// define default settings
$_SERVER['HTTP_HOST']       = 'default';
$_SERVER['PHP_SELF']        = '/update.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_SOFTWARE'] = 'PHP CLI';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['QUERY_STRING']    = '';
$_SERVER['PHP_SELF']        = $_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME']     = '/' . basename($_SERVER['SCRIPT_NAME']);
$_SERVER['HTTP_USER_AGENT'] = 'console';

// Starting directory
$cwd = realpath(getcwd());
beanstalkd_get_php();

// toggle verbose mode
$_verbose_mode = isset($args['v']) || isset($args['verbose']) ? TRUE : FALSE;

// parse invocation arguments
if (isset($args['r']) || isset($args['root'])) {
  // change working directory
  $path = isset($args['r']) ? $args['r'] : $args['root'];
  if (is_dir($path)) {
    chdir($path);
  }
  else {
    echo "\nERROR: {$path} not found.\n\n";
    exit(1);
  }
}
else {
  $path = $cwd;
  $prev_path = NULL;
  while ($path && $prev_path != $path && !(file_exists($path . '/index.php') && file_exists($path . '/includes/bootstrap.inc'))) {
    $prev_path = $path;
    $path = dirname($path);
  }

  if (!(file_exists($path . '/index.php') && file_exists($path . '/includes/bootstrap.inc'))) {
    echo "Unable to locate Drupal root, user -r option to specify path to Drupal root\n";
    exit(1);
  }
  chdir($path);
}

define('DRUPAL_ROOT', realpath(getcwd()));

if (isset($args['s']) || isset($args['site'])) {
  $site = isset($args['s']) ? $args['s'] : $args['site'];
  if (file_exists(realpath(DRUPAL_ROOT . '/sites/' . $site))) {
    $_SERVER['HTTP_HOST'] = $site;
  }
  else {
    echo "ERROR: Unable to locate site {$site}\n";
    exit(1);
  }
}
else if (preg_match('/' . preg_quote($path . '/sites/', '/') . '(.*?)\//i', $cwd, $matches)) {
  if ($matches[1] != 'all' && file_exists(realpath(DRUPAL_ROOT . '/sites/' . $matches[1]))) {
    $_SERVER['HTTP_HOST'] = $matches[1];
  }
}

ini_set('display_errors', 0);
include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
ini_set('display_errors', 1);

// turn off the output buffering that drupal is doing by default.
ob_end_flush();

$start_memory = memory_get_usage();

beanstalkd_load_pheanstalk();

if (isset($args['c']) || isset($args['host'])) {
  $conf['beanstalkd_host'] = isset($args['c']) ? $args['c'] : $args['host'];
}

if (isset($args['p']) || isset($args['port'])) {
  $conf['beanstalkd_port'] = isset($args['p']) ? $args['p'] : $args['port'];
}

$hostname = variable_get('beanstalkd_host', 'localhost') . ':' . variable_get('beanstalkd_port', Pheanstalk::DEFAULT_PORT);
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
foreach ($names as $name) {
  DrupalQueue::get($name)->createQueue();
}

$queue = new BeanstalkdQueue(NULL);

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
