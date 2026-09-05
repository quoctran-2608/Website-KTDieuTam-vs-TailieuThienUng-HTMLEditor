<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/assignment.php';

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

$result = editorial_resume_approved_editing($articleId, (string) ($currentUser['user_id'] ?? ''));
editorial_flash_set($result['ok'] ? 'success' : 'danger', (string) $result['message']);
editorial_redirect($result['ok']
    ? editorial_url('article.php?id=' . urlencode($articleId))
    : editorial_url('my-work.php'));
