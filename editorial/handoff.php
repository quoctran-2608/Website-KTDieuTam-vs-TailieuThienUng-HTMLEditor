<?php
declare(strict_types=1);

/**
 * Editorial V2 — POST-only Google Handoff endpoint.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/handoff.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$actorId = (string) ($currentUser['user_id'] ?? '');

if (!editorial_is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

editorial_enforce_csrf();

$articleId = trim((string) ($_POST['article_id'] ?? ''));
$note = trim((string) ($_POST['handoff_note'] ?? ''));
try {
    $result = $articleId === ''
        ? ['ok' => false, 'message' => 'Thiếu Article ID để bàn giao.']
        : editorial_handoff_article($articleId, $note, $currentUser);
} catch (\Throwable $e) {
    editorial_log_activity('handoff.failed', $articleId !== '' ? $articleId : null, $actorId, json_encode([
        'article_id' => $articleId,
        'code' => 'unexpected_service_error',
        'sync_status' => 'failed',
    ]));
    $result = ['ok' => false, 'message' => 'Google Handoff gặp lỗi hệ thống. Vui lòng thử lại.'];
}

editorial_flash_set(!empty($result['ok']) ? 'success' : 'danger', (string) ($result['message'] ?? 'Không thể bàn giao Google Handoff.'));

$return = [];
foreach (['q', 'section', 'library_kind_key', 'topic_lv1_key', 'topic_lv2_key', 'topic_lv3_key', 'assignment', 'page'] as $key) {
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value !== '') {
        $return[$key] = $value;
    }
}
$url = editorial_url('articles.php');
if ($return !== []) {
    $url .= '?' . http_build_query($return, '', '&', PHP_QUERY_RFC3986);
}
editorial_redirect($url);
