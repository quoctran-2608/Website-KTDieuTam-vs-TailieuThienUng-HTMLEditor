<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_auth();
require_role(['admin', 'editor']);

header('Content-Type: application/json; charset=utf-8');

try {
  if (!is_post_request()) {
    throw new RuntimeException('Method không hợp lệ.');
  }

  $csrfToken = isset($_POST['_csrf_token']) ? (string) $_POST['_csrf_token'] : null;
  if (!verify_csrf($csrfToken)) {
    throw new RuntimeException('CSRF token không hợp lệ.');
  }

  $articleId = trim((string) ($_POST['article_id'] ?? ''));
  if ($articleId === '') {
    throw new RuntimeException('Thiếu article id.');
  }

  $article = find_article_index_item($articleId);
  if ($article === null) {
    throw new RuntimeException('Không tìm thấy bài viết.');
  }

  if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    throw new RuntimeException('Không nhận được file ảnh.');
  }

  $saved = save_article_uploaded_image($articleId, $_FILES['image']);

  append_audit_log([
    'event' => 'article.upload.image',
    'article_id' => $articleId,
    'file' => (string) ($saved['name'] ?? ''),
    'username' => (string) ((current_user()['username'] ?? '') ?: ''),
  ]);

  echo json_encode([
    'ok' => true,
    'location' => '../' . ltrim((string) ($saved['public_path'] ?? ''), '/'),
    'admin_preview_url' => (string) ($saved['url'] ?? ''),
    'public_path' => (string) ($saved['public_path'] ?? ''),
    'name' => (string) ($saved['name'] ?? ''),
    'size' => (int) ($saved['size'] ?? 0),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
