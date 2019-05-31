<?php

/**
 * @file
 */

declare(strict_types = 1);

use Platformsh\ConfigReader\Config;
use wearewondrous\PshToolbelt\ConfigFileReader;
use wearewondrous\PshToolbelt\SiteSettings;

$databases                     = [];
$config_directories            = [];
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

$configFileReader = new ConfigFileReader();

$siteSettings = new SiteSettings($settings, $config, $databases, $config_directories);
$siteSettings->setDefaults();

$platformsh = new Config();

if (!$platformsh->inRuntime()) {
  return;
}

require 'settings.platformsh.php';

if (PHP_SAPI === 'cli') {
  $context = drush_get_context();

  if (is_array($context) && !empty($context) && isset($context['argv']) && isset($context['argv'][1])) {
    $argument = $context['argv'][1];

    if ($argument === 'config:export' || $argument === 'cex' || $argument === 'config-export') {
      $configSplit = $configFileReader->getConfigSplitFromRoboConfig();

      $config_directories[CONFIG_SYNC_DIRECTORY]              = $configSplit['default']['folder'];
      $config[$configSplit['prod']['machine_name']]['folder'] = $configSplit['prod']['folder'];
    }
  }
}
