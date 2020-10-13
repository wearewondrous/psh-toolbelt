<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt;

use Drupal;
use Drupal\Component\Assertion\Handle;
use Platformsh\ConfigReader\Config as PlatformshConfig;
use const ASSERT_ACTIVE;
use const E_ALL;
use function array_merge;
use function assert_options;
use function error_reporting;
use function getenv;
use function implode;
use function ini_set;
use function preg_quote;
use function sprintf;

class SiteSettings {
  public const SRC_PATH         = 'vendor/wearewondrous/psh-toolbelt/src';
  public const CACHE_404_TTL    = 3600;
  public const LOCAL_IDENTIFIER = 'local';
  public const DEV_IDENTIFIER   = 'dev';
  public const PROD_IDENTIFIER  = 'prod';

  /**
   * @var \Platformsh\ConfigReader\Config*/
  protected $pshConfig;

  /**
   * @var mixed[]*/
  protected $config;

  /**
   * @var mixed[]*/
  protected $settings;

  /**
   * @var mixed[]*/
  protected $databases;

  /**
   * @var mixed[]*/
  protected $configDirectories;

  /**
   * @var \Consolidation\Config\ConfigInterface*/
  protected $roboConfig;

  /**
   * @var ConfigFileReader*/
  private $configFileReader;

  /**
   * @param mixed[] $settings
   *   Drupal settings array.
   *
   * @param mixed[] $config
   *   Drupal config array.
   *
   * @param mixed[] $databases
   *   Drupal databases array.
   *
   * @param mixed[] $config_directories
   *   Drupal config_directories array.
   */
  public function __construct(array &$settings, array &$config, array &$databases, array &$config_directories) {
    $this->settings          =& $settings;
    $this->config            =& $config;
    $this->databases         =& $databases;
    $this->configDirectories =& $config_directories;

    $this->pshConfig        = new PlatformshConfig();
    $this->configFileReader = new ConfigFileReader();
    $this->roboConfig       = $this->configFileReader->getRoboConfig();
  }

  protected function getEnv(string $variableName) : ?string {
    $envVariable = getenv($variableName);
    $pshVariable = $this->pshConfig->variable($variableName);

    if ($pshVariable !== NULL) {
      return $pshVariable;
    }

    if ($envVariable !== FALSE) {
      return $envVariable;
    }

    return NULL;
  }


  protected function getLocalConfig(string $variableName) {
    if ($local = $this->roboConfig->get('local_dev.' . $variableName)) {
      return $local;
    }

    if ($dupalVm = $this->roboConfig->get('drupal_vm.' . $variableName)) {
      return $dupalVm;
    }

    return NULL;
  }

  /*
   * Deside if in drupal vm or lando environment
   */
  protected function isLocalDevelopment(): bool {
    if ($PLATFORM_ENVIRONMENT = $this->getEnv('PLATFORM_ENVIRONMENT')) {
      return $PLATFORM_ENVIRONMENT === 'lando';
    }

    if ($USER = $this->getEnv('USER')) {
      return $USER === 'vagrant';
    }

    return false;
  }

  /**
   * Primary function to set Drupal 8 config, depending on environment.
   */
  public function setDefaults() : void {
    $this->settings['trusted_host_patterns']        = [];
    $this->settings['update_free_access']           = FALSE;
    $this->configDirectories[CONFIG_SYNC_DIRECTORY] = implode(
      '/',
      [
        '..',
        $this->roboConfig->get('drupal.config_sync_directory'),
        $this->roboConfig->get('drupal.config.splits.default.folder'),
      ]
    );
    $this->settings['file_private_path']            = '../' . $this->roboConfig->get('drupal.private_files_directory');
    $this->config['raven.settings']['client_key']   = $this->getEnv('SENTRY_DSN');
    $this->settings['hash_salt']                    = $this->roboConfig->get('drupal.hash_salt');
    $this->settings['cache_ttl_4xx']                = self::CACHE_404_TTL;
    $this->settings['file_scan_ignore_directories'] = [
      'node_modules',
      'bower_components',
    ];

    $this->setTrustedHostPatterns();
    $this->setConfigSplit();
    $this->setSolr();

    if ($this->useRedis()) {
      $this->setRedisSettings();
    }

    if ($this->useDevelopmentSettings()) {
      $this->setDevSettings();
    }
  }

