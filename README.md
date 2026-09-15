# OFW Dormitory System

An offline PHP 8 and MariaDB dormitory-management application for XAMPP. It manages rooms, tenants, payments, visitors, maintenance, schedules, staff permissions, reports, tenant imports, and database backups.

## Requirements

- XAMPP with Apache, PHP 8.0 or newer, and MariaDB 10.4 or newer
- PHP extensions: PDO MySQL, mbstring, fileinfo, SimpleXML, and ZipArchive for `.xlsx` imports
- Apache `mod_rewrite` and permission to use `.htaccess`

## New installation

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Import `database.sql` through phpMyAdmin.
3. Open `http://localhost/dormitory-system/`.
4. Follow the first-time setup link to create the initial administrator.
5. Complete `TEST_CHECKLIST.md` before entering production data.

The default local database is `ofw_dormitory_system` with XAMPP's `root` user and a blank password. Override these values without editing source by setting `DORMITORY_DB_HOST`, `DORMITORY_DB_NAME`, `DORMITORY_DB_USER`, and `DORMITORY_DB_PASS` in the Apache environment.

## Existing installation

Apply each not-yet-applied SQL file in `migrations` in phase order. Back up the database first; migrations are intended to run once. The current schema includes phases 9 through 19.

## Operations

- Database backups are available under Administrator Control.
- Tenant photographs are stored separately under `uploads/tenants` and must be included in filesystem backups.
- See `BACKUP_RESTORE.md` for backup and restore instructions.
- Keep Apache and the database accessible only to trusted local-network users unless the host is separately hardened for public deployment.
