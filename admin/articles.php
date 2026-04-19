<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

$syncResult = sync_articles_index(false);
if (!$syncResult['synced']) {
  $reasonMap = [
    'source_missing' => 'không tìm thấy tệp dữ liệu bài viết',
    'source_empty' => 'tệp dữ liệu đang rỗng',
    'source_invalid_json' => 'tệp dữ liệu không đúng định dạng',
  ];
  $reasonCode = (string) ($syncResult['reason'] ?? '');
  $reasonText = $reasonMap[$reasonCode] ?? ('mã lỗi: ' . $reasonCode);
  flash_set('warning', 'Không thể cập nhật danh sách bài: ' . $reasonText . '.');
}

$cache = read_articles_index_cache();
$facets = is_array($cache['facets'] ?? null) ? $cache['facets'] : default_articles_facets();
$tree = is_array($facets['tree'] ?? null) ? $facets['tree'] : ['sections' => []];
$sectionTreeMap = [];
foreach (($tree['sections'] ?? []) as $node) {
  if (!is_array($node)) {
    continue;
  }
  $key = (string) ($node['key'] ?? '');
  if ($key !== '') {
    $sectionTreeMap[$key] = $node;
  }
}

$filters = [
  'q' => (string) ($_GET['q'] ?? ''),
  'section' => (string) ($_GET['section'] ?? ''),
  'library_kind_key' => (string) ($_GET['library_kind_key'] ?? ''),
  'topic_lv1_key' => (string) ($_GET['topic_lv1_key'] ?? ''),
  'topic_lv2_key' => (string) ($_GET['topic_lv2_key'] ?? ''),
  'tree_node_type' => (string) ($_GET['tree_node_type'] ?? ''), // backward compatibility
  'tree_node_key' => (string) ($_GET['tree_node_key'] ?? ''), // backward compatibility
  'date_from' => '',
  'date_to' => '',
  'sort' => (string) ($_GET['sort'] ?? 'latest'),
  'page' => (int) ($_GET['page'] ?? 1),
  'per_page' => (int) ($_GET['per_page'] ?? 20),
];

// Backward compatibility: map old tree-node URLs to new section/kind/topic filters.
if ($filters['tree_node_type'] !== '' && $filters['tree_node_key'] !== '') {
  if ($filters['tree_node_type'] === 'section') {
    $filters['section'] = $filters['tree_node_key'];
    $filters['library_kind_key'] = '';
    $filters['topic_lv1_key'] = '';
    $filters['topic_lv2_key'] = '';
  } elseif ($filters['tree_node_type'] === 'library_kind') {
    $filters['section'] = 'thu-vien';
    $filters['library_kind_key'] = $filters['tree_node_key'];
    $filters['topic_lv1_key'] = '';
    $filters['topic_lv2_key'] = '';
  } elseif ($filters['tree_node_type'] === 'topic_lv1') {
    $filters['topic_lv1_key'] = $filters['tree_node_key'];
    $filters['topic_lv2_key'] = '';
  } elseif ($filters['tree_node_type'] === 'topic_lv2') {
    $filters['topic_lv2_key'] = $filters['tree_node_key'];
  }
}
$filters['tree_node_type'] = '';
$filters['tree_node_key'] = '';

$activeSection = trim((string) $filters['section']);
if (!in_array($activeSection, ['thu-vien', 'ban-tin'], true)) {
  $activeSection = 'thu-vien';
}
$filters['section'] = $activeSection;

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
 */
function node_label(array $node): string
{
  return (string) ($node['label'] ?? ($node['key'] ?? ''));
}

$activeSectionNode = $sectionTreeMap[$activeSection] ?? null;
$sectionChildren = is_array($activeSectionNode['children'] ?? null) ? $activeSectionNode['children'] : [];

$sectionCountMap = [];
foreach (($facets['sections'] ?? []) as $entry) {
  if (!is_array($entry)) {
    continue;
  }
  $key = (string) ($entry['key'] ?? '');
  if ($key !== '') {
    $sectionCountMap[$key] = (int) ($entry['count'] ?? 0);
  }
}

$kindNodes = [];
$topicLv1Nodes = [];
$topicLv2Nodes = [];
$activeKindKey = '';
$activeTopicLv1Key = '';
$activeTopicLv2Key = '';
$activeKindLabel = '';
$activeTopicLv1Label = '';
$activeTopicLv2Label = '';

