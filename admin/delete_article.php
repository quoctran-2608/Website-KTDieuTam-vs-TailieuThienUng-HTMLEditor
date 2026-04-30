<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

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

/**
 * Keep the current article list context after delete/cancel.
 *
 * @return array<string,mixed>
 */
function delete_redirect_params_from_post(): array
{
  return [
    'section' => (string) ($_POST['section'] ?? ''),
    'library_kind_key' => (string) ($_POST['library_kind_key'] ?? ''),
    'topic_lv1_key' => (string) ($_POST['topic_lv1_key'] ?? ''),
    'topic_lv2_key' => (string) ($_POST['topic_lv2_key'] ?? ''),
    'topic_lv3_key' => (string) ($_POST['topic_lv3_key'] ?? ''),
    'tag' => (string) ($_POST['tag'] ?? ''),
    'review_status' => (string) ($_POST['review_status'] ?? ''),
    'q' => (string) ($_POST['q'] ?? ''),
    'sort' => (string) ($_POST['sort'] ?? ''),
    'per_page' => (int) ($_POST['per_page'] ?? 20),
    'page' => (int) ($_POST['page'] ?? 1),
    'list_article_id' => (string) ($_POST['list_article_id'] ?? ''),
  ];
}

/**
 * Render hidden inputs for delete context.
 *
 * @param array<string,mixed> $params
 */
function render_delete_context_inputs(array $params): void
{
  foreach ($params as $name => $value) {
    echo '<input type="hidden" name="' . h((string) $name) . '" value="' . h((string) $value) . '">' . PHP_EOL;
  }
}

/**
 * Render a server-side confirmation page when inbound internal links exist.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed> $report
 * @param array<string,mixed> $redirectParams
 */
