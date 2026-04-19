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
$scopeParams = [
  'q' => (string) $filters['q'],
  'sort' => (string) $filters['sort'],
  'per_page' => (int) $filters['per_page'],
];

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
$activeSectionLabel = $activeSection === 'ban-tin' ? 'Bản tin' : 'Thư viện';
$contextParts = [$activeSectionLabel];
if ($activeKindLabel !== '') {
  $contextParts[] = $activeKindLabel;
}
if ($activeTopicLv1Label !== '') {
  $contextParts[] = $activeTopicLv1Label;
}
if ($activeTopicLv2Label !== '') {
  $contextParts[] = $activeTopicLv2Label;
}
$contextSummary = implode(' › ', $contextParts);

$sidebarTreeMode = $activeSection === 'thu-vien' ? 'library' : 'news';
$sidebarTreeRoots = [];
if ($sidebarTreeMode === 'library') {
  foreach ($kindNodes as $kindKey => $kindNode) {
    $kindActive = $activeKindKey === $kindKey;
    $groupEntries = [];
    foreach (($kindNode['children'] ?? []) as $lv1Node) {
      if (!is_array($lv1Node)) {
        continue;
      }
      $lv1Key = (string) ($lv1Node['key'] ?? '');
      if ($lv1Key === '') {
        continue;
      }
      $lv1Active = $kindActive && $activeTopicLv1Key === $lv1Key;
      $childEntries = [];
      if ($lv1Active) {
        foreach (($lv1Node['children'] ?? []) as $lv2Node) {
          if (!is_array($lv2Node)) {
            continue;
          }
          $lv2Key = (string) ($lv2Node['key'] ?? '');
          if ($lv2Key === '') {
            continue;
          }
          $childEntries[] = [
            'label' => node_label($lv2Node),
            'count' => (int) ($lv2Node['count'] ?? 0),
            'active' => $activeTopicLv2Key === $lv2Key,
            'href' => admin_url('articles.php' . build_articles_query($scopeParams + [
              'section' => 'thu-vien',
              'library_kind_key' => $kindKey,
              'topic_lv1_key' => $lv1Key,
              'topic_lv2_key' => $lv2Key,
            ])),
          ];
        }
      }
      $groupEntries[] = [
        'label' => node_label($lv1Node),
        'count' => (int) ($lv1Node['count'] ?? 0),
        'active' => $lv1Active,
        'href' => admin_url('articles.php' . build_articles_query($scopeParams + [
          'section' => 'thu-vien',
          'library_kind_key' => $kindKey,
          'topic_lv1_key' => $lv1Key,
        ])),
        'children' => $childEntries,
      ];
    }

    $sidebarTreeRoots[] = [
      'label' => node_label($kindNode),
      'count' => (int) ($kindNode['count'] ?? 0),
      'active' => $kindActive,
      'href' => admin_url('articles.php' . build_articles_query($scopeParams + [
        'section' => 'thu-vien',
        'library_kind_key' => $kindKey,
      ])),
      'groups' => $groupEntries,
    ];
  }
} else {
  foreach ($topicLv1Nodes as $lv1Key => $lv1Node) {
    $lv1Active = $activeTopicLv1Key === $lv1Key;
    $childEntries = [];
    if ($lv1Active) {
      foreach (($lv1Node['children'] ?? []) as $lv2Node) {
        if (!is_array($lv2Node)) {
          continue;
        }
        $lv2Key = (string) ($lv2Node['key'] ?? '');
        if ($lv2Key === '') {
          continue;
        }
        $childEntries[] = [
          'label' => node_label($lv2Node),
          'count' => (int) ($lv2Node['count'] ?? 0),
          'active' => $activeTopicLv2Key === $lv2Key,
          'href' => admin_url('articles.php' . build_articles_query($scopeParams + [
            'section' => 'ban-tin',
            'topic_lv1_key' => $lv1Key,
            'topic_lv2_key' => $lv2Key,
          ])),
        ];
      }
    }
    $sidebarTreeRoots[] = [
      'label' => node_label($lv1Node),
      'count' => (int) ($lv1Node['count'] ?? 0),
      'active' => $lv1Active,
      'href' => admin_url('articles.php' . build_articles_query($scopeParams + [
        'section' => 'ban-tin',
        'topic_lv1_key' => $lv1Key,
      ])),
      'children' => $childEntries,
    ];
  }
}

