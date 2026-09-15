# OFW Dormitory System

A custom PHP 8 and MySQL 8 dormitory-management application. It manages rooms, tenants, payments, visitors, maintenance, schedules, staff permissions, reports, tenant imports, and database backups.

## Requirements

- Apache with `mod_rewrite` and `mod_headers`
- PHP 8.0 or newer with PDO MySQL, mbstring, fileinfo, SimpleXML, and ZipArchive
- MySQL 8.0.16 or newer

## Database configuration

Copy `.env.example` to `.env`, then replace the example username and password. The application reads these values directly; real credentials must never be committed.

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ofw_dormitory_system
DB_USER=dormitory_user
DB_PASSWORD=YOUR_SECURE_PASSWORD
```

Server environment variables with the same names take precedence over `.env`.

## New installation on XAMPP

1. Copy the project to `C:\xampp\htdocs\dormitory-system`.
2. Copy `.env.example` to `.env` and enter the local MySQL credentials.
3. Start Apache and MySQL.
4. Import `database.sql` through phpMyAdmin.
5. Open `http://localhost/dormitory-system/`. With no administrator in the new database, the one-time administrator setup opens automatically.
6. Complete `TEST_CHECKLIST.md` before entering production data.

## Ubuntu Server deployment

Install the required packages:

```bash
sudo apt update
sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-mbstring php-xml php-zip
sudo a2enmod rewrite headers
```

Create the database and restricted application accounts as a MySQL administrator. The `127.0.0.1` account is included because the application uses a TCP connection by default.

```sql
CREATE DATABASE ofw_dormitory_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'dormitory_user'@'localhost'
  IDENTIFIED BY 'YOUR_SECURE_PASSWORD';

CREATE USER 'dormitory_user'@'127.0.0.1'
  IDENTIFIED BY 'YOUR_SECURE_PASSWORD';

GRANT ALL PRIVILEGES ON ofw_dormitory_system.*
  TO 'dormitory_user'@'localhost';

GRANT ALL PRIVILEGES ON ofw_dormitory_system.*
  TO 'dormitory_user'@'127.0.0.1';
```

Clone or copy the repository, configure the environment, and import the clean schema:

```bash
cd /var/www/html
sudo git clone YOUR_GITHUB_REPOSITORY_URL dormitory-system
cd dormitory-system
sudo cp .env.example .env
sudo nano .env
mysql -h 127.0.0.1 -u dormitory_user -p < database.sql
sudo chown -R www-data:www-data uploads
sudo find uploads -type d -exec chmod 750 {} \;
sudo find uploads -type f -exec chmod 640 {} \;
sudo chmod 640 .env
sudo systemctl restart apache2
```

Ensure the Apache virtual-host directory block for the project permits `.htaccess` rules:

```apache
<Directory /var/www/html/dormitory-system>
    AllowOverride All
    Require all granted
</Directory>
```

Open the deployed URL and create the initial administrator. The setup page locks automatically after the first administrator is created.

## Clean schema and upgrades

`database.sql` is the complete clean-install schema for MySQL 8. It contains no administrator credentials or operational records. Do not import files from `migrations` after importing `database.sql`; migrations are only for upgrading older installations and must be applied once, in phase order, after a backup.

## Operations

- Database backups are available under Administrator Control.
- Tenant photographs and the company logo are stored under `uploads` and must be included in filesystem backups.
- See `BACKUP_RESTORE.md` for backup and restore instructions.
- Keep the application accessible only to trusted users unless the server has been separately hardened for public deployment.
