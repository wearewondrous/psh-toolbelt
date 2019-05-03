<?php

namespace wearewondrous\PshToolbelt;

use Robo\Robo;

class SiteSettings {

  const SRC_PATH = 'vendor/wearewondrous/psh-toolbelt/src';

  /**
   * @var \Platformsh\ConfigReader\Config
   */
  protected $pshConfig;

  /**
   * @var array
   */
  protected $config;

  /**
   * @var array
   */
  protected $settings;

  /**
   * @var array
   */
  protected $databases;

  /**
   * @var array
   */
  protected $config_directories;

  /**
   * @var \Robo\Config\Config
   */
  protected $roboConfig;

  /**
   * SiteSettings constructor.
   *
   * @param array $settings
   * @param array $config
   * @param array $databases
   * @param array $config_directories
   */
  public function __construct(array &$settings, array &$config, array &$databases, array &$config_directories) {
    $this->settings =& $settings;
    $this->config =& $config;
    $this->databases =& $databases;
    $this->config_directories =& $config_directories;

    $this->pshConfig = new \Platformsh\ConfigReader\Config();
    $this->roboConfig = self::getRoboConfig();
  }

  /**
   * Get merged yml config.
   *
   * @return \Robo\Config\Config
   */
  public static function getRoboConfig(): \Robo\Config\Config {
    $root_dir = self::getRootDir();

    return Robo::createConfiguration([
      __DIR__ . '/../robo.yml.dist',
      $root_dir . 'robo.yml',
    ]);
  }

  /**
   * @return string
   */
  public static function getRootDir(): string {
    return str_replace(self::SRC_PATH, '', __DIR__);
  }

  /**
   * @param string $variable
   *
   * @return string|null
   */
  protected function getEnv(string $variable): ?string {
    return $this->pshConfig->variable($variable, getenv($variable));
  }

  /**
   * Primary function to set Drupal 8 config, depending on environment.
   */
  public function setDefaults(): void {
    $this->settings['trusted_host_patterns'] = [];
    $this->settings['update_free_access'] = FALSE;
    $this->config_directories[CONFIG_SYNC_DIRECTORY] = implode('/', [
      '..',
      $this->roboConfig->get('drupal.config_sync_directory'),
      $this->roboConfig->get('drupal.config.splits.default.folder'),
    ]);
    $this->settings['file_private_path'] = '../' . $this->roboConfig->get('drupal.private_files_directory');
    // Sentry dsn key
    $this->config['raven.settings']['client_key'] = $this->getEnv('SENTRY_DSN');
    $this->settings['hash_salt'] = $this->roboConfig->get('drupal.hash_salt');
    $this->settings['file_scan_ignore_directories'] = [
      'node_modules',
      'bower_components',
    ];

    $this->setTrustedHostPatterns();
    $this->setConfigSplit();
    $this->setSolr();

    if ($this->pshConfig->isValidPlatform()) {
      return;
    }

    $this->setDevRedisSettings();
    $this->setDevSettings();
  }

  /**
   * Trusted host patterns
   */
  private function setTrustedHostPatterns(): void {
    if (empty($this->settings['trusted_host_patterns'])) {
      $this->settings['trusted_host_patterns'] = [];
    }

    $platformshHost = preg_quote($this->roboConfig->get('platform.host'));
    $platformPattern = [
      "^{$platformshHost}",
      "^.+\.{$platformshHost}",
    ];

    $prodIdentifier = preg_quote($this->roboConfig->get('platform.domain'));
    $prodPattern = [
      "^.+\.{$prodIdentifier}",
    ];

    $this->settings['trusted_host_patterns'] = array_merge(
      $this->settings['trusted_host_patterns'],
      $platformPattern,
      $prodPattern
    );

    if ($this->pshConfig->inRuntime()) {
      return;
    }

    $devIdentifier = preg_quote($this->roboConfig->get('drupal_vm.host'));
    $devPattern = [
      "^{$devIdentifier}",
      "^www\.{$devIdentifier}",
    ];

    $this->settings['trusted_host_patterns'] = array_merge(
      $this->settings['trusted_host_patterns'],
      $devPattern
    );
  }

  /**
   * Config Split
   */
  private function setConfigSplit(): void {
    $configSplit = self::getConfigSplitArray($this->roboConfig);
    // activate development split.
    $this->config[$configSplit['prod']['machine_name']]['status'] = FALSE;
    $this->config[$configSplit['dev']['machine_name']]['status'] = TRUE;

    if (!$this->pshConfig->isValidPlatform()) {
      return;
    }
    // enable production config split
    $this->config[$configSplit['prod']['machine_name']]['status'] = TRUE;
    $this->config[$configSplit['dev']['machine_name']]['status'] = FALSE;
  }

