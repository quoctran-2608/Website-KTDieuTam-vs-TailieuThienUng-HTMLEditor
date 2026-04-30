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
$publicTaxonomyTree = read_public_taxonomy_tree();
$tree = !empty($publicTaxonomyTree['sections'])
  ? $publicTaxonomyTree
  : (is_array($facets['tree'] ?? null) ? $facets['tree'] : ['sections' => []]);
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
  'topic_lv3_key' => (string) ($_GET['topic_lv3_key'] ?? ''),
  'tag' => (string) ($_GET['tag'] ?? ''),
  'review_status' => (string) ($_GET['review_status'] ?? ''),
  'tree_node_type' => (string) ($_GET['tree_node_type'] ?? ''), // backward compatibility
  'tree_node_key' => (string) ($_GET['tree_node_key'] ?? ''), // backward compatibility
  'date_from' => '',
  'date_to' => '',
  'sort' => (string) ($_GET['sort'] ?? 'latest'),
  'page' => (int) ($_GET['page'] ?? 1),
  'per_page' => (int) ($_GET['per_page'] ?? 20),
];

$currentUser = current_user();
if (is_post_request()) {
  enforce_post_csrf_or_reject();
  $intent = trim((string) ($_POST['_intent'] ?? ''));
  if ($intent === 'mark_unreviewed_quick') {
    $articleId = trim((string) ($_POST['article_id'] ?? ''));
    if ($articleId === '') {
      flash_set('warning', 'Thiếu mã bài để cập nhật trạng thái.');
    } else {
      $marked = mark_article_unreviewed($articleId, $currentUser, 'quick_list_reset');
      if ($marked) {
        flash_set('success', 'Đã đánh dấu bài về trạng thái Chưa sửa.');
      } else {
        flash_set('warning', 'Bài đang ở trạng thái Chưa sửa.');
      }
    }
  }

  $redirectParams = [
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
  ];
  redirect_to(admin_url('articles.php' . build_articles_query($redirectParams)));
}

