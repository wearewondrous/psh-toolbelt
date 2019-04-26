<?php

use Platformsh\ConfigReader\Config;
use wearewondrous\PshToolbelt\SiteSettings;

$databases = [];
$config_directories = [];
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

$siteSettings = new SiteSettings($settings, $config, $databases, $config_directories);
$siteSettings->setDefaults();

$platformsh = new Config();

if (!$platformsh->inRuntime()) {
  return;
}

include 'settings.platformsh.php';

/**
 * Alters the configuration directories.
 *
 * @see: Drush\Commands\config_split_psh_export\ConfigSplitPshExportCommands
 */
function preCommandConfigSplitPshExport() {
  global $config_directories;
  global $config;
  $configSplit = SiteSettings::getConfigSplitArray(SiteSettings::getRoboConfig());

  $config_directories[CONFIG_SYNC_DIRECTORY] = $configSplit['default']['folder'];
  $config[$configSplit['prod']['machine_name']]['folder'] = $configSplit['prod']['folder'];
}
