<?php

/**
 * @file
 * Contains BeanstalkdServerFactory.
 */

namespace Drupal\beanstalkd\Server;

use Drupal\Core\Site\Settings;
use Pheanstalk\Connection;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;

/**
 * Class BeanstalkdServerFactory makes BeanstalkServer instances.
 */
class BeanstalkdServerFactory {
  const DEFAULT_SERVER_ALIAS = 'default';

  // As luck has it.
  const DEFAULT_QUEUE_NAME = PheanstalkInterface::DEFAULT_TUBE;

  /**
   * The definitions of all configured servers in Settings.
   *
   * @var array
   */
  protected $servers = [];

  /**
   * An alias to instance hash of BeanstalkdServer objects.
   *
   * @var \Drupal\beanstalkd\Server\BeanstalkdServer[]
   */
  protected $instances = [];

  /**
   * The queue-server mapping configured in Settings.
   *
   * @var array
   */
  protected $mappings = [];

  /**
   * Constant: default [queue -> server alias] mappings.
   *
   * This is a static function because array constants are not supported in
   * PHP5.5, and in 2015 some sites need the module to work on 5.5.
   *
   * @XXX Revisit at some point after Drupal 8.1.0.
   */
  public static function defaultQueueMappings() {
    $result = [
      self::DEFAULT_QUEUE_NAME => self::DEFAULT_SERVER_ALIAS,
    ];
    return $result;
  }

  /**
   * Constant: default server parameters.
   *
   * This is a static function because array constants are not supported in
   * PHP5.5, and in 2015 some sites need the module to work on 5.5.
   *
   * @XXX Revisit at some point after Drupal 8.1.0.
   */
  public static function defaultServerParameters() {
    $result = [
      'host' => 'localhost',
      'port' => PheanstalkInterface::DEFAULT_PORT,
      'connect_timeout' => Connection::DEFAULT_CONNECT_TIMEOUT,
      'persistent' => FALSE,
    ];
    return $result;
  }

  /**
   * Constant: default server alias list.
   *
   * This is a static function because array constants are not supported in
   * PHP5.5, and in 2015 some sites need the module to work on 5.5.
   *
   * @XXX Revisit at some point after Drupal 8.1.0.
   */
  public static function defaultServers() {
    $result = [
      self::DEFAULT_SERVER_ALIAS => self::defaultServerParameters(),
    ];
    return $result;
  }

  /**
   * Constant: default module settings.
   *
   * This is a static function because array constants are not supported in
   * PHP5.5, and in 2015 some sites need the module to work on 5.5.
   *
   * @XXX Revisit at some point after Drupal 8.1.0.
   */
  public static function defaultSettings() {
    $result = self::defaultServers() + self::defaultQueueMappings();
    return $result;
  }

  /**
   * Constructor: provides sane defaults from settings.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The core Settings service.
   */
  public function __construct(Settings $settings) {
    $module_settings = $settings->get('beanstalkd', static::defaultSettings());

    $servers = isset($module_settings['servers']) ? $module_settings['servers'] : [];
    $this->initServers($servers);

    $mappings = isset($module_settings['mappings']) ? $module_settings['mappings'] : [];
    $this->initMappings($mappings);
  }

  /**
   * Return the information for a server from its alias.
   *
   * @param string $alias
   *   A server alias.
   *
   * @return mixed
   *   The definition for a server. If an alias has no specific definition, the
   *   definition returned will be the one for the default server.
   */
  protected function getServerDefinition($alias) {
    if (!isset($this->servers[$alias])) {
      $alias = static::DEFAULT_SERVER_ALIAS;
    }

    $result = $this->servers[$alias];
    return $result;
  }

  /**
   * Return the server definitions, as fixed during initialization.
   *
   * @return array
   *   The server definitions, keyed by alias.
   */
  public function getServerDefinitions() {
    return $this->servers;
  }

  /**
   * Initialize the server information from settings.
   *
   * @param array $servers
   *   A possibly incomplete array of server settings.
   *
   * @TODO add unit tests for the various missing values cases.
   */
  protected function initServers(array $servers) {
    foreach ($servers as &$server_parameters) {
      $server_parameters += static::defaultServerParameters();
    }
    $servers = array_replace_recursive(static::defaultServers(), $servers);
    $this->servers = $servers;
  }

  /**
   * Initialize the queue-server mappings from settings.
   *
   * @param array $mappings
   *   A queue name to server alias hash.
   */
  protected function initMappings(array $mappings) {
    $mappings += static::defaultQueueMappings();
    $this->mappings = $mappings;
  }

  /**
   * Return the BeanstalkServer instance for a server alias.
   *
   * @param string $alias
   *   The alias for the server.
   *
   * @return \Drupal\beanstalkd\Server\BeanstalkdServer
   *   A server instance. It will be created if the factory does not hold it
   *   already, or reused otherwise.
   *
   * @TODO deduplicate server instances: reuse the same server where applicable.
   */
  public function get($alias) {
    if (!isset($this->instances[$alias])) {
      $parameters = $this->getServerDefinition($alias);
      $pheanstalk = new Pheanstalk($parameters['host'], $parameters['port'],
        $parameters['connect_timeout'], $parameters['persistent']);
      $this->instances[$alias] = new BeanstalkdServer($pheanstalk);
    }

    return $this->instances[$alias];
  }

  /**
   * Return the alias of the server mapped to a queue.
   *
   * @param string $name
   *   The queue name.
   *
   * @return string
   *   The server alias.
   */
  protected function getQueueMapping($name) {
    $alias = isset($this->mappings[$name])
      ? $this->mappings[$name]
      : static::DEFAULT_SERVER_ALIAS;

    return $alias;
  }

  /**
   * Return queue mapping.
   *
   * @return array
   *   A hash of server aliases by queue names.
   */
  public function getQueueMappings() {
    return $this->mappings;
  }

  /**
   * Return the BeanstalkServer instance for a queue name.
   *
   * This is the method most likely to be useful, because it is mapping-aware.
   *
   * @param string $name
   *   The name of the queue.
   *
   * @return \Drupal\beanstalkd\Server\BeanstalkdServer
   *   The mapped server instance for that queue.
   */
  public function getQueueServer($name) {
    $alias = $this->getQueueMapping($name);
    $server = $this->get($alias);
    $server->addTube($name);
    return $server;
  }

}
