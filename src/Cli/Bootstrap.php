<?php
/**
 * @file
 * Bootstrap.php
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd\Cli;


use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class Bootstrap {
  public $args;
  public $script;
  public $script_name;
  public $starting_wd;

  public $is_help = TRUE;
  public $is_verbose = FALSE;
  public $root = NULL;

  public $commandName = 'work';

  /**
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  public $container = NULL;

  /**
   * @var \Drupal\Core\DrupalKernelInterface
   */
  public $kernel = NULL;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  public $logger = NULL;

  /**
   * @var \Symfony\Component\HttpFoundation\Request;
   */
  public $request;

  public function __construct($starting_wd) {
    $this->script = array_shift($_SERVER['argv']);
    $this->script_name = realpath($this->script);
    $this->args = $this->getOpts();
    $this->starting_wd = realpath($starting_wd);
  }

  /**
   * Initialize and validate command-line arguments, chdir() to site root.
   */
  public function parseEnvironment() {
    $args = $this->args;
    $this->initServer();

    $this->parseVerbose($args);
    $this->parseHelp($args);
    $this->parseRoot($args);

    if (empty($this->root)) {
      if (!$this->locateRoot()) {
        throw new \DomainException("Unable to locate Drupal root, use -r option to specify path to Drupal root\n");
      }
    }

    $this->parseSite($args);
    if (empty($this->site)) {
      if (!$this->locateSite()) {
        throw new \DomainException("Unable to identify current site, use -s option to specify site name");
      }
    }

    chdir($this->root);
  }

  /**
   * Actual Drupal boot.
   */
  public function bootstrapDrupal() {
    ini_set('display_errors', 0);
    $autoloader = include_once "{$this->root}/core/vendor/autoload.php";
    include_once "{$this->root}/core/includes/bootstrap.inc";

    $this->request = Request::createFromGlobals();
    $this->kernel = DrupalKernel::createFromRequest($this->request, $autoloader, 'prod');
    $this->kernel->boot();
    $this->kernel->prepareLegacyRequest($this->request);
    $this->container = $this->kernel->getContainer();
    $this->logger = $this->container->get('logger.channel.beanstalkd_runqueue');

    ini_set('display_errors', 1);

    // turn off the output buffering that drupal is doing by default.
    ob_end_flush();
    return $this->kernel;
  }

  /**
   * Get the short and long options from runtime and return them once parsed.
   *
   * @return array
   */
  protected function getOpts() {
    $short_options = 'hr:s:vlx:c:p:q:';
    $long_options = ['help', 'root:', 'site:', 'verbose', 'list', 'host:', 'port:', 'queue:'];

    $args = @getopt($short_options, $long_options);
    return $args;
  }

  public function initServer() {
    // TODO check which of these are both needed and correct.
    // Overwrite some default settings.
    $_SERVER['HTTP_HOST']       = 'default';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['SERVER_SOFTWARE'] = 'PHP CLI';
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['QUERY_STRING']    = '';
    $_SERVER['PHP_SELF']        = $_SERVER['REQUEST_URI'] = '/index.php';
    $_SERVER['SCRIPT_NAME']     = '/' . basename($_SERVER['SCRIPT_NAME']);
    $_SERVER['HTTP_USER_AGENT'] = 'console';
  }

  /**
   * @param string $path
   *
   * @return bool
   *   Does the path look like a Drupal root folder ?
   */
  protected function isRoot($path) {
    if (!is_dir($path)) {
      return FALSE;
    }
    if (!is_readable("$path/index.php")) {
      return FALSE;
    }
    if (!is_readable("$path/core/includes/bootstrap.inc")) {
      return FALSE;
    }

    // It looks like a Drupal 8 file layout.
    return TRUE;
  }

  /**
   * Climb from starting working directory to look for a Drupal root folder.
   *
   * @return bool
   *   Did location succeed ?
   */
  protected function locateRoot() {
    $candidate = $this->starting_wd;
    $prev_path = NULL;
    while ($candidate && $prev_path != $candidate && !$this->isRoot($candidate)) {
      $prev_path = $candidate;
      $candidate = dirname($candidate);
    }

    if ($this->isRoot($candidate)) {
      $this->root = $candidate;
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  protected function locateSite() {
    // If the starting working directory is below sites/ ...
    $sites_dir_regex = '/' . preg_quote("{$this->root}/sites/", '/') . '(.*)/i';
    if (preg_match($sites_dir_regex, $this->starting_wd, $matches)) {
      // ...and is below a valid site folder, then use it.
      $candidate = $matches[1];
      $candidate_path = realpath("{$this->root}/sites/$candidate");
      if ($candidate != 'all' && is_dir($candidate_path)) {
        $this->site = $_SERVER['HTTP_HOST'] = $candidate;
        return TRUE;
      }
    }
    else {
      return FALSE;
    }
  }

  /**
   * Parse the -v|--verbose option.
   *
   * @param array $args
   *
   * @return bool
   */
  public function parseHelp(array $args) {
    $this->is_help = isset($args['h']) || isset($args['help']);
    $this->commandName = 'help';
    return $this->is_help;
  }

  /**
   * Parse the -r|--root option.
   *
   * @param array $args
   */
  public function parseRoot(array $args) {
    if (isset($args['r']) || isset($args['root'])) {
      $this->root = isset($args['r']) ? $args['r'] : $args['root'];
      if (!is_dir($this->root)) {
        throw new \InvalidArgumentException("{$this->root} not found.\n");
      }
    }
  }

  public function parseSite(array $args) {
    if (isset($args['s']) || isset($args['site'])) {
      $this->site = isset($args['s']) ? $args['s'] : $args['site'];
      if (is_dir("{$this->root}/sites/{$this->site}")) {
        $_SERVER['HTTP_HOST'] = $this->site;
      }
      else {
        throw new \InvalidArgumentException("{$this->site} not found.\n");
      }
    }
  }

  public function parseVerbose(array $args) {
    $this->is_verbose = isset($args['v']) || isset($args['verbose']);
    return $this->is_verbose;
  }
}
