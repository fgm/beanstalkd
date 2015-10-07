<?php

/**
 * @file
 * Contains BeanstalkdServer.
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd\Server;

use Pheanstalk\PheanstalkInterface;

/**
 * Class BeanstalkdServer wraps a Pheanstalk facade and Drupal queue names.
 */
class BeanstalkdServer {
  /**
   * The wrapped Pheanstalk.
   *
   * @var \Pheanstalk\PheanstalkInterface
   */
  protected $driver;

  /**
   * The Drupal queue names this server is designated to serve.
   *
   * @var array
   */
  protected $queueNames;

  /**
   * Constructor.
   *
   * @param \Pheanstalk\PheanstalkInterface $driver
   *   The wrapped Pheanstalk.
   * @param string[] $queue_names
   *   An array of the names of the Drupal queues assigned to this server.
   */
  public function __construct(PheanstalkInterface $driver, array $queue_names = []) {
    $this->driver = $driver;
    $this->queueNames = array_combine($queue_names, $queue_names);
  }

  /**
   * Add a Drupal queue to this server.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function addQueue($name) {
    $this->queueNames[$name] = $name;
  }

  /**
   * Remove a Drupal queue from this server.
   *
   * @param string $name
   *   The name of a Drupal queue.
   */
  public function removeQueue($name) {
    unset($this->queueNames[$name]);
  }

}
