<?php
require_once __DIR__ . '/bootstrap.php';
require_role(['commuter']);

try {
    $ageExpression = DB_DRIVER === 'pgsql'
        ? 'EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - dl.recorded_at))::integer'
        : 'TIMESTAMPDIFF(SECOND, dl.recorded_at, NOW())';
    $buses = $conn->query(
        "SELECT m.minibus_id, m.bus_number, m.plate_number, m.capacity, m.seat_status,
                m.status, r.route_id, r.route_name, r.origin, r.destination, r.est_minutes,
                r.dest_lat, r.dest_lng,
                dl.latitude, dl.longitude, dl.speed, dl.recorded_at,
                $ageExpression AS age_seconds,
                t.trip_id, t.status AS trip_status, t.started_at
           FROM minibuses m
           LEFT JOIN routes r ON r.route_id = m.route_id
           LEFT JOIN (
               SELECT a.* FROM driver_locations a
               JOIN (
                   SELECT minibus_id, MAX(location_id) AS location_id
                     FROM driver_locations
                    GROUP BY minibus_id
               ) latest ON latest.location_id = a.location_id
           ) dl ON dl.minibus_id = m.minibus_id
           LEFT JOIN trips t ON t.minibus_id = m.minibus_id AND t.status = 'active'
          WHERE m.status = 'active'
          ORDER BY m.bus_number");
    $activeBuses = [];
    foreach ($buses->fetchAll() as $row) {
        $row['minibus_id'] = (int)$row['minibus_id'];
        $row['capacity'] = (int)$row['capacity'];
        $row['age_seconds'] = $row['age_seconds'] === null ? null : (int)$row['age_seconds'];
        $row['online'] = $row['age_seconds'] !== null && $row['age_seconds'] <= 120;
        $row['speed'] = $row['speed'] === null ? null : (float)$row['speed'];
        $row['latitude'] = $row['latitude'] === null ? null : (float)$row['latitude'];
        $row['longitude'] = $row['longitude'] === null ? null : (float)$row['longitude'];
        $row['dest_lat'] = $row['dest_lat'] === null ? null : (float)$row['dest_lat'];
        $row['dest_lng'] = $row['dest_lng'] === null ? null : (float)$row['dest_lng'];
        $activeBuses[] = $row;
    }

    $trips = $conn->query(
        "SELECT t.trip_id, t.status, t.started_at, t.ended_at, t.distance_km,
                m.bus_number, r.route_name, r.origin, r.destination, r.est_minutes
           FROM trips t
           JOIN minibuses m ON m.minibus_id = t.minibus_id
           JOIN routes r ON r.route_id = t.route_id
          ORDER BY (t.status = 'active') DESC, t.started_at DESC
          LIMIT 20");
    $routeActivity = [];
    foreach ($trips->fetchAll() as $row) {
        $row['trip_id'] = (int)$row['trip_id'];
        $row['distance_km'] = $row['distance_km'] === null ? null : (float)$row['distance_km'];
        $routeActivity[] = $row;
    }

    $stopsResult = $conn->query(
        "SELECT rs.stop_id, rs.route_id, rs.stop_name, rs.latitude, rs.longitude, rs.stop_sequence
           FROM route_stops rs
           JOIN routes r ON r.route_id = rs.route_id AND r.status = 'active'
          ORDER BY rs.route_id, rs.stop_sequence");
    $routeStops = [];
    foreach ($stopsResult->fetchAll() as $row) {
        $routeId = (int)$row['route_id'];
        if (!isset($routeStops[$routeId])) $routeStops[$routeId] = [];
        $routeStops[$routeId][] = [
            'stop_id' => (int)$row['stop_id'],
            'stop_name' => $row['stop_name'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'stop_sequence' => (int)$row['stop_sequence'],
        ];
    }

    $stmt = $conn->prepare(
        "SELECT complaint_id, subject, message, admin_response, status, created_at, updated_at
           FROM complaints
          WHERE user_id = ?
          ORDER BY created_at DESC, complaint_id DESC
          LIMIT 20");
    $commuterId = (int)$_SESSION['user_id'];
    $stmt->execute([$commuterId]);
    $complaints = $stmt->fetchAll();
    $stmt->closeCursor();

    out(true, '', [
        'buses' => $activeBuses,
        'trips' => $routeActivity,
        'route_stops' => $routeStops,
        'complaints' => $complaints,
    ]);
} catch (Throwable $e) {
    fail_server($e, 'Unable to load commuter trip information.');
}