ob_start();
?>
<section class="sidebar-tree">
  <h3 class="sidebar-tree-title">Cây mục bài viết</h3>
  <div class="sidebar-section-switch">
    <a class="sidebar-section-btn <?= $activeSection === 'thu-vien' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($scopeParams + ['section' => 'thu-vien']))) ?>">
      <span>Thư viện</span>
      <small><?= h((string) ($sectionCountMap['thu-vien'] ?? 0)) ?></small>
    </a>
    <a class="sidebar-section-btn <?= $activeSection === 'ban-tin' ? 'is-active' : '' ?>" href="<?= h(admin_url('articles.php' . build_articles_query($scopeParams + ['section' => 'ban-tin']))) ?>">
      <span>Bản tin</span>
      <small><?= h((string) ($sectionCountMap['ban-tin'] ?? 0)) ?></small>
    </a>
  </div>

  <div class="sidebar-tree-area">
    <?php foreach ($sidebarTreeRoots as $root): ?>
      <?php
      if (!is_array($root)) {
        continue;
      }
      $rootLabel = (string) ($root['label'] ?? '');
      $rootCount = (int) ($root['count'] ?? 0);
      $rootHref = (string) ($root['href'] ?? '#');
      $rootActive = !empty($root['active']);
      if ($rootLabel === '') {
        continue;
      }
      ?>
      <section class="sidebar-tree-node <?= $rootActive ? 'is-active' : '' ?>">
        <a class="sidebar-tree-root <?= $rootActive ? 'is-active' : '' ?>" href="<?= h($rootHref) ?>">
          <span><?= h($rootLabel) ?></span>
          <small><?= h((string) $rootCount) ?></small>
        </a>

        <?php if ($sidebarTreeMode === 'library'): ?>
          <?php
          $groupEntries = is_array($root['groups'] ?? null) ? $root['groups'] : [];
          ?>
          <?php if ($rootActive && !empty($groupEntries)): ?>
            <div class="sidebar-tree-groups">
              <?php foreach ($groupEntries as $group): ?>
                <?php
                if (!is_array($group)) {
                  continue;
                }
                $groupLabel = (string) ($group['label'] ?? '');
                $groupCount = (int) ($group['count'] ?? 0);
                $groupHref = (string) ($group['href'] ?? '#');
                $groupActive = !empty($group['active']);
                $childEntries = is_array($group['children'] ?? null) ? $group['children'] : [];
                if ($groupLabel === '') {
                  continue;
                }
                ?>
                <div class="sidebar-tree-group-wrap">
                  <a class="sidebar-tree-group <?= $groupActive ? 'is-active' : '' ?>" href="<?= h($groupHref) ?>">
                    <span><?= h($groupLabel) ?></span>
                    <small><?= h((string) $groupCount) ?></small>
                  </a>

                  <?php if ($groupActive && !empty($childEntries)): ?>
                    <div class="sidebar-tree-children">
                      <?php foreach ($childEntries as $child): ?>
                        <?php
                        if (!is_array($child)) {
                          continue;
                        }
                        $childLabel = (string) ($child['label'] ?? '');
                        $childCount = (int) ($child['count'] ?? 0);
                        $childHref = (string) ($child['href'] ?? '#');
                        $childActive = !empty($child['active']);
                        if ($childLabel === '') {
                          continue;
                        }
                        ?>
                        <a class="sidebar-tree-child <?= $childActive ? 'is-active' : '' ?>" href="<?= h($childHref) ?>">
                          <span><?= h($childLabel) ?></span>
                          <small><?= h((string) $childCount) ?></small>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <?php
          $childEntries = is_array($root['children'] ?? null) ? $root['children'] : [];
          ?>
          <?php if ($rootActive && !empty($childEntries)): ?>
            <div class="sidebar-tree-children">
              <?php foreach ($childEntries as $child): ?>
                <?php
                if (!is_array($child)) {
                  continue;
                }
                $childLabel = (string) ($child['label'] ?? '');
                $childCount = (int) ($child['count'] ?? 0);
                $childHref = (string) ($child['href'] ?? '#');
                $childActive = !empty($child['active']);
                if ($childLabel === '') {
                  continue;
                }
                ?>
                <a class="sidebar-tree-child <?= $childActive ? 'is-active' : '' ?>" href="<?= h($childHref) ?>">
                  <span><?= h($childLabel) ?></span>
                  <small><?= h((string) $childCount) ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <?php if (empty($sidebarTreeRoots)): ?>
      <p class="sidebar-tree-empty">Chưa có dữ liệu cây mục.</p>
    <?php endif; ?>
  </div>
