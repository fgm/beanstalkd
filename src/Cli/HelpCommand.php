<?php
/**
 * @file
 * HelpCommand.php
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd\Cli;


class HelpCommand extends CommandBase {

  public function __invoke() {
    echo <<<EOF

Beanstalkd Queue manager.

Usage:        {$this->script} [OPTIONS]
Example:      {$this->script}

All arguments are long options.

  -h, --help  This page.

  -r, --root  Set the working directory for the script to the specified path.
              To execute Drupal this has to be the root directory of your
              Drupal installation, f.e. /home/www/foo/drupal (assuming Drupal
              running on Unix). Current directory is not required.
              Use surrounding quotation marks on Windows.

  -s, --site  Used to specify with site will be used for the upgrade. If no
              site is selected, then site for current directory will be used.

  -l, --list  List available beanstalkd queues

  -c, --host  Specify host of the beanstalkd server.

  -p, --port  Specify port of the beanstalkd server.

  -q , --queue Specify a comma separated list of queues to watch.

  -v, --verbose This option displays the options as they are set, but will
              produce errors from setting the session.

To run this script without --root argument invoke it from the root directory
of your Drupal installation with

  {$this->script}

\n
EOF;
  }

}
