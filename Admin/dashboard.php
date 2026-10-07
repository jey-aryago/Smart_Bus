<?php
/* =========================================================
   SMARTMINIBUS - ADMIN DASHBOARD
   PHP backend + HTML + JavaScript in ONE file.

   Location : admin/dashboard.php
   CSS      : ../css/admin-dashboard.css
   SQL      : ../database/admin_setup.sql
   Needs    : ../config/database.php  ($conn = PDO), session keys
              user_id / role / full_name / email, role = "admin"
========================================================= */

session_start();

/* =========================================================
   CONFIG - palitan lang dito kung iba ang pangalan sa DB mo
========================================================= */

// Posibleng lokasyon ng database.php (susubukan isa-isa)
$dbCandidates = [
    __DIR__ . '/../database.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../includes/database.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/database.php',
];

// Table names
$T = [
    'minibuses'     => 'minibuses',
    'routes'        => 'routes',
    'complaints'    => 'complaints',
    'notifications' => 'notifications',
];

// Existing `users` table
$U = [
    'table'    => 'users',
    'id'       => 'user_id',
    'name'     => 'full_name',
    'email'    => 'email',
    'phone'    => 'phone',
    'password' => 'password',
    'role'     => 'role',
    'status'   => 'status',
    'created'  => 'created_at',
];

// Existing live-location table (ginagamit ng ../api/update_location.php)
$L = [
    'table' => 'driver_locations',
    'user'  => 'driver_id',
    'lat'   => 'latitude',
    'lng'   => 'longitude',
    'speed' => 'speed',
    'time'  => 'recorded_at',
];

// Gaano katagal (seconds) bago ituring na "stale" ang location
$ONLINE_SECONDS = 120;

/* =========================================================
   HELPERS
========================================================= */

