# ZoeCloud

ZoeCloud is a WordPress backup plugin focused on portable backups, safe restores, and cloud storage integrations.

The current implementation supports local backup creation, local downloads, restore validation, restore execution, retention limits, scheduled backups, and cloud uploads to Cloudflare R2 or AWS S3.

## Features

- Create portable WordPress backup ZIP files.
- Include `/wp-content/` by default.
- Optionally include WordPress core files.
- Export the database to `database.sql`.
- Store backup metadata in `manifest.json`.
- Download generated ZIP files locally.
- Restore an existing backup with URL search/replace.
- Preserve ZoeCloud backup records after restore.
- Delete local backups from the dashboard.
- Run manual and scheduled backups.
- Upload backups to Cloudflare R2 or AWS S3.
- Store cloud secrets encrypted in WordPress options.
- Process backup jobs in stages to reduce timeout risk.

## Backup Format

Backups are generated with this filename pattern:

```text
zoe-cloud-backup-{domain}-{YYYY-MM-DD-HH-mm}.zip
```

ZIP structure:

```text
files/
database.sql
manifest.json
```

## Requirements

- WordPress 6.4+
- PHP 7.4+
- PHP `ZipArchive` extension
- Writable WordPress uploads directory
- WP-Cron enabled for scheduled/background jobs
- Outbound internet access for cloud uploads

## Installation

1. Copy the `zoe-cloud` directory into `wp-content/plugins/`.
2. Activate ZoeCloud in the WordPress admin.
3. Open the `ZoeCloud` admin menu.
4. Confirm the preflight checks pass.
5. Configure Cloudflare R2 or AWS S3 if cloud uploads are required.
6. Create a backup from the dashboard.

## Cloud Storage Configuration

ZoeCloud stores cloud backups through S3-compatible APIs. Select the active provider in `ZoeCloud > Storage`.

### Cloudflare R2

No OAuth or redirect URI is required.

Required settings:

- `R2 Account ID`
- `R2 Access Key ID`
- `R2 Secret Access Key`
- `R2 Bucket`
- `R2 Prefix` optional, defaults to `zoe-cloud`

Endpoint format:

```text
https://{account_id}.r2.cloudflarestorage.com
```

S3 region:

```text
auto
```

Recommended Cloudflare setup:

1. Create an R2 bucket.
2. Create S3 API credentials scoped to that bucket.
3. Select `Cloudflare R2` in `ZoeCloud > Storage`.
4. Save the credentials.
5. Enable `Upload to cloud storage` when creating a backup.

### AWS S3

Required settings:

- `S3 Access Key ID`
- `S3 Secret Access Key`
- `S3 Bucket`
- `S3 Region`, for example `us-east-1`
- `S3 Prefix` optional, defaults to `zoe-cloud`

Endpoint format:

```text
https://{bucket}.s3.{region}.amazonaws.com
```

Recommended AWS setup:

1. Create an S3 bucket.
2. Create an IAM user or access key with write permissions for that bucket.
3. Select `AWS S3` in `ZoeCloud > Storage`.
4. Save the credentials, bucket, region, and optional prefix.
5. Enable `Upload to cloud storage` when creating a backup.

## Backup Workflow

The staged backup runner performs these steps:

1. Initialize backup job.
2. Export database tables in batches.
3. Scan files into a durable file list.
4. Add files to the ZIP in batches.
5. Add `database.sql` and `manifest.json`.
6. Store a local backup record.
7. Upload to the selected cloud provider when enabled.
8. Clean temporary files.

## Restore Workflow

The restore system can:

- Select an existing backup.
- Validate ZIP structure before restore.
- Read origin metadata from `manifest.json`.
- Restore files and database tables.
- Replace source URLs with the current target URL.
- Preserve ZoeCloud backup records after restore.

Restore requires explicit confirmation because it can overwrite site files and database tables.

## Security

ZoeCloud currently applies:

- `manage_options` capability checks.
- WordPress nonces for admin actions.
- REST nonce validation.
- Encrypted storage for cloud secrets.
- ZIP path traversal validation during restore.
- Basic direct-access protection for the local backup directory.

Use cloud credentials with the narrowest bucket permissions possible.

## Current Limitations

- Cloud restore from R2/S3 is not implemented yet; backups are restored from local records/files.
- Manual upload of external ZIP files for restore is not implemented yet.
- Incremental backups are not implemented yet.

## Roadmap

- Restore from manually uploaded ZIP.
- Download/import backups from cloud providers.
- Additional S3-compatible providers beyond R2 and AWS S3.
- Google Drive via OAuth broker.
- Incremental backups.
- SaaS dashboard.
- Multisite support.
