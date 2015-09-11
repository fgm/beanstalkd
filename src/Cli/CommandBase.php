<?php
/**
 * @file
 * CommandBase.php
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd\Cli;


class CommandBase {
  protected $boot;

  public function __construct(Bootstrap $boot) {
    $this->boot = $boot;
  }

}
