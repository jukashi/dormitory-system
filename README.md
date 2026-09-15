# OFW Dormitory System

An offline PHP 8 and MariaDB dormitory-management application for XAMPP. It manages rooms, tenants, payments, visitors, maintenance, schedules, staff permissions, reports, tenant imports, and database backups.

## Requirements

- XAMPP with Apache, PHP 8.0 or newer, and MariaDB 10.4 or newer
- PHP extensions: PDO MySQL, mbstring, fileinfo, SimpleXML, and ZipArchive for `.xlsx` imports
- Apache `mod_rewrite` and permission to use `.htaccess`

## New installation

1. Copy this project folder to `C:\xampp\htdocs\dormitory-system` on the new computer.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin at `http://localhost/phpmyadmin/` and import `database.sql`.
4. Open `http://localhost/dormitory-system/`. With no administrator in the new database, the system automatically opens the one-time setup page.
5. Enter the administrator's name, username, and a password containing at least 10 characters. The setup page locks automatically after this account is created.
6. Complete `TEST_CHECKLIST.md` before entering production data.

`database.sql` is the complete clean-install schema. It intentionally contains no administrator credentials, tenants, rooms, payments, visitors, maintenance records, schedules, or uploaded files. Do not import files from `migrations` after importing `database.sql`; those files are only for upgrading older installations.

The default local database is `ofw_dormitory_system` with XAMPP's `root` user and a blank password. Override these values without editing source by setting `DORMITORY_DB_HOST`, `DORMITORY_DB_NAME`, `DORMITORY_DB_USER`, and `DORMITORY_DB_PASS` in the Apache environment.

## Existing installation

Apply each not-yet-applied SQL file in `migrations` in phase order. Back up the database first; migrations are intended to run once. The current schema includes phases 9 through 19.

## Operations

- Database backups are available under Administrator Control.
- Tenant photographs are stored separately under `uploads/tenants` and must be included in filesystem backups.
- See `BACKUP_RESTORE.md` for backup and restore instructions.
- Keep Apache and the database accessible only to trusted local-network users unless the host is separately hardened for public deployment.