  /**
   * Render a config split array for default, dev, and production info
   *
   * @param \Robo\Config\Config $roboConfig
   *
   * @return array
   */
  public static function getConfigSplitArray(\Robo\Config\Config $roboConfig): array {
    return [
      'default' => [
        'machine_name' => implode('.', [
          'config_split.config_split',
          $roboConfig->get('drupal.config.splits.default.machine_name'),
        ]),
        'folder' => implode('/', [
          DRUPAL_ROOT,
          '..',
          $roboConfig->get('platform.mounts.config'),
          $roboConfig->get('drupal.config.splits.default.folder'),
        ]),
      ],
      'prod' => [
        'machine_name' => implode('.', [
          'config_split.config_split',
          $roboConfig->get('drupal.config.splits.prod.machine_name'),
        ]),
        'folder' => implode('/', [
          DRUPAL_ROOT,
          '..',
          $roboConfig->get('platform.mounts.config'),
          $roboConfig->get('drupal.config.splits.prod.folder'),
        ]),
      ],
      'dev' => [
        'machine_name' => implode('.', [
          'config_split.config_split',
          $roboConfig->get('drupal.config.splits.dev.machine_name'),
        ]),
        'folder' => implode('/', [
          DRUPAL_ROOT,
          '..',
          $roboConfig->get('platform.mounts.config'),
          $roboConfig->get('drupal.config.splits.dev.folder'),
        ]),
      ],
    ];
  }

  /**
   * Solr config for production if enabled.
   */
  public function setSolr(): void {
    if (!$this->pshConfig->inRuntime()) {
      return;
    }

    if (!$this->roboConfig->get('solr_relationships')) {
      return;
    }

    foreach ($this->roboConfig->get('solr_relationships') as $key => $config) {
      if (!$this->pshConfig->hasRelationship($key)) {
        continue;
      }

      $solr = $this->pshConfig->credentials($key);
      $searchApiMachineName = 'search_api.server.' . $config['machine_name'];

      $this->config[$searchApiMachineName]['backend_config']['connector_config']['host'] = $solr['host'];
      $this->config[$searchApiMachineName]['backend_config']['connector_config']['port'] = $solr['port'];
      $this->config[$searchApiMachineName]['backend_config']['connector_config']['core'] = $config['core'];
    }
  }

  /**
   * Development config for Redis in VM.
   */
  public function setDevRedisSettings(): void {
    $this->settings['container_yamls'][] = DRUPAL_ROOT . '/modules/contrib/redis/example.services.yml';
    $this->settings['container_yamls'][] = DRUPAL_ROOT . '/modules/contrib/redis/redis.services.yml';

    $this->settings['cache_prefix'] = 'drupal';

    $this->settings['redis.connection']['interface'] = 'PhpRedis';
    $this->settings['redis.connection']['host'] = '127.0.0.1';
    $this->settings['cache']['default'] = 'cache.backend.redis';

    $class_loader = require DRUPAL_ROOT . '/../vendor/autoload.php';
    $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

    $this->settings['bootstrap_container_definition'] = [
      'parameters' => [],
      'services' => [
        'redis.factory' => [
          'class' => 'Drupal\redis\ClientFactory',
        ],
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
        'serialization.phpserialize' => [
          'class' => 'Drupal\Component\Serialization\PhpSerialize',
        ],
      ],
    ];
  }

  /**
   * Development settings for the VM.
   */
  public function setDevSettings(): void {
    // error logging
    error_reporting(E_ALL);
    ini_set('display_errors', TRUE);
    ini_set('display_startup_errors', TRUE);
    // http headers
    $this->config['http_response_headers.response_header.strict_transport_security']['status'] = FALSE;

    assert_options(ASSERT_ACTIVE, TRUE);
    \Drupal\Component\Assertion\Handle::register();
    // verbose error logging
    $this->config['system.logging']['error_level'] = 'verbose';

    if ($this->roboConfig->get('drupal_vm.disable_cache')) {
      // disable frontend preprocesing
      $this->config['system.performance']['css']['preprocess'] = FALSE;
      $this->config['system.performance']['js']['preprocess'] = FALSE;
      // Cache settings, use redis but do not cache render
      $this->settings['container_yamls'][] = DRUPAL_ROOT . '/sites/default/local.services.yml';
      $this->settings['cache']['bins']['render'] = 'cache.backend.null';
      $this->settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
      $this->settings['cache']['bins']['page'] = 'cache.backend.null';
    }

    $this->settings['deployment_identifier'] = \Drupal::VERSION;
    $this->settings['extension_discovery_scan_tests'] = FALSE;
    $this->settings['rebuild_access'] = TRUE;
    $this->settings['skip_permissions_hardening'] = TRUE;
    $this->settings['update_free_access'] = TRUE;
    // database settings
    $this->databases['default']['default'] = [
      'database' => $this->roboConfig->get('drupal_vm.mysql.database'),
      'driver' => 'mysql',
      'host' => $this->roboConfig->get('drupal_vm.mysql.hostname'),
      'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
      'password' => $this->roboConfig->get('drupal_vm.mysql.password'),
      'port' => $this->roboConfig->get('drupal_vm.mysql.port'),
      'prefix' => '',
      'username' => $this->roboConfig->get('drupal_vm.mysql.user'),
    ];
  }
}
