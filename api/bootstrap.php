<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/../config/database.php';

function out(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    $response = [
        'success' => $success,
        'ok' => $success,
        'message' => $message,
    ];
    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }
    $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_role(array $roles): int
{
    if (
        !isset($_SESSION['user_id'], $_SESSION['role']) ||
        !in_array($_SESSION['role'], $roles, true)
    ) {
        out(false, 'Unauthorized. Please log in again.', [], 401);
    }

    return (int)$_SESSION['user_id'];
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        out(false, 'POST required.', [], 405);
    }
}

function fail_server(Throwable $error, string $message): void
{
    error_log('SmartMinibus API error: ' . $error->getMessage());
    out(false, $message, [], 500);
}