if ($activeSection === 'thu-vien') {
  foreach ($sectionChildren as $node) {
    if (!is_array($node)) {
      continue;
    }
    $key = (string) ($node['key'] ?? '');
    if ($key !== '') {
      $kindNodes[$key] = $node;
    }
  }

  $candidateKind = trim((string) $filters['library_kind_key']);
  if ($candidateKind !== '' && isset($kindNodes[$candidateKind])) {
    $activeKindKey = $candidateKind;
    $activeKindLabel = node_label($kindNodes[$candidateKind]);
  }
  $filters['library_kind_key'] = $activeKindKey;

  if ($activeKindKey !== '') {
    foreach (($kindNodes[$activeKindKey]['children'] ?? []) as $node) {
      if (!is_array($node)) {
        continue;
      }
      $key = (string) ($node['key'] ?? '');
      if ($key !== '') {
        $topicLv1Nodes[$key] = $node;
      }
    }
  }

  $candidateLv1 = trim((string) $filters['topic_lv1_key']);
  if ($candidateLv1 !== '' && isset($topicLv1Nodes[$candidateLv1])) {
    $activeTopicLv1Key = $candidateLv1;
    $activeTopicLv1Label = node_label($topicLv1Nodes[$candidateLv1]);
  }
  $filters['topic_lv1_key'] = $activeTopicLv1Key;

  if ($activeTopicLv1Key !== '') {
    foreach (($topicLv1Nodes[$activeTopicLv1Key]['children'] ?? []) as $node) {
      if (!is_array($node)) {
        continue;
      }
      $key = (string) ($node['key'] ?? '');
      if ($key !== '') {
        $topicLv2Nodes[$key] = $node;
      }
    }
  }

  $candidateLv2 = trim((string) $filters['topic_lv2_key']);
  if ($candidateLv2 !== '' && isset($topicLv2Nodes[$candidateLv2])) {
    $activeTopicLv2Key = $candidateLv2;
    $activeTopicLv2Label = node_label($topicLv2Nodes[$candidateLv2]);
  }
  $filters['topic_lv2_key'] = $activeTopicLv2Key;
} else {
  $filters['library_kind_key'] = '';
  foreach ($sectionChildren as $node) {
    if (!is_array($node)) {
      continue;
    }
    $key = (string) ($node['key'] ?? '');
    if ($key !== '') {
      $topicLv1Nodes[$key] = $node;
    }
  }

  $candidateLv1 = trim((string) $filters['topic_lv1_key']);
  if ($candidateLv1 !== '' && isset($topicLv1Nodes[$candidateLv1])) {
    $activeTopicLv1Key = $candidateLv1;
    $activeTopicLv1Label = node_label($topicLv1Nodes[$candidateLv1]);
  }
  $filters['topic_lv1_key'] = $activeTopicLv1Key;

  if ($activeTopicLv1Key !== '') {
    foreach (($topicLv1Nodes[$activeTopicLv1Key]['children'] ?? []) as $node) {
      if (!is_array($node)) {
        continue;
      }
      $key = (string) ($node['key'] ?? '');
      if ($key !== '') {
        $topicLv2Nodes[$key] = $node;
      }
    }
  }

  $candidateLv2 = trim((string) $filters['topic_lv2_key']);
  if ($candidateLv2 !== '' && isset($topicLv2Nodes[$candidateLv2])) {
    $activeTopicLv2Key = $candidateLv2;
    $activeTopicLv2Label = node_label($topicLv2Nodes[$candidateLv2]);
  }
  $filters['topic_lv2_key'] = $activeTopicLv2Key;
}

$query = query_articles_index($filters);
$items = $query['items'];
$meta = $query['meta'];
$applied = $query['filters'];

$activeSectionLabel = $activeSection === 'ban-tin' ? 'Bản tin' : 'Thư viện';
$breadcrumb = [$activeSectionLabel];
if ($activeKindLabel !== '') {
  $breadcrumb[] = $activeKindLabel;
}
if ($activeTopicLv1Label !== '') {
  $breadcrumb[] = $activeTopicLv1Label;
}
if ($activeTopicLv2Label !== '') {
  $breadcrumb[] = $activeTopicLv2Label;
}

admin_layout_header([
  'title' => 'Bài viết',
  'active' => 'articles',
  'description' => 'Tìm bài theo mục và chủ đề, sau đó mở bài để chỉnh sửa.',
  'sidebar_note' => 'Khu vực quản trị nội dung',
]);
?>

