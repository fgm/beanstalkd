#!/usr/bin/env php
<?php
// $Id$

/**
 * beanstalkd_get_queues().
 */
function beanstalkd_get_queues() {
  $queues = module_invoke_all('cron_queue_info');
  drupal_alter('cron_queue_info', $queues);
  
  foreach ($queues as $queue => $settings) {
    $name = 'queue_module_' . $queue;
    if (variable_get($name, 'System') != 'Beanstalkd') {
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
  
  echo $string . ($noeol ? '' : "\n");
}

function beanstalkd_process() {
  global $queue;
  
  while (1) {
    $item = $queue->claimItem();
    if (!$item) {
      beanstalkd_log(t("Waiting for next item to be claimed"));
      $item = $queue->claimItemBlocking();
    }
    if ($item) {
      $queues = beanstalkd_get_queues();

      if (isset($queues[$item->name])) {
        $info = $queues[$item->name];
        $function = $info['worker callback'];
      
        try {
          beanstalkd_log(t("Processing job @id for queue @name", array('@id' => $item->id, '@name' => $item->name)));
          $function($item->data);
      
          beanstalkd_log(t('Deleting job @id', array('@id' => $item->id)));
          $queue->deleteItem($item);
        }
        catch (Exception $e) {
          beanstalkd_log(t('Exception caught: @message', array('@message' => $e->getMessage())));
        }
      }
    }
    else {
      sleep(5); // sleep for 5 seconds and try again.
    }

    drupal_get_messages(); // Clear out the messages so they don't take up memory
    drupal_static_reset();
  }
}

/**
 * Drupal shell execution script
 */

$script = basename(array_shift($_SERVER['argv']));

$shortopts = 'hr:s:vl';
$longopts = array('help', 'root:', 'site:', 'verbose', 'list');

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
$_SERVER['PHP_SELF']        = $_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME']     = '/' . basename($_SERVER['SCRIPT_NAME']);
$_SERVER['HTTP_USER_AGENT'] = 'console';

// Starting directory
$cwd = getcwd();

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
    $_SERVER['PHP_SELF'] = $_SERVER['REQUEST_URI'] = '/index.php';
  }
  else {
    echo "ERROR: Unable to locate site {$site}\n";
    exit(1);
  }
}

define('DRUPAL_ROOT', realpath(getcwd()));

include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

// turn off the output buffering that drupal is doing by default.
ob_end_flush();

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
foreach ($names as $name) {
  DrupalQueue::get($name)->createQueue();
}

$queue = new BeanstalkdQueue(NULL);

/* foreach ($args as $arg => $option) {
  switch ($arg) {
    
  }
} */

if (empty($names)) {
  echo "Exiting: No queues available.\n";
  exit(1);
}

$queue->watch($names);
beanstalkd_log(t("Watching the following queues: @queues", array('@queues' => implode(", ", $names))));
$queue->ignore('default');
beanstalkd_log(t("Ignoring default queue"));

beanstalkd_process();

exit();
