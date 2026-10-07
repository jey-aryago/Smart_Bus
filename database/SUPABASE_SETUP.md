# Supabase database setup

The PHP application connects directly to Supabase PostgreSQL. It does not use
the browser anon key or the service-role key, and PHP sessions/password hashes
remain unchanged.

## 1. Enable PostgreSQL support in XAMPP

Open the `php.ini` used by XAMPP and enable `pdo_pgsql`:

```ini
extension=pdo_pgsql
```

The XAMPP PHP installation already includes `php_pdo_pgsql.dll`. Restart
Apache, then confirm `pdo_pgsql` appears in `php -m`.

## 2. Set private connection settings

In the Supabase dashboard, open **Connect** and choose **Session pooler**. If
the connection-method menu only shows Direct connection, open the project's
Connect dialog with the `method=session` option, or go to
**Project Settings → Database → Connection pooling** and copy its Session
pooler connection details. Do not guess the pooler host: copy it exactly from
Supabase. Direct connections are IPv6-only on projects without an IPv4
add-on; this setup uses the IPv4 Session pooler.

Set the values from the Session pooler connection string in Windows **System**
environment variables (so the Apache service can read them), then restart
Apache. For a shared pooler, use the full pooler username Supabase displays
(usually `postgres.<project-ref>`), not the direct-connection username:

```text
SUPABASE_DB_HOST=<host from Connect>
SUPABASE_DB_PORT=<port from Connect>
SUPABASE_DB_NAME=postgres
SUPABASE_DB_USER=<user from Connect>
SUPABASE_DB_PASSWORD=<database password>
```

Do not put the database password, anon key, or service-role key in source
files, the browser, or chat. Restart Apache after changing environment
variables. The project URL alone is not a PostgreSQL connection string.

## 3. Create the schema

In the Supabase dashboard, open **SQL Editor** and run
[`migrations/002_supabase_schema.sql`](./migrations/002_supabase_schema.sql)
against a new, empty project database. The tables have row-level security
enabled and no client policies; the PHP server uses its private PostgreSQL
connection.

## 4. Copy the existing MySQL data

Keep the local MySQL database intact as a backup. From the project directory,
run a dry run first; this only reads MySQL and does not require Supabase
credentials:

```powershell
C:\xampp\php\php.exe .\database\migrate_mysql_to_supabase.php
```

Review the table row counts. The script does not print account data or
password hashes. After confirming the counts and applying the schema, set the `SUPABASE_DB_*`
variables above locally. If needed, set `MYSQL_HOST`, `MYSQL_USER`,
`MYSQL_PASS`, and `MYSQL_NAME` for the source database. Confirm that every
destination table is empty, then copy the rows with:

```powershell
C:\xampp\php\php.exe .\database\migrate_mysql_to_supabase.php --apply
```

The migration preserves existing PHP password hashes and user IDs. It skips
the removed commuter-location sharing table. The `--apply` run refuses to
write if any destination table already contains rows and rolls back on errors.

## 5. Switch the application

Only after the data-copy script completes, set `DB_DRIVER=pgsql` for Apache
and restart it. Keep the MySQL database available until login, commuter,
driver, and admin workflows have all been verified against Supabase.
