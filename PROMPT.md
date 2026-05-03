# ZoeCloud — WordPress Backup Plugin (Product Prompt)

## Goal

Build a professional WordPress plugin called **ZoeCloud** that allows users to:

- Create full backups of their WordPress site (files + database)
- Upload backups automatically to Google Drive
- Download a portable backup file
- Restore a site on a new WordPress installation using that file

The plugin must be scalable, modular, and designed to evolve into a SaaS platform.

---

## Core Features

### 1. Full Backup System

The plugin must generate a complete backup including:

- `/wp-content/` directory
- WordPress core files (optional)
- Database dump (MySQL)
- Configuration metadata (JSON)

Backup output format:

backup-{domain}-{YYYY-MM-DD-HH-mm}.zip

Zip structure:

/files/
/database.sql
/manifest.json

---

### 2. Google Drive Integration

Implement integration with Google Drive API using OAuth 2.0.

Requirements:

- Secure authentication flow
- Token storage in WordPress options (encrypted)
- Automatic folder creation

Drive structure:

/ZoeCloud Backups/
/{UserName or Project}/
/{domain.com}/
backup-2026-05-02.zip

Behavior:

- If folders do not exist → create them
- Upload backups automatically after generation
- Support resumable uploads

---

### 3. Downloadable Backup (Portable)

User must be able to:

- Download backup ZIP locally
- Use it independently from Google Drive

---

### 4. Restore System

#### Mode A: Inside WordPress plugin

- Upload backup ZIP
- Validate structure
- Restore database and files
- Update URLs (search & replace)

#### Mode B: Fresh WordPress install

- Upload ZIP
- Run restoration wizard
- Rebuild site fully

---

## Technical Architecture

### Backend

- PHP (WordPress standards)
- WP_Filesystem API
- WPDB

### Frontend

- React or Vanilla JS
- WordPress REST API

---

## Background Processing

Use:

- WP Cron or background queue

Steps:

1. Initialize job
2. Process files in chunks
3. Generate ZIP
4. Upload to Drive

---

## 📦 Performance

- Handle large sites
- Avoid memory limits
- Stream ZIP if possible

---

## Security

- Nonces
- Capability checks
- Secure OAuth tokens
- Validate files before restore

---

## UX

- Dashboard (status + create backup)
- Backups list (download + restore)
- Settings (Drive + schedule)

---

## Backup Strategy

- Manual + scheduled backups
- Retention limits

---

## Future Scalability

- Multi-cloud (R2, S3)
- SaaS dashboard
- Multi-site
- Incremental backups

---

## Expected Output

- Plugin structure
- BackupManager
- DriveService
- RestoreManager
- REST API endpoints

---

## Naming

- ZoeCloud
- Prefix: zoecloud\_
- Classes: ZoeCloud_Backup_Manager

---

## End Goal

A production-ready plugin ready to scale into a SaaS platform.
