<?php
// $id$

/**
 * @file
 * Provide administration of Beanstalk
 */

/**
 * Global settings for beanstalkd integration
 */
function beanstalkd_admin_settings() {
  $form = array();

  $form['beanstalkd_host'] = array(
    '#type' => 'textfield',
    '#title' => t('Beanstalkd host'),
    '#default_value' => variable_get('beanstalkd_host', 'localhost'),
    '#description' => t('Enter the host name of the server which has beanstalkd running.'),
  );
  $form['beanstalkd_port'] = array(
    '#type' => 'textfield',
    '#title' => t('Beanstalkd port'),
    '#default_value' => variable_get('beanstalkd_port', '11300'),
    '#description' => t('Enter the port of the server which has beanstalkd running.'),
  );

  $queues = module_invoke_all('cron_queue_info');
  drupal_alter('cron_queue_info', $queues);

  $form['beanstalkd_queues'] = array(
    '#type' => 'item',
    '#title' => t('Queues'),
    '#descriptions' => t('Mark which queues will use Beanstalkd.'),
  );

  foreach ($queues as $queue => $settings) {
    $name = 'queue_module_' . $queue;

    $form['beanstalkd_queues'][$name] = array(
      '#type' => 'checkbox',
      '#title' => $queue,
      '#return_value' => 'Beanstalkd',
      '#default_value' => variable_get($name, 'System') == 'System' ? NULL : variable_get($name, 'System'),
    );
  }

  $form['#submit'][] = 'beanstalkd_admin_settings_submit';

  return system_settings_form($form);
}

function beanstalkd_admin_settings_submit($form, &$form_state) {
  $queues = preg_grep('/^queue_module_/', array_keys($form_state['values']));

  foreach ($queues as $name) {
    if (!$form_state['values'][$name]) {
      $form_state['values'][$name] = variable_get($name, 'System');
    }
  }
} 