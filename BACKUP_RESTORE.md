# Backup and restore — OFW Dormitory System

Keep backups outside `htdocs` so nobody can download them through the browser. This guide uses `C:\xampp\backups`.

## Backup

1. Create `C:\xampp\backups` if it does not yet exist.
2. Open **Command Prompt**.
3. Run the following, changing the date in the filename:

```bat
C:\xampp\mysql\bin\mysqldump.exe -u root ofw_dormitory_system > C:\xampp\backups\ofw_dormitory_2026-08-20.sql
```

If the MySQL `root` account has a password, use `-p` after `root`; MySQL will ask for it without showing it on screen.

Back up the tenant images too by copying this folder to the same backup location:

```text
C:\xampp\htdocs\dormitory-system\uploads\tenants
```

## Restore

1. Stop people from using the system while restoring.
2. Create a fresh backup first.
3. In Command Prompt, run:

```bat
C:\xampp\mysql\bin\mysql.exe -u root ofw_dormitory_system < C:\xampp\backups\ofw_dormitory_2026-08-20.sql
```

4. Restore the backed-up `uploads\tenants` folder if tenant photographs are needed.
5. Log in and verify the dashboard, tenant count, and latest payment before allowing normal use.

Never restore an SQL file from an unknown source.
