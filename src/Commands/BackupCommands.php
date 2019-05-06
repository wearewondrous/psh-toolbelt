<?php

namespace wearewondrous\PshToolbelt\Commands;

use Aws\S3\S3Client;
use Exception;
use Raven_Client;
use Robo\Robo;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class BackupCommands extends BaseCommands
{

    /**
     * @var Raven_Client
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
     * Command post-initialization.
     *
     * @hook post-init
     */
    public function initVars(): void
    {
        if (!$this->pshConfig->isValidPlatform()) {
            die("Not in a Platform.sh Environment.");
        }

        if (!$this->pshConfig->hasRelationship('database')) {
            die("Not an Environment with a database.");
        }

        try {
            $this->validateEnvVars();
        } catch (Exception $e) {
            die("Not all required environment variables defined" . $e);
        }

        $this->projectPrefix = implode(
            '',
            [
            Robo::Config()->get('drush.alias_group') . '-',
            $this->pshConfig->project . self::FILE_DELIMITER,
            ]
        );
        $this->sentryClient = new Raven_Client($this->getEnv('SENTRY_DSN'));
        $this->s3Client = new S3Client(
            [
            'version' => Robo::Config()->get('storage.s3.version'),
            'region' => Robo::Config()->get('storage.s3.region'),
            'credentials' => [
            'key' => $this->getEnv('AWS_ACCESS_KEY_ID'),
            'secret' => $this->getEnv('AWS_SECRET_KEY_ID'),
            ],
            ]
        );
        $this->s3Client->registerStreamWrapper();
    }

    /**
     * Backup current branch to AWS, including files and db
     *
     * @param  array $opt
     * @option $force Ignore config and force uploading the current environment
     *
     * @throws \Exception
     */
    public function backupBranch($opt = [
    'force|f' => false,
    ]
    ): void
    {

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
            "Successfully backed up: $prefix",
            [],
            [
            'level' => 'info',
            ]
        );
    }

    /**
     * @throws \Exception
     */
    private function validateEnvVars(): void
    {
        $variables = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_KEY_ID',
        'SENTRY_DSN',
        ];

        foreach ($variables as $variable) {
            if (!$this->getEnv($variable)) {
                throw new Exception(sprintf('Environment variable %s missing', $variable));
            }
        }
    }

    /**
     * @param bool $forced
     *
     * @return bool
     */
    private function backupCurrentBranch(bool $forced): bool
    {
        if ($forced) {
            return true;
        }
        // look for env var and in PLATFORM_VARIABLES
        if ($this->getEnv('BACKUP_THIS_BRANCH')) {
            return true;
        }

        return $this->pshConfig->branch === self::VCS_MASTER;
    }

    /**
     * Remove remote backup files that are older than storage.backup.max_age
     * (defaults to 5 days).
     */
    private function cleanupRemote(): void
    {
        $dir = "s3://" . implode(
            '/',
            [
            Robo::Config()->get('storage.s3.upload_bucket'),
            Robo::Config()->get('drush.alias_group'),
            ]
        );

        if (!is_dir($dir)) {
            return;
        }

        $dir_handle = opendir($dir);

        if (!$dir_handle) {
            return;
        }

        $removedFolders = [];

        while (($file = readdir($dir_handle)) !== false) {
            if (strpos($file, $this->projectPrefix) !== 0) {
                continue;
            }

            $dirPath = $dir . '/' . $file;

            if (is_dir($dirPath) && $this->isBackupOutdated($dirPath)) {
                $removedFolders[] = $dirPath;
                unlink($dirPath);
            }
        }

        closedir($dir_handle);

        if ($removedFolders) {
            $this->sentryClient->captureMessage(
                "Cleanup, folders removed: " . implode(', ', $removedFolders),
                [],
                [
                'level' => 'info',
                ]
            );
        }
    }

    /**
     * @param string $dirPath
     *
     * @return bool
     */
    private function isBackupOutdated(string $dirPath): bool
    {
        $lastModified = $this->getLastModifiedFromFolder($dirPath);

        return time() - $lastModified >= (int) Robo::Config()
        ->get('storage.backup.max_age');
    }

    /**
     * @param string $folderName
     *
     * @return int
     */
    private function getLastModifiedFromFolder(string $folderName): int
    {
        $fileParts = explode(self::FILE_DELIMITER, $folderName);
        $datetime = array_pop($fileParts);

        if (!$datetime) {
            return 0;
        }

        return (int) strtotime($datetime);
    }

    /**
     * Upload the database.
     *
     * @param string $prefix
     */
    private function dbDumpAndUpload(string $prefix): void
    {
        $fileName = $prefix . self::DB_DUMP_SUFFIX . '.sql.gz';
        $pathToFile = implode(
            '/',
            [
            $this->pshConfig->appDir,
            trim(Robo::Config()->get('platform.mounts.temp'), '/'),
            $fileName,
            ]
        );
        $objectKey = implode(
            '/',
            [
            Robo::Config()->get('drush.alias_group'),
            $prefix,
            $fileName,
            ]
        );

        try {
            $drushPath = Robo::Config()->get('drush.path');
            $this->_exec("{$drushPath} sql:dump | gzip > {$pathToFile}");

            $this->s3Client->putObject(
                [
                'Bucket' => Robo::Config()->get('storage.s3.upload_bucket'),
                'Key' => $objectKey,
                'Body' => fopen($pathToFile, 'r'),
                ]
            );

            unlink($pathToFile);
            $this->sentryClient->captureMessage(
                "DB backed up in: " . $objectKey,
                [],
                [
                'level' => 'info',
                ]
            );
        } catch (Exception $e) {
            $this->sentryClient->captureMessage(
                "Database backup: " . $e->getMessage(),
                [],
                [
                'level' => 'error',
                ]
            );
        }
    }

    /**
     * Upload public and private files.
     *
     * @param string $prefix
     */
    private function archiveAndUploadFiles(string $prefix): void
    {
        $paths = [
        'public' => Robo::Config()->get('drupal.public_files_directory') . '/',
        'private' => Robo::Config()->get('drupal.private_files_directory') . '/',
        ];
        $targetFileTemplate = $prefix . self::FILES_DUMP_SUFFIX . '.tar.gz';
        $objectKeyTemplate = implode(
            '/',
            [
            Robo::Config()->get('drush.alias_group'),
            $prefix,
            $targetFileTemplate,
            ]
        );

        try {
            foreach ($paths as $key => $path) {
                $objectKey = sprintf($objectKeyTemplate, $key);
                $targetFile = implode(
                    '/',
                    [
                    $this->pshConfig->appDir,
                    trim(Robo::Config()->get('platform.mounts.temp'), '/'),
                    sprintf($targetFileTemplate, $key),
                    ]
                );
                $directory = implode(
                    '/',
                    [
                    // no app dir, for the export will work with relative paths
                    trim($path, '/'),
                    '', // have a trailing slash
                    ]
                );
                $excludes = '--exclude=' . implode(
                    ' --exclude=',
                    Robo::Config()
                    ->get('drupal.excludes')
                );
                // Tar: excludes first, create tar.
                // Pipe to gzip, otherwise error on platform.sh: "tar: z: Cannot open: Read-only file system"
                $this->_exec("tar {$excludes} -c {$directory} | gzip > {$targetFile}");

                $this->s3Client->putObject(
                    [
                    'Bucket' => Robo::Config()->get('storage.s3.upload_bucket'),
                    'Key' => $objectKey,
                    'Body' => fopen($targetFile, 'r'),
                    ]
                );

                unlink($targetFile);
                $this->sentryClient->captureMessage(
                    "{$key}-files backed up in: " . $objectKey,
                    [],
                    [
                    'level' => 'info',
                    ]
                );
            }
        } catch (Exception $e) {
            $this->sentryClient->captureMessage(
                "Files backup: " . $e->getMessage(),
                [],
                [
                'level' => 'error',
                ]
            );
        }
    }
}
