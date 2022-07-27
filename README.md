# Platform.sh Toolbelt


[![Build Status](https://travis-ci.com/wearewondrous/psh-toolbelt.svg?branch=master)](https://travis-ci.com/wearewondrous/psh-toolbelt)

Make the Drupal 9 installation highly configurable using:

- Robo
- Environment Variables 

## Todos

- create task to recover a backup
- allow switching from AWS to Google cloud
- create packagist entry
- write documentation for each Task

## Installation

```bash
  $ composer require wearewondrous/psh-toolbelt
```

After you configured your environment with a `robo.yml` (described below). you can run:

```bash
  $ vendor/bin/psh-toolbelt
```

and see all available commands.

## Configuration

### `sites/default/settings.php` (required)

Overwrite the `sites/default/settings.php` with the given two includes.

```php
<?php
// Default Drupal 9 settings.
//
// These are already explained with detailed comments in Drupal's
// default.settings.php file.
//
// See https://api.drupal.org/api/drupal/sites!default!default.settings.php/9
// customized project settings
include $app_root . '/../vendor/wearewondrous/psh-toolbelt/src/site.settings.php';
// Local settings. These come last so that they can override anything.
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
```

### services

If your project needs to overwrite the default services use a file called local.services.yml

### `robo.yml.dist` (required)

Copy over the default config from the `robo.yml.dist` in the project root, and name it `robo.yml`.
All paths given are relative to project root. No trailing slashes.
File `wearewondrous/psh-toolbelt/robo.yml.dist` contents:

```yaml
storage:
  backup:
    max_age: 432000         # 60 * 60 * 24 * 5
  s3:
    version: new-latest
    region: eu-west-1
    upload_bucket: backups
platform:
  host: eu.platform.sh
  domain: my-website.com
  mounts:
    temp: tmp
    config: remote-config
drupal:
  hash_salt: 1234567890abcdefghijklmnopqlmnopqrstuvwxyz12345678
  config_sync_directory: config/drupal/default
  public_files_directory: web/sites/default/files
  private_files_directory: private
  excludes:
    - js
    - css
    - styles
    - translations
    - languages
    - config
  config:
    splits:
      default:
        machine_name: default
        folder: default
      prod:
        machine_name: production
        folder: prod
      dev:
        machine_name: development
        folder: dev
drush:
  alias_group: my-website
  alias: local
  path: vendor/bin/drush
lando:
  disable_cache: true
  host: my-website.lndo.site
  mysql:
    database: drupal
    hostname: 127.0.0.1
    password: drupal
    port: 3306
    user: drupal
```

Copy it in your root folder and rename it to `robo.yml`. Adjust to your needs. Normally, you only need to set it like this:

```yaml
platform:
  domain: drupal-rocks.com
  host: eu.platform.sh
drush:
  alias_group: drupalrocks
lando:
  hash_salt: 1234567890abcdefghijklmnopqlmnopqrstuvwxyz12345678
  host: my-website.lndo.site
```

### `sites/default/settings.php` (required)

See an example for a `sites/default/settings.php`, have a look at [platformsh-template/drupal9](https://github.com/platformsh-templates/drupal9/blob/master/web/sites/default/settings.php).
Before the include of the `settings.local.php` add the following:

```php
// customized project settings
include $app_root . '/../vendor/wearewondrous/psh-toolbelt/src/site.settings.php';
```

### Platform.sh Variables (required for backup-task)

The following variables are required to have the backup task working:

```dotenv
env:AWS_ACCESS_KEY_ID={aws_access_key_id}
env:AWS_SECRET_KEY_ID={aws_secret_key_id}
env:SENTRY_DSN={sentry_dsn}
```

Optionally, to backup a branch that is not `master`, add this to the platform.sh variables of the desired branch.

```dotenv
env:BACKUP_THIS_BRANCH=1
```

Note: optionally, the variables can live as [actual environment variables](https://docs.platform.sh/development/variables.html#top-level-environment-variables) or as [Platform.sh variables](https://docs.platform.sh/development/variables.html#environment-variables). 

### `composer.json` and `.env` (optional)

For local development and tests with env vars, add to your root project `composer.json`:

```json
{
  "autoload": {
    "files": ["vendor/wearewondrous/psh-toolbelt/load.environment.php"]
  }
}
```

Then copy `wearewondrous/psh-toolbelt/.env.dist` to the root of you project and rename it to `.env`.
Exclude this file from your vcs. Do This only, if you want to mock production vars.

```dotenv
### Platform.sh VARS ############
#PLATFORMSH_CLI_TOKEN=
#PLATFORM_APP_DIR=/app
#PLATFORM_PROJECT=
#PLATFORM_ENVIRONMENT=
#PLATFORM_DOCUMENT_ROOT=/app/web
#PLATFORM_BRANCH=master

### Required VARS ###############
# AWS config
AWS_ACCESS_KEY_ID=
AWS_SECRET_KEY_ID=
# Logging
SENTRY_DSN=
```

### Troubleshooting

Make sure to have the following mounts in your `platform.app.yaml`. 
Otherwise you will run in errors on the server, like `Could not create directory '/app/.ssh'.`

```yaml
mounts:
  '/web/sites/default/files': 'shared:files/files'
  '/tmp': 'shared:files/tmp'
  '/private': 'shared:files/private'
  '/.drush': 'shared:files/.drush'
  '/remote-config': 'shared:files/remote-config'
```
