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
$revisionName = trim((string) ($_POST['revision_name'] ?? ''));

if ($articleId === '' || $revisionName === '') {
  flash_set('danger', 'Thiếu thông tin revision cần khôi phục.');
  redirect_to(admin_url('article.php?id=' . rawurlencode($articleId)));
}

$article = find_article_index_item($articleId);
if ($article === null) {
  flash_set('danger', 'Không tìm thấy bài viết để khôi phục revision.');
  redirect_to(admin_url('articles.php'));
}

$result = restore_article_revision($article, $revisionName, current_user());
if (!empty($result['ok'])) {
  mark_article_reviewed($articleId, current_user(), 'restore_revision');
  flash_set('success', (string) ($result['message'] ?? 'Đã khôi phục revision thành công.'));
} else {
  flash_set('danger', (string) ($result['message'] ?? 'Khôi phục revision thất bại.'));
}

redirect_to(admin_url('article.php?id=' . rawurlencode($articleId)));

