<?php

use Platformsh\ConfigReader\Config as PlatformshConfig;
use wearewondrous\PshToolbelt\SiteSettings;

$databases = [];
$config_directories = [];

$settings['container_yamls'][] = '../services/default.services.yml';

// Load service overrides
// @DEPRECATED
if(file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}

// Load service overrides
if(file_exists($app_root . '/' . $site_path . '/override.services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/override.services.yml';
}

$siteSettings = new SiteSettings($settings, $config, $databases, $config_directories);
$siteSettings->setDefaults();

$platformsh = new PlatformshConfig();

if (!$platformsh->inRuntime()) {
  return;
}

include 'settings.platformsh.php';

if (PHP_SAPI == 'cli') {
  $context = drush_get_context();

  if (is_array($context) && !empty($context) && isset($context['argv']) && isset($context['argv'][1])) {
    $argument = $context['argv'][1];

    if ($argument === 'config:export' || $argument === 'cex' || $argument === 'config-export') {
      $configSplit = SiteSettings::getConfigSplitArray(SiteSettings::getRoboConfig());

      $config_directories[CONFIG_SYNC_DIRECTORY] = $configSplit['default']['folder'];
      $config[$configSplit['prod']['machine_name']]['folder'] = $configSplit['prod']['folder'];
    }
  }
}
