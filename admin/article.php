<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

$id = trim((string) ($_GET['id'] ?? ''));
$article = find_article_index_item($id);
$forceAudit = isset($_GET['audit']) && (string) $_GET['audit'] === '1';
$audit = run_parser_audit($forceAudit);
$auditMeta = is_array($audit['meta'] ?? null) ? $audit['meta'] : [];
$auditFails = is_array($audit['fails'] ?? null) ? $audit['fails'] : [];
$auditSafeRate = (float) ($auditMeta['safe_rate_percent'] ?? 0);

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

/**
 * Build query string from params.
 *
 * @param array<string,mixed> $params
 */
function build_article_query(array $params): string
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
 * Small text preview helper.
 */
function preview_text(string $html, int $length = 280): string
{
  $plain = trim(strip_tags($html));
  $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
  $strlen = function_exists('mb_strlen') ? (int) mb_strlen($plain) : strlen($plain);
  if ($strlen <= $length) {
    return $plain;
  }
  $slice = function_exists('mb_substr') ? (string) mb_substr($plain, 0, $length) : substr($plain, 0, $length);
  return $slice . '...';
}

$parseResult = null;
if ($article !== null) {
  $path = resolve_article_file_path($article);
  $parseResult = parse_article_file($path);
}

admin_layout_header([
  'title' => 'Chi tiết bài & parser safety',
  'active' => 'articles',
  'description' => 'Kiểm tra parse-safe cho .article-prose và script#article-meta trước khi cho phép sửa bài.',
  'phase_label' => 'Phase 3 — Parser-safe detail',
]);
?>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Parser audit toàn kho</h2>
    <p>Đánh giá mức độ an toàn parse cho toàn bộ bài đã index để chuẩn bị phase chỉnh sửa.</p>
  </div>

  <div class="parser-audit-grid">
    <article class="metric-card">
      <span class="metric-icon success"><i class="fa-solid fa-shield-check"></i></span>
      <div class="metric-body">
        <h3><?= h((string) ($auditMeta['safe_count'] ?? 0)) ?></h3>
        <p>Bài parse-safe</p>
      </div>
    </article>
    <article class="metric-card">
      <span class="metric-icon warning"><i class="fa-solid fa-bug"></i></span>
      <div class="metric-body">
        <h3><?= h((string) ($auditMeta['fail_count'] ?? 0)) ?></h3>
        <p>Bài lỗi parse</p>
      </div>
    </article>
    <article class="metric-card">
      <span class="metric-icon info"><i class="fa-solid fa-percent"></i></span>
      <div class="metric-body">
        <h3><?= h(number_format($auditSafeRate, 2, ',', '.')) ?>%</h3>
        <p>Tỷ lệ parse-safe</p>
      </div>
    </article>
    <article class="metric-card">
      <span class="metric-icon"><i class="fa-solid fa-arrows-rotate"></i></span>
      <div class="metric-body">
        <h3><?= h((string) format_admin_datetime((string) ($auditMeta['generated_at'] ?? ''))) ?></h3>
        <p>Thời điểm audit</p>
      </div>
    </article>
  </div>

  <p style="margin-top:12px;">
    <a class="clear-filter-btn inline" href="<?= h(admin_url('article.php' . build_article_query(['id' => $id, 'audit' => 1]))) ?>">
      <i class="fa-solid fa-arrows-rotate"></i>
      <span>Chạy lại parser audit</span>
    </a>
  </p>

  <?php if (!empty($auditFails)): ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Mã lỗi</th>
            <th>Đường dẫn</th>
            <th>Thông điệp</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($auditFails, 0, 20) as $fail): ?>
            <?php if (!is_array($fail)) continue; ?>
            <tr>
              <td><code><?= h((string) ($fail['id'] ?? '')) ?></code></td>
              <td><span class="event-pill"><?= h((string) ($fail['code'] ?? '')) ?></span></td>
              <td><code><?= h((string) ($fail['href'] ?? '')) ?></code></td>
              <td><?= h((string) ($fail['message'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Chi tiết bài theo ID</h2>
    <p>Hiển thị parser detail để kiểm tra biên trước khi mở form edit ở Phase 4.</p>
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

    <?php if (is_array($parseResult) && !empty($parseResult['ok'])): ?>
      <?php
      $prose = is_array($parseResult['prose'] ?? null) ? $parseResult['prose'] : [];
      $meta = is_array($parseResult['meta'] ?? null) ? $parseResult['meta'] : [];
      $metaPayload = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
      ?>
      <div class="parse-ok-banner">
        <i class="fa-solid fa-circle-check"></i>
        <div>
          <strong>Parser-safe</strong>
          <p>Bài này đủ điều kiện parse để đi tiếp sang phase chỉnh sửa.</p>
        </div>
      </div>

      <div class="parser-detail-grid">
        <article class="parser-detail-card">
          <h4>Vùng .article-prose</h4>
          <ul>
            <li>Start: <code><?= h((string) ($prose['start'] ?? '')) ?></code></li>
            <li>OpenEnd: <code><?= h((string) ($prose['open_tag_end'] ?? '')) ?></code></li>
            <li>CloseStart: <code><?= h((string) ($prose['close_tag_start'] ?? '')) ?></code></li>
            <li>End: <code><?= h((string) ($prose['end'] ?? '')) ?></code></li>
            <li>Inner length: <code><?= h((string) ($prose['inner_length'] ?? '')) ?></code></li>
          </ul>
          <p><strong>Preview:</strong> <?= h(preview_text((string) ($prose['inner'] ?? ''))) ?></p>
        </article>

        <article class="parser-detail-card">
          <h4>Vùng article-meta</h4>
          <ul>
            <li>Start: <code><?= h((string) ($meta['start'] ?? '')) ?></code></li>
            <li>OpenEnd: <code><?= h((string) ($meta['open_tag_end'] ?? '')) ?></code></li>
            <li>CloseStart: <code><?= h((string) ($meta['close_tag_start'] ?? '')) ?></code></li>
            <li>End: <code><?= h((string) ($meta['end'] ?? '')) ?></code></li>
            <li>Inner length: <code><?= h((string) ($meta['inner_length'] ?? '')) ?></code></li>
          </ul>
          <details>
            <summary>Xem JSON article-meta</summary>
            <pre class="json-preview"><?= h(pretty_json($metaPayload)) ?></pre>
          </details>
        </article>
      </div>
    <?php else: ?>
      <div class="parse-fail-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
          <strong>Parser lỗi: <?= h((string) ($parseResult['code'] ?? 'unknown')) ?></strong>
          <p><?= h((string) ($parseResult['message'] ?? 'Không xác định được lỗi parse.')) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <p style="margin-top:12px;">
      <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-up-right-from-square"></i>
        <span>Mở bài viết public</span>
      </a>
    </p>
    <div class="next-phase-banner">
      <i class="fa-solid fa-arrow-right-long"></i>
      <div>
        <strong>Tiếp theo: Phase 4 — Editor form + draft + preview diff</strong>
        <p>Nền parser-safe đã có. Phase kế tiếp sẽ mở form edit theo contract v1.</p>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php admin_layout_footer(); ?>
