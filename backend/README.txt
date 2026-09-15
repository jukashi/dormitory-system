OFW Dormitory System legacy backend

The JSON backend in this directory is retained only as historical source and is
disabled for web requests. The live application uses the page-level PHP files,
app/config/database.php, and the role/permission system.

Database settings are shared with the live application. Copy .env.example to
.env in the project root and configure DB_HOST, DB_PORT, DB_NAME, DB_USER, and
DB_PASSWORD. The canonical database name is ofw_dormitory_system.
