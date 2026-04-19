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
  'tree_node_type' => (string) ($_GET['tree_node_type'] ?? ''),
  'tree_node_key' => (string) ($_GET['tree_node_key'] ?? ''),
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
$tree = is_array($facets['tree'] ?? null) ? $facets['tree'] : ['sections' => []];

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
 * @param array<string,mixed> $article
 */
function article_public_url(array $article): string
{
  return public_article_url($article);
}

/**
 * @param array<string,mixed> $node
 * @param array<string,mixed> $baseParams
 * @param array<string,mixed> $active
 * @param int $depth
 */
function render_tree_node(array $node, array $baseParams, array $active, int $depth = 0): void
{
  $type = (string) ($node['type'] ?? 'section');
  $key = (string) ($node['key'] ?? '');
  $label = (string) ($node['label'] ?? $key);
  $count = (int) ($node['count'] ?? 0);
  $children = is_array($node['children'] ?? null) ? $node['children'] : [];

  $isActive = $active['tree_node_type'] === $type && $active['tree_node_key'] === $key;
  $hasChildren = !empty($children);
  $itemClass = 'tree-node depth-' . $depth . ($isActive ? ' is-active' : '') . ($hasChildren ? ' has-children' : '');
  $query = build_articles_query($baseParams + [
    'tree_node_type' => $type,
    'tree_node_key' => $key,
    'page' => 1,
  ]);
  ?>
  <li class="<?= h($itemClass) ?>">
    <a class="tree-node-link" href="<?= h(admin_url('articles.php' . $query)) ?>">
      <span class="tree-node-label"><?= h($label) ?></span>
      <span class="tree-node-count"><?= h((string) $count) ?></span>
    </a>
    <?php if ($hasChildren): ?>
      <ul class="tree-node-children">
        <?php foreach ($children as $child): ?>
          <?php if (!is_array($child)) continue; ?>
          <?php render_tree_node($child, $baseParams, $active, $depth + 1); ?>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </li>
  <?php
}

admin_layout_header([
  'title' => 'Bài viết: Thư viện & Bản tin',
  'active' => 'articles',
  'description' => 'Điều hướng theo cây nội dung giống trang user: chọn Thư viện/Bản tin rồi drill-down tới đúng nhóm bài.',
  'phase_label' => 'Phase UX — Tree navigation',
]);
?>

<section class="admin-panel article-panel tree-layout">
  <div class="tree-sidebar">
    <div class="panel-head">
      <h2>Điều hướng nội dung</h2>
      <p>Chọn nhánh để lọc nhanh theo cấu trúc thật của site.</p>
    </div>

    <?php
    $baseTreeParams = [
      'q' => (string) $filters['q'],
      'sort' => (string) $filters['sort'],
      'per_page' => (int) $filters['per_page'],
    ];
    $sections = is_array($tree['sections'] ?? null) ? $tree['sections'] : [];
    ?>

    <ul class="tree-root-list">
      <?php foreach ($sections as $sectionNode): ?>
        <?php if (!is_array($sectionNode)) continue; ?>
        <?php render_tree_node($sectionNode, $baseTreeParams, $applied, 0); ?>
      <?php endforeach; ?>
    </ul>

    <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
      <i class="fa-solid fa-filter-circle-xmark"></i>
      <span>Xóa bộ lọc cây</span>
    </a>
  </div>

  <div class="tree-main">
    <div class="panel-head panel-head-inline">
      <div>
        <h2>Danh sách bài</h2>
        <p>
          Trang <?= h((string) $meta['page']) ?>/<?= h((string) $meta['total_pages']) ?> ·
          Hiển thị <?= h((string) count($items)) ?> / <?= h((string) $meta['total']) ?> bài.
        </p>
      </div>
    </div>

    <form method="get" class="article-filter-form compact" novalidate>
      <input type="hidden" name="tree_node_type" value="<?= h((string) $applied['tree_node_type']) ?>">
      <input type="hidden" name="tree_node_key" value="<?= h((string) $applied['tree_node_key']) ?>">
      <div class="filter-grid compact">
        <label class="filter-field span-2">
          <span>Tìm nhanh trong nhánh đã chọn</span>
          <input
            type="text"
            name="q"
            value="<?= h((string) $filters['q']) ?>"
            placeholder="Tiêu đề, id, href..."
          >
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
          <span>Tìm</span>
        </button>
      </div>
    </form>

    <?php if (empty($items)): ?>
      <div class="empty-state roomy">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p>Không có bài nào khớp nhánh + từ khóa hiện tại.</p>
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
          'sort' => (string) $filters['sort'],
          'per_page' => (int) $filters['per_page'],
          'tree_node_type' => (string) $applied['tree_node_type'],
          'tree_node_key' => (string) $applied['tree_node_key'],
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
  </div>
</section>

<?php admin_layout_footer(); ?>

