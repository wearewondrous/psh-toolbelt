<?php

namespace wearewondrous\PshToolbelt\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands to to manage modules which are excluded from config export.
 *
 * @todo Add a config-exclude:list command, which just reads the list of
 *   excluded modules.
 */
class ConfigExcludeCommands extends DrushCommands {

  /**
   * Enable all modules specified by $settings['config_exclude_modules'].
   *
   * @command config-exclude:install
   * @bootstrap full
   *
   * @todo Add some usefull output, to say what happened.
   *
   * @todo Add a confirmation step? Tell the user which modules are going to be
   *   installed.
   */
  public function installExcludedModules(): void {
    $excluded_modules = \Drupal::service('settings')->get('config_exclude_modules');
    \Drupal::service('module_installer')->install($excluded_modules);
  }

  /**
   * Uninstall all modules specified by $settings['config_exclude_modules'].
   *
   * @command config-exclude:uninstall
   * @bootstrap full
   *
   * @todo Add some usefull output, to say what happened.
   *
   * @todo Add a confirmation step? Tell the user which modules are going to be
   *   un-installed.
   */
  public function uninstallExcludedModules(): void {
    $excluded_modules = \Drupal::service('settings')->get('config_exclude_modules');
    \Drupal::service('module_installer')->uninstall($excluded_modules);
  }

}
