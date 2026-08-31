# ZoeCloud 1.0

ZoeCloud is a WordPress 6.4+ backup and recovery plugin for PHP 8.1+. It creates checksummed v2 archives, runs work through durable database-backed jobs, and supports optional Cloudflare R2 and Amazon S3 storage.

## Architecture

The runtime is separated into:

- private storage with opaque archive keys;
- backup and job repositories backed by dedicated WordPress tables;
- staged backup, upload, cloud-download, and restore runners;
- strict manifest, ZIP, checksum, SQL, and path validation;
- authenticated REST resources based on UUIDs;
- WP-CLI commands for automation and recovery.

Restore always requires a verified full safety backup. Database content is imported into auxiliary tables before an atomic exchange. Replaced files are recorded in a private rollback journal.

## Development

Install dependencies and the WordPress test library:

```bash
composer install
composer test:install
```

The installer supports these environment variables:

```text
WP_VERSION=latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib
WP_TESTS_DB_NAME=wordpress_test
WP_TESTS_DB_USER=root
WP_TESTS_DB_PASS=
WP_TESTS_DB_HOST=localhost
```

Run the quality suite:

```bash
composer lint:php
composer analyse
composer test
composer audit
```

Build the WordPress.org distribution:

```bash
composer package
```

The generated archive is `dist/zoe-cloud-1.0.0.zip`. It excludes tests, development dependencies, CI configuration, caches, and internal tooling.

## System cron

WP-Cron remains the trigger and is automatically reconciled. Sites that disable or cannot reliably trigger WP-Cron should invoke:

```bash
wp zoecloud jobs run
```

## Recovery

```bash
wp zoecloud doctor
wp zoecloud backup list
wp zoecloud backup verify <backup-id>
wp zoecloud restore <backup-id> --hostname=<current-hostname>
```

Keep the mandatory safety backup until the restored site has been fully verified.

## Privacy and external services

ZoeCloud has no telemetry or automatic external connections. Optional R2/S3 requests occur only after administrator configuration and an explicit action or enabled scheduled cloud upload. Backup archives can contain all site files and database data; use narrowly scoped cloud credentials and appropriate retention policies.

See [readme.txt](readme.txt) for the complete WordPress.org disclosure and recovery procedure.