function jout($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail($message, $code = 400)
{
    jout(['ok' => false, 'message' => $message], $code);
}

function rows(PDO $c, $sql, $types = '', array $params = [])
{
    $s = $c->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function one(PDO $c, $sql, $types = '', array $params = [])
{
    $r = rows($c, $sql, $types, $params);
    return $r ? array_values($r[0])[0] : null;
}

function run(PDO $c, $sql, $types = '', array $params = [])
{
    $s = $c->prepare($sql);
    $s->execute($params);
    $id = 0;
    if (preg_match('/^\s*INSERT\s+INTO\s+[`"]?([a-z_][a-z0-9_]*)[`"]?/i', $sql, $match)) {
        if (DB_DRIVER === 'pgsql' && $match[1] === 'users') {
            $id = (int)$c->query(
                "SELECT currval(pg_get_serial_sequence('public.users', 'user_id'))"
            )->fetchColumn();
        } elseif (DB_DRIVER === 'mysql') {
            $id = (int)$c->lastInsertId();
        }
    }
    $res = ['affected' => $s->rowCount(), 'id' => $id];
    $s->closeCursor();
    return $res;
}

function p_str($key, $max = 255)
{
    return trim(mb_substr((string)($_POST[$key] ?? ''), 0, $max));
}

function p_int($key)
{
    return (int)($_POST[$key] ?? 0);
}

function p_float_or_null($key)
{
    $v = trim((string)($_POST[$key] ?? ''));
    return $v === '' ? null : (float)$v;
}

function bt($name)
{
    $quote = DB_DRIVER === 'pgsql' ? '"' : '`';
    return $quote . str_replace([$quote, '`', '"'], '', $name) . $quote;
}

/* =========================================================
   AUTH (admin lang)
========================================================= */

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$isAjax = $action !== '';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    if ($isAjax) {
        fail('Unauthorized. Please login again.', 401);
    }
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_csrf'];

$full_name = $_SESSION['full_name']
    ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($full_name === '') $full_name = 'Administrator';
$email     = $_SESSION['email'] ?? '';

/* =========================================================
   DATABASE
========================================================= */

$conn = null;
foreach ($dbCandidates as $path) {
    if (is_file($path)) {
        require_once $path;
        break;
    }
}

if (!isset($conn) || !($conn instanceof PDO)) {
    if ($isAjax) {
        fail('database.php not found. Check $dbCandidates at the top of admin/dashboard.php.', 500);
    }
    die('database.php not found. Check $dbCandidates at the top of admin/dashboard.php.');
}

/* =========================================================
   AJAX ACTIONS (JSON)
========================================================= */

if ($isAjax) {

    $writeActions = [
        'minibus_save', 'minibus_delete',
        'driver_save', 'driver_delete',
        'commuter_status', 'commuter_delete',
        'route_save', 'route_delete',
        'complaint_status', 'complaint_delete',
        'notification_send',
    ];

    if (in_array($action, $writeActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fail('POST required.', 405);
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($csrf, $token)) {
            fail('Security token expired. Please refresh the page.', 419);
        }
    }

    // Quoted identifiers
    $mb = bt($T['minibuses']);
    $rt = bt($T['routes']);
    $cp = bt($T['complaints']);
    $nt = bt($T['notifications']);

    $ut = bt($U['table']);
    $ui = bt($U['id']);
    $un = bt($U['name']);
    $ue = bt($U['email']);
    $up = bt($U['phone']);
    $upw = bt($U['password']);
    $ur = bt($U['role']);
    $us = bt($U['status']);
    $uc = bt($U['created']);

    // users table has first_name + last_name (no full_name column)
    $nd = "TRIM(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')))";
    $nu = "TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))";
    $n0 = "TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))";

    $lt = bt($L['table']);
    $lu = bt($L['user']);
    $la = bt($L['lat']);
    $lo = bt($L['lng']);
    $ls = bt($L['speed']);
    $lm = bt($L['time']);

    try {
        switch ($action) {

            /* ---------------- OVERVIEW ---------------- */
            case 'stats':
                jout(['ok' => true, 'data' => [
                    'commuters'  => (int)one($conn, "SELECT COUNT(*) FROM $ut WHERE $ur = 'commuter'"),
                    'drivers'    => (int)one($conn, "SELECT COUNT(*) FROM $ut WHERE $ur = 'driver'"),
                    'minibuses'  => (int)one($conn, "SELECT COUNT(*) FROM $mb"),
                    'active'     => (int)one($conn, "SELECT COUNT(*) FROM $mb WHERE status = 'active'"),
                    'routes'     => (int)one($conn, "SELECT COUNT(*) FROM $rt"),
                    'complaints' => (int)one($conn, "SELECT COUNT(*) FROM $cp WHERE status = 'Pending'"),
                ]]);

            /* ---------------- MINIBUSES ---------------- */
            case 'minibus_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT m.minibus_id, m.bus_number, m.plate_number, m.capacity, m.status,
                            m.driver_id, m.route_id, $nd AS driver_name, r.route_name
                     FROM $mb m
                     LEFT JOIN $ut d ON d.$ui = m.driver_id
                     LEFT JOIN $rt r ON r.route_id = m.route_id
                     ORDER BY m.bus_number ASC")]);

            case 'minibus_save':
                $id     = p_int('id');
                $bus    = p_str('bus_number', 30);
                $plate  = strtoupper(p_str('plate_number', 20));
                $cap    = p_int('capacity');
                $status = p_str('status', 20);
                $driver = p_int('driver_id');
                $route  = p_int('route_id');

                if ($bus === '' || $plate === '') fail('Bus number and plate number are required.');
                if ($cap < 1 || $cap > 100) fail('Capacity must be between 1 and 100.');
                if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) fail('Invalid status.');

                if (one($conn, "SELECT minibus_id FROM $mb WHERE (bus_number = ? OR plate_number = ?) AND minibus_id <> ? LIMIT 1",
                        'ssi', [$bus, $plate, $id]) !== null) {
                    fail('Bus number or plate number already exists.');
                }

                $driverVal = null;
                if ($driver > 0) {
                    if (one($conn, "SELECT $ui FROM $ut WHERE $ui = ? AND $ur = 'driver' LIMIT 1", 'i', [$driver]) === null) {
                        fail('Selected driver does not exist.');
                    }
                    $other = one($conn, "SELECT bus_number FROM $mb WHERE driver_id = ? AND minibus_id <> ? LIMIT 1", 'ii', [$driver, $id]);
                    if ($other !== null) fail("That driver is already assigned to Bus $other.");
                    $driverVal = $driver;
                }

                $routeVal = null;
                if ($route > 0) {
                    if (one($conn, "SELECT route_id FROM $rt WHERE route_id = ? LIMIT 1", 'i', [$route]) === null) {
                        fail('Selected route does not exist.');
                    }
                    $routeVal = $route;
                }

                if ($id > 0) {
                    if (one($conn, "SELECT minibus_id FROM $mb WHERE minibus_id = ?", 'i', [$id]) === null) fail('Minibus not found.', 404);
                    run($conn, "UPDATE $mb SET bus_number=?, plate_number=?, capacity=?, status=?, driver_id=?, route_id=? WHERE minibus_id=?",
                        'ssisiii', [$bus, $plate, $cap, $status, $driverVal, $routeVal, $id]);
                    jout(['ok' => true, 'message' => 'Minibus updated.']);
                }

                run($conn, "INSERT INTO $mb (bus_number, plate_number, capacity, status, driver_id, route_id) VALUES (?,?,?,?,?,?)",
                    'ssisii', [$bus, $plate, $cap, $status, $driverVal, $routeVal]);
                jout(['ok' => true, 'message' => 'Minibus added.']);

            case 'minibus_delete':
                $id = p_int('id');
                if ($id < 1) fail('Invalid minibus.');
                $r = run($conn, "DELETE FROM $mb WHERE minibus_id = ?", 'i', [$id]);
                if ($r['affected'] < 1) fail('Minibus not found.', 404);
                jout(['ok' => true, 'message' => 'Minibus deleted.']);

            /* ---------------- DRIVERS ---------------- */
            case 'driver_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT u.$ui AS id, $nu AS name, u.$ue AS email, u.$up AS phone,
                            u.$us AS status, u.$uc AS created_at,
                            m.minibus_id, m.bus_number, m.plate_number
                     FROM $ut u
                     LEFT JOIN $mb m ON m.driver_id = u.$ui
                     WHERE u.$ur = 'driver'
                     ORDER BY $nu ASC")]);

            case 'driver_save':
                $id      = p_int('id');
                $name    = p_str('name', 100);
                $mail    = strtolower(p_str('email', 120));
                $phone   = p_str('phone', 30);
                $status  = p_str('status', 20);
                $pass    = (string)($_POST['password'] ?? '');
                $busId   = p_int('minibus_id');

                if ($name === '') fail('Full name is required.');
                $nameParts = preg_split('/\s+/', $name, 2);
                $firstName = $nameParts[0];
                $lastName  = $nameParts[1] ?? '';
                if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');
                if (!in_array($status, ['active', 'inactive'], true)) fail('Invalid status.');
                if ($id === 0 && strlen($pass) < 6) fail('Password must be at least 6 characters.');
                if ($id > 0 && $pass !== '' && strlen($pass) < 6) fail('Password must be at least 6 characters.');

                if (one($conn, "SELECT $ui FROM $ut WHERE $ue = ? AND $ui <> ? LIMIT 1", 'si', [$mail, $id]) !== null) {
                    fail('Email is already registered.');
                }
                if ($busId > 0 && one($conn, "SELECT minibus_id FROM $mb WHERE minibus_id = ?", 'i', [$busId]) === null) {
                    fail('Selected minibus does not exist.');
                }

                $conn->beginTransaction();
                try {
                    if ($id > 0) {
                        if (one($conn, "SELECT $ui FROM $ut WHERE $ui = ? AND $ur = 'driver'", 'i', [$id]) === null) {
                            throw new RuntimeException('Driver not found.');
                        }
                        if ($pass !== '') {
                            run($conn, "UPDATE $ut SET first_name=?, last_name=?, $ue=?, $up=?, $us=?, $upw=? WHERE $ui=? AND $ur='driver'",
                                'ssssssi', [$firstName, $lastName, $mail, $phone, $status, password_hash($pass, PASSWORD_DEFAULT), $id]);
                        } else {
                            run($conn, "UPDATE $ut SET first_name=?, last_name=?, $ue=?, $up=?, $us=? WHERE $ui=? AND $ur='driver'",
                                'sssssi', [$firstName, $lastName, $mail, $phone, $status, $id]);
                        }
                        $driverId = $id;
                    } else {
                        $r = run($conn, "INSERT INTO $ut (first_name, last_name, $ue, $up, $upw, $ur, $us) VALUES (?,?,?,?,?, 'driver', ?)",
                            'ssssss', [$firstName, $lastName, $mail, $phone, password_hash($pass, PASSWORD_DEFAULT), $status]);
                        $driverId = $r['id'];
                    }

                    // Assignment: isang minibus lang bawat driver
                    run($conn, "UPDATE $mb SET driver_id = NULL WHERE driver_id = ?", 'i', [$driverId]);
                    if ($busId > 0) {
                        run($conn, "UPDATE $mb SET driver_id = ? WHERE minibus_id = ?", 'ii', [$driverId, $busId]);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    if ($e instanceof RuntimeException) fail($e->getMessage(), 404);
                    throw $e;
                }
                jout(['ok' => true, 'message' => $id > 0 ? 'Driver updated.' : 'Driver added.']);

            case 'driver_delete':
                $id = p_int('id');
                if ($id < 1) fail('Invalid driver.');
                $conn->beginTransaction();
                try {
                    run($conn, "UPDATE $mb SET driver_id = NULL WHERE driver_id = ?", 'i', [$id]);
                    $r = run($conn, "DELETE FROM $ut WHERE $ui = ? AND $ur = 'driver'", 'i', [$id]);
                    if ($r['affected'] < 1) {
                        $conn->rollBack();
                        fail('Driver not found.', 404);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }
                jout(['ok' => true, 'message' => 'Driver deleted.']);

            /* ---------------- COMMUTERS ---------------- */
            case 'commuter_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT $ui AS id, $n0 AS name, $ue AS email, $up AS phone, $us AS status, $uc AS created_at
                     FROM $ut WHERE $ur = 'commuter' ORDER BY $n0 ASC")]);

            case 'commuter_status':
                $id = p_int('id');
                $status = p_str('status', 20);
                if (!in_array($status, ['active', 'inactive'], true)) fail('Invalid status.');
                $r = run($conn, "UPDATE $ut SET $us = ? WHERE $ui = ? AND $ur = 'commuter'", 'si', [$status, $id]);
                jout(['ok' => true, 'message' => $status === 'active' ? 'Account activated.' : 'Account deactivated.']);

            case 'commuter_delete':
                $id = p_int('id');
                if ($id < 1) fail('Invalid commuter.');
                $r = run($conn, "DELETE FROM $ut WHERE $ui = ? AND $ur = 'commuter'", 'i', [$id]);
                if ($r['affected'] < 1) fail('Commuter not found.', 404);
                jout(['ok' => true, 'message' => 'Commuter account deleted.']);

            /* ---------------- ROUTES ---------------- */
            case 'route_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT r.*, (SELECT COUNT(*) FROM $mb m WHERE m.route_id = r.route_id) AS bus_count
                     FROM $rt r ORDER BY r.route_name ASC")]);

            case 'route_save':
                $id    = p_int('id');
                $name  = p_str('route_name', 120);
                $org   = p_str('origin', 120);
                $dst   = p_str('destination', 120);
                $dist  = p_float_or_null('distance_km');
                $mins  = trim((string)($_POST['est_minutes'] ?? '')) === '' ? null : p_int('est_minutes');
                $status = p_str('status', 20);
                $oLat = p_float_or_null('origin_lat');
                $oLng = p_float_or_null('origin_lng');
                $dLat = p_float_or_null('dest_lat');
                $dLng = p_float_or_null('dest_lng');

                if ($name === '' || $org === '' || $dst === '') fail('Route name, origin and destination are required.');
                if (!in_array($status, ['active', 'inactive'], true)) fail('Invalid status.');
                if ($dist !== null && $dist < 0) fail('Distance cannot be negative.');
                if ($mins !== null && $mins < 0) fail('Estimated time cannot be negative.');
                foreach ([[$oLat, 90], [$dLat, 90], [$oLng, 180], [$dLng, 180]] as $pair) {
                    if ($pair[0] !== null && abs($pair[0]) > $pair[1]) fail('Invalid coordinates.');
                }
                if (one($conn, "SELECT route_id FROM $rt WHERE route_name = ? AND route_id <> ? LIMIT 1", 'si', [$name, $id]) !== null) {
                    fail('A route with that name already exists.');
                }

                if ($id > 0) {
                    if (one($conn, "SELECT route_id FROM $rt WHERE route_id = ?", 'i', [$id]) === null) fail('Route not found.', 404);
                    run($conn, "UPDATE $rt SET route_name=?, origin=?, destination=?, distance_km=?, est_minutes=?, status=?,
                                origin_lat=?, origin_lng=?, dest_lat=?, dest_lng=? WHERE route_id=?",
                        'sssdisddddi', [$name, $org, $dst, $dist, $mins, $status, $oLat, $oLng, $dLat, $dLng, $id]);
                    jout(['ok' => true, 'message' => 'Route updated.']);
                }

                run($conn, "INSERT INTO $rt (route_name, origin, destination, distance_km, est_minutes, status, origin_lat, origin_lng, dest_lat, dest_lng)
                            VALUES (?,?,?,?,?,?,?,?,?,?)",
                    'sssdisdddd', [$name, $org, $dst, $dist, $mins, $status, $oLat, $oLng, $dLat, $dLng]);
                jout(['ok' => true, 'message' => 'Route added.']);

            case 'route_delete':
                $id = p_int('id');
                if ($id < 1) fail('Invalid route.');
                $conn->beginTransaction();
                try {
                    run($conn, "UPDATE $mb SET route_id = NULL WHERE route_id = ?", 'i', [$id]);
                    $r = run($conn, "DELETE FROM $rt WHERE route_id = ?", 'i', [$id]);
                    if ($r['affected'] < 1) {
                        $conn->rollBack();
                        fail('Route not found.', 404);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }
                jout(['ok' => true, 'message' => 'Route deleted. Assigned minibuses were unassigned.']);

            /* ---------------- LIVE MONITORING ---------------- */
            case 'live':
                $ageExpression = DB_DRIVER === 'pgsql'
                    ? 'EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - l.' . $lm . '))::integer'
                    : 'TIMESTAMPDIFF(SECOND, l.' . $lm . ', NOW())';
                $list = rows($conn,
                    "SELECT m.minibus_id, m.bus_number, m.plate_number, m.status,
                            $nd AS driver_name,
                            l.$la AS latitude, l.$lo AS longitude, l.$ls AS speed, l.$lm AS recorded_at,
                            $ageExpression AS age
                     FROM $mb m
                     LEFT JOIN $ut d ON d.$ui = m.driver_id
                     LEFT JOIN (
                         SELECT a.* FROM $lt a
                         JOIN (SELECT $lu AS uid, MAX($lm) AS t FROM $lt GROUP BY $lu) x
                           ON x.uid = a.$lu AND x.t = a.$lm
                     ) l ON l.$lu = d.$ui
                     WHERE m.status = 'active'
                     ORDER BY m.bus_number ASC");

                $seen = [];
                $out = [];
                foreach ($list as $row) {
                    if (isset($seen[$row['minibus_id']])) continue;
                    $seen[$row['minibus_id']] = true;
                    $row['online'] = ($row['latitude'] !== null && $row['age'] !== null && (int)$row['age'] <= $ONLINE_SECONDS);
                    $out[] = $row;
                }
                jout(['ok' => true, 'data' => $out]);

            /* ---------------- COMPLAINTS ---------------- */
            case 'complaint_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT c.complaint_id, c.user_id, c.complaint_type, c.route_id, c.subject, c.message,
                            c.admin_response, c.status, c.created_at, c.updated_at,
                            $nu AS commuter_name, u.$ue AS commuter_email
                     FROM $cp c
                     LEFT JOIN $ut u ON u.$ui = c.user_id
                     ORDER BY CASE c.status WHEN 'Pending' THEN 1 WHEN 'In Progress' THEN 2 WHEN 'Resolved' THEN 3 ELSE 4 END,
                              c.created_at DESC")]);

            case 'complaint_status':
                $id = p_int('id');
                $status = p_str('status', 20);
                $response = trim((string)($_POST['admin_response'] ?? ''));
                if (!in_array($status, ['Pending', 'In Progress', 'Resolved'], true)) fail('Invalid status.');
                if (mb_strlen($response) > 3000) fail('Response must be 3000 characters or fewer.');
                $complaint = rows($conn, "SELECT user_id, status, admin_response, subject FROM $cp WHERE complaint_id = ?", 'i', [$id])[0] ?? null;
                if ($complaint === null) fail('Complaint not found.', 404);
                run($conn, "UPDATE $cp SET status = ?, admin_response = ?, updated_at = NOW() WHERE complaint_id = ?",
                    'ssi', [$status, $response !== '' ? $response : null, $id]);
                if ($status !== $complaint['status'] || $response !== (string)($complaint['admin_response'] ?? '')) {
                    $title = 'Update on your complaint';
                    $message = 'Complaint "' . $complaint['subject'] . '" is now ' . $status . '.';
                    if ($response !== '') $message .= ' Admin response: ' . $response;
                    $audience = 'commuters';
                    $recipient = (int)$complaint['user_id'];
                    $sender = (int)$_SESSION['user_id'];
                    run($conn, "INSERT INTO $nt (title, message, audience, sent_by, recipient_id) VALUES (?, ?, ?, ?, ?)",
                        'sssii', [$title, $message, $audience, $sender, $recipient]);
                }
                jout(['ok' => true, 'message' => 'Complaint status updated.']);

            case 'complaint_delete':
                $id = p_int('id');
                $cur = one($conn, "SELECT status FROM $cp WHERE complaint_id = ?", 'i', [$id]);
                if ($cur === null) fail('Complaint not found.', 404);
                if ($cur !== 'Resolved') fail('Only resolved complaints can be deleted.');
                run($conn, "DELETE FROM $cp WHERE complaint_id = ?", 'i', [$id]);
                jout(['ok' => true, 'message' => 'Complaint deleted.']);

            /* ---------------- NOTIFICATIONS ---------------- */
            case 'notification_list':
                jout(['ok' => true, 'data' => rows($conn,
                    "SELECT notification_id, title, message, audience, created_at
                     FROM $nt ORDER BY created_at DESC, notification_id DESC LIMIT 30")]);

            case 'notification_send':
                $title = p_str('title', 120);
                $msg = p_str('message', 1000);
                $aud = p_str('audience', 20);
                if ($title === '' || $msg === '') fail('Title and message are required.');
                if (!in_array($aud, ['all', 'commuters', 'drivers'], true)) fail('Invalid audience.');
                run($conn, "INSERT INTO $nt (title, message, audience, sent_by) VALUES (?,?,?,?)",
                    'sssi', [$title, $msg, $aud, (int)$_SESSION['user_id']]);
                jout(['ok' => true, 'message' => 'Notification sent.']);

            default:
                fail('Unknown action.', 404);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $sqlState = $e instanceof PDOException ? (string)$e->getCode() : '';
        $driverCode = $e instanceof PDOException && isset($e->errorInfo[1])
            ? (int)$e->errorInfo[1]
            : 0;
        if (in_array($sqlState, ['42P01', '42S02'], true)) {
            $msg = 'A required table is missing. Run database/admin_setup.sql (or fix the $T / $U / $L config).';
        } elseif (in_array($sqlState, ['42703', '42S22'], true)) {
            $msg = 'A required column is missing. Run database/admin_setup.sql (or fix the $U / $L config). ' . $msg;
        } elseif ($sqlState === '23503' || $driverCode === 1451) {
            $msg = 'This record is still linked to other data and cannot be deleted. Set it to inactive instead.';
        } elseif ($sqlState === '23505' || $driverCode === 1062) {
            $msg = 'Duplicate value. That record already exists.';
        }
        fail($msg, 500);
    }
}

/* =========================================================
   PAGE
========================================================= */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard - SmartMinibus</title>

    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/app-polish.css?v=<?= (int)@filemtime(__DIR__ . '/../css/app-polish.css') ?>">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

<div class="dashboard-container">

    <!-- ==========================================
         TOP NAVIGATION HEADER (fixed)
    =========================================== -->
    <header class="top-header" id="topHeader">

        <a href="dashboard.php" class="brand-section">
            <div class="brand-logo">
                <img src="../images/logo.png" alt="SmartMinibus Logo" onerror="this.style.visibility='hidden'">
            </div>

            <div class="brand-copy">
                <div class="brand-name">
                    <span>Smart</span><strong>Minibus</strong>
                </div>
                <div class="brand-tagline">Smart Travel. Smarter Commute</div>
            </div>
        </a>

        <nav class="top-nav" id="topNav" aria-label="Main navigation">
            <a href="#overview" class="nav-link active" data-view="overview">
                <i class="fas fa-chart-line"></i><span>Overview</span>
            </a>
            <a href="#minibuses" class="nav-link" data-view="minibuses">
                <i class="fas fa-bus"></i><span>Minibuses</span>
            </a>
            <a href="#drivers" class="nav-link" data-view="drivers">
                <i class="fas fa-id-card"></i><span>Drivers</span>
            </a>
            <a href="#commuters" class="nav-link" data-view="commuters">
                <i class="fas fa-users"></i><span>Commuters</span>
            </a>
            <a href="#routes" class="nav-link" data-view="routes">
                <i class="fas fa-route"></i><span>Routes</span>
            </a>
            <a href="#live" class="nav-link" data-view="live">
                <i class="fas fa-location-dot"></i><span>Live Map</span>
            </a>
            <a href="#complaints" class="nav-link" data-view="complaints">
                <i class="fas fa-triangle-exclamation"></i><span>Complaints</span>
                <span class="notification-badge" id="complaintBadge">0</span>
            </a>
            <a href="#notifications" class="nav-link" data-view="notifications">
                <i class="fas fa-bell"></i><span>Alerts</span>
            </a>
        </nav>

        <div class="profile-menu" id="profileMenu">

            <button class="driver-account" id="profileButton" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="profileDropdown">

                <div class="driver-avatar">
                    <?php echo htmlspecialchars(strtoupper(mb_substr($full_name, 0, 1))); ?>
                </div>

                <div class="driver-info">
                    <strong><?php echo htmlspecialchars($full_name); ?></strong>
                    <span>Admin</span>
                </div>

                <i class="fas fa-chevron-down profile-caret"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown" role="menu">

                <div class="profile-dropdown-header">
                    <div class="driver-avatar">
                        <?php echo htmlspecialchars(strtoupper(mb_substr($full_name, 0, 1))); ?>
                    </div>

                    <div class="driver-info">
                        <strong><?php echo htmlspecialchars($full_name); ?></strong>
                        <span>Admin</span>
                    </div>
                </div>

                <a href="../logout.php" class="profile-logout" role="menuitem">
                    <i class="fas fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <button class="menu-button" id="menuButton" type="button"
            aria-label="Toggle navigation" aria-controls="topNav" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>

    </header>

    <!-- ==========================================
         MAIN CONTENT
    =========================================== -->
    <main class="main-content">

        <section class="content">

            <!-- ============ OVERVIEW ============ -->
            <div class="view active" id="view-overview">

                <div class="welcome-card">
                    <div>
                        <span class="small-label">ADMINISTRATOR</span>
                        <h2>Hello, <?php echo htmlspecialchars($full_name); ?>!</h2>
                        <p>Manage minibuses, drivers, commuters and routes, monitor live locations,
                           and respond to commuter complaints from one place.</p>
                    </div>
                    <div class="welcome-icon"><i class="fas fa-user-shield"></i></div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div><span>Total Commuters</span><h3 id="statCommuters">--</h3></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-id-card"></i></div>
                        <div><span>Total Drivers</span><h3 id="statDrivers">--</h3></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon sky"><i class="fas fa-bus"></i></div>
                        <div><span>Registered Minibuses</span><h3 id="statMinibuses">--</h3></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-signal"></i></div>
                        <div><span>Active Minibuses</span><h3 id="statActive">--</h3></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-route"></i></div>
                        <div><span>Total Routes</span><h3 id="statRoutes">--</h3></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                        <div><span>Pending Complaints</span><h3 id="statComplaints">--</h3></div>
                    </div>
                </div>

                <div class="two-col">
                    <div class="panel">
                        <h3 class="panel-title">Recent Complaints</h3>
                        <p class="panel-sub">Latest commuter reports that need attention.</p>
                        <div class="mini-list" id="overviewComplaints"></div>
                    </div>
                    <div class="panel">
                        <h3 class="panel-title">Latest Announcements</h3>
                        <p class="panel-sub">Most recent messages sent from this dashboard.</p>
                        <div class="mini-list" id="overviewNotifications"></div>
                    </div>
                </div>
            </div>

            <!-- ============ MINIBUSES ============ -->
            <div class="view" id="view-minibuses">
                <div class="section-header">
                    <div>
                        <h2>Minibus Management</h2>
                        <p>Registered minibuses, plate numbers and assigned drivers.</p>
                    </div>
                    <button class="btn btn-primary" type="button" id="addMinibusBtn">
                        <i class="fas fa-plus"></i> Add Minibus
                    </button>
                </div>
                <div class="panel">
                    <div class="toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchMinibuses" placeholder="Search bus no., plate or driver">
                        </div>
                    </div>
                    <div id="minibusTable"></div>
                </div>
            </div>

            <!-- ============ DRIVERS ============ -->
            <div class="view" id="view-drivers">
                <div class="section-header">
                    <div>
                        <h2>Driver Management</h2>
                        <p>Registered drivers, their status and assigned minibus.</p>
                    </div>
                    <button class="btn btn-primary" type="button" id="addDriverBtn">
                        <i class="fas fa-plus"></i> Add Driver
                    </button>
                </div>
                <div class="panel">
                    <div class="toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchDrivers" placeholder="Search name, email or bus">
                        </div>
                    </div>
                    <div id="driverTable"></div>
                </div>
            </div>

            <!-- ============ COMMUTERS ============ -->
            <div class="view" id="view-commuters">
                <div class="section-header">
                    <div>
                        <h2>Commuter Management</h2>
                        <p>Registered commuters. View details and manage accounts.</p>
                    </div>
                </div>
                <div class="panel">
                    <div class="toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchCommuters" placeholder="Search name, email or phone">
                        </div>
                    </div>
                    <div id="commuterTable"></div>
                </div>
            </div>

            <!-- ============ ROUTES ============ -->
            <div class="view" id="view-routes">
                <div class="section-header">
                    <div>
                        <h2>Route Management</h2>
                        <p>Add, edit and manage the routes served by SmartMinibus.</p>
                    </div>
                    <button class="btn btn-primary" type="button" id="addRouteBtn">
                        <i class="fas fa-plus"></i> Add Route
                    </button>
                </div>

                <div id="primaryRoute"></div>

                <div class="panel">
                    <div class="toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchRoutes" placeholder="Search route, origin or destination">
                        </div>
                    </div>
                    <div id="routeTable"></div>
                </div>
            </div>

            <!-- ============ LIVE MAP ============ -->
            <div class="view" id="view-live">
                <div class="section-header">
                    <div>
                        <h2>Live Minibus Monitoring</h2>
                        <p>Active minibuses and their real-time locations.</p>
                    </div>
                    <div class="live-pill"><span class="status-dot"></span><span id="liveSummary">Loading...</span></div>
                </div>

                <div class="live-layout">
                    <div class="map-card"><div id="liveMap"></div></div>
                    <div class="live-list" id="liveList"></div>
                </div>
            </div>

            <!-- ============ COMPLAINTS ============ -->
            <div class="view" id="view-complaints">
                <div class="section-header">
                    <div>
                        <h2>Complaint Management</h2>
                        <p>Review commuter complaints and update their status.</p>
                    </div>
                </div>
                <div class="panel">
                    <div class="toolbar">
                        <div class="filter-tabs" id="complaintTabs">
                            <button type="button" class="filter-tab active" data-filter="all">All</button>
                            <button type="button" class="filter-tab" data-filter="Pending">Pending</button>
                            <button type="button" class="filter-tab" data-filter="In Progress">In Progress</button>
                            <button type="button" class="filter-tab" data-filter="Resolved">Resolved</button>
                        </div>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchComplaints" placeholder="Search subject or commuter">
                        </div>
                    </div>
                    <div id="complaintTable"></div>
                </div>
            </div>

            <!-- ============ NOTIFICATIONS ============ -->
            <div class="view" id="view-notifications">
                <div class="section-header">
                    <div>
                        <h2>Notifications and Alerts</h2>
                        <p>Send messages to commuters, drivers or everyone.</p>
                    </div>
                </div>

                <div class="notify-grid">
                    <div class="panel">
                        <h3 class="panel-title">Send Notification</h3>
                        <p class="panel-sub">Choose who should receive the message.</p>

                        <form id="notifyForm" novalidate>
                            <div class="form-error" id="notifyError"></div>
                            <div class="form-grid">
                                <div class="field full">
                                    <label for="nfAudience">Send to <em>*</em></label>
                                    <select id="nfAudience" name="audience">
                                        <option value="commuters">All Commuters</option>
                                        <option value="drivers">All Drivers</option>
                                        <option value="all">System-wide Announcement (Everyone)</option>
                                    </select>
                                </div>
                                <div class="field full">
                                    <label for="nfTitle">Title <em>*</em></label>
                                    <input type="text" id="nfTitle" name="title" maxlength="120" placeholder="e.g. Schedule update">
                                </div>
                                <div class="field full">
                                    <label for="nfMessage">Message <em>*</em></label>
                                    <textarea id="nfMessage" name="message" maxlength="1000" placeholder="Write your message here..."></textarea>
                                    <small id="nfCount">0 / 1000</small>
                                </div>
                                <div class="field full">
                                    <button class="btn btn-success" type="submit" id="nfSubmit">
                                        <i class="fas fa-paper-plane"></i> Send Notification
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="panel">
                        <h3 class="panel-title">Sent Notifications</h3>
                        <p class="panel-sub">Latest 30 messages.</p>
                        <div class="mini-list" id="notificationHistory"></div>
                    </div>
                </div>
            </div>

        </section>

        <!-- ================= FOOTER ================= -->
        <footer class="site-footer">
            <div class="footer-content">

                <div class="footer-brand">
                    <div class="footer-brand-title">
                        <img src="../images/logo.png" alt="SmartMinibus Logo" onerror="this.style.visibility='hidden'">
                        <span>
                            <span class="footer-smart">Smart</span><span class="footer-minibus">Minibus</span>
                        </span>
                    </div>
                    <p>Empowering Gonzaga commuting with real-time analytics,<br>
                    location intelligence, and automated schedules.</p>
                </div>

                <div class="footer-links">
                    <h4>Quick Links</h4>
                    <div class="footer-link-row">
                        <a href="../index.php">Home</a>
                        <a href="#notifications" data-view-link="notifications">Notifications</a>
                        <a href="#">About</a>
                    </div>
                </div>

                <div class="footer-contact">
                    <h4>Contact Us</h4>
                    <p>📞 <span>+63 955 555 5555</span></p>
                    <p>✉️ <a href="mailto:support@smartminibus.ph">support@smartminibus.ph</a></p>
                    <p>📍 <span>Gonzaga, Cagayan, Philippines</span></p>
                </div>

                <div class="footer-social">
                    <h4>Follow Us</h4>
                    <div class="social-icons">
                        <a href="https://www.facebook.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="Facebook">f</a>
                        <a href="https://x.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="X">𝕏</a>
                        <a href="https://www.instagram.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>
                                <circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/>
                                <circle cx="17.5" cy="6.5" r="1" fill="currentColor"/>
                            </svg>
                        </a>
                    </div>
                </div>

            </div>

            <div class="footer-bottom">© <?php echo date('Y'); ?> SmartMinibus. All rights reserved.</div>
        </footer>

    </main>
</div>

<!-- ==========================================
     MODAL + TOASTS
=========================================== -->
<div class="modal" id="modal" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-head">
            <h3 id="modalTitle"></h3>
            <button type="button" class="icon-btn" id="modalClose" aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="modalForm" novalidate>
            <div class="modal-body">
                <div class="form-error" id="modalError"></div>
                <div id="modalBody"></div>
            </div>
            <div class="modal-foot" id="modalFoot"></div>
        </form>
    </div>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<!-- ==========================================
     LEAFLET JS
=========================================== -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
(function () {
    "use strict";

    const CSRF = <?php echo json_encode($csrf); ?>;
    const ENDPOINT = window.location.pathname;

    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));

    const state = {
        minibuses: [], drivers: [], commuters: [], routes: [],
        complaints: [], notifications: [], live: [],
        complaintFilter: "all"
    };

    // ======================================
    // HELPERS
    // ======================================

    function esc(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function fmtDate(value) {
        return value ? String(value).slice(0, 16) : "—";
    }

    function api(action, data) {
        const isPost = data !== undefined;
        const url = ENDPOINT + "?action=" + encodeURIComponent(action);
        const options = { credentials: "same-origin", headers: { "Accept": "application/json" } };

        if (isPost) {
            const fd = new FormData();
            fd.append("action", action);
            Object.keys(data).forEach(function (key) {
                fd.append(key, data[key] === null || data[key] === undefined ? "" : data[key]);
            });
            options.method = "POST";
            options.body = fd;
            options.headers["X-CSRF-Token"] = CSRF;
        }

        return fetch(url, options)
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = "../login.php";
                }
                return res.json().catch(function () {
                    return { ok: false, message: "Invalid server response." };
                });
            })
            .then(function (json) {
                if (!json.ok) throw new Error(json.message || "Request failed.");
                return json;
            });
    }

    function toast(message, type) {
        const el = document.createElement("div");
        el.className = "toast " + (type || "");
        el.textContent = message;
        $("#toastStack").appendChild(el);
        setTimeout(function () { el.remove(); }, 3800);
    }

    function badge(text, color) {
        return '<span class="badge ' + color + '">' + esc(text) + "</span>";
    }

    function busStatusBadge(status) {
        if (status === "active") return badge("Active", "green");
        if (status === "maintenance") return badge("Maintenance", "orange");
        return badge("Inactive", "gray");
    }

    function accountBadge(status) {
        return status === "active" ? badge("Active", "green") : badge("Inactive", "gray");
    }

    function complaintBadge(status) {
        if (status === "Resolved") return badge("Resolved", "green");
        if (status === "In Progress") return badge("In Progress", "blue");
        return badge("Pending", "orange");
    }

    function empty(icon, text) {
        return '<div class="empty-state"><i class="fas ' + icon + '"></i>' + esc(text) + "</div>";
    }

    function loadError(message) {
        return '<div class="load-error"><i class="fas fa-circle-exclamation"></i> ' + esc(message) + "</div>";
    }

    function matches(haystack, term) {
        return String(haystack).toLowerCase().indexOf(term.toLowerCase()) !== -1;
    }

    // ======================================
    // MOBILE NAVIGATION + PROFILE DROPDOWN
    // ======================================

    const menuButton = $("#menuButton");
    const topNav = $("#topNav");
    const profileMenu = $("#profileMenu");
    const profileButton = $("#profileButton");

    function setNavOpen(isOpen) {
        topNav.classList.toggle("open", isOpen);
        menuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    }

    function setProfileOpen(isOpen) {
        profileMenu.classList.toggle("open", isOpen);
        profileButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    }

    menuButton.addEventListener("click", function (e) {
        e.stopPropagation();
        setProfileOpen(false);
        setNavOpen(!topNav.classList.contains("open"));
    });

    profileButton.addEventListener("click", function (e) {
        e.stopPropagation();
        setNavOpen(false);
        setProfileOpen(!profileMenu.classList.contains("open"));
    });

    document.addEventListener("click", function (e) {
        if (!topNav.contains(e.target) && !menuButton.contains(e.target)) setNavOpen(false);
        if (!profileMenu.contains(e.target)) setProfileOpen(false);
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            setNavOpen(false);
            setProfileOpen(false);
            closeModal();
        }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 1280) setNavOpen(false);
        if (map) map.invalidateSize();
    });

    // ======================================
    // VIEW SWITCHING
    // ======================================

    const VIEWS = ["overview", "minibuses", "drivers", "commuters", "routes", "live", "complaints", "notifications"];
    let currentView = "overview";

    function showView(name) {
        if (VIEWS.indexOf(name) === -1) name = "overview";
        currentView = name;

        $$(".view").forEach(function (v) { v.classList.toggle("active", v.id === "view-" + name); });
        $$(".nav-link[data-view]").forEach(function (a) { a.classList.toggle("active", a.dataset.view === name); });

        if (window.location.hash !== "#" + name) history.replaceState(null, "", "#" + name);

        setNavOpen(false);
        setProfileOpen(false);
        window.scrollTo({ top: 0 });

        if (name === "live") {
            initMap();
            loadLive();
        }
    }

    $$(".nav-link[data-view], [data-view-link]").forEach(function (a) {
        a.addEventListener("click", function (e) {
            e.preventDefault();
            showView(a.dataset.view || a.dataset.viewLink);
        });
    });

    window.addEventListener("hashchange", function () {
        showView(window.location.hash.replace("#", ""));
    });

    // ======================================
    // MODAL
    // ======================================

    const modal = $("#modal");
    const modalForm = $("#modalForm");
    let modalSubmit = null;

    function openModal(opts) {
        $("#modalTitle").textContent = opts.title;
        $("#modalBody").innerHTML = opts.body;
        $("#modalError").classList.remove("show");
        modalSubmit = opts.onSubmit || null;

        let foot = '<button type="button" class="btn btn-outline" id="modalCancel">' + (opts.onSubmit ? "Cancel" : "Close") + "</button>";
        if (opts.onSubmit) {
            foot += '<button type="submit" class="btn ' + (opts.danger ? "btn-danger" : "btn-primary") + '" id="modalSubmit">' +
                esc(opts.submitText || "Save") + "</button>";
        }
        $("#modalFoot").innerHTML = foot;
        $("#modalCancel").addEventListener("click", closeModal);

        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";

        const first = $("#modalBody input, #modalBody select, #modalBody textarea");
        if (first) setTimeout(function () { first.focus(); }, 60);
    }

    function closeModal() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        modalSubmit = null;
    }

    $("#modalClose").addEventListener("click", closeModal);
    modal.addEventListener("click", function (e) { if (e.target === modal) closeModal(); });

    modalForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!modalSubmit) return;

        const data = {};
        new FormData(modalForm).forEach(function (value, key) { data[key] = value; });

        const btn = $("#modalSubmit");
        const err = $("#modalError");
        err.classList.remove("show");
        if (btn) btn.disabled = true;

        Promise.resolve(modalSubmit(data))
            .then(function () { closeModal(); })
            .catch(function (error) {
                err.textContent = error.message;
                err.classList.add("show");
            })
            .finally(function () { if (btn) btn.disabled = false; });
    });

    function confirmAction(title, message, buttonText, task) {
        openModal({
            title: title,
            body: '<p class="confirm-text">' + message + "</p>",
            submitText: buttonText,
            danger: true,
            onSubmit: task
        });
    }

    // Form field builders
    function field(label, name, value, o) {
        o = o || {};
        return '<div class="field' + (o.full ? " full" : "") + '">' +
            '<label for="f_' + name + '">' + esc(label) + (o.required ? " <em>*</em>" : "") + "</label>" +
            '<input id="f_' + name + '" name="' + name + '" type="' + (o.type || "text") + '" value="' + esc(value) + '"' +
            (o.placeholder ? ' placeholder="' + esc(o.placeholder) + '"' : "") +
            (o.attrs ? " " + o.attrs : "") + ">" +
            (o.hint ? "<small>" + esc(o.hint) + "</small>" : "") + "</div>";
    }

    function select(label, name, options, selected, o) {
        o = o || {};
        let html = '<div class="field' + (o.full ? " full" : "") + '"><label for="f_' + name + '">' + esc(label) +
            (o.required ? " <em>*</em>" : "") + '</label><select id="f_' + name + '" name="' + name + '">';
        options.forEach(function (opt) {
            html += '<option value="' + esc(opt[0]) + '"' + (String(opt[0]) === String(selected) ? " selected" : "") + ">" + esc(opt[1]) + "</option>";
        });
        return html + "</select></div>";
    }

    // ======================================
    // STATS + OVERVIEW
    // ======================================

    function loadStats() {
        return api("stats").then(function (res) {
            const d = res.data;
            $("#statCommuters").textContent = d.commuters;
            $("#statDrivers").textContent = d.drivers;
            $("#statMinibuses").textContent = d.minibuses;
            $("#statActive").textContent = d.active;
            $("#statRoutes").textContent = d.routes;
            $("#statComplaints").textContent = d.complaints;

            const b = $("#complaintBadge");
            b.textContent = d.complaints;
            b.classList.toggle("show", d.complaints > 0);
        }).catch(function (error) {
            toast(error.message, "error");
        });
    }

    function renderOverview() {
        const c = state.complaints.slice(0, 5);
        $("#overviewComplaints").innerHTML = c.length ? c.map(function (x) {
            return '<div class="mini-item"><div><strong>' + esc(x.subject) + "</strong><p>" +
                esc(x.commuter_name || "Unknown commuter") + " · " + fmtDate(x.created_at) + "</p></div>" +
                complaintBadge(x.status) + "</div>";
        }).join("") : empty("fa-circle-check", "No complaints yet.");

        const n = state.notifications.slice(0, 4);
        $("#overviewNotifications").innerHTML = n.length ? n.map(function (x) {
            return '<div class="mini-item"><div><strong>' + esc(x.title) + "</strong><p>" +
                esc(audienceLabel(x.audience)) + "</p></div><time>" + fmtDate(x.created_at) + "</time></div>";
        }).join("") : empty("fa-bell-slash", "No notifications sent yet.");
    }

    function audienceLabel(a) {
        if (a === "commuters") return "Commuters";
        if (a === "drivers") return "Drivers";
        return "Everyone";
    }

    // ======================================
    // MINIBUSES
    // ======================================

    function loadMinibuses() {
        return api("minibus_list").then(function (res) {
            state.minibuses = res.data;
            renderMinibuses();
        }).catch(function (error) {
            $("#minibusTable").innerHTML = loadError(error.message);
        });
    }

    function renderMinibuses() {
        const term = $("#searchMinibuses").value.trim();
        const list = state.minibuses.filter(function (m) {
            return !term || matches([m.bus_number, m.plate_number, m.driver_name, m.route_name].join(" "), term);
        });

        if (!list.length) {
            $("#minibusTable").innerHTML = empty("fa-bus", term ? "No minibuses match your search." : "No minibuses registered yet.");
            return;
        }

        let html = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
            "<th>Bus No.</th><th>Plate No.</th><th>Capacity</th><th>Route</th><th>Assigned Driver</th><th>Status</th><th>Actions</th>" +
            "</tr></thead><tbody>";

        list.forEach(function (m) {
            html += "<tr>" +
                '<td data-label="Bus No."><span class="cell-title">Bus #' + esc(m.bus_number) + "</span></td>" +
                '<td data-label="Plate No.">' + esc(m.plate_number) + "</td>" +
                '<td data-label="Capacity">' + esc(m.capacity) + " seats</td>" +
                '<td data-label="Route">' + esc(m.route_name || "—") + "</td>" +
                '<td data-label="Driver">' + (m.driver_name ? esc(m.driver_name) : '<span class="cell-sub">Unassigned</span>') + "</td>" +
                '<td data-label="Status">' + busStatusBadge(m.status) + "</td>" +
                '<td><div class="row-actions">' +
                '<button class="icon-btn" type="button" data-act="edit" data-id="' + m.minibus_id + '" title="Edit"><i class="fas fa-pen"></i></button>' +
                '<button class="icon-btn danger" type="button" data-act="delete" data-id="' + m.minibus_id + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                "</div></td></tr>";
        });

        $("#minibusTable").innerHTML = html + "</tbody></table></div>";
    }

    function minibusForm(m) {
        m = m || {};
        const routes = [["0", "— No route —"]].concat(state.routes.map(function (r) { return [r.route_id, r.route_name]; }));
        const drivers = [["0", "— Unassigned —"]].concat(state.drivers.map(function (d) {
            const taken = d.minibus_id && String(d.minibus_id) !== String(m.minibus_id || "");
            return [d.id, d.name + (taken ? " (Bus " + d.bus_number + ")" : "")];
        }));

        openModal({
            title: m.minibus_id ? "Edit Minibus" : "Add Minibus",
            submitText: m.minibus_id ? "Save Changes" : "Add Minibus",
            body: '<div class="form-grid">' +
                field("Bus Number", "bus_number", m.bus_number || "", { required: true, placeholder: "e.g. 30", attrs: 'maxlength="30"' }) +
                field("Plate Number", "plate_number", m.plate_number || "", { required: true, placeholder: "e.g. ABC 1234", attrs: 'maxlength="20"' }) +
                field("Capacity (seats)", "capacity", m.capacity || 20, { required: true, type: "number", attrs: 'min="1" max="100"' }) +
                select("Status", "status", [["active", "Active"], ["inactive", "Inactive"], ["maintenance", "Maintenance"]], m.status || "active", { required: true }) +
                select("Route", "route_id", routes, m.route_id || 0) +
                select("Assigned Driver", "driver_id", drivers, m.driver_id || 0) +
                "</div>",
            onSubmit: function (data) {
                data.id = m.minibus_id || 0;
                return api("minibus_save", data).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadMinibuses(), loadDrivers(), loadStats()]);
                });
            }
        });
    }

    $("#addMinibusBtn").addEventListener("click", function () { minibusForm(); });
    $("#searchMinibuses").addEventListener("input", renderMinibuses);

    $("#minibusTable").addEventListener("click", function (e) {
        const btn = e.target.closest("button[data-act]");
        if (!btn) return;
        const m = state.minibuses.find(function (x) { return String(x.minibus_id) === btn.dataset.id; });
        if (!m) return;

        if (btn.dataset.act === "edit") return minibusForm(m);

        confirmAction("Delete Minibus",
            "Delete <strong>Bus #" + esc(m.bus_number) + "</strong> (" + esc(m.plate_number) + ")? This cannot be undone.",
            "Delete",
            function () {
                return api("minibus_delete", { id: m.minibus_id }).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadMinibuses(), loadDrivers(), loadStats()]);
                });
            });
    });

    // ======================================
    // DRIVERS
    // ======================================

    function loadDrivers() {
        return api("driver_list").then(function (res) {
            state.drivers = res.data;
            renderDrivers();
        }).catch(function (error) {
            $("#driverTable").innerHTML = loadError(error.message);
        });
    }

    function renderDrivers() {
        const term = $("#searchDrivers").value.trim();
        const list = state.drivers.filter(function (d) {
            return !term || matches([d.name, d.email, d.phone, d.bus_number, d.plate_number].join(" "), term);
        });

        if (!list.length) {
            $("#driverTable").innerHTML = empty("fa-id-card", term ? "No drivers match your search." : "No drivers registered yet.");
            return;
        }

        let html = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
            "<th>Driver</th><th>Phone</th><th>Assigned Minibus</th><th>Status</th><th>Actions</th>" +
            "</tr></thead><tbody>";

        list.forEach(function (d) {
            html += "<tr>" +
                '<td data-label="Driver"><span class="cell-title">' + esc(d.name) + '</span><span class="cell-sub">' + esc(d.email) + "</span></td>" +
                '<td data-label="Phone">' + esc(d.phone || "—") + "</td>" +
                '<td data-label="Minibus">' + (d.bus_number
                    ? "Bus #" + esc(d.bus_number) + '<span class="cell-sub">' + esc(d.plate_number) + "</span>"
                    : '<span class="cell-sub">Unassigned</span>') + "</td>" +
                '<td data-label="Status">' + accountBadge(d.status) + "</td>" +
                '<td><div class="row-actions">' +
                '<button class="icon-btn" type="button" data-act="edit" data-id="' + d.id + '" title="Edit"><i class="fas fa-pen"></i></button>' +
                '<button class="icon-btn danger" type="button" data-act="delete" data-id="' + d.id + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                "</div></td></tr>";
        });

        $("#driverTable").innerHTML = html + "</tbody></table></div>";
    }

    function driverForm(d) {
        d = d || {};
        const buses = [["0", "— Unassigned —"]].concat(state.minibuses.map(function (m) {
            const taken = m.driver_id && String(m.driver_id) !== String(d.id || "");
            return [m.minibus_id, "Bus #" + m.bus_number + " (" + m.plate_number + ")" + (taken ? " - currently " + m.driver_name : "")];
        }));

        openModal({
            title: d.id ? "Edit Driver" : "Add Driver",
            submitText: d.id ? "Save Changes" : "Add Driver",
            body: '<div class="form-grid">' +
                field("Full Name", "name", d.name || "", { required: true, full: true, attrs: 'maxlength="100"' }) +
                field("Email", "email", d.email || "", { required: true, type: "email", attrs: 'maxlength="120"' }) +
                field("Phone", "phone", d.phone || "", { attrs: 'maxlength="30"' }) +
                field(d.id ? "New Password" : "Password", "password", "", {
                    required: !d.id, type: "password", attrs: 'autocomplete="new-password" minlength="6"',
                    hint: d.id ? "Leave blank to keep the current password." : "At least 6 characters."
                }) +
                select("Status", "status", [["active", "Active"], ["inactive", "Inactive"]], d.status || "active", { required: true }) +
                select("Assigned Minibus", "minibus_id", buses, d.minibus_id || 0, { full: true }) +
                "</div>",
            onSubmit: function (data) {
                data.id = d.id || 0;
                return api("driver_save", data).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadDrivers(), loadMinibuses(), loadStats()]);
                });
            }
        });
    }

    $("#addDriverBtn").addEventListener("click", function () { driverForm(); });
    $("#searchDrivers").addEventListener("input", renderDrivers);

    $("#driverTable").addEventListener("click", function (e) {
        const btn = e.target.closest("button[data-act]");
        if (!btn) return;
        const d = state.drivers.find(function (x) { return String(x.id) === btn.dataset.id; });
        if (!d) return;

        if (btn.dataset.act === "edit") return driverForm(d);

        confirmAction("Delete Driver",
            "Delete driver <strong>" + esc(d.name) + "</strong>? Their minibus assignment will be removed. This cannot be undone.",
            "Delete",
            function () {
                return api("driver_delete", { id: d.id }).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadDrivers(), loadMinibuses(), loadStats()]);
                });
            });
    });

    // ======================================
    // COMMUTERS
    // ======================================

    function loadCommuters() {
        return api("commuter_list").then(function (res) {
            state.commuters = res.data;
            renderCommuters();
        }).catch(function (error) {
            $("#commuterTable").innerHTML = loadError(error.message);
        });
    }

    function renderCommuters() {
        const term = $("#searchCommuters").value.trim();
        const list = state.commuters.filter(function (c) {
            return !term || matches([c.name, c.email, c.phone].join(" "), term);
        });

        if (!list.length) {
            $("#commuterTable").innerHTML = empty("fa-users", term ? "No commuters match your search." : "No commuters registered yet.");
            return;
        }

        let html = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
            "<th>Commuter</th><th>Phone</th><th>Registered</th><th>Status</th><th>Actions</th>" +
            "</tr></thead><tbody>";

        list.forEach(function (c) {
            const active = c.status === "active";
            html += "<tr>" +
                '<td data-label="Commuter"><span class="cell-title">' + esc(c.name) + '</span><span class="cell-sub">' + esc(c.email) + "</span></td>" +
                '<td data-label="Phone">' + esc(c.phone || "—") + "</td>" +
                '<td data-label="Registered">' + fmtDate(c.created_at) + "</td>" +
                '<td data-label="Status">' + accountBadge(c.status) + "</td>" +
                '<td><div class="row-actions">' +
                '<button class="icon-btn" type="button" data-act="view" data-id="' + c.id + '" title="View details"><i class="fas fa-eye"></i></button>' +
                '<button class="icon-btn" type="button" data-act="toggle" data-id="' + c.id + '" title="' + (active ? "Deactivate" : "Activate") + '"><i class="fas ' + (active ? "fa-user-slash" : "fa-user-check") + '"></i></button>' +
                '<button class="icon-btn danger" type="button" data-act="delete" data-id="' + c.id + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                "</div></td></tr>";
        });

        $("#commuterTable").innerHTML = html + "</tbody></table></div>";
    }

    $("#searchCommuters").addEventListener("input", renderCommuters);

    $("#commuterTable").addEventListener("click", function (e) {
        const btn = e.target.closest("button[data-act]");
        if (!btn) return;
        const c = state.commuters.find(function (x) { return String(x.id) === btn.dataset.id; });
        if (!c) return;

        if (btn.dataset.act === "view") {
            return openModal({
                title: "Commuter Details",
                body: '<div class="detail-list">' +
                    "<div><span>Full Name</span><strong>" + esc(c.name) + "</strong></div>" +
                    "<div><span>Status</span><strong>" + esc(c.status) + "</strong></div>" +
                    '<div class="full"><span>Email</span><strong>' + esc(c.email) + "</strong></div>" +
                    "<div><span>Phone</span><strong>" + esc(c.phone || "—") + "</strong></div>" +
                    "<div><span>Registered</span><strong>" + fmtDate(c.created_at) + "</strong></div>" +
                    "</div>"
            });
        }

        if (btn.dataset.act === "toggle") {
            const next = c.status === "active" ? "inactive" : "active";
            return confirmAction(next === "inactive" ? "Deactivate Account" : "Activate Account",
                (next === "inactive" ? "Deactivate" : "Activate") + " the account of <strong>" + esc(c.name) + "</strong>?",
                next === "inactive" ? "Deactivate" : "Activate",
                function () {
                    return api("commuter_status", { id: c.id, status: next }).then(function (res) {
                        toast(res.message, "success");
                        return loadCommuters();
                    });
                });
        }

        confirmAction("Delete Commuter Account",
            "Delete the account of <strong>" + esc(c.name) + "</strong>? This cannot be undone.",
            "Delete",
            function () {
                return api("commuter_delete", { id: c.id }).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadCommuters(), loadStats()]);
                });
            });
    });

    // ======================================
    // ROUTES
    // ======================================

    const PRIMARY_ROUTE = {
        route_name: "Santa Ana - Tuguegarao",
        origin: "Santa Ana, Cagayan",
        destination: "Tuguegarao, Cagayan",
        origin_lat: "18.4614", origin_lng: "122.1398",
        dest_lat: "17.6132", dest_lng: "121.7270",
        status: "active"
    };

    function loadRoutes() {
        return api("route_list").then(function (res) {
            state.routes = res.data;
            renderRoutes();
        }).catch(function (error) {
            $("#routeTable").innerHTML = loadError(error.message);
        });
    }

    function isPrimary(r) {
        const t = (r.origin + " " + r.destination + " " + r.route_name).toLowerCase();
        return t.indexOf("santa ana") !== -1 && t.indexOf("tuguegarao") !== -1;
    }

    function renderRoutes() {
        // Santa Ana <-> Tuguegarao highlight
        const primary = state.routes.find(isPrimary);
        $("#primaryRoute").innerHTML = '<div class="route-highlight"><div>' +
            '<span class="small-label">PRIMARY CORRIDOR</span>' +
            '<div class="route-flow">Santa Ana, Cagayan <i class="fas fa-right-left"></i> Tuguegarao, Cagayan</div>' +
            "<p>" + (primary
                ? esc(primary.bus_count) + " minibus(es) assigned · " + (primary.status === "active" ? "Active" : "Inactive")
                : "This route is not registered yet.") + "</p></div>" +
            (primary
                ? '<button class="btn btn-outline" type="button" id="editPrimaryBtn"><i class="fas fa-pen"></i> Manage Route</button>'
                : '<button class="btn btn-success" type="button" id="addPrimaryBtn"><i class="fas fa-plus"></i> Add This Route</button>') +
            "</div>";

        const term = $("#searchRoutes").value.trim();
        const list = state.routes.filter(function (r) {
            return !term || matches([r.route_name, r.origin, r.destination].join(" "), term);
        });

        if (!list.length) {
            $("#routeTable").innerHTML = empty("fa-route", term ? "No routes match your search." : "No routes yet.");
            return;
        }

        let html = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
            "<th>Route</th><th>Origin → Destination</th><th>Distance</th><th>Est. Time</th><th>Minibuses</th><th>Status</th><th>Actions</th>" +
            "</tr></thead><tbody>";

        list.forEach(function (r) {
            html += "<tr>" +
                '<td data-label="Route"><span class="cell-title">' + esc(r.route_name) + "</span></td>" +
                '<td data-label="Path">' + esc(r.origin) + " → " + esc(r.destination) + "</td>" +
                '<td data-label="Distance">' + (r.distance_km !== null ? esc(r.distance_km) + " km" : "—") + "</td>" +
                '<td data-label="Est. Time">' + (r.est_minutes !== null ? esc(r.est_minutes) + " min" : "—") + "</td>" +
                '<td data-label="Minibuses">' + esc(r.bus_count) + "</td>" +
                '<td data-label="Status">' + accountBadge(r.status) + "</td>" +
                '<td><div class="row-actions">' +
                '<button class="icon-btn" type="button" data-act="edit" data-id="' + r.route_id + '" title="Edit"><i class="fas fa-pen"></i></button>' +
                '<button class="icon-btn danger" type="button" data-act="delete" data-id="' + r.route_id + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                "</div></td></tr>";
        });

        $("#routeTable").innerHTML = html + "</tbody></table></div>";
    }

    function routeForm(r) {
        r = r || {};
        openModal({
            title: r.route_id ? "Edit Route" : "Add Route",
            submitText: r.route_id ? "Save Changes" : "Add Route",
            body: '<div class="form-grid">' +
                field("Route Name", "route_name", r.route_name || "", { required: true, full: true, attrs: 'maxlength="120"' }) +
                field("Origin", "origin", r.origin || "", { required: true, attrs: 'maxlength="120"' }) +
                field("Destination", "destination", r.destination || "", { required: true, attrs: 'maxlength="120"' }) +
                field("Distance (km)", "distance_km", r.distance_km === null || r.distance_km === undefined ? "" : r.distance_km, { type: "number", attrs: 'min="0" step="0.01"' }) +
                field("Estimated Time (minutes)", "est_minutes", r.est_minutes === null || r.est_minutes === undefined ? "" : r.est_minutes, { type: "number", attrs: 'min="0" step="1"' }) +
                field("Origin Latitude", "origin_lat", r.origin_lat || "", { type: "number", attrs: 'step="any"', hint: "Optional - for the live map." }) +
                field("Origin Longitude", "origin_lng", r.origin_lng || "", { type: "number", attrs: 'step="any"' }) +
                field("Destination Latitude", "dest_lat", r.dest_lat || "", { type: "number", attrs: 'step="any"' }) +
                field("Destination Longitude", "dest_lng", r.dest_lng || "", { type: "number", attrs: 'step="any"' }) +
                select("Status", "status", [["active", "Active"], ["inactive", "Inactive"]], r.status || "active", { required: true, full: true }) +
                "</div>",
            onSubmit: function (data) {
                data.id = r.route_id || 0;
                return api("route_save", data).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadRoutes(), loadMinibuses(), loadStats()]);
                });
            }
        });
    }

    $("#addRouteBtn").addEventListener("click", function () { routeForm(); });
    $("#searchRoutes").addEventListener("input", renderRoutes);

    $("#primaryRoute").addEventListener("click", function (e) {
        if (e.target.closest("#addPrimaryBtn")) routeForm(PRIMARY_ROUTE);
        if (e.target.closest("#editPrimaryBtn")) {
            const p = state.routes.find(isPrimary);
            if (p) routeForm(p);
        }
    });

    $("#routeTable").addEventListener("click", function (e) {
        const btn = e.target.closest("button[data-act]");
        if (!btn) return;
        const r = state.routes.find(function (x) { return String(x.route_id) === btn.dataset.id; });
        if (!r) return;

        if (btn.dataset.act === "edit") return routeForm(r);

        confirmAction("Delete Route",
            "Delete <strong>" + esc(r.route_name) + "</strong>? Minibuses on this route will become unassigned.",
            "Delete",
            function () {
                return api("route_delete", { id: r.route_id }).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadRoutes(), loadMinibuses(), loadStats()]);
                });
            });
    });

    // ======================================
    // LIVE MONITORING (Leaflet)
    // ======================================

    let map = null;
    const markers = {};
    let routeLayer = null;
    let firstFit = true;

    function initMap() {
        if (map) {
            setTimeout(function () { map.invalidateSize(); }, 80);
            return;
        }

        map = L.map("liveMap").setView([18.04, 121.93], 9);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        routeLayer = L.layerGroup().addTo(map);
        drawRoutes();
        setTimeout(function () { map.invalidateSize(); }, 120);
    }

    function drawRoutes() {
        if (!map || !routeLayer) return;
        routeLayer.clearLayers();

        state.routes.forEach(function (r) {
            if (r.status !== "active" || r.origin_lat === null || r.dest_lat === null) return;

            const a = [parseFloat(r.origin_lat), parseFloat(r.origin_lng)];
            const b = [parseFloat(r.dest_lat), parseFloat(r.dest_lng)];
            if ([a[0], a[1], b[0], b[1]].some(isNaN)) return;

            L.polyline([a, b], { color: "#13b37a", weight: 4, dashArray: "8, 8", opacity: .8 }).addTo(routeLayer);
            L.circleMarker(a, { radius: 7, color: "#0b2345", fillColor: "#fff", fillOpacity: 1, weight: 3 })
                .bindPopup("<strong>" + esc(r.origin) + "</strong>").addTo(routeLayer);
            L.circleMarker(b, { radius: 7, color: "#13b37a", fillColor: "#fff", fillOpacity: 1, weight: 3 })
                .bindPopup("<strong>" + esc(r.destination) + "</strong>").addTo(routeLayer);
        });
    }

    function loadLive() {
        return api("live").then(function (res) {
            state.live = res.data;
            renderLive();
        }).catch(function (error) {
            $("#liveList").innerHTML = loadError(error.message);
            $("#liveSummary").textContent = "Unavailable";
        });
    }

    function liveStatus(b) {
        if (b.online) return { text: "Online", color: "green" };
        if (b.latitude !== null) return { text: "Stale signal", color: "orange" };
        return { text: "No signal", color: "gray" };
    }

    function renderLive() {
        const list = state.live;
        const online = list.filter(function (b) { return b.online; }).length;
        $("#liveSummary").textContent = online + " online / " + list.length + " active";

        // List
        $("#liveList").innerHTML = list.length ? list.map(function (b) {
            const s = liveStatus(b);
            return '<div class="live-bus" data-id="' + b.minibus_id + '">' +
                '<div class="live-bus-top"><strong>Bus #' + esc(b.bus_number) + "</strong>" + badge(s.text, s.color) + "</div>" +
                "<p>Plate: " + esc(b.plate_number) + "<br>Driver: " + esc(b.driver_name || "Unassigned") +
                (b.recorded_at ? "<br>Updated: " + esc(String(b.recorded_at).slice(11, 19)) : "") + "</p></div>";
        }).join("") : empty("fa-bus", "No active minibuses.");

        if (!map) return;

        // Markers
        const seen = {};
        const points = [];

        list.forEach(function (b) {
            if (b.latitude === null || b.longitude === null) return;
            const ll = [parseFloat(b.latitude), parseFloat(b.longitude)];
            if (isNaN(ll[0]) || isNaN(ll[1])) return;

            seen[b.minibus_id] = true;
            points.push(ll);

            const speed = b.speed !== null && b.speed !== "" ? parseFloat(b.speed).toFixed(0) + " km/h" : "—";
            const s = liveStatus(b);
            const popup = "<strong>Bus #" + esc(b.bus_number) + "</strong><br>" +
                "Plate: " + esc(b.plate_number) + "<br>" +
                "Driver: " + esc(b.driver_name || "Unassigned") + "<br>" +
                "Status: " + esc(s.text) + "<br>" +
                "Speed: " + esc(speed) + "<br>" +
                "Updated: " + esc(String(b.recorded_at || "").slice(11, 19));

            const icon = L.divIcon({
                className: "",
                html: '<div class="admin-bus-marker' + (b.online ? "" : " stale") + '"><i class="fas fa-bus"></i></div>',
                iconSize: [38, 38],
                iconAnchor: [19, 19],
                popupAnchor: [0, -18]
            });

            if (markers[b.minibus_id]) {
                markers[b.minibus_id].setLatLng(ll).setIcon(icon).setPopupContent(popup);
            } else {
                markers[b.minibus_id] = L.marker(ll, { icon: icon }).bindPopup(popup).addTo(map);
            }
        });

        Object.keys(markers).forEach(function (id) {
            if (!seen[id]) {
                map.removeLayer(markers[id]);
                delete markers[id];
            }
        });

        if (points.length && firstFit) {
            map.fitBounds(points, { padding: [50, 50], maxZoom: 14 });
            firstFit = false;
        }
    }

    $("#liveList").addEventListener("click", function (e) {
        const card = e.target.closest(".live-bus");
        if (!card || !map) return;
        const m = markers[card.dataset.id];
        if (m) {
            map.setView(m.getLatLng(), 15);
            m.openPopup();
            document.getElementById("liveMap").scrollIntoView({ behavior: "smooth", block: "center" });
        } else {
            toast("This minibus has no location yet.");
        }
    });

    // ======================================
    // COMPLAINTS
    // ======================================

    function loadComplaints() {
        return api("complaint_list").then(function (res) {
            state.complaints = res.data;
            renderComplaints();
            renderOverview();
        }).catch(function (error) {
            $("#complaintTable").innerHTML = loadError(error.message);
            $("#overviewComplaints").innerHTML = loadError(error.message);
        });
    }

    function renderComplaints() {
        const term = $("#searchComplaints").value.trim();
        const list = state.complaints.filter(function (c) {
            const okStatus = state.complaintFilter === "all" || c.status === state.complaintFilter;
            const okTerm = !term || matches([c.subject, c.commuter_name, c.commuter_email].join(" "), term);
            return okStatus && okTerm;
        });

        if (!list.length) {
            $("#complaintTable").innerHTML = empty("fa-inbox", "No complaints found.");
            return;
        }

        let html = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
            "<th>Subject</th><th>Commuter</th><th>Date</th><th>Status</th><th>Actions</th>" +
            "</tr></thead><tbody>";

        list.forEach(function (c) {
            html += "<tr>" +
                '<td data-label="Subject"><span class="cell-title cell-clip">' + esc(c.subject) + "</span></td>" +
                '<td data-label="Commuter">' + esc(c.commuter_name || "Unknown") + '<span class="cell-sub">' + esc(c.commuter_email || "") + "</span></td>" +
                '<td data-label="Date">' + fmtDate(c.created_at) + "</td>" +
                '<td data-label="Status">' + complaintBadge(c.status) + "</td>" +
                '<td><div class="row-actions">' +
                '<button class="icon-btn" type="button" data-act="view" data-id="' + c.complaint_id + '" title="View and update"><i class="fas fa-eye"></i></button>' +
                '<button class="icon-btn danger" type="button" data-act="delete" data-id="' + c.complaint_id + '" title="' +
                (c.status === "Resolved" ? "Delete" : "Only resolved complaints can be deleted") + '"' +
                (c.status === "Resolved" ? "" : " disabled") + '><i class="fas fa-trash"></i></button>' +
                "</div></td></tr>";
        });

        $("#complaintTable").innerHTML = html + "</tbody></table></div>";
    }

    $("#searchComplaints").addEventListener("input", renderComplaints);

    $("#complaintTabs").addEventListener("click", function (e) {
        const tab = e.target.closest(".filter-tab");
        if (!tab) return;
        $$("#complaintTabs .filter-tab").forEach(function (t) { t.classList.toggle("active", t === tab); });
        state.complaintFilter = tab.dataset.filter;
        renderComplaints();
    });

    $("#complaintTable").addEventListener("click", function (e) {
        const btn = e.target.closest("button[data-act]");
        if (!btn || btn.disabled) return;
        const c = state.complaints.find(function (x) { return String(x.complaint_id) === btn.dataset.id; });
        if (!c) return;

        if (btn.dataset.act === "view") {
            return openModal({
                title: "Complaint Details",
                submitText: "Update Status",
                body: '<div class="detail-list">' +
                    '<div class="full"><span>Subject</span><strong>' + esc(c.subject) + "</strong></div>" +
                    "<div><span>Commuter</span><strong>" + esc(c.commuter_name || "Unknown") + "</strong></div>" +
                    "<div><span>Email</span><strong>" + esc(c.commuter_email || "—") + "</strong></div>" +
                    "<div><span>Submitted</span><strong>" + fmtDate(c.created_at) + "</strong></div>" +
                    "<div><span>Last Updated</span><strong>" + fmtDate(c.updated_at) + "</strong></div>" +
                    '<div class="full"><span>Message</span><div class="detail-message">' + esc(c.message) + "</div></div>" +
                    '<div class="field full"><label for="f_admin_response">Response to commuter</label><textarea id="f_admin_response" name="admin_response" maxlength="3000" rows="4" placeholder="Write a response or resolution update...">' +
                    esc(c.admin_response || "") + "</textarea></div>" +
                    "</div><br>" +
                    select("Status", "status", [["Pending", "Pending"], ["In Progress", "In Progress"], ["Resolved", "Resolved"]], c.status, { full: true }),
                onSubmit: function (data) {
                    return api("complaint_status", {
                        id: c.complaint_id,
                        status: data.status,
                        admin_response: data.admin_response
                    }).then(function (res) {
                        toast(res.message, "success");
                        return Promise.all([loadComplaints(), loadStats()]);
                    });
                }
            });
        }

        confirmAction("Delete Complaint",
            "Delete the resolved complaint <strong>" + esc(c.subject) + "</strong>? This cannot be undone.",
            "Delete",
            function () {
                return api("complaint_delete", { id: c.complaint_id }).then(function (res) {
                    toast(res.message, "success");
                    return Promise.all([loadComplaints(), loadStats()]);
                });
            });
    });

    // ======================================
    // NOTIFICATIONS
    // ======================================

    function loadNotifications() {
        return api("notification_list").then(function (res) {
            state.notifications = res.data;
            renderNotifications();
            renderOverview();
        }).catch(function (error) {
            $("#notificationHistory").innerHTML = loadError(error.message);
            $("#overviewNotifications").innerHTML = loadError(error.message);
        });
    }

    function renderNotifications() {
        $("#notificationHistory").innerHTML = state.notifications.length ? state.notifications.map(function (n) {
            return '<div class="mini-item"><div><strong>' + esc(n.title) + "</strong><p>" + esc(n.message) + "</p>" +
                "<p>" + badge(audienceLabel(n.audience), "blue") + "</p></div><time>" + fmtDate(n.created_at) + "</time></div>";
        }).join("") : empty("fa-bell-slash", "No notifications sent yet.");
    }

    const nfMessage = $("#nfMessage");
    nfMessage.addEventListener("input", function () {
        $("#nfCount").textContent = nfMessage.value.length + " / 1000";
    });

    $("#notifyForm").addEventListener("submit", function (e) {
        e.preventDefault();

        const err = $("#notifyError");
        const btn = $("#nfSubmit");
        err.classList.remove("show");
        btn.disabled = true;

        api("notification_send", {
            audience: $("#nfAudience").value,
            title: $("#nfTitle").value,
            message: nfMessage.value
        }).then(function (res) {
            toast(res.message, "success");
            $("#nfTitle").value = "";
            nfMessage.value = "";
            $("#nfCount").textContent = "0 / 1000";
            return loadNotifications();
        }).catch(function (error) {
            err.textContent = error.message;
            err.classList.add("show");
        }).finally(function () {
            btn.disabled = false;
        });
    });

    // ======================================
    // START
    // ======================================

    // Routes/drivers/minibuses are loaded first because forms depend on them.
    Promise.all([loadRoutes(), loadDrivers(), loadMinibuses()]).then(function () {
        drawRoutes();
    });
    loadCommuters();
    loadComplaints();
    loadNotifications();
    loadStats();

    showView(window.location.hash.replace("#", "") || "overview");

    // Auto refresh
    setInterval(function () {
        if (document.hidden) return;
        if (currentView === "live") loadLive();
    }, 5000);

    setInterval(function () {
        if (!document.hidden) loadStats();
    }, 30000);

})();
</script>

</body>

</html>