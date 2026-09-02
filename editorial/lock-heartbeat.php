<?php
declare(strict_types=1);

/**
 * Editorial V2 — Lock Heartbeat Endpoint.
 *
 * POST-only JSON endpoint. Extends editing lock TTL.
 * Called by browser JS every 60 seconds.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';

header('Content-Type: application/json; charset=UTF-8');

// Must be POST
if (!editorial_is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Must be authenticated
if (!editorial_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Verify CSRF
if (!editorial_verify_csrf($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$user = editorial_current_user();
$userId = (string) $user['user_id'];
$articleId = trim((string) ($_POST['article_id'] ?? ''));
$lockToken = trim((string) ($_POST['lock_token'] ?? ''));

if ($articleId === '' || $lockToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing article_id or lock_token']);
    exit;
}

// Validate assignment
$state = editorial_get_article_state($articleId);
if ($state === null || (string) ($state['assigned_user_id'] ?? '') !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not the assigned editor']);
    exit;
}

$result = editorial_heartbeat_article_lock($articleId, $userId, $lockToken);

if ($result['ok']) {
    echo json_encode([
        'ok' => true,
        'expires_at' => $result['expires_at'],
    ]);
} else {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => $result['message'] ?? 'Lock invalid',
    ]);
}
