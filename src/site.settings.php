<?php

declare(strict_types = 1);

use Platformsh\ConfigReader\Config as PlatformshConfig;
use wearewondrous\PshToolbelt\ConfigFileReader;
use wearewondrous\PshToolbelt\SiteSettings;

$databases          = [];
$config_directories = [];

if (!isset($settings)) {
  $settings = [];
}

$settings['container_yamls'][] = '../services/default.services.yml';

// Load service overrides.
if (file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}

$configFileReader = new ConfigFileReader();

$siteSettings = new SiteSettings($settings, $config, $databases, $config_directories);
$siteSettings->setDefaults();

$platformsh = new PlatformshConfig();

if (!$platformsh->inRuntime()) {
  return;
}

require 'settings.platformsh.php';

if (PHP_SAPI === 'cli') {
  $args = $_SERVER['argv'];

  if(is_array($args) && count($args) > 0) {
    foreach ($args as $arg) {
      if ($arg === 'config:export' || $arg === 'cex' || $arg === 'config-export') {
        $configSplit = $configFileReader->getConfigSplitFromRoboConfig();

        $config_directories[CONFIG_SYNC_DIRECTORY]              = $configSplit['default']['folder'];
        $config[$configSplit['prod']['machine_name']]['folder'] = $configSplit['prod']['folder'];

        break;
      }
    }
  }
}
