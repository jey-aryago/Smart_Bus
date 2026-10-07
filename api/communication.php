<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(bool $ok, string $message = '', $data = null, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        ['ok' => $ok, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['commuter', 'driver', 'admin'], true)
) {
    out(false, 'Unauthorized. Please log in again.', null, 401);
}

require_once __DIR__ . '/../config/database.php';
$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function stmtrows(PDO $conn, string $sql, array $params = []): array
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

try {
    if ($action === 'complaint_submit' && $role === 'commuter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $type = substr(trim($_POST['complaint_type'] ?? 'other'), 0, 30);
        $message = trim($_POST['message'] ?? '');
        $routeId = filter_var($_POST['route_id'] ?? '', FILTER_VALIDATE_INT);
        $allowedTypes = ['service', 'driver', 'vehicle', 'route', 'other'];
        if (!in_array($type, $allowedTypes, true) || $message === '' || mb_strlen($message) > 3000) {
            out(false, 'Pumili ng complaint type at maglagay ng mensaheng hanggang 3000 characters.', null, 422);
        }

        $subject = substr(trim($_POST['subject'] ?? ''), 0, 150);
        if ($subject === '') {
            $subject = ucfirst($type) . ' complaint';
        }
        $routeId = $routeId && $routeId > 0 ? $routeId : null;

        $insertComplaintSql =
            "INSERT INTO complaints (user_id, complaint_type, route_id, subject, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'Pending', CURRENT_TIMESTAMP)";
        if (DB_DRIVER === 'pgsql') {
            $insertComplaintSql .= ' RETURNING complaint_id';
        }
        $stmt = $conn->prepare($insertComplaintSql);
        $stmt->execute([$uid, $type, $routeId, $subject, $message]);
        $complaintId = DB_DRIVER === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$conn->lastInsertId();

        $title = 'New commuter complaint';
        $body = 'A commuter submitted complaint #' . $complaintId . '. Please review it in Complaint Management.';
        $stmt = $conn->prepare(
            "INSERT INTO notifications (title, message, audience, sent_by, recipient_id)
             VALUES (?, ?, 'admin', ?, NULL)"
        );
        $stmt->execute([$title, $body, $uid]);
        out(true, 'Naipadala na ang complaint. Makikita mo rito ang status update.', ['complaint_id' => $complaintId]);
    }

    if ($action === 'notification_list') {
        $audience = $role === 'commuter' ? 'commuters' : ($role === 'driver' ? 'drivers' : 'admin');
        $rows = stmtrows(
            $conn,
            "SELECT n.notification_id, n.title, n.message, n.audience, n.created_at, n.sent_by, n.recipient_id,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS sender_name,
                    u.role AS sender_role,
                    CASE WHEN nr.user_id IS NULL THEN 0 ELSE 1 END AS is_read
               FROM notifications n
               LEFT JOIN users u ON u.user_id = n.sent_by
               LEFT JOIN notification_reads nr
                 ON nr.notification_id = n.notification_id AND nr.user_id = ?
              WHERE (n.audience = 'all' OR n.audience = ? OR n.recipient_id = ?)
                AND (n.recipient_id IS NULL OR n.recipient_id = ?)
              ORDER BY n.created_at DESC, n.notification_id DESC
              LIMIT 50",
            [$uid, $audience, $uid, $uid]
        );
        out(true, '', $rows);
    }

    if ($action === 'notification_send_driver' && $role === 'driver' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $routeId = (int)($_POST['route_id'] ?? 0);
        if ($title === '' || mb_strlen($title) > 120 || $message === '' || mb_strlen($message) > 1000) {
            out(false, 'Kailangan ang title at message (hanggang 1000 characters).', null, 422);
        }

        if ($routeId > 0) {
            $stmt = $conn->prepare(
                "INSERT INTO notifications (title, message, audience, sent_by, route_id)
                 VALUES (?, ?, 'commuters', ?, ?)"
            );
            $stmt->execute([$title, $message, $uid, $routeId]);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO notifications (title, message, audience, sent_by)
                 VALUES (?, ?, 'commuters', ?)"
            );
            $stmt->execute([$title, $message, $uid]);
        }
        out(true, 'Naipadala ang notification sa commuters.');
    }

    if ($action === 'notification_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId < 1) {
            out(false, 'Invalid notification.', null, 422);
        }
        $audience = $role === 'commuter' ? 'commuters' : ($role === 'driver' ? 'drivers' : 'admin');
        $readConflict = DB_DRIVER === 'pgsql'
            ? ' ON CONFLICT (notification_id, user_id) DO NOTHING'
            : '';
        $readInsert = "INSERT INTO notification_reads (notification_id, user_id)
                       SELECT notification_id, ?
                         FROM notifications
                        WHERE notification_id = ?
                          AND (audience = 'all' OR audience = ? OR recipient_id = ?)$readConflict";
        if (DB_DRIVER === 'mysql') {
            $readInsert = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $readInsert);
        }
        $stmt = $conn->prepare($readInsert);
        $stmt->execute([$uid, $notificationId, $audience, $uid]);
        out(true, 'Marked as read.');
    }

    out(false, 'Unknown action or permission denied.', null, 404);
} catch (Throwable $e) {
    error_log('SmartMinibus communication API error: ' . $e->getMessage());
    out(false, 'May error sa database operation. I-check ang database schema at PHP error log.', null, 500);
}
