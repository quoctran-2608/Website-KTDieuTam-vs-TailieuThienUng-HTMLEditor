<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/media.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!editorial_is_post()) {
    header('Allow: POST');
    $respond(405, ['ok' => false, 'error' => 'Phương thức không hợp lệ.']);
}
if (!editorial_is_authenticated()) {
    $respond(401, ['ok' => false, 'error' => 'Phiên đăng nhập không hợp lệ.']);
}
if (!editorial_verify_csrf(isset($_POST['_csrf_token']) ? (string) $_POST['_csrf_token'] : null)) {
    $respond(403, ['ok' => false, 'error' => 'CSRF token không hợp lệ.']);
}

$currentUser = editorial_current_user();
$userId = (string) ($currentUser['user_id'] ?? '');
$role = (string) ($currentUser['role'] ?? '');
$articleId = trim((string) ($_POST['article_id'] ?? ''));
$lockToken = (string) ($_POST['lock_token'] ?? '');
$purpose = trim((string) ($_POST['purpose'] ?? 'content'));
if (!in_array($purpose, ['content', 'featured'], true)) {
    $purpose = 'content';
}

if ($userId === '' || $role !== 'editor' || !empty($currentUser['must_change_password'])) {
    $respond(403, ['ok' => false, 'error' => 'Tài khoản hiện tại không được phép upload ảnh.']);
}
if ($articleId === '' || editorial_find_article($articleId) === null) {
    $respond(400, ['ok' => false, 'error' => 'Bài viết hoặc người dùng không hợp lệ.']);
}
$state = editorial_get_article_state($articleId);
if ($state === null
    || (string) ($state['assigned_user_id'] ?? '') !== $userId
    || !in_array((string) ($state['status'] ?? ''), ['editing', 'returned'], true)) {
    $respond(403, ['ok' => false, 'error' => 'Bạn không có quyền upload ảnh cho bài viết này.']);
}
$assignment = editorial_get_active_assignment($articleId);
if ($assignment === null || (string) ($assignment['user_id'] ?? '') !== $userId) {
    $respond(403, ['ok' => false, 'error' => 'Phân công biên tập hiện tại không hợp lệ.']);
}
$lock = editorial_validate_article_lock($articleId, $userId, $lockToken);
if (empty($lock['ok'])) {
    $respond(409, ['ok' => false, 'error' => (string) ($lock['message'] ?? 'Phiên chỉnh sửa không hợp lệ.')]);
}
$lockRow = editorial_get_article_lock($articleId);
$lockExpiry = $lockRow ? strtotime((string) ($lockRow['expires_at'] ?? '')) : false;
if ($lockRow === null
    || (string) ($lockRow['user_id'] ?? '') !== $userId
    || !hash_equals((string) ($lockRow['lock_token'] ?? ''), $lockToken)
    || $lockExpiry === false
    || $lockExpiry < time()) {
    $respond(409, ['ok' => false, 'error' => 'Phiên chỉnh sửa không còn hợp lệ. Vui lòng tải lại Workspace.']);
}
if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    $respond(400, ['ok' => false, 'error' => 'Không nhận được file ảnh.']);
}

try {
    $saved = editorial_media_save_uploaded_image($_FILES['image']);
    editorial_log_activity('article.media.uploaded', $articleId, $userId, json_encode([
        'article_id' => $articleId,
        'user_id' => $userId,
        'filename' => $saved['name'],
        'mime' => $saved['mime'],
        'size' => $saved['size'],
        'purpose' => $purpose,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $respond(200, [
        'ok' => true,
        'public_path' => $saved['public_path'],
        'location' => $saved['public_path'],
        'preview_url' => $saved['preview_url'],
        'name' => $saved['name'],
        'size' => $saved['size'],
    ]);
} catch (\Throwable $e) {
    error_log('Editorial media upload failed: article_id=' . $articleId
        . ' user_id=' . $userId
        . ' exception=' . get_class($e));
    $status = $e instanceof EditorialMediaUploadException ? $e->httpStatus : 500;
    $message = $e instanceof EditorialMediaUploadException
        ? $e->getMessage()
        : 'Không thể upload ảnh. Vui lòng thử lại hoặc báo quản trị viên.';
    $respond($status, ['ok' => false, 'error' => $message]);
}
