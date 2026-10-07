<?php
// Configure DB_DRIVER=pgsql and SUPABASE_DB_* for Supabase.
// The default keeps a local XAMPP MySQL database available during migration.
$driver = strtolower(getenv('DB_DRIVER') ?: 'mysql');
if (!defined('DB_DRIVER')) {
    define('DB_DRIVER', $driver);
}

try {
    if ($driver === 'pgsql') {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PHP pdo_pgsql extension is not enabled.');
        }

        $host = getenv('SUPABASE_DB_HOST') ?: '';
        $port = getenv('SUPABASE_DB_PORT') ?: '5432';
        $name = getenv('SUPABASE_DB_NAME') ?: 'postgres';
        $user = getenv('SUPABASE_DB_USER') ?: 'postgres';
        $pass = getenv('SUPABASE_DB_PASSWORD') ?: '';
        if ($host === '' || $pass === '') {
            throw new RuntimeException('Supabase database connection settings are incomplete.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            $host,
            $port,
            $name
        );
        $conn = new PDO($dsn, $user, $pass);
        $conn->exec("SET TIME ZONE 'Asia/Manila'");
    } elseif ($driver === 'mysql') {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PHP pdo_mysql extension is not enabled.');
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: 'smart_minibus';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
        $conn = new PDO($dsn, $user, $pass);
    } else {
        throw new RuntimeException('DB_DRIVER must be either mysql or pgsql.');
    }

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed.');
}
