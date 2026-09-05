<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/assignment.php';
require_once __DIR__ . '/includes/revision.php';

editorial_require_auth();

if (!editorial_is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

editorial_enforce_csrf();

$currentUser = editorial_current_user();
$role = (string) ($currentUser['role'] ?? '');
if ($role !== 'editor') {
    editorial_flash_set('danger', 'Chỉ biên tập viên đang phụ trách mới có thể tiếp tục biên tập bài đã duyệt.');
    editorial_redirect(editorial_url('my-work.php'));
}
$articleId = trim((string) ($_POST['article_id'] ?? ''));
if ($articleId === '') {
    editorial_flash_set('danger', 'Thiếu mã bài viết.');
    editorial_redirect(editorial_url('my-work.php'));
}

try {
    $result = editorial_resume_approved_editing($articleId, (string) ($currentUser['user_id'] ?? ''));
} catch (\Throwable $e) {
    error_log('Editorial resume editing failed: article_id=' . $articleId
        . ' user_id=' . (string) ($currentUser['user_id'] ?? '')
        . ' exception=' . get_class($e));
    editorial_flash_set('danger', 'Không thể mở lại bài để biên tập. Vui lòng thử lại hoặc báo quản trị viên.');
    editorial_redirect(editorial_url('my-work.php'));
}
editorial_flash_set($result['ok'] ? 'success' : 'danger', (string) $result['message']);
editorial_redirect($result['ok']
    ? editorial_url('article.php?id=' . urlencode($articleId))
    : editorial_url('my-work.php'));