function render_delete_internal_link_confirmation(array $article, array $report, array $redirectParams): void
{
  $sourceCount = (int) ($report['source_count'] ?? 0);
  $occurrenceCount = (int) ($report['occurrence_count'] ?? 0);
  $listReturnUrl = admin_url('articles.php' . build_delete_redirect_query($redirectParams));
  $targetTitle = (string) ($article['title'] ?? '');
  $targetId = (string) ($article['id'] ?? '');
  $targetHref = (string) ($article['href'] ?? '');

  admin_layout_header([
    'title' => 'Kiểm tra trước khi xóa',
    'active' => 'articles',
    'description' => 'Hệ thống đã quét internal link để tránh tạo link chết sau khi xóa bài.',
    'body_class' => 'admin-page-delete-article',
  ]);
  ?>
  <section class="admin-panel delete-impact-panel">
    <div class="panel-head panel-head-inline">
      <div>
        <h2>Không nên xóa ngay</h2>
        <p>Tìm thấy <?= number_format($occurrenceCount, 0, ',', '.') ?> internal link từ <?= number_format($sourceCount, 0, ',', '.') ?> bài đang trỏ tới bài này.</p>
      </div>
      <a class="clear-filter-btn inline" href="<?= h($listReturnUrl) ?>">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Hủy xóa</span>
      </a>
    </div>

    <div class="delete-impact-warning">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>
        <strong>Nếu xóa ngay, các internal link dưới đây sẽ thành 404.</strong>
        <p>Cách tốt nhất: mở các bài nguồn để sửa/bỏ link hoặc thay bằng URL mới. Chỉ xóa cưỡng bức khi bạn đã chấp nhận rủi ro hoặc đã có redirect phù hợp.</p>
      </div>
    </div>

    <div class="delete-impact-target">
      <strong>Bài sắp xóa</strong>
      <p><?= h($targetTitle) ?></p>
      <code><?= h($targetId) ?></code>
      <?php if ($targetHref !== ''): ?><code><?= h($targetHref) ?></code><?php endif; ?>
    </div>

    <div class="table-wrap delete-impact-table-wrap">
      <table class="admin-table delete-impact-table">
        <thead>
          <tr>
            <th>Bài đang đặt link</th>
            <th>Anchor / href phát hiện</th>
            <th>Tác vụ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((is_array($report['sources'] ?? null) ? $report['sources'] : []) as $source): ?>
            <?php if (!is_array($source)) continue; ?>
            <?php
            $sourceId = (string) ($source['id'] ?? '');
            $sourceEditUrl = admin_url('article.php' . build_delete_redirect_query([
              'id' => $sourceId,
              'section' => (string) ($source['section'] ?? ''),
              'library_kind_key' => (string) ($source['library_kind_key'] ?? ''),
              'topic_lv1_key' => (string) ($source['topic_lv1_key'] ?? ''),
              'topic_lv2_key' => (string) ($source['topic_lv2_key'] ?? ''),
              'topic_lv3_key' => (string) ($source['topic_lv3_key'] ?? ''),
              'list_article_id' => $sourceId,
              'return_mode' => 'exact',
            ]));
            ?>
            <tr>
              <td>
                <strong><?= h((string) ($source['title'] ?? '')) ?></strong>
                <div class="article-subline">
                  <code><?= h($sourceId) ?></code>
                  <?php if (!empty($source['href'])): ?><small><?= h((string) $source['href']) ?></small><?php endif; ?>
                </div>
              </td>
              <td>
                <div class="delete-impact-links">
                  <?php foreach ((is_array($source['occurrences'] ?? null) ? $source['occurrences'] : []) as $occurrence): ?>
                    <?php if (!is_array($occurrence)) continue; ?>
                    <div>
                      <strong><?= h((string) ($occurrence['text'] ?? '')) ?></strong>
                      <small>line <?= h((string) ($occurrence['line'] ?? '')) ?> · <code><?= h((string) ($occurrence['href'] ?? '')) ?></code></small>
                    </div>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <a class="table-action-link primary" href="<?= h($sourceEditUrl) ?>" target="_blank" rel="noopener">
                  <i class="fa-solid fa-pen-to-square"></i>
                  <span>Sửa bài nguồn</span>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($report['truncated'])): ?>
      <div class="flash flash-warning">Danh sách quá dài nên chỉ hiển thị một phần. Hãy xử lý các bài nguồn chính trước khi xóa.</div>
    <?php endif; ?>

    <form method="post" action="<?= h(admin_url('delete_article.php')) ?>" class="delete-confirm-form" onsubmit="return confirm('Xác nhận XÓA CƯỠNG BỨC dù còn internal link trỏ tới bài này?');">
      <?= csrf_input_html() ?>
      <input type="hidden" name="article_id" value="<?= h($targetId) ?>">
      <?php render_delete_context_inputs($redirectParams); ?>
      <label class="delete-impact-ack">
        <input type="checkbox" name="delete_ack_internal_links" value="1" required>
        <span>Tôi đã xem danh sách internal link và vẫn muốn xóa bài này.</span>
      </label>
      <div class="editor-top-actions-row">
        <a class="clear-filter-btn inline" href="<?= h($listReturnUrl) ?>">
          <i class="fa-solid fa-xmark"></i>
          <span>Không xóa</span>
        </a>
        <button type="submit" class="rollback-btn inline">
          <i class="fa-solid fa-trash-can"></i>
          <span>Xóa cưỡng bức</span>
        </button>
      </div>
    </form>
  </section>
  <?php
  admin_layout_footer();
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

$redirectParams = delete_redirect_params_from_post();
$internalLinkReport = build_article_delete_internal_link_report($article);
$hasInboundInternalLinks = (int) ($internalLinkReport['occurrence_count'] ?? 0) > 0;
$ackInternalLinks = (string) ($_POST['delete_ack_internal_links'] ?? '') === '1';
if ($hasInboundInternalLinks && !$ackInternalLinks) {
  render_delete_internal_link_confirmation($article, $internalLinkReport, $redirectParams);
  exit;
}

$result = delete_article_with_assets($article, current_user(), [
  'internal_link_report' => $internalLinkReport,
  'force_delete_with_internal_links' => $ackInternalLinks,
]);
if (!empty($result['ok'])) {
  if ($hasInboundInternalLinks && $ackInternalLinks) {
    flash_set('warning', 'Đã xóa bài viết. Lưu ý: bài này vẫn có internal link trỏ tới trong các bài khác, cần xử lý để tránh 404.');
  } else {
    flash_set('success', 'Đã xóa bài viết và ảnh liên quan.');
  }
} else {
  $message = (string) ($result['message'] ?? 'Xóa bài thất bại.');
  flash_set('danger', $message);
}

$redirectParams = $redirectParams + [
  'from_edit' => 1,
  'return_mode' => 'fresh',
];
redirect_to(admin_url('articles.php' . build_delete_redirect_query($redirectParams)));
