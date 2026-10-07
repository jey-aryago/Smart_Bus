<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "Enable the PHP mysqli extension before migration.\n");
    exit(1);
}

$source = new mysqli(
    getenv('MYSQL_HOST') ?: 'localhost',
    getenv('MYSQL_USER') ?: 'root',
    getenv('MYSQL_PASS') ?: '',
    getenv('MYSQL_NAME') ?: 'smart_minibus'
);
$source->set_charset('utf8mb4');

$tables = [
    'users' => ['user_id', 'first_name', 'last_name', 'email', 'password', 'role', 'phone', 'profile_image', 'status', 'email_verified', 'phone_verified', 'created_at', 'updated_at'],
    'routes' => ['route_id', 'route_name', 'origin', 'destination', 'distance_km', 'est_minutes', 'status', 'origin_lat', 'origin_lng', 'dest_lat', 'dest_lng', 'created_at'],
    'route_stops' => ['stop_id', 'route_id', 'stop_name', 'latitude', 'longitude', 'stop_sequence', 'created_at'],
    'minibuses' => ['minibus_id', 'bus_number', 'plate_number', 'capacity', 'seat_status', 'status', 'driver_id', 'route_id', 'created_at'],
    'trips' => ['trip_id', 'driver_id', 'minibus_id', 'route_id', 'status', 'started_at', 'ended_at', 'distance_km', 'avg_speed_kmh'],
    'driver_locations' => ['location_id', 'driver_id', 'trip_id', 'minibus_id', 'route_id', 'latitude', 'longitude', 'accuracy', 'speed', 'heading', 'recorded_at'],
    'locations' => ['location_id', 'user_id', 'latitude', 'longitude', 'speed', 'recorded_at'],
    'commuter_pickups' => ['commuter_id', 'route_id', 'stop_id', 'updated_at'],
    'schedules' => ['schedule_id', 'route_id', 'minibus_id', 'departure_time', 'days_of_week', 'status', 'created_at'],
    'complaints' => ['complaint_id', 'user_id', 'complaint_type', 'route_id', 'subject', 'message', 'admin_response', 'status', 'created_at', 'updated_at'],
    'notifications' => ['notification_id', 'title', 'message', 'audience', 'sent_by', 'recipient_id', 'route_id', 'created_at'],
    'notification_reads' => ['notification_id', 'user_id', 'read_at'],
    'verification_codes' => ['verification_id', 'user_id', 'email_code', 'phone_code', 'email_expires_at', 'phone_expires_at', 'created_at'],
];

$identityColumns = [
    'users' => 'user_id',
    'routes' => 'route_id',
    'route_stops' => 'stop_id',
    'minibuses' => 'minibus_id',
    'trips' => 'trip_id',
    'driver_locations' => 'location_id',
    'locations' => 'location_id',
    'schedules' => 'schedule_id',
    'complaints' => 'complaint_id',
    'notifications' => 'notification_id',
    'verification_codes' => 'verification_id',
];

$counts = [];
foreach ($tables as $table => $columns) {
    $result = $source->query("SELECT COUNT(*) AS row_count FROM `$table`");
    $counts[$table] = (int)$result->fetch_assoc()['row_count'];
}

if (!in_array('--apply', $argv, true)) {
    echo "Dry run only. No data was copied.\n";
    foreach ($counts as $table => $count) {
        echo sprintf("%-22s %d rows\n", $table, $count);
    }
    echo "\nApply the schema in 002_supabase_schema.sql, verify the target is empty, then rerun with --apply.\n";
    exit(0);
}

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Enable the PHP pdo_pgsql extension before copying data.\n");
    exit(1);
}

$host = getenv('SUPABASE_DB_HOST') ?: '';
$password = getenv('SUPABASE_DB_PASSWORD') ?: '';
if ($host === '' || $password === '') {
    fwrite(STDERR, "Set SUPABASE_DB_HOST and SUPABASE_DB_PASSWORD locally before copying data.\n");
    exit(1);
}

$port = getenv('SUPABASE_DB_PORT') ?: '5432';
$database = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$username = getenv('SUPABASE_DB_USER') ?: 'postgres';
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
    $host,
    $port,
    $database
);
$target = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$target->exec("SET TIME ZONE 'Asia/Manila'");

foreach (array_keys($tables) as $table) {
    $count = (int)$target->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
    if ($count !== 0) {
        fwrite(STDERR, "Target table public.$table is not empty; migration stopped without changing data.\n");
        exit(1);
    }
}

try {
    $target->beginTransaction();

    foreach ($tables as $table => $columns) {
        if ($counts[$table] === 0) {
            continue;
        }

        $quotedColumns = array_map(static fn(string $column): string => '"' . $column . '"', $columns);
        $sql = 'INSERT INTO public."' . $table . '" (' . implode(', ', $quotedColumns) . ') VALUES (' .
            implode(', ', array_fill(0, count($columns), '?')) . ')';
        $insert = $target->prepare($sql);
        $select = $source->query('SELECT `' . implode('`, `', $columns) . '` FROM `' . $table . '`');

        while ($row = $select->fetch_assoc()) {
            foreach (['email_verified', 'phone_verified'] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $row[$flag] = (int)$row[$flag];
                }
            }
            $insert->execute(array_values($row));
        }
        $insert->closeCursor();
        printf("Copied %s (%d rows)\n", $table, $counts[$table]);
    }

    foreach ($identityColumns as $table => $column) {
        $target->exec(
            "SELECT setval(pg_get_serial_sequence('public.$table', '$column'), " .
            "GREATEST(COALESCE(MAX(\"$column\"), 1), 1), COUNT(*) > 0) FROM public.\"$table\""
        );
    }

    $target->commit();
    echo "Migration completed. Existing PHP password hashes were preserved.\n";
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Migration failed and was rolled back: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    $source->close();
}
