# OFW Dormitory System — XAMPP Backend

This is the BACKEND-FIRST stage of the migration from the original offline HTML storage to PHP + MySQL.

## Stack

- XAMPP
- Apache
- PHP 8+
- MySQL / MariaDB
- PDO
- PHP sessions
- JSON REST API

## Important

The MySQL database/schema is intentionally NOT included in this package yet.

The next step is to create the database and tables from the fields used by the original OFW Dormitory System.

## Install backend

1. Install XAMPP.
2. Start Apache.
3. Start MySQL.
4. Create this folder:

   C:\xampp\htdocs\ofw-dormitory\backend

5. Copy:
   - config.php
   - api\api.php
   - api\.htaccess

6. Backend URL will be:

   http://localhost/ofw-dormitory/backend/api/

## Current API endpoints

### Authentication

POST /auth/login
POST /auth/logout
GET  /auth/me

### Dashboard

GET /dashboard

### CRUD

GET/POST       /tenants
GET/PUT/DELETE /tenants/{id}

GET/POST       /payments
GET/PUT/DELETE /payments/{id}

GET/POST       /visitors
GET/PUT/DELETE /visitors/{id}

GET/POST       /maintenance
GET/PUT/DELETE /maintenance/{id}

GET/POST       /schedules
GET/PUT/DELETE /schedules/{id}

GET/POST       /dormitories
GET/PUT/DELETE /dormitories/{id}

GET/POST       /rooms
GET/PUT/DELETE /rooms/{id}

GET/POST       /employers
GET/PUT/DELETE /employers/{id}

GET/POST       /agencies
GET/PUT/DELETE /agencies/{id}

GET/POST       /settings
GET/PUT/DELETE /settings/{id}

### Schedule comments

GET  /schedules/{id}/comments
POST /schedules/{id}/comments

### Staff administration

POST /staff/create
POST /staff/toggle/{id}

### Shift CSV import

POST /shift-import

## Database credentials

Edit config.php if your XAMPP MySQL settings differ:

DB_HOST = 127.0.0.1
DB_PORT = 3306
DB_NAME = ofw_dormitory
DB_USER = root
DB_PASS = ''

## Security

Passwords are NOT stored in plaintext by the backend. Staff passwords are stored using PHP password_hash() and verified with password_verify().

Do not expose config.php or commit database passwords to GitHub.

## What comes next

After the backend is placed in XAMPP, the next stage is:

1. Create MySQL database `ofw_dormitory`.
2. Create all tables and indexes.
3. Insert the initial administrator account.
4. Test `/health`.
5. Connect the new frontend to these API endpoints.
6. Remove the old localStorage/window.storage database code.
