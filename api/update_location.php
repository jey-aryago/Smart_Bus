<?php
// Driver app posts its GPS here. speed = km/h, heading = degrees.
require_once __DIR__ . '/bootstrap.php';
require_post();
$driverId = require_role(['driver']);

if (($_POST['action'] ?? '') === 'stop') {
    try {
        $recentFilter = DB_DRIVER === 'pgsql'
            ? "recorded_at >= (CURRENT_TIMESTAMP - INTERVAL '2 minutes')"
            : 'recorded_at >= (NOW() - INTERVAL 2 MINUTE)';
        $stmt = $conn->prepare(
            "DELETE FROM driver_locations
              WHERE driver_id = ? AND $recentFilter"
        );
        $stmt->execute([$driverId]);
        out(true, 'Location sharing stopped.');
    } catch (Throwable $e) {
        fail_server($e, 'Unable to stop sharing.');
    }
}

$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$acc = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
$spd = ($_POST['speed'] ?? '') !== '' ? filter_var($_POST['speed'], FILTER_VALIDATE_FLOAT) : null;
$hdg = ($_POST['heading'] ?? '') !== '' ? filter_var($_POST['heading'], FILTER_VALIDATE_FLOAT) : null;

if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    out(false, 'Invalid latitude or longitude.', [], 422);
}
if ($acc === false || $acc < 0 || $acc > 100000) $acc = null;
if ($spd === false || ($spd !== null && ($spd < 0 || $spd > 300))) $spd = null;
if ($hdg === false || ($hdg !== null && ($hdg < 0 || $hdg > 360))) $hdg = null;

try {
    $stmt = $conn->prepare(
        "SELECT m.minibus_id, m.route_id,
                (SELECT t.trip_id FROM trips t
                  WHERE t.driver_id = m.driver_id AND t.minibus_id = m.minibus_id AND t.status = 'active'
                  ORDER BY t.started_at DESC, t.trip_id DESC LIMIT 1) AS trip_id
           FROM minibuses m
          WHERE m.driver_id = ? AND m.status = 'active'
          ORDER BY m.minibus_id ASC LIMIT 1"
    );
    $stmt->execute([$driverId]);
    $bus = $stmt->fetch();
    if (!$bus) {
        out(false, 'No active minibus is assigned to this driver.', [], 409);
    }

    $minibusId = (int)$bus['minibus_id'];
    $routeId = $bus['route_id'] !== null ? (int)$bus['route_id'] : null;
    $tripId = $bus['trip_id'] !== null ? (int)$bus['trip_id'] : null;

    $insertSql = "INSERT INTO driver_locations
            (driver_id, trip_id, minibus_id, route_id, latitude, longitude, accuracy, speed, heading, recorded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
    if (DB_DRIVER === 'pgsql') {
        $insertSql .= ' RETURNING location_id';
    }
    $stmt = $conn->prepare($insertSql);
    $stmt->execute([$driverId, $tripId, $minibusId, $routeId, $lat, $lng, $acc, $spd, $hdg]);
    $id = DB_DRIVER === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$conn->lastInsertId();

    if (mt_rand(1, 50) === 1) {
        if (DB_DRIVER === 'pgsql') {
            $conn->exec(
                "WITH expired AS (
                    SELECT location_id FROM driver_locations
                     WHERE recorded_at < (CURRENT_TIMESTAMP - INTERVAL '1 day')
                     ORDER BY recorded_at ASC
                     LIMIT 5000
                 )
                 DELETE FROM driver_locations WHERE location_id IN (SELECT location_id FROM expired)"
            );
        } else {
            $conn->exec(
                "DELETE FROM driver_locations
                  WHERE recorded_at < (NOW() - INTERVAL 1 DAY)
                  ORDER BY recorded_at ASC LIMIT 5000"
            );
        }
    }

    out(true, 'GPS location saved.', ['data' => [
        'location_id' => $id,
        'driver_id' => $driverId,
        'minibus_id' => $minibusId,
        'route_id' => $routeId,
        'latitude' => $lat,
        'longitude' => $lng,
        'accuracy' => $acc,
        'speed' => $spd,
        'heading' => $hdg,
    ]]);
} catch (Throwable $e) {
    fail_server($e, 'Unable to save GPS location.');
}
