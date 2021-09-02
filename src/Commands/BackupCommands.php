<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt\Commands;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Robo\Robo;
use function array_pop;
use function closedir;
use function date;
use function explode;
use function fopen;
use function implode;
use function is_dir;
use function opendir;
use function readdir;
use function sprintf;
use function strpos;
use function strtotime;
use function time;
use function trim;
use function unlink;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class BackupCommands extends BaseCommands {
  /**
   * @var \Raven\Client
   */
  private $sentryClient;

  /**
   * @var \Aws\S3\S3Client
   */
  private $s3Client;

  /**
   * @var string
   */
  private $projectPrefix;

  /**
   * @var \Aws\S3\MultipartUploader
   */
  private $multipartUploader;

  /**
   * Command post-initialization.
   *
   * @hook post-init
   */
  public function initVars() : void {
    if (!$this->pshConfig->isValidPlatform()) {
      die('Not in a Platform.sh Environment.');
    }

    if (!$this->pshConfig->hasRelationship('database')) {
      die('Not an Environment with a database.');
    }

    try {
      $this->validateEnvVars();
    }
    catch (\Throwable $e) {
      die('Not all required environment variables defined' . $e);
    }

    $this->projectPrefix = implode(
          '',
          [
            Robo::config()->get('drush.alias_group') . '-',
            $this->pshConfig->project . self::FILE_DELIMITER,
          ]
      );
    $this->sentryClient  = new \Raven\Client($this->getEnv('SENTRY_DSN'));
    $this->s3Client      = new S3Client(
          [
            'version' => Robo::config()->get('storage.s3.version'),
            'region' => Robo::config()->get('storage.s3.region'),
            'credentials' => [
              'key' => $this->getEnv('AWS_ACCESS_KEY_ID'),
              'secret' => $this->getEnv('AWS_SECRET_KEY_ID'),
            ],
          ]
      );
    $this->s3Client->registerStreamWrapper();
  }

  /**
   * Backup current branch to AWS, including files and db.
   *
   * @throws \Exception
   *
   * @option $force Ignore config and force uploading the current environment
   */
  public function backupBranch(array $opt = ['force|f' => FALSE]) : void {
    if (!$this->backupCurrentBranch($opt['force'])) {
      return;
    }

    $prefix = implode(
          '',
          [
            $this->projectPrefix,
            $this->pshConfig->branch . self::FILE_DELIMITER,
            date(self::DATETIME_FORMAT),
          ]
      );

    $this->dbDumpAndUpload($prefix);
    $this->archiveAndUploadFiles($prefix);
    $this->cleanupRemote();

    $this->sentryClient->captureMessage(
          sprintf('Successfully backed up: %s', $prefix),
          [],
          ['level' => 'info']
      );
  }

  /**
   * @throws \Exception
   */
  private function validateEnvVars() : void {
    $variables = [
      'AWS_ACCESS_KEY_ID',
      'AWS_SECRET_KEY_ID',
      'SENTRY_DSN',
    ];

    foreach ($variables as $variable) {
      if ($this->getEnv($variable) === NULL) {
        throw new \Exception(sprintf('Environment variable %s missing', $variable));
      }
    }
  }

  private function backupCurrentBranch(bool $forced) : bool {
    if ($forced) {
      return TRUE;
    }
    // Look for env var and in PLATFORM_VARIABLES.
    if ($this->getEnv('BACKUP_THIS_BRANCH') !== NULL) {
      return TRUE;
    }

    return $this->pshConfig->branch === self::VCS_MASTER;
  }

  /**
   * Remove remote backup files that are older than storage.backup.max_age.
   *
   * (defaults to 5 days).
   */
  private function cleanupRemote() : void {
    $dir = 's3://' . implode(
          '/',
          [
            Robo::config()->get('storage.s3.upload_bucket'),
            Robo::config()->get('drush.alias_group'),
          ]
      );

    if (!is_dir($dir)) {
      return;
    }

    $dir_handle = opendir($dir);

    if ($dir_handle === FALSE) {
      return;
    }

    $removedFolders = [];

    while (($file = readdir($dir_handle)) !== FALSE) {
      if (strpos($file, $this->projectPrefix) !== 0) {
        continue;
      }

      $dirPath = $dir . '/' . $file;

      if (!is_dir($dirPath)) {
        continue;
      }

      if (!$this->isBackupOutdated($dirPath)) {
        continue;
      }

      $folderDeleted = $this->deleteFolderRecursively($dirPath);

      if ($folderDeleted) {
        $removedFolders[] = $dirPath;
      }
      else {
        $this->sentryClient->captureMessage("Could not wipe folder: " . $dirPath, [], [
          'level' => 'warning',
        ]);
      }
    }

    closedir($dir_handle);

    if (count($removedFolders) === 0) {
      return;
    }

    $this->sentryClient->captureMessage(
          'Cleanup, folders removed: ' . implode(', ', $removedFolders),
          [],
          ['level' => 'info']
      );
  }

  private function deleteFolderRecursively(string $folder) : bool {
    if (is_dir($folder)) {
      $handle = opendir($folder);

      if ($handle === FALSE) {
        return FALSE;
      }

      while ($subFile = readdir($handle)) {
        if ($subFile == '.' or $subFile == '..') {
          continue;
        }

        if (is_file($subFile)) {
          return unlink("{$folder}/{$subFile}");
        }
        else {
          return $this->deleteFolderRecursively("{$folder}/{$subFile}");
        }
      }

      closedir($handle);
      return rmdir($folder);
    }
    else {
      return unlink($folder);
    }
  }

  private function isBackupOutdated(string $dirPath) : bool {
    $lastModified = $this->getLastModifiedFromFolder($dirPath);

    return time() - $lastModified >= (int) Robo::config()
      ->get('storage.backup.max_age');
  }

  private function getLastModifiedFromFolder(string $folderName) : int {
    $fileParts = explode(self::FILE_DELIMITER, $folderName);
    $datetime = array_pop($fileParts);

    if ($datetime === NULL) {
      return 0;
    }

    return (int) strtotime($datetime);
  }

  /**
   * Upload the database.
   */
  private function dbDumpAndUpload(string $prefix) : void {
    $fileName   = $prefix . self::DB_DUMP_SUFFIX . '.sql.gz';
    $pathToFile = implode(
          '/',
          [
            $this->pshConfig->appDir,
            trim(Robo::config()->get('platform.mounts.temp'), '/'),
            $fileName,
          ]
      );
    $objectKey  = implode(
          '/',
          [
            Robo::config()->get('drush.alias_group'),
            $prefix,
            $fileName,
          ]
      );

    try {
      $drushPath = Robo::config()->get('drush.path');
      $this->_exec(sprintf('%s sql:dump | gzip > %s', $drushPath, $pathToFile));

      $this->multipartUploader = new MultipartUploader($this->s3Client, fopen($pathToFile, 'r'), [
        'bucket' => Robo::config()->get('storage.s3.upload_bucket'),
        'key'    => $objectKey,
      ]);
      $this->multipartUploader->upload();

      $this->sentryClient->captureMessage(
            'DB backed up in: ' . $objectKey,
            [],
            ['level' => 'info']
        );
    }
    catch (\Throwable $e) {
      $this->sentryClient->captureMessage(
            'Database backup: ' . $e->getMessage(),
            [],
            ['level' => 'error']
            );
    }
    finally {
      $fileDeleted = unlink($pathToFile);
      
      if($fileDeleted !== FALSE) {
        $this->sentryClient->captureMessage("Successfully purged temp file: " . $pathToFile, [], [
          'level' => 'info',
        ]);
      }
      else {
        $this->sentryClient->captureMessage("Could not purge temp file: " . $pathToFile, [], [
          'level' => 'error',
        ]);
      }
    }
  }

  /**
   * Upload public and private files.
   */
  private function archiveAndUploadFiles(string $prefix) : void {
    $paths              = [
      'public' => Robo::config()->get('drupal.public_files_directory') . '/',
      'private' => Robo::config()->get('drupal.private_files_directory') . '/',
    ];
    $targetFileTemplate = $prefix . self::FILES_DUMP_SUFFIX . '.tar.gz';
    $objectKeyTemplate  = implode(
          '/',
          [
            Robo::config()->get('drush.alias_group'),
            $prefix,
            $targetFileTemplate,
          ]
      );

    foreach ($paths as $key => $path) {
      try {
        $objectKey = sprintf($objectKeyTemplate, $key);
        $targetFile = implode(
              '/',
              [
                $this->pshConfig->appDir,
                trim(Robo::config()->get('platform.mounts.temp'), '/'),
                sprintf($targetFileTemplate, $key),
              ]
          );
        $directory = implode(
              '/',
              [
              // No app dir, for the export will work with relative paths.
                trim($path, '/'),
        // Have a trailing slash.
                '',
              ]
          );
        $excludes = '--exclude=' . implode(
              ' --exclude=',
              Robo::config()
                ->get('drupal.excludes')
          );
        // Tar: excludes first, create tar.
        // Pipe to gzip, otherwise error on platform.sh: "tar: z: Cannot open: Read-only file system".
        $this->_exec(sprintf('tar %s -c %s | gzip > %s', $excludes, $directory, $targetFile));

        $this->multipartUploader = new MultipartUploader($this->s3Client, fopen($targetFile, 'r'), [
          'bucket' => Robo::config()->get('storage.s3.upload_bucket'),
          'key'    => $objectKey,
        ]);
        $this->multipartUploader->upload();

        $this->sentryClient->captureMessage(
              sprintf('%s-files backed up in: ', $key) . $objectKey,
              [],
              ['level' => 'info']
          );
      }
      catch (\Throwable $e) {
        $this->sentryClient->captureMessage(
              'Files backup: ' . $e->getMessage(),
              [],
              ['level' => 'error']
              );
      }
      finally {
        $fileDeleted = unlink($targetFile);
        
        if($fileDeleted !== FALSE) {
          $this->sentryClient->captureMessage("Successfully purged temp file: " . $targetFile, [], [
            'level' => 'info',
          ]);
        }
        else {
          $this->sentryClient->captureMessage("Could not purge temp file: " . $targetFile, [], [
            'level' => 'error',
          ]);
        }
      }
    }
  }

}