</section>
<?php
$sidebarExtraHtml = (string) ob_get_clean();

admin_layout_header([
  'title' => 'Bài viết',
  'active' => 'articles',
  'sidebar_note' => 'Khu vực quản trị nội dung',
  'sidebar_extra_html' => $sidebarExtraHtml,
]);
?>

<section class="admin-panel article-panel">
  <div class="panel-head panel-head-inline">
    <div>
      <h2>Danh sách bài</h2>
      <p>
        <?= h($contextSummary) ?> · <?= h((string) $meta['total']) ?> bài
      </p>
    </div>
    <a class="clear-filter-btn" href="<?= h(admin_url('articles.php' . build_articles_query(['section' => $activeSection]))) ?>">
      <i class="fa-solid fa-filter-circle-xmark"></i>
      <span>Đặt lại</span>
    </a>
  </div>

  <form method="get" class="article-filter-form compact single-row" novalidate data-instant-filter="1">
    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
    <input type="hidden" name="library_kind_key" value="<?= h((string) $filters['library_kind_key']) ?>">
    <input type="hidden" name="topic_lv1_key" value="<?= h((string) $filters['topic_lv1_key']) ?>">
    <input type="hidden" name="topic_lv2_key" value="<?= h((string) $filters['topic_lv2_key']) ?>">
    <div class="editor-toolbar-row">
      <input
        class="toolbar-search"
        type="text"
        name="q"
        value="<?= h((string) $filters['q']) ?>"
        placeholder="Tìm tiêu đề, mã bài..."
        aria-label="Tìm nhanh"
      >

      <select class="toolbar-select" name="sort" aria-label="Sắp xếp">
        <?php
        $sortOptions = [
          'latest' => 'Mới nhất',
          'oldest' => 'Cũ nhất',
          'title_asc' => 'A-Z',
          'title_desc' => 'Z-A',
        ];
        foreach ($sortOptions as $value => $label):
        ?>
          <option value="<?= h($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select class="toolbar-select slim" name="per_page" aria-label="Mỗi trang">
        <?php foreach ([20, 30, 50, 100] as $size): ?>
          <option value="<?= h((string) $size) ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>>
            <?= h((string) $size) ?>/tr
          </option>
        <?php endforeach; ?>
      </select>

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
            <th>Ngữ cảnh</th>
            <th>Cập nhật</th>
            <th>Tác vụ</th>
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
                    <?php if (!empty($article['path'])): ?>
                      <small><?= h((string) $article['path']) ?></small>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <?php
                $sectionLabel = trim((string) ($article['section_label'] ?? ''));
                if ($sectionLabel === '') {
                  $sectionLabel = (string) ($article['section'] ?? '');
                }
                $contextTokens = [];
                if (!empty($article['library_kind_label'])) {
                  $contextTokens[] = (string) $article['library_kind_label'];
                }
                if (!empty($article['topic_lv1_label'])) {
                  $contextTokens[] = (string) $article['topic_lv1_label'];
                }
                if (!empty($article['topic_lv2_label'])) {
                  $contextTokens[] = (string) $article['topic_lv2_label'];
                }
                $contextText = implode(' › ', $contextTokens);
                ?>
                <div class="taxonomy-stack">
                  <span><?= h($sectionLabel) ?></span>
                  <?php if ($contextText !== ''): ?>
                    <small><?= h($contextText) ?></small>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="date-stack">
                  <span><?= h((string) ($article['modified_date'] ?: $article['publish_date'] ?: '—')) ?></span>
                </div>
              </td>
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