  /**
   * Trusted host patterns.
   */
  private function setTrustedHostPatterns() : void {
    $pshHost = $this->roboConfig->get('platform.host');
    $pshDomain = $this->roboConfig->get('platform.domain');
    $localHost = $this->getLocalConfig('host');

    if (count($this->settings['trusted_host_patterns']) === 0) {
      $this->settings['trusted_host_patterns'] = [];
    }

    if ($this->isLocalDevelopment() && $localHost !== NULL) {
      $devIdentifier = preg_quote($localHost);
      $devPattern = [
        sprintf('^%s', $devIdentifier),
        sprintf('^www\.%s', $devIdentifier),
      ];

      $this->settings['trusted_host_patterns'] = array_merge(
        $this->settings['trusted_host_patterns'],
        $devPattern
      );

      return;
    }

    if ($pshHost !== NULL && $pshDomain !== NULL) {
      $platformshHost = preg_quote($pshHost);
      $platformPattern = [
        sprintf('^%s', $platformshHost),
        sprintf('^.+\.%s', $platformshHost),
      ];

      $prodIdentifier = preg_quote($pshDomain);
      $prodPattern = [sprintf('^.+\.%s', $prodIdentifier)];

      $this->settings['trusted_host_patterns'] = array_merge(
        $this->settings['trusted_host_patterns'],
        $platformPattern,
        $prodPattern
      );
    }
  }

  /**
   * Config Split.
   */
  private function setConfigSplit() : void {
    $configSplit = $this->configFileReader->getConfigSplitFromRoboConfig();

    if ($this->isLocalDevelopment()) {
      $this->config[$configSplit['prod']['machine_name']]['status'] = FALSE;
      $this->config[$configSplit['dev']['machine_name']]['status'] = TRUE;

      return;
    }

    // Enable production config split.
    $this->config[$configSplit['prod']['machine_name']]['status'] = TRUE;
    $this->config[$configSplit['dev']['machine_name']]['status'] = FALSE;
  }

  /**
   * Solr config for production if enabled.
   */
  public function setSolr() : void {
    // @todo: does this need a dev env check?
    if (!$this->pshConfig->inRuntime()) {
      return;
    }

    if (count($this->roboConfig->get('solr_relationships')) === 0) {
      return;
    }

    foreach ($this->roboConfig->get('solr_relationships') as $key => $config) {
      if (!$this->pshConfig->hasRelationship($key)) {
        continue;
      }

      $searchApiMachineName = 'search_api.server.' . $config['machine_name'];

      $this->pshConfig->registerFormatter('drupal-solr', function ($solr) {
        return [
          'core' => substr($solr['path'], 5) ?: 'main',
          'path' => '',
          'host' => $solr['host'],
          'port' => $solr['port'],
        ];
      });

      $this->config[$searchApiMachineName]['backend_config']['connector_config'] = $this->pshConfig->formattedCredentials($key, 'drupal-solr');
    }
  }

  /**
   * Development config for Redis in VM.
   */
  public function setRedisSettings() : void {
    $this->settings['container_yamls'][] = DRUPAL_ROOT . '/modules/contrib/redis/example.services.yml';
    $this->settings['container_yamls'][] = DRUPAL_ROOT . '/modules/contrib/redis/redis.services.yml';

    try {
      if ($redis = $this->pshConfig->credentials('redis')) {
        $this->settings['redis.connection']['host'] = $redis['host'];
        $this->settings['redis.connection']['port'] = $redis['port'];
      }
    } catch (\RuntimeException $exception) {
      // todo update config file
      if ($this->useRedis()) {
        $this->settings['redis.connection']['interface'] = 'PhpRedis';
        $this->settings['redis.connection']['host']      = '127.0.0.1';
      }
    }

    $this->settings['cache_prefix'] = 'drupal';
    $this->settings['cache']['default'] = 'cache.backend.redis';

    $class_loader = include DRUPAL_ROOT . '/../vendor/autoload.php';
    $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

    $this->settings['bootstrap_container_definition'] = [
      'parameters' => [],
      'services' => [
        'redis.factory' => ['class' => 'Drupal\redis\ClientFactory'],
        'cache.backend.redis' => [
          'class' => 'Drupal\redis\Cache\CacheBackendFactory',
          'arguments' => [
            '@redis.factory',
            '@cache_tags_provider.container',
            '@serialization.phpserialize',
          ],
        ],
        'cache.container' => [
          'class' => '\Drupal\redis\Cache\PhpRedis',
          'factory' => ['@cache.backend.redis', 'get'],
          'arguments' => ['container'],
        ],
        'cache_tags_provider.container' => [
          'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
          'arguments' => ['@redis.factory'],
        ],
        'serialization.phpserialize' => ['class' => 'Drupal\Component\Serialization\PhpSerialize'],
      ],
    ];
  }

