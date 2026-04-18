<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

$id = trim((string) ($_GET['id'] ?? ''));
$article = find_article_index_item($id);

/**
 * Resolve view URL for public article page.
 *
 * @param array<string,mixed> $article
 */
function article_public_url_detail(array $article): string
{
  $canonical = trim((string) ($article['canonical'] ?? ''));
  if ($canonical !== '') {
    return $canonical;
  }
  $href = trim((string) ($article['href'] ?? ''));
  if ($href === '') {
    return '#';
  }
  if (preg_match('/^(https?:)?\/\//i', $href) === 1) {
    return $href;
  }
  return '../' . ltrim($href, '/');
}

admin_layout_header([
  'title' => 'Chi tiết bài (Phase 3)',
  'active' => 'articles',
  'description' => 'Trang chi tiết parser-safe sẽ được triển khai ở Phase 3. Hiện tại dùng làm điểm vào từ list.',
]);
?>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Điểm vào article detail</h2>
    <p>Phase 2 hoàn thành list/filter. Chi tiết bài và parser safety sẽ triển khai ở Phase 3.</p>
  </div>

  <?php if ($id === ''): ?>
    <div class="empty-state roomy">
      <i class="fa-solid fa-circle-info"></i>
      <p>Thiếu tham số bài viết. Hãy quay lại trang danh sách để chọn bài cần thao tác.</p>
      <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Về danh sách bài</span>
      </a>
    </div>
  <?php elseif ($article === null): ?>
    <div class="empty-state roomy">
      <i class="fa-solid fa-circle-exclamation"></i>
      <p>Không tìm thấy bài với id: <code><?= h($id) ?></code></p>
      <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Về danh sách bài</span>
      </a>
    </div>
  <?php else: ?>
    <div class="article-summary-card">
      <h3><?= h((string) ($article['title'] ?? '')) ?></h3>
      <p><strong>ID:</strong> <code><?= h((string) ($article['id'] ?? '')) ?></code></p>
      <?php
      $sectionLabel = trim((string) ($article['section_label'] ?? ''));
      if ($sectionLabel === '') {
        $sectionLabel = (string) ($article['section'] ?? '');
      }
      ?>
      <p><strong>Section:</strong> <?= h($sectionLabel) ?></p>
      <p><strong>Href:</strong> <code><?= h((string) ($article['href'] ?? '')) ?></code></p>
      <p><strong>Publish:</strong> <?= h((string) ($article['publish_date'] ?? '—')) ?> · <strong>Modified:</strong> <?= h((string) ($article['modified_date'] ?? '—')) ?></p>
    </div>
    <p style="margin-top:12px;">
      <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-up-right-from-square"></i>
        <span>Mở bài viết public</span>
      </a>
    </p>
    <div class="next-phase-banner">
      <i class="fa-solid fa-arrow-right-long"></i>
      <div>
        <strong>Phase 3 sẽ mở parser-safe viewer + metadata inspector</strong>
        <p>Đảm bảo trước khi cho sửa: parse chuẩn `.article-prose` và payload `article-meta`.</p>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php admin_layout_footer(); ?>
