<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

$syncResult = sync_articles_index(false);
if (!$syncResult['synced']) {
  flash_set('warning', 'Không thể đồng bộ articles index: ' . (string) $syncResult['reason']);
}

$filters = [
  'q' => (string) ($_GET['q'] ?? ''),
  'section' => (string) ($_GET['section'] ?? ''),
  'library_kind_key' => (string) ($_GET['library_kind_key'] ?? ''),
  'topic_lv1_key' => (string) ($_GET['topic_lv1_key'] ?? ''),
  'topic_lv2_key' => (string) ($_GET['topic_lv2_key'] ?? ''),
  'date_from' => (string) ($_GET['date_from'] ?? ''),
  'date_to' => (string) ($_GET['date_to'] ?? ''),
  'sort' => (string) ($_GET['sort'] ?? 'latest'),
  'page' => (int) ($_GET['page'] ?? 1),
  'per_page' => (int) ($_GET['per_page'] ?? 20),
];

$query = query_articles_index($filters);
$items = $query['items'];
$meta = $query['meta'];
$applied = $query['filters'];
$facets = $query['facets'];

admin_layout_header([
  'title' => 'Danh sách bài viết',
  'active' => 'articles',
  'description' => 'Tìm nhanh bài trong Thư viện và Bản tin bằng filter nhiều lớp giống trải nghiệm hub hiện tại.',
]);

$sectionCounts = [];
foreach (($facets['sections'] ?? []) as $entry) {
  if (is_array($entry) && isset($entry['key'], $entry['count'])) {
    $sectionCounts[(string) $entry['key']] = (int) $entry['count'];
  }
}

/**
 * @param array<string,mixed> $params
 */
function build_articles_query(array $params): string
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
 * Resolve view URL for public article page.
 *
 * @param array<string,mixed> $article
 */
function article_public_url(array $article): string
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
?>

