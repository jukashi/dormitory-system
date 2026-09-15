# Backup and restore — OFW Dormitory System

Keep backups outside the Apache document root and restrict access to them.

## Backup

Create a database dump using the application database account. Enter the password only at the interactive prompt.

```bash
mkdir -p "$HOME/dormitory-backups"
mysqldump -h 127.0.0.1 -u dormitory_user -p \
  --single-transaction --routines --triggers \
  ofw_dormitory_system > "$HOME/dormitory-backups/ofw_dormitory_system_YYYY-MM-DD.sql"
```

Also back up `uploads/company` and `uploads/tenants` so company and tenant images can be restored with the database.

## Restore

1. Stop users from changing data during the restore.
2. Back up the current database and uploads first.
3. Restore the selected dump:

```bash
mysql -h 127.0.0.1 -u dormitory_user -p \
  ofw_dormitory_system < "$HOME/dormitory-backups/ofw_dormitory_system_YYYY-MM-DD.sql"
```

4. Restore the matching upload directories.
5. Verify the dashboard, tenant count, latest payment, and a protected image before reopening the system.

Never restore an SQL file from an unknown source.
