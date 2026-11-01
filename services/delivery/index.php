<?php
declare(strict_types=1);

// Synthetic provider, available only on the application's private Docker network.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json; charset=utf-8');
if ($path === '/health') {
    echo '{"status":"ok"}';
} elseif ($path === '/internal/dispatch') {
    echo json_encode(['service' => 'dispatch', 'warehouse' => 'LAB-A', 'internal_token' => 'local-dispatch-demo-token']);
} elseif ($path === '/tracking/REDIRECT1') {
    header('Location: /internal/dispatch', true, 302);
} elseif (preg_match('~^/tracking/([A-Z0-9-]{1,40})$~D', $path, $match)) {
    echo json_encode(['tracking_id' => $match[1], 'status' => 'In transit', 'carrier' => 'Mini Delivery']);
} else {
    http_response_code(404);
    echo '{"detail":"Not found"}';
}