<section class="admin-panel article-panel">
  <div class="panel-head panel-head-inline">
    <div>
      <h2>Bộ lọc nâng cao</h2>
      <p>Tối ưu cho tác vụ tìm bài: theo section, tầng taxonomy, thời gian và từ khóa.</p>
    </div>
    <a class="clear-filter-btn" href="<?= h(admin_url('articles.php')) ?>">
      <i class="fa-solid fa-filter-circle-xmark"></i>
      <span>Xóa toàn bộ filter</span>
    </a>
  </div>

  <form method="get" class="article-filter-form" novalidate>
    <div class="filter-grid">
      <label class="filter-field span-2">
        <span>Từ khóa</span>
        <input
          type="text"
          name="q"
          value="<?= h((string) $filters['q']) ?>"
          placeholder="Tìm theo tiêu đề, id, href..."
        >
      </label>

      <label class="filter-field">
        <span>Section</span>
        <select name="section">
          <option value="">Tất cả</option>
          <?php foreach (($facets['sections'] ?? []) as $entry): ?>
            <?php if (!is_array($entry)) continue; ?>
            <?php $key = (string) ($entry['key'] ?? ''); ?>
            <?php $label = (string) ($entry['label'] ?? $key); ?>
            <option value="<?= h($key) ?>" <?= $filters['section'] === $key ? 'selected' : '' ?>>
              <?= h($label) ?> (<?= h((string) ($entry['count'] ?? 0)) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filter-field">
        <span>Loại thư viện</span>
        <select name="library_kind_key">
          <option value="">Tất cả</option>
          <?php foreach (($facets['library_kinds'] ?? []) as $entry): ?>
            <?php if (!is_array($entry)) continue; ?>
            <?php $key = (string) ($entry['key'] ?? ''); ?>
            <?php $label = (string) ($entry['label'] ?? $key); ?>
            <option value="<?= h($key) ?>" <?= $filters['library_kind_key'] === $key ? 'selected' : '' ?>>
              <?= h($label) ?> (<?= h((string) ($entry['count'] ?? 0)) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filter-field">
        <span>Topic Lv1</span>
        <select name="topic_lv1_key">
          <option value="">Tất cả</option>
          <?php foreach (($facets['topic_lv1'] ?? []) as $entry): ?>
            <?php if (!is_array($entry)) continue; ?>
            <?php $key = (string) ($entry['key'] ?? ''); ?>
            <?php $label = (string) ($entry['label'] ?? $key); ?>
            <option value="<?= h($key) ?>" <?= $filters['topic_lv1_key'] === $key ? 'selected' : '' ?>>
              <?= h($label) ?> (<?= h((string) ($entry['count'] ?? 0)) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filter-field">
        <span>Topic Lv2</span>
        <select name="topic_lv2_key">
          <option value="">Tất cả</option>
          <?php foreach (($facets['topic_lv2'] ?? []) as $entry): ?>
            <?php if (!is_array($entry)) continue; ?>
            <?php $key = (string) ($entry['key'] ?? ''); ?>
            <?php $label = (string) ($entry['label'] ?? $key); ?>
            <option value="<?= h($key) ?>" <?= $filters['topic_lv2_key'] === $key ? 'selected' : '' ?>>
              <?= h($label) ?> (<?= h((string) ($entry['count'] ?? 0)) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filter-field">
        <span>Từ ngày</span>
        <input type="date" name="date_from" value="<?= h((string) $filters['date_from']) ?>">
      </label>

      <label class="filter-field">
        <span>Đến ngày</span>
        <input type="date" name="date_to" value="<?= h((string) $filters['date_to']) ?>">
      </label>

      <label class="filter-field">
        <span>Sắp xếp</span>
        <select name="sort">
          <?php
          $sortOptions = [
            'latest' => 'Mới nhất',
            'oldest' => 'Cũ nhất',
            'title_asc' => 'Tiêu đề A -> Z',
            'title_desc' => 'Tiêu đề Z -> A',
          ];
          foreach ($sortOptions as $value => $label):
          ?>
            <option value="<?= h($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filter-field">
        <span>Mỗi trang</span>
        <select name="per_page">
          <?php foreach ([20, 30, 50, 100] as $size): ?>
            <option value="<?= h((string) $size) ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>>
              <?= h((string) $size) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="filter-actions">
      <button class="filter-submit-btn" type="submit">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Áp dụng bộ lọc</span>
      </button>
    </div>
  </form>
</section>

<section class="admin-grid-cards compact">
  <article class="metric-card">
    <span class="metric-icon"><i class="fa-solid fa-layer-group"></i></span>
    <div class="metric-body">
      <h3><?= h(number_format((int) $meta['total'], 0, ',', '.')) ?></h3>
      <p>Kết quả đang hiển thị theo filter</p>
    </div>
  </article>
  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-solid fa-book-open"></i></span>
    <div class="metric-body">
      <h3><?= h(number_format((int) ($sectionCounts['thu-vien'] ?? 0), 0, ',', '.')) ?></h3>
      <p>Tổng bài Thư viện</p>
    </div>
  </article>
  <article class="metric-card">
    <span class="metric-icon success"><i class="fa-solid fa-newspaper"></i></span>
    <div class="metric-body">
      <h3><?= h(number_format((int) ($sectionCounts['ban-tin'] ?? 0), 0, ',', '.')) ?></h3>
      <p>Tổng bài Bản tin</p>
    </div>
  </article>
  <article class="metric-card">
    <span class="metric-icon warning"><i class="fa-solid fa-arrows-rotate"></i></span>
    <div class="metric-body">
      <h3><?= h((string) ($syncResult['cache_updated'] ? 'Rebuilt' : 'Ready')) ?></h3>
      <p>Trạng thái index cache</p>
    </div>
  </article>
</section>

<?php
$chips = [];
if ($applied['q'] !== '') $chips[] = ['label' => 'Từ khóa', 'value' => $applied['q']];
if ($applied['section'] !== '') $chips[] = ['label' => 'Section', 'value' => $applied['section']];
if ($applied['library_kind_key'] !== '') $chips[] = ['label' => 'Loại', 'value' => $applied['library_kind_key']];
if ($applied['topic_lv1_key'] !== '') $chips[] = ['label' => 'Topic Lv1', 'value' => $applied['topic_lv1_key']];
if ($applied['topic_lv2_key'] !== '') $chips[] = ['label' => 'Topic Lv2', 'value' => $applied['topic_lv2_key']];
if ($applied['date_from'] !== '') $chips[] = ['label' => 'Từ ngày', 'value' => $applied['date_from']];
if ($applied['date_to'] !== '') $chips[] = ['label' => 'Đến ngày', 'value' => $applied['date_to']];
?>

<section class="admin-panel article-panel">
  <div class="panel-head panel-head-inline">
    <div>
      <h2>Kết quả tìm kiếm</h2>
      <p>
        Trang <?= h((string) $meta['page']) ?>/<?= h((string) $meta['total_pages']) ?> ·
        Hiển thị <?= h((string) count($items)) ?> / <?= h((string) $meta['total']) ?> bài.
      </p>
    </div>
  </div>

  <?php if (!empty($chips)): ?>
    <div class="filter-chip-row">
      <?php foreach ($chips as $chip): ?>
        <span class="filter-chip">
          <small><?= h($chip['label']) ?></small>
          <strong><?= h($chip['value']) ?></strong>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="empty-state roomy">
      <i class="fa-solid fa-magnifying-glass"></i>
      <p>Không có bài nào khớp bộ lọc hiện tại.</p>
      <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
        <i class="fa-solid fa-rotate-left"></i>
        <span>Đặt lại filter</span>
      </a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table articles-table">
        <thead>
          <tr>
            <th>Tiêu đề</th>
            <th>Section</th>
            <th>Taxonomy</th>
            <th>Ngày</th>
            <th>Tác giả</th>
            <th>Hành động</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $article): ?>
            <?php if (!is_array($article)) continue; ?>
            <tr>
              <td>
                <div class="article-title-cell">
                  <strong><?= h((string) ($article['title'] ?? '')) ?></strong>
                  <div class="article-subline">
                    <code><?= h((string) ($article['id'] ?? '')) ?></code>
                  </div>
                </div>
              </td>
              <td>
                <?php
                $sectionLabel = trim((string) ($article['section_label'] ?? ''));
                if ($sectionLabel === '') {
                  $sectionLabel = (string) ($article['section'] ?? '');
                }
                ?>
                <span class="event-pill"><?= h($sectionLabel) ?></span>
              </td>
              <td>
                <div class="taxonomy-stack">
                  <?php if (!empty($article['library_kind_label'])): ?>
                    <span><?= h((string) $article['library_kind_label']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($article['topic_lv1_label'])): ?>
                    <small><?= h((string) $article['topic_lv1_label']) ?></small>
                  <?php endif; ?>
                  <?php if (!empty($article['topic_lv2_label'])): ?>
                    <small><?= h((string) $article['topic_lv2_label']) ?></small>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="date-stack">
                  <span>Đăng: <?= h((string) ($article['publish_date'] ?: '—')) ?></span>
                  <small>Sửa: <?= h((string) ($article['modified_date'] ?: '—')) ?></small>
                </div>
              </td>
              <td><?= h((string) ($article['author_name'] ?: '—')) ?></td>
              <td>
                <div class="table-action-row">
                  <a class="table-action-link" href="<?= h(article_public_url($article)) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square"></i>
                    <span>Xem</span>
                  </a>
                  <a class="table-action-link primary" href="<?= h(admin_url('article.php' . build_articles_query(['id' => (string) ($article['id'] ?? '')]))) ?>">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Sửa</span>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ((int) $meta['total_pages'] > 1): ?>
    <div class="pagination-row">
      <?php
      $currentPage = (int) $meta['page'];
      $totalPages = (int) $meta['total_pages'];
      $baseParams = [
        'q' => (string) $filters['q'],
        'section' => (string) $filters['section'],
        'library_kind_key' => (string) $filters['library_kind_key'],
        'topic_lv1_key' => (string) $filters['topic_lv1_key'],
        'topic_lv2_key' => (string) $filters['topic_lv2_key'],
        'date_from' => (string) $filters['date_from'],
        'date_to' => (string) $filters['date_to'],
        'sort' => (string) $filters['sort'],
        'per_page' => (int) $filters['per_page'],
      ];
      $start = max(1, $currentPage - 2);
      $end = min($totalPages, $currentPage + 2);
      ?>

      <a class="pager-btn <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseParams + ['page' => max(1, $currentPage - 1)]))) ?>">
        <i class="fa-solid fa-chevron-left"></i>
      </a>

      <?php for ($i = $start; $i <= $end; $i++): ?>
        <a class="pager-btn <?= $i === $currentPage ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseParams + ['page' => $i]))) ?>">
          <?= h((string) $i) ?>
        </a>
      <?php endfor; ?>

      <a class="pager-btn <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseParams + ['page' => min($totalPages, $currentPage + 1)]))) ?>">
        <i class="fa-solid fa-chevron-right"></i>
      </a>
    </div>
  <?php endif; ?>
</section>

<?php admin_layout_footer(); ?>
