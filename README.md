
Beanstalkd is a Drupal module to allow Drupal Queues to take advantage of 
beanstalkd to process the queues instead of the built-in Database queue system 
that ships with Drupal.

[![Build Status](https://travis-ci.org/FGM/beanstalkd.svg?branch=wip)](https://travis-ci.org/FGM/beanstalkd)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/FGM/beanstalkd/badges/quality-score.png?b=8x-worker)](https://scrutinizer-ci.com/g/FGM/beanstalkd/?branch=8x-worker)
[![Code Coverage](https://scrutinizer-ci.com/g/FGM/beanstalkd/badges/coverage.png?b=wip)](https://scrutinizer-ci.com/g/FGM/beanstalkd/?branch=wip)

What is Beanstalkd
------------------

Beanstalk is a simple, fast work queue service. Its interface is generic, but 
was originally designed for reducing the latency of page views in high-volume 
web applications by running time-consuming tasks asynchronously.


Requirements
------------

  * A beanstalkd server needs to be installed and configured.
  * Drupal 8.0.0-RC2 or more recent must be configured with Pheanstalk 3.x:  
    - edit `(yoursite)/composer.json` (not `(yoursite)/core/composer.json`)
    - insert: `"pda/pheanstalk": "^3.1"` in the `require` section and save.
    - update your vendors by typing `composer update` at the site root.


Installation
------------

  1. Install like a normal Drupal module. Do _not_ run `composer install` in the module directory: although there is a `composer.json` file in the project, it is only here to inform Scrutinizer CI about dependencies.
  2. Once your site is installed, edit your `settings.php`, setting the `$settings` variables appropriately:
      * If you want to set beanstalkd as the default queue manager then add the following to your settings.

            $settings['queue_default'] = 'queue.beanstalkd';

      * Alternatively you can also set for each queue to use beanstalkd using one of these formats:

            $settings['queue_service_{queue_name}'] = 'queue.beanstalkd';
            $settings['queue_reliable_service_{queue_name}'] = 'queue.beanstalkd';


_Notice_: With the current version of the module, you may add the module to your 
installation profile to have it enabled automatically, but not set the default
queue to be handled by Beanstalkd during installation : at this point, the 
module is not yet installed, so the `queue.beanstalkd` service is not defined,
but the Drupal 8 installer needs a queue to handle its batch operations. Replace
the default queue only once installation is complete.

____

Text above these lines is up-to-date. Text below this line no longer applies, and needs editing.
____

Lastly you can also set some beanstalkd defaults.

    $settings['beanstalk_queue_{queue name}'] = array(
      'host' => 'localhost', // Name of the host where beanstalkd is installed.
      'port' => '11300', // Port which beanstalkd is listening to.
      'fork' => FALSE, // Used in runqueue.sh to know if it should run the job in another process.
      'reserve_timeout' => 0, // How long you should wait when reserving a job.
      'ttr' => 60, // Seconds a job can be reserved for
      'release_delay' => 0 // Seconds to delay a job
      'forked_extra_timeout' => FALSE, // When forking the job runner, wait n time for more items on this queue.
      'forked_extra_items' => FALSE, // When forking the job runner, process n items in addition on this queue.
      'priority' => 1024, // Sets the priority of the job
      'delay' => 0, // Set the default delay for a queue
    );

Overall queue defaults can be set like so.

    $settings['beanstalk_default_queue'] = array(
      'host' => 'localhost', // Name of the host where beanstalkd is installed.
      'port' => '11300', // Port which beanstalkd is listening to.
      'fork' => FALSE, // Used in runqueue.sh to know if it should run the job in another process.
      'reserve_timeout' => 0, // How long you should wait when reserving a job.
      'ttr' => 60, // Seconds a job can be reserved for
      'release_delay' => 0 // Seconds to delay a job
      'forked_extra_timeout' => FALSE, // When forking the job runner, wait n time for more items on this queue.
      'forked_extra_items' => FALSE, // When forking the job runner, process n items in addition on this queue.
      'priority' => 1024, // Sets the priority of the job
      'delay' => 0, // Set the default delay for a queue
    );

If any options are missed then they will be populated with the default options.


Running
-------

Beanstalkd will run in a default environment where the messages will be processed 
during the normal cron run. However this will not give you any of the advantages 
that Beanstalkd can give you.

In the module directory is the runqueue.sh script will process messages as they 
are received. This runs in a shell and uses a blocking method of waiting for the 
messages to be received. This means that as soon as the message has been submitted 
if a queue manager is waiting it will start processing the message quickly.

Since Beanstalkd has a non-blocking queue manager you can run many queue 
managers as you want on different machines.

So in the normal case the cron will run on a single machine in a single thread. 
However with beanstalkd many message processes as needed across as many machines 
as you need. Also it means that you can run the queue manages on system that are 
not your web servers so the processing of the messages will not have any impact 
on the system except for the intersections between the systems such as the database.


Running the Queue manager
-------------------------

    php ./runqueue.php -h

Beanstalkd Queue manager.

    Usage:        runqueue.sh [OPTIONS]
    Example:      runqueue.sh

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

To run this script without `--root` argument invoke it from the root directory
of your Drupal installation with

    php ./runqueue.php

Running this will process any messages on any Beanstalkd queue.