  /**
   * Development settings for the VM.
   */
  public function setDevSettings() : void {
    // Error logging.
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
    ini_set('display_startup_errors', 'On');
    // Http headers.
    $this->config['http_response_headers.response_header.strict_transport_security']['status'] = FALSE;
    $this->settings['file_temp_path'] = '/tmp';

    assert_options(ASSERT_ACTIVE, TRUE);
    Handle::register();
    // Verbose error logging.
    $this->config['system.logging']['error_level'] = 'verbose';

    if ($this->isCacheDisabled()) {
      // Disable frontend preprocesing.
      $this->config['system.performance']['css']['preprocess'] = FALSE;
      $this->config['system.performance']['js']['preprocess'] = FALSE;
      // Cache settings, use redis but do not cache render.
      $this->settings['container_yamls'][]                   = DRUPAL_ROOT . '/../vendor/wearewondrous/psh-toolbelt/services/development.services.yml';
      $this->settings['cache']['bins']['render']             = 'cache.backend.null';
      $this->settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
      $this->settings['cache']['bins']['page']               = 'cache.backend.null';
    }

    $this->settings['deployment_identifier']          = Drupal::VERSION;
    $this->settings['extension_discovery_scan_tests'] = FALSE;
    $this->settings['rebuild_access']                 = TRUE;
    $this->settings['skip_permissions_hardening']     = TRUE;
    $this->settings['update_free_access']             = TRUE;

    // Database settings.
    $this->databases['default']['default'] = [
      'database' => $this->getLocalConfig('mysql.database'),
      'driver' => 'mysql',
      'host' => $this->getLocalConfig('mysql.hostname'),
      'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
      'password' => $this->getLocalConfig('mysql.password'),
      'port' => $this->getLocalConfig('mysql.port'),
      'prefix' => '',
      'username' => $this->getLocalConfig('mysql.user'),
    ];
  }

  private function getCurrentEnvironment() : string {
    if ($this->isLocalDevelopment()) {
      return self::LOCAL_IDENTIFIER;
    }

    if ($this->pshConfig->branch === 'master' || $this->pshConfig->branch === 'main') {
      return self::PROD_IDENTIFIER;
    }

    return self::DEV_IDENTIFIER;
  }

  // todo refactor
  private function useRedis() : bool {
    $currentEnvironment = $this->getCurrentEnvironment();
    $redisSettings = $this->roboConfig->get('redis.' . $currentEnvironment);

    // Backwards compatible layer.
    if ($redisSettings === NULL || !is_bool($redisSettings)) {
      return TRUE;
    }

    return $redisSettings === TRUE;
  }

  private function useProductionSettings() : bool {
    return $this->getCurrentEnvironment() !== self::LOCAL_IDENTIFIER;
  }

  private function useDevelopmentSettings() : bool {
    return $this->getCurrentEnvironment() === self::LOCAL_IDENTIFIER;
  }

  private function isCacheDisabled() : bool {
    $disableCache = $this->getLocalConfig('disable_cache');

    if ($disableCache === NULL) {
      return TRUE;
    }

    if (!is_bool($disableCache)) {
      return TRUE;
    }

    return $disableCache === TRUE;
  }

}
