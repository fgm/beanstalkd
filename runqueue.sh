#!/usr/bin/env php
<?php


/**
 *
 */
function beanstalkd_get_php() {
  static $php_exec;
  
  if (!isset($php_exec)) {
    if (isset($_ENV['_'])) {
      $php_exec = realpath($_ENV['_']);
    }
    else {
      exec('which php', $output, $retval);
      if (!$retval) {
        $php_exec = $output;
      }
    }
  }
  
  return $php_exec;
}

/**
 * beanstalkd_get_queues().
 */
function beanstalkd_get_queues() {
  $queues = module_invoke_all('cron_queue_info');
  drupal_alter('cron_queue_info', $queues);

  foreach ($queues as $queue => $settings) {
    $name = 'queue_module_' . $queue;
    $options = beanstalkd_get_queue_options($queue);
    if (variable_get($name, 'System') != 'Beanstalkd') {
      unset($queues[$queue]);
    }
    elseif (variable_get('beanstalkd_host', 'localhost') != $options['host']) {
      unset($queues[$queue]);
    }
    elseif (variable_get('beanstalkd_port', Pheanstalk::DEFAULT_PORT) != $options['port']) {
      unset($queues[$queue]);
    }
  }

  return $queues;
}

function beanstalkd_log($string, $noeol = FALSE) {
  global $_verbose_mode;

  if (!$_verbose_mode) {
    return;
  }

  echo format_date(time(), 'custom', 'd M Y H:i:s') . "\t" . $string . ($noeol ? '' : "\n");
}

function beanstalkd_process() {
  global $queue;

  while (1) {
    $item = $queue->claimItem(3600, 0);
    if (!$item) {
      beanstalkd_log(t("Waiting for next item to be claimed"));
      $item = $queue->claimItem(3600, NULL);
    }
    if ($item) {
      $queue_defaults = beanstalkd_get_queue_options($item->name);
      $process_function = $queue_defaults['fork'] ? 'beanstalkd_execute' : 'beanstalkd_process_item';
      
      if ($process_function($item)) {
        beanstalkd_log(t('Deleting job @id', array('@id' => $item->id)));
        $queue->deleteItem($item);
      }
    }
    else {
      sleep(5); // sleep for 5 seconds and try again.
    }

    drupal_get_messages(); // Clear out the messages so they don't take up memory
    drupal_static_reset(NULL);
  }
}

function beanstalkd_process_item($item) {
  global $queue;

  $queues = beanstalkd_get_queues();

  if (isset($queues[$item->name])) {
    $info = $queues[$item->name];
    $function = $info['worker callback'];

    try {
      beanstalkd_log(t("Processing job @id for queue @name", array('@id' => $item->id, '@name' => $item->name)));

      ini_set('display_errors', 0);
      $function($item->data);
      ini_set('display_errors', 1);

      return TRUE;
    }
    catch (Exception $e) {
      beanstalkd_log(t('Exception caught: @message', array('@message' => $e->getMessage())));
      $queue->releaseItem($item);
      return FALSE;
    }
  }
}

function beanstalkd_execute($item) {
  global $args, $script_name, $_verbose_mode;

  $cmd = beanstalkd_get_php() . ' ' . (in_array(basename($php_exec), array('php', 'PHP.EXE', 'php.exe')) ? ' -r ' . $script_name : '') . ' -r ' . realpath(getcwd()) . ' -s ' . $_SERVER['HTTP_HOST'] . ' -x ' . $item->id;

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

$shortopts = 'hr:s:vlx:c:p:';
$longopts = array('help', 'root:', 'site:', 'verbose', 'list', 'host:', 'port:');

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
  while ($path && !(file_exists($path . '/index.php') && file_exists($path . '/includes/bootstrap.inc'))) {
    $path = dirname($path);
  }

  if (!(file_exists($path . '/index.php') && file_exists($path . '/includes/bootstrap.inc'))) {
    echo "Unable to locate Drupal root, user -r option to specify path to Drupal root\n";
    exit(1);
  }
  chdir($path);
}

if (isset($args['s']) || isset($args['site'])) {
  $site = isset($args['s']) ? $args['s'] : $args['site'];
  if (file_exists('./sites/' . $site)) {
    $_SERVER['HTTP_HOST'] = $site;
  }
  else {
    echo "ERROR: Unable to locate site {$site}\n";
    exit(1);
  }
}
else if (preg_match('/' . preg_quote($path . '/sites/', '/') . '(.*?)\//i', $cwd, $matches)) {
  if ($matches[1] != 'all' && file_exists('./sites/' . $matches[1])) {
    $_SERVER['HTTP_HOST'] = $matches[1];
  }
}

define('DRUPAL_ROOT', realpath(getcwd()));

ini_set('display_errors', 0);
include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
ini_set('display_errors', 1);

// turn off the output buffering that drupal is doing by default.
ob_end_flush();

beanstalkd_load_pheanstalk();
drupal_queue_include();

if (isset($args['c']) || isset($args['host'])) {
  $conf['beanstalkd_host'] = isset($args['c']) ? $args['c'] : $args['host'];
}

if (isset($args['p']) || isset($args['port'])) {
  $conf['beanstalkd_port'] = isset($args['p']) ? $args['p'] : $args['port'];
}

$names = array_keys(beanstalkd_get_queues());

if (isset($args['l']) || isset($args['list'])) {
  if (!empty($names)) {
    echo (t("Available beanstalkd queues:\n\n@queues\n", array('@queues' => implode("\n", $names))));
  }
  else {
    echo (t('No queues available'));
  }
  exit();
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
  $item = $queue->peekItem($args['x']);
  if ($item) {
    if (beanstalkd_process_item($item)) {
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
