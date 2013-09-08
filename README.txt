
Beanstalkd is a Drupal module to allow Drupal Queues to take advantage of beanstalkd to process the queues instead of the built in Database queue system that ships with Drupal.

What is Beanstalkd
------------------

Beanstalk is a simple, fast workqueue service. Its interface is generic, but was originally designed for reducing the latency of page views in high-volume web applications by running time-consuming tasks asynchronously.

Requirements
------------

* beanstalkd needs to be installed and configured.
* a copy of pheanstalk needs to be checked out and put inside one of the following directories. 

1. profile/{profile}/libraries
2. sites/all/libraries
3. sites/{config}/libraries

Use the following command in one of the above directories.

$ git clone git://github.com/pda/pheanstalk.git

or download the latest version from https://github.com/pda/pheanstalk and untar/unzip it into one of the above directories.

Installation
------------

1. Install like a normal Drupal module.
2. In your settings.php you need to set the $conf variables to the correct settings.

If you want to set beanstalkd as the default queue manager then add the following to your settings.php

$conf['queue_default_class'] = 'BeanstalkdQueue';

Alternatively you can also set for each queue to use beanstalkd

$conf['queue_class_{queue name}'] = 'BeanstalkdQueue';

Lastly you can also set some beanstalkd defaults.

$conf['beanstalk_queue_{queue name}'] = array(
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

$conf['beanstalk_default_queue'] = array(
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

Beanstalkd will run in a default environment where the messages will be processed during the normal cron run. However this will not give you any of the advantages that Beanstalkd can give you.

In the module directory is the runqueue.sh script will process messages as they are received. This runs in a shell and uses a blocking method of waiting for the messages to be received. This means that as soon as the message has been submitted if a queue manager is waiting it will start processing the message quickly.

Since Beanstalkd has a non-blocking queue manager you can run many queue managers as you want on different machines. 

So in the normal case the cron will run on a single machine in a single thread. However with beanstalkd many message processes as needed across as many machines as you need. Also it means that you can run the queue manages on system that are not your web servers so the processing of the messages will not have any impact on the system except for the intersections between the systems such as the database.

Running the Queue manager
-------------------------

./runqueue.sh -h

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

To run this script without --root argument invoke it from the root directory
of your Drupal installation with

  ./runqueue.sh

Running this will process any messages on any Beanstalkd queue.

Using Supervisord
-----------------

Since PHP is not designed to run as a deamon process, long running PHP scripts will generally consume more and more memory. All care has been taken to avoid this as much as possible, but any code which has not been written to be a part of beanstalkd will generally not cope with running for too long. With good set up practices and configuration the runqueue.sh script will run for a very long time even when using extremely memory hungry processes such as the grammar parser in the API module. So as a backup it is advised that you run this process using the supervisord module which will monitor runqueue.sh and make sure that if it does exit it will be restarted.

Here is an example configuration for supervisord.

---8<---
command=/usr/bin/php sites/all/modules/contrib/beanstalkd/runqueue.sh -s http://example.com/
autorestart=true
user=www-data
directory=/var/www
---8<---

