<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_auth();
require_role(['admin']);

/**
 * Build query string from params (local to delete endpoint, avoid cross-file dependency).
 *
 * @param array<string,mixed> $params
 */
function build_delete_redirect_query(array $params): string
{
  $clean = [];
  foreach ($params as $key => $value) {
    if ($value === '' || $value === null) {
      continue;
    }
    if (is_int($value) && $value <= 0) {
      continue;
    }
    $clean[$key] = $value;
  }
  $query = http_build_query($clean);
  return $query === '' ? '' : ('?' . $query);
}

if (!is_post_request()) {
  redirect_to(admin_url('articles.php'));
}

enforce_post_csrf_or_reject();

$articleId = trim((string) ($_POST['article_id'] ?? ''));
if ($articleId === '') {
  flash_set('danger', 'Thiếu mã bài để xóa.');
  redirect_to(admin_url('articles.php'));
}

$article = find_article_index_item($articleId);
if ($article === null) {
  flash_set('danger', 'Không tìm thấy bài cần xóa.');
  redirect_to(admin_url('articles.php'));
}

$result = delete_article_with_assets($article, current_user());
if (!empty($result['ok'])) {
  flash_set('success', 'Đã xóa bài viết và ảnh liên quan.');
} else {
  $message = (string) ($result['message'] ?? 'Xóa bài thất bại.');
  flash_set('danger', $message);
}

$redirectParams = [
  'section' => (string) ($_POST['section'] ?? ''),
  'library_kind_key' => (string) ($_POST['library_kind_key'] ?? ''),
  'topic_lv1_key' => (string) ($_POST['topic_lv1_key'] ?? ''),
  'topic_lv2_key' => (string) ($_POST['topic_lv2_key'] ?? ''),
  'review_status' => (string) ($_POST['review_status'] ?? ''),
  'q' => (string) ($_POST['q'] ?? ''),
  'sort' => (string) ($_POST['sort'] ?? ''),
  'per_page' => (int) ($_POST['per_page'] ?? 20),
  'page' => (int) ($_POST['page'] ?? 1),
  'list_article_id' => (string) ($_POST['list_article_id'] ?? ''),
  'from_edit' => 1,
  'return_mode' => 'fresh',
];
redirect_to(admin_url('articles.php' . build_delete_redirect_query($redirectParams)));
