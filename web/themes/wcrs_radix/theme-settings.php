<?php

declare(strict_types=1);

/**
 * @file
 * Theme settings form for WCRS Radix theme.
 */

use Drupal\Core\Form\FormState;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function wcrs_radix_form_system_theme_settings_alter(array &$form, FormState $form_state): void {

  $form['wcrs_radix'] = [
    '#type' => 'details',
    '#title' => t('WCRS Radix'),
    '#open' => TRUE,
  ];

  $form['wcrs_radix']['example'] = [
    '#type' => 'textfield',
    '#title' => t('Example'),
    '#default_value' => theme_get_setting('example'),
  ];

}
