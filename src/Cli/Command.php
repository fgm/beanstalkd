<?php
/**
 * @file
 * Command.php
 *
 * @author: Frédéric G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2015 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\beanstalkd\Cli;


/**
 * Class Command
 *
 * @package Drupal\beanstalkd\Cli
 */
class Command {
  function beanstalkd_log($string, $no_eol = FALSE) {
    global $_verbose_mode;

    if (!$_verbose_mode) {
      return;
    }

    echo format_date(time(), 'custom', 'd M Y H:i:s') . "\t" . $string . ($no_eol ? '' : "\n");
  }

  function beanstalkd_process($allow_forking = TRUE, $process_time = FALSE, $process_items = FALSE) {
    global $queue, $start_memory;

    $start_time = time();
    $process_count = 0;

    while (1) {
      $items = $queue->reserve(0);
      $item = reset($items);

      if (!$item) {
        if ($process_time === FALSE && $process_items === FALSE) {
          beanstalkd_log(t("Waiting for next item to be claimed"));
          $items = $queue->reserve(NULL);
          $item = reset($items);
        }
        else {
          // There are no more items, and as we have limits we just want to return.
          return;
        }
      }
      if ($item) {
        $process_count++;
        $queue_defaults = beanstalkd_get_queue_options($item->name);
        $process_function = ($allow_forking && $queue_defaults['fork']) ? 'beanstalkd_execute' : 'beanstalkd_process_item';
        $_SERVER['REQUEST_TIME'] = time();

        if ($process_function($item)) {
          beanstalkd_log(t('Deleting job @id', array('@id' => $item->id)));

          // This should never happen but sometimes it does.
          try {
            $queue->delete($item);
          }
          catch (Exception $e) {
            NULL;
          }
        }
      }
      else {
        if ($process_time === FALSE && $process_items === FALSE) {
          sleep(5); // sleep for 5 seconds and try again.
        }
        else {
          // There are no more items, and as we have limits we just want to return.
          return;
        }
      }

      drupal_get_messages(); // Clear out the messages so they don't take up memory
      drupal_static_reset(NULL);
      beanstalkd_log(t('Total Memory Used: @memory, @bootstrap since bootstrap', array('@memory' => format_size(memory_get_usage()), '@bootstrap' => format_size(memory_get_usage() - $start_memory))));

      // Check to see if the limits have been exceeded and return.
      if ($process_time && $start_time+$process_time < time()) {
        beanstalkd_log(t('Processing time limit of @seconds seconds exceeded.', array('@seconds' => $process_time)));
        return;
      }
      if ($process_items && $process_items < $process_count) {
        beanstalkd_log(t('Processing limit of @items jobs exceeded.', array('@items' => $process_items)));
        return;
      }
    }
  }

  function beanstalkd_process_item($item) {
    global $queue;

    $info = beanstalkd_get_host_queues(NULL, $item->name);

    $logger = \Drupal::logger('beanstalkd');
    if (!empty($info)) {
      $function = $info['worker callback'];

      try {
        beanstalkd_log(t("Processing job @id for queue @name", array('@id' => $item->id, '@name' => $item->name)));
        if (isset($info['description callback']) && function_exists($info['description callback'])) {
          beanstalkd_log($info['description callback']($item->data));
        }

        ini_set('display_errors', 0);
        Timer::start('beanstalkd_process_item');
        $function($item->data);
        $timer = Timer::read('beanstalkd_process_item');
        ini_set('display_errors', 1);

        $logger->notice('Processed job @id for queue @name taking @timer msec<br />@description', [
          '@id' => $item->id,
          '@name' => $item->name,
          '@timer' => $timer,
          '@description' => (isset($info['description callback']) && function_exists($info['description callback']) ? $info['description callback']($item->data) : ''),
        ]);

        return TRUE;
      }
      catch (Exception $e) {
        beanstalkd_log(t("Exception caught: @message in @file on line @line.\n@trace", array('@message' => $e->getMessage(), '@file' => $e->getFile(), '@line' => $e->getLine(), '@trace' => $e->getTraceAsString())));
        $logger->error('Job @id - @name: Exception caught: @message in @file on line @line.<br/><pre>@trace</pre>', [
          '@id' => $item->id,
          '@name' => $item->name,
          '@message' => $e->getMessage(),
          '@file' => $e->getFile(),
          '@line' => $e->getLine(),
          '@trace' => $e->getTraceAsString(),
        ]);
        $stats = $queue->statsJob($item);
        $queue_defaults = beanstalkd_get_queue_options($item->name);
        if ($stats['releases'] < $queue_defaults['retries']) {
          $queue->release($item, $queue_defaults['priority'], $queue_defaults['release_delay']);
        }
        else {
          $queue->bury($item);
        }
        return FALSE;
      }
    }
    return FALSE;
  }

  function beanstalkd_execute($item) {
    global $script_name, $_verbose_mode, $hostname;

    $php_names = ['php', 'php5', 'PHP.EXE', 'php.exe'];

    $parts = parse_url($hostname);

    $cmd_parts = [escapeshellarg(PHP_BINARY)];
    if (in_array(basename(PHP_BINARY), $php_names)) {
      $cmd_parts[] = $script_name;
    }
    $cmd_parts[] = '-r ' . escapeshellarg(realpath(getcwd()));
    $cmd_parts[] = '-s ' . $_SERVER['HTTP_HOST'];
    $cmd_parts[] = '-x ' . $item->id;
    $cmd_parts[] = '-c ' . $parts['host'];
    $cmd_parts[] = '-p ' . $parts['port'];

    if ($_verbose_mode) {
      $cmd_parts[] = '-v';
    }

    $cmd = implode(' ', $cmd_parts);

    beanstalkd_log('Executing: ' . $cmd);
    passthru($cmd, $return_value);

    beanstalkd_log('Return Val: ' . $return_value);

    return $return_value == 0;
  }

  function beanstalkd_shutdown() {
    beanstalkd_log('Shutdown complete.');
  }


}
