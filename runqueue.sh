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

/**
 * Drupal shell execution script
 */

$script = basename(array_shift($_SERVER['argv']));

$shortopts = 'hr:s:vl';
$longopts = array('help', 'root:', 'site:', 'verbose', 'list');

$args = @getopt($shortopts, $longopts);

if (isset($args['h']) || isset($args['help'])) {
  echo <<<EOF

Execute a Drupal page from the shell.

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

  ./scripts/{$script}

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

// toggle verbose mode
$_verbose_mode = isset($args['v']) || isset($args['verbose']) ? TRUE : FALSE;

// parse invocation arguments
if (isset($args['r']) || isset($args['root'])) {
  // change working directory
  $path = isset($args['r']) ? $args['r'] : $args['root'];
  if (is_dir($path)) {
    chdir($path);
    if ($_verbose_mode) {
      echo "cwd changed to: {$path}\n";
    }
  }
  else {
    echo"\nERROR: {$path} not found.\n\n";
  }
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

// Some unavoidable errors happen because the database is not yet up-to-date.
// Our custom error handler is not yet installed, so we just suppress them.
ini_set('display_errors', TRUE);

// We prepare a minimal bootstrap for the update requirements check to avoid
// reaching the PHP memory limit.
include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

if (drupal_is_cli() && ini_get('output_buffering')) {
  flush();
  ini_set('output_buffering', 0);
}

$names = array_keys(beanstalkd_get_queues());

if (isset($args['l']) || isset($args['list'])) {
  if (!empty($names)) {
    echo t("Available beanstalkd queues:\n\n@queues\n\n", array('@queues' => implode("\n", $names)));
  }
  else {
    echo t('No queues available');
  }
  exit();
}

$queue = new BeanstalkdQueue(NULL);

foreach ($args as $arg => $option) {
  switch ($arg) {
    
  }
}

exit();
