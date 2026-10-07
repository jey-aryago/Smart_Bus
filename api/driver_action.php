<?php
require_once __DIR__ . '/bootstrap.php';
require_post();
$driverId = require_role(['driver']);
$action = $_POST['action'] ?? '';

try {
    if ($action === 'seat_status') {
        $seatStatus = $_POST['seat_status'] ?? '';
        if (!in_array($seatStatus, ['available', 'full'], true)) {
            out(false, 'Choose Available or Full.', [], 422);
        }

        $stmt = $conn->prepare(
            "UPDATE minibuses SET seat_status = ?
              WHERE driver_id = ? AND status = 'active'"
        );
        $stmt->execute([$seatStatus, $driverId]);
        $affected = $stmt->rowCount();
        if ($affected < 1) {
            $stmt = $conn->prepare(
                "SELECT minibus_id FROM minibuses
                  WHERE driver_id = ? AND status = 'active' AND seat_status = ? LIMIT 1"
            );
            $stmt->execute([$driverId, $seatStatus]);
            if ($stmt->fetch() === false) {
                out(false, 'No active minibus is assigned to your account.', [], 409);
            }
        }

        out(true, 'Passenger availability updated.', ['seat_status' => $seatStatus]);
    }

    if ($action === 'trip_start') {
        $stmt = $conn->prepare(
            "SELECT m.minibus_id, m.route_id, r.route_name
               FROM minibuses m
               JOIN routes r ON r.route_id = m.route_id AND r.status = 'active'
              WHERE m.driver_id = ? AND m.status = 'active'
              ORDER BY m.minibus_id LIMIT 1"
        );
        $stmt->execute([$driverId]);
        $bus = $stmt->fetch();
        if (!$bus) {
            out(false, 'You need an active assigned bus and route before starting a trip.', [], 409);
        }

        $stmt = $conn->prepare(
            "SELECT trip_id, started_at FROM trips WHERE driver_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$driverId]);
        $activeTrip = $stmt->fetch();
        if ($activeTrip) {
            out(true, 'Your trip is already active.', [
                'trip_id' => (int)$activeTrip['trip_id'],
                'started_at' => $activeTrip['started_at'],
            ]);
        }

        $busId = (int)$bus['minibus_id'];
        $routeId = (int)$bus['route_id'];
        $insertTripSql = "INSERT INTO trips (driver_id, minibus_id, route_id, status, started_at)
                          VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)";
        if (DB_DRIVER === 'pgsql') {
            $insertTripSql .= ' RETURNING trip_id';
        }
        $stmt = $conn->prepare($insertTripSql);
        $stmt->execute([$driverId, $busId, $routeId]);
        $tripId = DB_DRIVER === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$conn->lastInsertId();

        $title = 'Trip departed';
        $message = 'Bus ' . $bus['route_name'] . ' has departed.';
        $stmt = $conn->prepare(
            'INSERT INTO notifications (title, message, audience, sent_by, route_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $message, 'commuters', $driverId, $routeId]);

        out(true, 'Trip started.', ['trip_id' => $tripId]);
    }

    if ($action === 'trip_end') {
        $stmt = $conn->prepare(
            "SELECT trip_id, route_id FROM trips WHERE driver_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$driverId]);
        $trip = $stmt->fetch();
        if (!$trip) {
            out(false, 'There is no active trip to complete.', [], 409);
        }

        $tripId = (int)$trip['trip_id'];
        $routeId = (int)$trip['route_id'];
        $stmt = $conn->prepare(
            "UPDATE trips SET status = 'completed', ended_at = CURRENT_TIMESTAMP
              WHERE trip_id = ? AND driver_id = ?"
        );
        $stmt->execute([$tripId, $driverId]);

        $stmt = $conn->prepare(
            'INSERT INTO notifications (title, message, audience, sent_by, route_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Trip completed',
            'The driver has completed a trip.',
            'commuters',
            $driverId,
            $routeId,
        ]);

        out(true, 'Trip marked completed.');
    }

    out(false, 'Unknown driver action.', [], 404);
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        out(false, 'A trip is already active for this bus or driver.', [], 409);
    }
    fail_server($e, 'Unable to complete that driver action.');
} catch (Throwable $e) {
    fail_server($e, 'Unable to complete that driver action.');
}