// Backward compatibility: map old tree-node URLs to new section/kind/topic filters.
if ($filters['tree_node_type'] !== '' && $filters['tree_node_key'] !== '') {
  if ($filters['tree_node_type'] === 'section') {
    $filters['section'] = $filters['tree_node_key'];
    $filters['library_kind_key'] = '';
    $filters['topic_lv1_key'] = '';
    $filters['topic_lv2_key'] = '';
    $filters['topic_lv3_key'] = '';
  } elseif ($filters['tree_node_type'] === 'library_kind') {
    $filters['section'] = 'thu-vien';
    $filters['library_kind_key'] = $filters['tree_node_key'];
    $filters['topic_lv1_key'] = '';
    $filters['topic_lv2_key'] = '';
    $filters['topic_lv3_key'] = '';
  } elseif ($filters['tree_node_type'] === 'topic_lv1') {
    $filters['topic_lv1_key'] = $filters['tree_node_key'];
    $filters['topic_lv2_key'] = '';
    $filters['topic_lv3_key'] = '';
  } elseif ($filters['tree_node_type'] === 'topic_lv2') {
    $filters['topic_lv2_key'] = $filters['tree_node_key'];
    $filters['topic_lv3_key'] = '';
  } elseif ($filters['tree_node_type'] === 'topic_lv3') {
    $filters['topic_lv3_key'] = $filters['tree_node_key'];
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
  'tag' => (string) $filters['tag'],
  'review_status' => (string) $filters['review_status'],
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

/**
 * @param array<string,mixed> $kindNode
 */
function kind_node_contains_topic(array $kindNode, string $lv1Key, string $lv2Key): bool
{
  foreach (($kindNode['children'] ?? []) as $lv1Node) {
    if (!is_array($lv1Node)) {
      continue;
    }
    $nodeLv1Key = (string) ($lv1Node['key'] ?? '');
    if ($lv1Key !== '' && $nodeLv1Key !== $lv1Key) {
      continue;
    }
    if ($lv2Key === '') {
      return true;
    }
    foreach (($lv1Node['children'] ?? []) as $lv2Node) {
      if (is_array($lv2Node) && (string) ($lv2Node['key'] ?? '') === $lv2Key) {
        return true;
      }
    }
  }
  return false;
}

$activeSectionNode = $sectionTreeMap[$activeSection] ?? null;
$sectionChildren = is_array($activeSectionNode['children'] ?? null) ? $activeSectionNode['children'] : [];

$sectionCountMap = [];
foreach ($sectionTreeMap as $key => $node) {
  if (is_array($node) && $key !== '') {
    $sectionCountMap[(string) $key] = (int) ($node['count'] ?? 0);
  }
}
foreach (($facets['sections'] ?? []) as $entry) {
  if (!is_array($entry)) {
    continue;
  }
  $key = (string) ($entry['key'] ?? '');
  if ($key !== '' && !isset($sectionCountMap[$key])) {
    $sectionCountMap[$key] = (int) ($entry['count'] ?? 0);
  }
}

$kindNodes = [];
$topicLv1Nodes = [];
$topicLv2Nodes = [];
$topicLv3Nodes = [];
$activeKindKey = '';
$activeTopicLv1Key = '';
$activeTopicLv2Key = '';
$activeTopicLv3Key = '';
$activeKindLabel = '';
$activeTopicLv1Label = '';
$activeTopicLv2Label = '';
$activeTopicLv3Label = '';

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
  if (empty($kindNodes)) {
    foreach (($facets['library_kinds'] ?? []) as $node) {
      if (!is_array($node)) {
        continue;
      }
      $key = (string) ($node['key'] ?? '');
      if ($key !== '') {
        $kindNodes[$key] = $node;
      }
    }
  }

  $candidateKind = trim((string) $filters['library_kind_key']);
  if ($candidateKind !== '' && isset($kindNodes[$candidateKind])) {
    $activeKindKey = $candidateKind;
    $activeKindLabel = node_label($kindNodes[$candidateKind]);
  } else {
    $candidateLv1 = trim((string) $filters['topic_lv1_key']);
    $candidateLv2 = trim((string) $filters['topic_lv2_key']);
    if ($candidateLv1 !== '' || $candidateLv2 !== '') {
      $matchedKinds = [];
      foreach ($kindNodes as $kindKey => $kindNode) {
        if (is_array($kindNode) && kind_node_contains_topic($kindNode, $candidateLv1, $candidateLv2)) {
          $matchedKinds[] = (string) $kindKey;
        }
      }
      if (count($matchedKinds) === 1) {
        $activeKindKey = $matchedKinds[0];
        $activeKindLabel = node_label($kindNodes[$activeKindKey]);
      }
    }
  }
  $filters['library_kind_key'] = $activeKindKey;
} else {
  $filters['library_kind_key'] = '';
}

$topicSourceChildren = $sectionChildren;
if ($activeSection === 'thu-vien') {
  $topicSourceChildren = ($activeKindKey !== '' && is_array($kindNodes[$activeKindKey]['children'] ?? null))
    ? $kindNodes[$activeKindKey]['children']
    : [];
}

foreach ($topicSourceChildren as $node) {
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
    if ($key !== '' || node_label($node) !== '') {
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

if ($activeTopicLv2Key !== '' && isset($topicLv2Nodes[$activeTopicLv2Key])) {
  foreach (($topicLv2Nodes[$activeTopicLv2Key]['children'] ?? []) as $node) {
    if (!is_array($node)) {
      continue;
    }
    $key = (string) ($node['key'] ?? '');
    if ($key !== '') {
      $topicLv3Nodes[$key] = $node;
    }
  }
}

$candidateLv3 = trim((string) $filters['topic_lv3_key']);
if ($candidateLv3 !== '' && isset($topicLv3Nodes[$candidateLv3])) {
  $activeTopicLv3Key = $candidateLv3;
  $activeTopicLv3Label = node_label($topicLv3Nodes[$candidateLv3]);
}
$filters['topic_lv3_key'] = $activeTopicLv3Key;

$query = query_articles_index($filters);
$items = $query['items'];
$meta = $query['meta'];
$focusArticleId = trim((string) ($_GET['focus_article_id'] ?? ''));
$focusInjected = false;
if ($focusArticleId !== '') {
  $foundOnPage = false;
  foreach ($items as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['id'] ?? '') === $focusArticleId) {
      $foundOnPage = true;
      break;
    }
  }
  if (!$foundOnPage) {
    $focusArticle = find_article_index_item($focusArticleId);
    if (is_array($focusArticle)) {
      $reviewRow = read_article_review_status($focusArticleId);
      $rowStatus = is_array($reviewRow) ? normalize_article_review_status((string) ($reviewRow['status'] ?? 'unreviewed')) : 'unreviewed';
      $editedAtRaw = $rowStatus !== 'unreviewed' ? trim((string) ($reviewRow['edited_at'] ?? '')) : '';
      $focusArticle['review_status'] = $rowStatus;
      if ($rowStatus === 'edited') {
        $focusArticle['review_status_label'] = 'Đã sửa';
      } elseif ($rowStatus === 'draft_saved') {
        $focusArticle['review_status_label'] = 'Lưu nháp';
      } else {
        $focusArticle['review_status_label'] = 'Chưa sửa';
      }
      $focusArticle['review_edited_at'] = $editedAtRaw;
      $focusArticle['review_edited_at_label'] = format_admin_datetime($editedAtRaw);
      $focusArticle['review_edited_by'] = is_array($reviewRow) ? review_status_actor_text($reviewRow) : '';

      // Pin một dòng ở đầu list để tránh cảm giác "bài bị mất" sau save/publish.
      array_unshift($items, $focusArticle);
      $focusInjected = true;
    }
  }
}
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
if ($activeTopicLv3Label !== '') {
  $contextParts[] = $activeTopicLv3Label;
}
if (trim((string) ($filters['tag'] ?? '')) !== '') {
  $contextParts[] = 'Tag: ' . trim((string) $filters['tag']);
}
$contextSummary = implode(' › ', $contextParts);

$sidebarTreeRoots = [];
if ($activeSection === 'thu-vien') {
  foreach ($kindNodes as $kindKey => $kindNode) {
    if (!is_array($kindNode)) {
      continue;
    }
    $kindKey = (string) $kindKey;
    $kindActive = $activeKindKey === $kindKey;
    $groupEntries = [];
    $kindBaseParams = $scopeParams + [
      'section' => 'thu-vien',
      'library_kind_key' => $kindKey,
    ];

    if ($kindActive) {
      foreach (($kindNode['children'] ?? []) as $lv1Node) {
        if (!is_array($lv1Node)) {
          continue;
        }
        $lv1Key = (string) ($lv1Node['key'] ?? '');
        $lv1Label = node_label($lv1Node);
        if ($lv1Key === '' && $lv1Label === '') {
          continue;
        }
        $lv1Expanded = $activeTopicLv1Key === $lv1Key;
        $lv2Entries = [];
        if ($lv1Expanded) {
          foreach (($lv1Node['children'] ?? []) as $lv2Node) {
            if (!is_array($lv2Node)) {
              continue;
            }
            $lv2Key = (string) ($lv2Node['key'] ?? '');
            $lv2Label = node_label($lv2Node);
            if ($lv2Key === '' && $lv2Label === '') {
              continue;
            }
            $lv2Entries[] = [
              'label' => $lv2Label,
              'count' => (int) ($lv2Node['count'] ?? 0),
              'active' => $activeTopicLv2Key === $lv2Key,
              'href' => admin_url('articles.php' . build_articles_query($kindBaseParams + [
                'topic_lv1_key' => $lv1Key,
                'topic_lv2_key' => $lv2Key,
              ])),
            ];
          }
        }
        $groupEntries[] = [
          'label' => $lv1Label,
          'count' => (int) ($lv1Node['count'] ?? 0),
          'active' => $lv1Expanded && $activeTopicLv2Key === '',
          'expanded' => $lv1Expanded,
          'href' => admin_url('articles.php' . build_articles_query($kindBaseParams + [
            'topic_lv1_key' => $lv1Key,
          ])),
          'children' => $lv2Entries,
        ];
      }
    }

    $sidebarTreeRoots[] = [
      'label' => node_label($kindNode),
      'count' => (int) ($kindNode['count'] ?? 0),
      'active' => $kindActive,
      'expanded' => $kindActive,
      'href' => admin_url('articles.php' . build_articles_query($kindBaseParams)),
      'children' => $groupEntries,
    ];
  }
} else {
  foreach ($topicLv1Nodes as $lv1Key => $lv1Node) {
    $lv1Active = $activeTopicLv1Key === $lv1Key;
    $childEntries = [];
    $treeBaseParams = $scopeParams + [
      'section' => 'ban-tin',
    ];

    if ($lv1Active) {
      foreach (($lv1Node['children'] ?? []) as $lv2Node) {
        if (!is_array($lv2Node)) {
          continue;
        }
        $lv2Key = (string) ($lv2Node['key'] ?? '');
        $lv2Label = node_label($lv2Node);
        if ($lv2Key === '' && $lv2Label === '') {
          continue;
        }
        $childEntries[] = [
          'label' => $lv2Label,
          'count' => (int) ($lv2Node['count'] ?? 0),
          'active' => $activeTopicLv2Key === $lv2Key,
          'href' => admin_url('articles.php' . build_articles_query($treeBaseParams + [
            'topic_lv1_key' => $lv1Key,
            'topic_lv2_key' => $lv2Key,
          ])),
        ];
      }
    }
    $sidebarTreeRoots[] = [
      'label' => node_label($lv1Node),
      'count' => (int) ($lv1Node['count'] ?? 0),
      'active' => $lv1Active && $activeTopicLv2Key === '',
      'expanded' => $lv1Active,
      'href' => admin_url('articles.php' . build_articles_query($treeBaseParams + [
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
      $rootExpanded = array_key_exists('expanded', $root) ? !empty($root['expanded']) : $rootActive;
      if ($rootLabel === '') {
        continue;
      }
      ?>
      <section class="sidebar-tree-node <?= $rootExpanded ? 'is-active' : '' ?>">
        <a class="sidebar-tree-root <?= $rootActive ? 'is-active' : '' ?>" href="<?= h($rootHref) ?>">
          <span><?= h($rootLabel) ?></span>
          <small><?= h((string) $rootCount) ?></small>
        </a>

          <?php
          $childEntries = is_array($root['children'] ?? null) ? $root['children'] : [];
          ?>
          <?php if ($rootExpanded && !empty($childEntries)): ?>
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
                $childExpanded = array_key_exists('expanded', $child) ? !empty($child['expanded']) : $childActive;
                $grandchildEntries = is_array($child['children'] ?? null) ? $child['children'] : [];
                if ($childLabel === '') {
                  continue;
                }
                ?>
                <a class="sidebar-tree-child <?= $childActive ? 'is-active' : '' ?>" href="<?= h($childHref) ?>">
                  <span><?= h($childLabel) ?></span>
                  <small><?= h((string) $childCount) ?></small>
                </a>
                <?php if ($childExpanded && !empty($grandchildEntries)): ?>
                  <div class="sidebar-tree-grandchildren">
                    <?php foreach ($grandchildEntries as $grandchild): ?>
                      <?php
                      if (!is_array($grandchild)) {
                        continue;
                      }
                      $grandchildLabel = (string) ($grandchild['label'] ?? '');
                      $grandchildCount = (int) ($grandchild['count'] ?? 0);
                      $grandchildHref = (string) ($grandchild['href'] ?? '#');
                      $grandchildActive = !empty($grandchild['active']);
                      if ($grandchildLabel === '') {
                        continue;
                      }
                      ?>
                      <a class="sidebar-tree-grandchild <?= $grandchildActive ? 'is-active' : '' ?>" href="<?= h($grandchildHref) ?>">
                        <span><?= h($grandchildLabel) ?></span>
                        <small><?= h((string) $grandchildCount) ?></small>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
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
        <?= h($contextSummary) ?> ·
        Tiến độ: <?= h((string) ($meta['total_edited'] ?? 0)) ?>/<?= h((string) (($meta['total_edited'] ?? 0) + ($meta['total_unreviewed'] ?? 0))) ?> bài đã sửa ·
        Hiển thị: <?= h((string) $meta['total']) ?> bài
      </p>
    </div>
    <a class="clear-filter-btn" href="<?= h(admin_url('articles.php' . build_articles_query(['section' => $activeSection]))) ?>">
      <i class="fa-solid fa-filter-circle-xmark"></i>
      <span>Đặt lại</span>
    </a>
  </div>

  <form method="get" class="article-filter-form compact single-row" novalidate data-instant-filter="1">
    <input type="hidden" name="section" value="<?= h($activeSection) ?>">
    <input type="hidden" name="topic_lv1_key" value="<?= h((string) $filters['topic_lv1_key']) ?>">
    <input type="hidden" name="topic_lv2_key" value="<?= h((string) $filters['topic_lv2_key']) ?>">
    <input type="hidden" name="topic_lv3_key" value="<?= h((string) $filters['topic_lv3_key']) ?>">
    <input type="hidden" name="tag" value="<?= h((string) $filters['tag']) ?>">
    <div class="editor-toolbar-row">
      <input
        class="toolbar-search"
        type="text"
        name="q"
        value="<?= h((string) $filters['q']) ?>"
        placeholder="Tìm tiêu đề, mã bài..."
        aria-label="Tìm nhanh"
      >

      <select class="toolbar-select" name="library_kind_key" aria-label="Loại tài liệu">
        <?php if ($activeSection === 'thu-vien'): ?>
          <option value="">Tất cả loại tài liệu</option>
          <?php foreach ($kindNodes as $kindKey => $kindNode): ?>
            <?php if (!is_array($kindNode)) continue; ?>
            <option value="<?= h((string) $kindKey) ?>" <?= $activeKindKey === (string) $kindKey ? 'selected' : '' ?>>
              <?= h(node_label($kindNode)) ?>
            </option>
          <?php endforeach; ?>
        <?php else: ?>
          <option value="">Loại tài liệu: không áp dụng</option>
        <?php endif; ?>
      </select>

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

      <select class="toolbar-select" name="review_status" aria-label="Trạng thái biên tập">
        <?php
        $reviewOptions = [
          '' => 'Tất cả trạng thái',
          'unreviewed' => 'Chưa sửa',
          'draft_saved' => 'Lưu nháp',
          'edited' => 'Đã sửa',
        ];
        foreach ($reviewOptions as $value => $label):
        ?>
          <option value="<?= h($value) ?>" <?= (string) ($filters['review_status'] ?? '') === $value ? 'selected' : '' ?>>
            <?= h($label) ?>
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
    <?php if ($focusInjected): ?>
      <div class="flash flash-warning">
        Đang hiển thị thêm bài bạn vừa thao tác để tiện quay lại ngay. Bài này có thể không khớp bộ lọc/trang hiện tại.
      </div>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="admin-table articles-table">
        <thead>
          <tr>
            <th>Tiêu đề</th>
            <th>Trạng thái</th>
            <th>Cập nhật</th>
            <th>Tác vụ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $article): ?>
            <?php if (!is_array($article)) continue; ?>
            <?php
            $articleId = (string) ($article['id'] ?? '');
            $editorUrl = admin_url('article.php' . build_articles_query([
              'id' => $articleId,
              'section' => $activeSection,
              'library_kind_key' => (string) $filters['library_kind_key'],
              'topic_lv1_key' => (string) $filters['topic_lv1_key'],
              'topic_lv2_key' => (string) $filters['topic_lv2_key'],
              'topic_lv3_key' => (string) $filters['topic_lv3_key'],
              'tag' => (string) $filters['tag'],
              'review_status' => (string) $filters['review_status'],
              'q' => (string) $filters['q'],
              'sort' => (string) $filters['sort'],
              'per_page' => (int) $filters['per_page'],
              'page' => (int) $meta['page'],
              'from_edit' => 1,
            ]));
            ?>
            <tr>
              <td>
                <div class="article-title-cell">
                  <strong>
                    <a class="article-title-link js-open-article-editor" data-article-id="<?= h($articleId) ?>" href="<?= h($editorUrl) ?>">
                      <?= h((string) ($article['title'] ?? '')) ?>
                    </a>
                  </strong>
                  <div class="article-subline">
                    <code><?= h($articleId) ?></code>
                    <?php if (!empty($article['path'])): ?>
                      <small class="article-path"><?= h((string) $article['path']) ?></small>
                    <?php endif; ?>
                  </div>
                  <?php
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
                  if (!empty($article['topic_lv3_label'])) {
                    $contextTokens[] = (string) $article['topic_lv3_label'];
                  }
                  $contextLine = implode(' · ', $contextTokens);
                  ?>
                  <?php if ($contextLine !== ''): ?>
                    <div class="article-context-line"><?= h($contextLine) ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php
                $reviewStatus = (string) ($article['review_status'] ?? 'unreviewed');
                $isEdited = $reviewStatus === 'edited';
                $reviewLabel = (string) ($article['review_status_label'] ?? ($isEdited ? 'Đã sửa' : ($reviewStatus === 'draft_saved' ? 'Lưu nháp' : 'Chưa sửa')));
                $reviewAt = (string) ($article['review_edited_at_label'] ?? '');
                if ($reviewAt === '') {
                  $reviewAt = '—';
                }
                $reviewBy = trim((string) ($article['review_edited_by'] ?? ''));
                if ($reviewBy === '') {
                  $reviewBy = '—';
                }
                ?>
                <div class="review-state-stack">
                  <span class="review-state-badge <?= $reviewStatus === 'edited' ? 'is-edited' : ($reviewStatus === 'draft_saved' ? 'is-draft' : 'is-unreviewed') ?>">
                    <?= h($reviewLabel) ?>
                  </span>
                  <?php if ($reviewStatus !== 'unreviewed'): ?>
                    <small><?= h($reviewAt) ?> · <?= h($reviewBy) ?></small>
                  <?php else: ?>
                    <small>Cần biên tập</small>
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
                  <?php if ($isEdited): ?>
                    <form method="post" class="inline-action-form" onsubmit="return confirm('Đánh dấu bài này là Chưa sửa?');">
                      <?= csrf_input_html() ?>
                      <input type="hidden" name="_intent" value="mark_unreviewed_quick">
                      <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>">
                      <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                      <input type="hidden" name="library_kind_key" value="<?= h((string) $filters['library_kind_key']) ?>">
                      <input type="hidden" name="topic_lv1_key" value="<?= h((string) $filters['topic_lv1_key']) ?>">
                      <input type="hidden" name="topic_lv2_key" value="<?= h((string) $filters['topic_lv2_key']) ?>">
                      <input type="hidden" name="topic_lv3_key" value="<?= h((string) $filters['topic_lv3_key']) ?>">
                      <input type="hidden" name="tag" value="<?= h((string) $filters['tag']) ?>">
                      <input type="hidden" name="review_status" value="<?= h((string) $filters['review_status']) ?>">
                      <input type="hidden" name="q" value="<?= h((string) $filters['q']) ?>">
                      <input type="hidden" name="sort" value="<?= h((string) $filters['sort']) ?>">
                      <input type="hidden" name="per_page" value="<?= h((string) $filters['per_page']) ?>">
                      <input type="hidden" name="page" value="<?= h((string) $meta['page']) ?>">
                      <button type="submit" class="table-action-link warning">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Chưa sửa</span>
                      </button>
                    </form>
                  <?php endif; ?>
                  <a class="table-action-link" href="<?= h(article_public_url($article)) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square"></i>
                    <span>Xem</span>
                  </a>
                  <a class="table-action-link primary js-open-article-editor" data-article-id="<?= h($articleId) ?>" href="<?= h($editorUrl) ?>">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Sửa</span>
                  </a>
                  <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                    <form method="post" action="<?= h(admin_url('delete_article.php')) ?>" class="inline-action-form" onsubmit="return confirm('Xác nhận kiểm tra và xóa bài này?\nHệ thống sẽ quét internal link trước. Nếu có bài khác đang trỏ tới, bạn sẽ thấy danh sách cảnh báo trước khi xóa.');">
                      <?= csrf_input_html() ?>
                      <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>">
                      <input type="hidden" name="section" value="<?= h($activeSection) ?>">
                      <input type="hidden" name="library_kind_key" value="<?= h((string) $filters['library_kind_key']) ?>">
                      <input type="hidden" name="topic_lv1_key" value="<?= h((string) $filters['topic_lv1_key']) ?>">
                      <input type="hidden" name="topic_lv2_key" value="<?= h((string) $filters['topic_lv2_key']) ?>">
                      <input type="hidden" name="topic_lv3_key" value="<?= h((string) $filters['topic_lv3_key']) ?>">
                      <input type="hidden" name="tag" value="<?= h((string) $filters['tag']) ?>">
                      <input type="hidden" name="review_status" value="<?= h((string) $filters['review_status']) ?>">
                      <input type="hidden" name="q" value="<?= h((string) $filters['q']) ?>">
                      <input type="hidden" name="sort" value="<?= h((string) $filters['sort']) ?>">
                      <input type="hidden" name="per_page" value="<?= h((string) $filters['per_page']) ?>">
                      <input type="hidden" name="page" value="<?= h((string) $meta['page']) ?>">
                      <button type="submit" class="table-action-link danger">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Xóa</span>
                      </button>
                    </form>
                  <?php endif; ?>
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
        'topic_lv3_key' => (string) $filters['topic_lv3_key'],
        'tag' => (string) $filters['tag'],
        'review_status' => (string) $filters['review_status'],
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
