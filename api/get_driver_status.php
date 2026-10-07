<?php
require_once __DIR__ . '/bootstrap.php';
$driverId = require_role(['driver']);

try {
    $stmt = $conn->prepare(
        "SELECT t.trip_id, t.started_at, t.distance_km, r.est_minutes, m.capacity,
                m.seat_status, r.route_name, r.origin, r.destination
           FROM trips t
           JOIN minibuses m ON m.minibus_id = t.minibus_id
           JOIN routes r ON r.route_id = t.route_id
          WHERE t.driver_id = ? AND t.status = 'active'
          ORDER BY t.started_at DESC, t.trip_id DESC
          LIMIT 1");
    $stmt->execute([$driverId]);
    $row = $stmt->fetch();

    if (!$row) out(true, 'No active trip.', ['trip' => null]);

    out(true, 'Active trip found.', ['trip' => [
        'trip_id'     => (int)$row['trip_id'],
        'started_at'  => $row['started_at'],
        'distance_km' => $row['distance_km'] === null ? null : (float)$row['distance_km'],
        'est_minutes' => $row['est_minutes'] === null ? null : (int)$row['est_minutes'],
        'capacity'    => $row['capacity'] === null ? null : (int)$row['capacity'],
        'seat_status' => $row['seat_status'],
        'route_name'  => $row['route_name'],
        'origin'      => $row['origin'],
        'destination' => $row['destination'],
    ]]);
} catch (Throwable $e) {
    fail_server($e, 'Unable to read trip status.');
}
