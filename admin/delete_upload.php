<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_auth();
require_role(['admin', 'editor']);

if (!is_post_request()) {
  redirect_to(admin_url('articles.php'));
}

enforce_post_csrf_or_reject();

$articleId = trim((string) ($_POST['article_id'] ?? ''));
$uploadId = trim((string) ($_POST['upload_id'] ?? ''));
$uploadName = trim((string) ($_POST['upload_name'] ?? ''));
$uploadYear = trim((string) ($_POST['upload_year'] ?? ''));
$uploadMonth = trim((string) ($_POST['upload_month'] ?? ''));

if ($articleId === '' || $uploadName === '') {
  flash_set('danger', 'Thiếu thông tin ảnh cần xóa.');
  redirect_to(admin_url('article.php?id=' . rawurlencode($articleId)));
}

$ok = delete_article_uploaded_image($articleId, $uploadName, $uploadYear, $uploadMonth, $uploadId);
if ($ok) {
  append_audit_log([
    'event' => 'article.upload.deleted',
    'article_id' => $articleId,
    'file' => basename($uploadName),
    'username' => (string) ((current_user()['username'] ?? '') ?: ''),
  ]);
  flash_set('success', 'Đã xóa file ảnh upload.');
} else {
  flash_set('warning', 'Không tìm thấy file ảnh để xóa.');
}

redirect_to(admin_url('article.php?id=' . rawurlencode($articleId)));