<section class="admin-panel article-panel">
  <div class="panel-head">
    <h2>Chọn khu vực nội dung</h2>
    <p>Chọn đúng mục trước khi tìm và sửa bài.</p>
  </div>

  <?php
  $baseTabParams = [
    'q' => (string) $filters['q'],
    'sort' => (string) $filters['sort'],
    'per_page' => (int) $filters['per_page'],
  ];
  ?>
  <div class="section-tabs">
    <a class="section-tab <?= $activeSection === 'thu-vien' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseTabParams + ['section' => 'thu-vien']))) ?>">
      <i class="fa-solid fa-book-open"></i>
      <span>Thư viện</span>
      <small><?= h((string) ($sectionCountMap['thu-vien'] ?? 0)) ?></small>
    </a>
    <a class="section-tab <?= $activeSection === 'ban-tin' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseTabParams + ['section' => 'ban-tin']))) ?>">
      <i class="fa-solid fa-newspaper"></i>
      <span>Bản tin</span>
      <small><?= h((string) ($sectionCountMap['ban-tin'] ?? 0)) ?></small>
    </a>
  </div>

  <div class="context-breadcrumb">
    <i class="fa-solid fa-location-dot"></i>
    <span><?= h(implode(' › ', $breadcrumb)) ?></span>
  </div>

  <div class="context-groups">
    <?php if ($activeSection === 'thu-vien'): ?>
      <div class="context-group">
        <h3>Loại Thư viện</h3>
        <div class="context-chip-row">
          <a class="context-chip <?= $activeKindKey === '' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseTabParams + ['section' => 'thu-vien']))) ?>">Tất cả</a>
          <?php foreach ($kindNodes as $key => $node): ?>
            <?php $nodeCount = (int) ($node['count'] ?? 0); ?>
            <a class="context-chip <?= $activeKindKey === $key ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseTabParams + ['section' => 'thu-vien', 'library_kind_key' => $key]))) ?>">
              <?= h(node_label($node)) ?> <small>(<?= h((string) $nodeCount) ?>)</small>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="context-group">
      <h3><?= $activeSection === 'thu-vien' ? 'Nhóm chủ đề' : 'Nhóm chủ đề bản tin' ?></h3>
      <div class="context-chip-row">
        <?php
        $baseLv1Params = $baseTabParams + ['section' => $activeSection];
        if ($activeSection === 'thu-vien' && $activeKindKey !== '') {
          $baseLv1Params['library_kind_key'] = $activeKindKey;
        }
        ?>
        <a class="context-chip <?= $activeTopicLv1Key === '' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseLv1Params))) ?>">Tất cả</a>
        <?php foreach ($topicLv1Nodes as $key => $node): ?>
          <?php $nodeCount = (int) ($node['count'] ?? 0); ?>
          <a class="context-chip <?= $activeTopicLv1Key === $key ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseLv1Params + ['topic_lv1_key' => $key]))) ?>">
            <?= h(node_label($node)) ?> <small>(<?= h((string) $nodeCount) ?>)</small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="context-group">
      <h3>Chủ đề con</h3>
      <div class="context-chip-row">
        <?php
        $baseLv2Params = $baseLv1Params;
        if ($activeTopicLv1Key !== '') {
          $baseLv2Params['topic_lv1_key'] = $activeTopicLv1Key;
        }
        ?>
        <a class="context-chip <?= $activeTopicLv2Key === '' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseLv2Params))) ?>">Tất cả</a>
        <?php foreach ($topicLv2Nodes as $key => $node): ?>
          <?php $nodeCount = (int) ($node['count'] ?? 0); ?>
          <a class="context-chip <?= $activeTopicLv2Key === $key ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($baseLv2Params + ['topic_lv2_key' => $key]))) ?>">
            <?= h(node_label($node)) ?> <small>(<?= h((string) $nodeCount) ?>)</small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section class="admin-panel article-panel">
  <div class="panel-head panel-head-inline">
    <div>
      <h2>Danh sách bài</h2>
      <p>
        Trang <?= h((string) $meta['page']) ?>/<?= h((string) $meta['total_pages']) ?> ·
        Đang hiển thị <?= h((string) count($items)) ?> / <?= h((string) $meta['total']) ?> bài.
      </p>
    </div>
    <a class="clear-filter-btn" href="<?= h(admin_url('articles.php' . build_articles_query(['section' => $activeSection]))) ?>">
      <i class="fa-solid fa-filter-circle-xmark"></i>
      <span>Đặt lại ngữ cảnh</span>
    </a>
  </div>

  <form method="get" class="article-filter-form compact" novalidate>
    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
    <input type="hidden" name="library_kind_key" value="<?= h((string) $filters['library_kind_key']) ?>">
    <input type="hidden" name="topic_lv1_key" value="<?= h((string) $filters['topic_lv1_key']) ?>">
    <input type="hidden" name="topic_lv2_key" value="<?= h((string) $filters['topic_lv2_key']) ?>">
    <div class="filter-grid compact">
      <label class="filter-field span-2">
        <span>Tìm trong khu vực đang chọn</span>
        <input
          type="text"
          name="q"
          value="<?= h((string) $filters['q']) ?>"
          placeholder="Tiêu đề, mã bài, đường dẫn..."
        >
      </label>

      <label class="filter-field">
        <span>Sắp xếp</span>
        <select name="sort">
          <?php
          $sortOptions = [
            'latest' => 'Mới nhất',
            'oldest' => 'Cũ nhất',
            'title_asc' => 'Tiêu đề A đến Z',
            'title_desc' => 'Tiêu đề Z đến A',
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
      <p>Không có bài nào khớp điều kiện đang chọn.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table articles-table">
        <thead>
          <tr>
            <th>Tiêu đề</th>
            <th>Mục</th>
            <th>Chủ đề</th>
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
        'section' => $activeSection,
        'library_kind_key' => (string) $filters['library_kind_key'],
        'topic_lv1_key' => (string) $filters['topic_lv1_key'],
        'topic_lv2_key' => (string) $filters['topic_lv2_key'],
        'q' => (string) $filters['q'],
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
