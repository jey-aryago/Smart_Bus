<?php
// Returns every driver with fresh GPS (<= 2 min) and an active minibus.
//  - locations : array of all drivers   (new)
//  - location  : newest single driver   (kept so older dashboard code still works)
require_once __DIR__ . '/bootstrap.php';
require_role(['commuter', 'driver', 'admin']);

try {
    $ageExpression = DB_DRIVER === 'pgsql'
        ? 'EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - dl.recorded_at))::integer'
        : 'TIMESTAMPDIFF(SECOND, dl.recorded_at, NOW())';
    $freshLocationFilter = DB_DRIVER === 'pgsql'
        ? "recorded_at >= (CURRENT_TIMESTAMP - INTERVAL '2 minutes')"
        : 'recorded_at >= (NOW() - INTERVAL 2 MINUTE)';
    $res = $conn->query(
        "SELECT dl.location_id, dl.driver_id, dl.minibus_id, dl.route_id, dl.trip_id,
                dl.latitude, dl.longitude, dl.accuracy, dl.speed, dl.heading, dl.recorded_at,
                $ageExpression AS age_seconds,
                CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS driver_name,
                m.bus_number, m.plate_number, m.capacity, m.seat_status,
                r.route_name, r.origin, r.destination, r.est_minutes,
                r.dest_lat, r.dest_lng, t.started_at
           FROM driver_locations dl
           JOIN (SELECT MAX(location_id) AS id
                   FROM driver_locations
                  WHERE $freshLocationFilter
                  GROUP BY driver_id) latest ON latest.id = dl.location_id
           JOIN users u     ON u.user_id = dl.driver_id AND u.role = 'driver' AND u.status = 'active'
           JOIN minibuses m ON m.minibus_id = dl.minibus_id AND m.status = 'active'
           LEFT JOIN routes r ON r.route_id = dl.route_id
           LEFT JOIN trips t ON t.trip_id = dl.trip_id AND t.status = 'active'
          ORDER BY dl.recorded_at DESC, dl.location_id DESC");

    $list = [];
    foreach ($res->fetchAll() as $row) {
        $lat = (float)$row['latitude']; $lng = (float)$row['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $list[] = [
            'location_id' => (int)$row['location_id'],
            'driver_id'   => (int)$row['driver_id'],
            'driver_name' => trim($row['driver_name']) ?: 'Driver',
            'minibus_id'  => (int)$row['minibus_id'],
            'bus_number'  => $row['bus_number'],
            'plate_number'=> $row['plate_number'],
            'capacity'    => $row['capacity'] === null ? null : (int)$row['capacity'],
            'seat_status' => $row['seat_status'],
            'route_id'    => $row['route_id'] === null ? null : (int)$row['route_id'],
            'route_name'  => $row['route_name'],
            'origin'      => $row['origin'],
            'destination' => $row['destination'],
            'est_minutes' => $row['est_minutes'] === null ? null : (int)$row['est_minutes'],
            'dest_lat'    => $row['dest_lat'] === null ? null : (float)$row['dest_lat'],
            'dest_lng'    => $row['dest_lng'] === null ? null : (float)$row['dest_lng'],
            'trip_id'     => $row['trip_id'] === null ? null : (int)$row['trip_id'],
            'trip_started_at' => $row['started_at'],
            'latitude'    => $lat,
            'longitude'   => $lng,
            'accuracy'    => $row['accuracy'] === null ? null : (float)$row['accuracy'],
            'speed'       => $row['speed']    === null ? 0    : (float)$row['speed'],
            'heading'     => $row['heading']  === null ? null : (float)$row['heading'],
            'recorded_at' => $row['recorded_at'],
            'age_seconds' => (int)$row['age_seconds'],
        ];
    }
    out(true, $list ? count($list) . ' driver(s) online.' : 'No drivers are online right now.',
        ['location' => $list[0] ?? null, 'locations' => $list, 'server_time' => date('c')]);
} catch (Throwable $e) {
    fail_server($e, 'Unable to read driver GPS.');
}
