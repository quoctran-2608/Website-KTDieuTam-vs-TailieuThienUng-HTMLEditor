<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_role(['admin']);

function taxonomy_admin_root_path(string $relative = ''): string
{
  $root = dirname(__DIR__);
  $relative = ltrim($relative, '/');
  return $relative === '' ? $root : ($root . '/' . $relative);
}

function taxonomy_admin_read_json(string $relative, array $fallback = []): array
{
  $path = taxonomy_admin_root_path($relative);
  if (!file_exists($path)) {
    return $fallback;
  }
  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') {
    return $fallback;
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : $fallback;
}

function taxonomy_admin_node_key(array $node): string
{
  return trim((string) ($node['key'] ?? ($node['id'] ?? '')));
}

function taxonomy_admin_node_label(array $node): string
{
  $label = trim((string) ($node['label'] ?? ''));
  return $label !== '' ? $label : taxonomy_admin_node_key($node);
}

/**
 * @return array<int,array<string,mixed>>
 */
function taxonomy_admin_children(array $node): array
{
  return is_array($node['children'] ?? null) ? array_values(array_filter($node['children'], 'is_array')) : [];
}

/**
 * @param array<int,string> $parts
 */
function taxonomy_admin_format_path(array $parts): string
{
  $out = [];
  foreach ($parts as $part) {
    $out[] = $part === '' ? '__empty__' : $part;
  }
  return implode('/', $out);
}

/**
 * @return array<int,string>
 */
function taxonomy_admin_split_path(string $path): array
{
  $path = trim($path, "/ \t\n\r\0\x0B");
  if ($path === '') {
    return [];
  }
  return array_map(static fn (string $part): string => $part === '__empty__' ? '' : $part, explode('/', $path));
}

/**
 * @param array<int,array<string,mixed>> $nodes
 * @param array<int,string> $parts
 * @return array<string,mixed>|null
 */
function taxonomy_admin_find_node(array $nodes, array $parts): ?array
{
  $current = null;
  foreach ($parts as $part) {
    $current = null;
    foreach ($nodes as $node) {
      if (taxonomy_admin_node_key($node) === $part) {
        $current = $node;
        break;
      }
    }
    if ($current === null) {
      return null;
    }
    $nodes = taxonomy_admin_children($current);
  }
  return is_array($current) ? $current : null;
}

/**
 * @param array<int,array<string,mixed>> $nodes
 * @param array<int,string> $prefix
 * @return array<string,int>
 */
function taxonomy_admin_count_map(array $nodes, array $prefix = []): array
{
  $map = [];
  foreach ($nodes as $node) {
    $parts = array_merge($prefix, [taxonomy_admin_node_key($node)]);
    $map[taxonomy_admin_format_path($parts)] = (int) ($node['count'] ?? 0);
    foreach (taxonomy_admin_count_map(taxonomy_admin_children($node), $parts) as $path => $count) {
      $map[$path] = $count;
    }
  }
  return $map;
}

/**
 * @param array<int,array<string,mixed>> $nodes
 * @param array<int,string> $prefix
 * @param array<string,int> $countMap
 * @param array<int,array<string,mixed>> $rows
 */
function taxonomy_admin_flatten(array $nodes, array $prefix, array $countMap, array &$rows): void
{
  foreach ($nodes as $node) {
    $parts = array_merge($prefix, [taxonomy_admin_node_key($node)]);
    $path = taxonomy_admin_format_path($parts);
    $rows[] = [
      'path' => $path,
      'parts' => $parts,
      'depth' => count($parts),
      'key' => taxonomy_admin_node_key($node),
      'label' => taxonomy_admin_node_label($node),
      'count' => (int) ($countMap[$path] ?? 0),
      'system' => !empty($node['system']) || !empty($node['locked']) || !empty($node['lockedKey']),
      'description' => (string) ($node['description'] ?? ''),
      'icon' => (string) ($node['icon'] ?? ''),
    ];
    taxonomy_admin_flatten(taxonomy_admin_children($node), $parts, $countMap, $rows);
  }
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function taxonomy_admin_default_path(array $rows, string $section): string
{
  $preferred = $section === 'ban-tin' ? 'ban-tin' : 'thu-vien/huong-dan';
  foreach ($rows as $row) {
    if (($row['path'] ?? '') === $preferred) {
      return $preferred;
    }
  }
  foreach ($rows as $row) {
    if (($row['parts'][0] ?? '') === $section) {
      return (string) $row['path'];
    }
  }
  return (string) ($rows[0]['path'] ?? '');
}

/**
 * @param array<string,string> $params
 */
function taxonomy_admin_url_with(array $params): string
{
  $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  return admin_url('taxonomy.php' . ($query !== '' ? ('?' . $query) : ''));
}

/**
 * @param array<int,string> $args
 * @return array<string,mixed>
 */
function taxonomy_admin_decode_cli_json(string $text): ?array
{
  $decoded = json_decode($text, true);
  if (is_array($decoded)) {
    return $decoded;
  }

  $start = strpos($text, '{');
  $end = strrpos($text, '}');
  if ($start === false || $end === false || $end <= $start) {
    return null;
  }
  $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
  return is_array($decoded) ? $decoded : null;
}

function taxonomy_admin_python_env_prefix(): string
{
  if (PHP_OS_FAMILY === 'Windows') {
    return 'set PYTHONUTF8=1&& set PYTHONIOENCODING=utf-8&& ';
  }
  return 'PYTHONUTF8=1 PYTHONIOENCODING=utf-8 ';
}

function taxonomy_admin_run_cli(array $args): array
{
  if (!function_exists('exec')) {
    return ['ok' => false, 'error' => 'PHP exec() đang bị tắt nên admin không thể chạy taxonomy CLI.'];
  }
  $script = taxonomy_admin_root_path('tools/manage_taxonomy.py');
  if (!file_exists($script)) {
    return ['ok' => false, 'error' => 'Không tìm thấy tools/manage_taxonomy.py'];
  }
  $candidates = [];
  $envPython = trim((string) getenv('KDTD_PYTHON_BIN'));
  if ($envPython !== '') {
    $candidates[] = $envPython;
  }
  $candidates[] = 'python3';
  $candidates[] = 'python';
  $candidates = array_values(array_unique($candidates));

  $last = null;
  foreach ($candidates as $python) {
    $pythonCmd = function_exists('public_rebuild_python_command')
      ? public_rebuild_python_command((string) $python)
      : escapeshellcmd((string) $python);
    $command = taxonomy_admin_python_env_prefix() . $pythonCmd . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
      $command .= ' ' . escapeshellarg($arg);
    }
    $command .= ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    $text = implode("\n", $output);
    $decoded = taxonomy_admin_decode_cli_json($text);
    $last = is_array($decoded) ? $decoded : [
      'ok' => false,
      'error' => trim($text) !== '' ? trim($text) : ('Không parse được JSON từ taxonomy CLI: ' . json_last_error_msg()),
    ];
    $last['exit_code'] = $exitCode;
    $last['python'] = (string) $python;
    if (!empty($last['ok'])) {
      return $last;
    }
  }
  return is_array($last) ? $last : ['ok' => false, 'error' => 'Không chạy được taxonomy CLI.'];
}

function taxonomy_admin_redirect(string $path = '', string $section = ''): void
{
  $params = [];
  if ($section !== '') {
    $params['section'] = $section;
  }
  if ($path !== '') {
    $params['path'] = $path;
  }
  redirect_to(taxonomy_admin_url_with($params));
}

if (is_post_request()) {
  $selectedPath = trim((string) ($_POST['taxonomy_path'] ?? ($_POST['taxonomy_parent'] ?? '')));
  $postSection = trim((string) ($_POST['taxonomy_section'] ?? ''));
  if (!verify_csrf((string) ($_POST['_csrf_token'] ?? ''))) {
    flash_set('danger', 'CSRF token không hợp lệ.');
    taxonomy_admin_redirect($selectedPath, $postSection);
  }

  $action = trim((string) ($_POST['taxonomy_action'] ?? ''));
  $result = ['ok' => false, 'error' => 'Hành động không hợp lệ.'];
  if ($action === 'edit-node') {
    $label = trim((string) ($_POST['taxonomy_label'] ?? ''));
    $args = ['edit-node', '--path', $selectedPath, '--label', $label];
    $key = trim((string) ($_POST['taxonomy_key'] ?? ''));
    if ($key !== '') {
      $args[] = '--key';
      $args[] = $key;
    }
    if (array_key_exists('taxonomy_description', $_POST)) {
      $args[] = '--description';
      $args[] = trim((string) $_POST['taxonomy_description']);
    }
    if (array_key_exists('taxonomy_icon', $_POST)) {
      $args[] = '--icon';
      $args[] = trim((string) $_POST['taxonomy_icon']);
    }
    $args[] = '--apply';
    $result = taxonomy_admin_run_cli($args);
  } elseif ($action === 'add-node') {
    $selectedPath = trim((string) ($_POST['taxonomy_parent'] ?? ''));
    $args = [
      'add-node',
      '--parent',
      $selectedPath,
      '--key',
      trim((string) ($_POST['taxonomy_key'] ?? '')),
      '--label',
      trim((string) ($_POST['taxonomy_label'] ?? '')),
      '--apply',
    ];
    $result = taxonomy_admin_run_cli($args);
  } elseif ($action === 'delete-node') {
    $args = ['delete-node', '--path', $selectedPath, '--apply'];
    $result = taxonomy_admin_run_cli($args);
    if (!empty($result['ok'])) {
      $parts = taxonomy_admin_split_path($selectedPath);
      array_pop($parts);
      $selectedPath = taxonomy_admin_format_path($parts);
    }
  }

  if (!empty($result['ok'])) {
    if (($action === 'add-node' || $action === 'edit-node') && !empty($result['path'])) {
      $selectedPath = (string) $result['path'];
    }
    $message = match ($action) {
      'add-node' => 'Đã thêm category và đồng bộ public.',
      'delete-node' => 'Đã xóa category rỗng và đồng bộ public.',
      default => 'Đã lưu sửa category và đồng bộ public.',
    };
    flash_set('success', $message);
  } else {
    flash_set('danger', 'Không lưu được category: ' . (string) ($result['error'] ?? 'Lỗi không rõ.'));
  }
  taxonomy_admin_redirect($selectedPath, $postSection);
}

$master = taxonomy_admin_read_json('data/taxonomy-master.json');
$publicTaxonomy = taxonomy_admin_read_json('data/taxonomy.json');
$roots = is_array($master['roots'] ?? null) ? array_values(array_filter($master['roots'], 'is_array')) : [];
$publicRoots = is_array($publicTaxonomy['roots'] ?? null) ? array_values(array_filter($publicTaxonomy['roots'], 'is_array')) : [];
$countMap = taxonomy_admin_count_map($publicRoots);
$rows = [];
taxonomy_admin_flatten($roots, [], $countMap, $rows);

$allowedSections = ['thu-vien', 'ban-tin', 'all'];
$requestedSection = trim((string) ($_GET['section'] ?? ''));
if (!in_array($requestedSection, $allowedSections, true)) {
  $requestedSection = '';
}

$selectedPath = trim((string) ($_GET['path'] ?? ''));
if ($selectedPath === '') {
  $selectedPath = taxonomy_admin_default_path($rows, $requestedSection !== '' && $requestedSection !== 'all' ? $requestedSection : 'thu-vien');
}
if ($selectedPath === '' || taxonomy_admin_find_node($roots, taxonomy_admin_split_path($selectedPath)) === null) {
  $fallbackSection = $requestedSection !== '' && $requestedSection !== 'all' ? $requestedSection : 'thu-vien';
  $selectedPath = taxonomy_admin_default_path($rows, $fallbackSection);
}
$selectedParts = taxonomy_admin_split_path($selectedPath);
$selectedSection = (string) ($selectedParts[0] ?? '');
if (in_array($requestedSection, ['thu-vien', 'ban-tin'], true) && $selectedSection !== $requestedSection) {
  $selectedPath = taxonomy_admin_default_path($rows, $requestedSection);
  $selectedParts = taxonomy_admin_split_path($selectedPath);
  $selectedSection = (string) ($selectedParts[0] ?? '');
}
$selectedNode = taxonomy_admin_find_node($roots, $selectedParts);
$selectedCount = (int) ($countMap[$selectedPath] ?? 0);
$selectedDepth = count($selectedParts);
$activeSection = $requestedSection !== '' ? $requestedSection : (in_array($selectedSection, ['thu-vien', 'ban-tin'], true) ? $selectedSection : 'thu-vien');
$maxDepth = $selectedSection === 'ban-tin' ? 4 : 5;
$canEdit = $selectedNode !== null && $selectedDepth > 1;
$canEditSlug = $canEdit
  && empty($selectedNode['system'])
  && empty($selectedNode['locked'])
  && empty($selectedNode['lockedKey']);
$canAddChild = $selectedNode !== null && $selectedDepth < $maxDepth && !($selectedSection === 'thu-vien' && $selectedDepth === 1);
$canDelete = $selectedNode !== null
  && $selectedDepth > 1
  && $selectedCount === 0
  && empty($selectedNode['system'])
  && empty($selectedNode['locked'])
  && empty($selectedNode['lockedKey']);

$sectionCounts = ['thu-vien' => 0, 'ban-tin' => 0];
foreach ($publicRoots as $root) {
  $key = taxonomy_admin_node_key($root);
  if (isset($sectionCounts[$key])) {
    $sectionCounts[$key] = (int) ($root['count'] ?? 0);
  }
}

$sectionNodeCounts = ['thu-vien' => 0, 'ban-tin' => 0];
foreach ($rows as $row) {
  $section = (string) ($row['parts'][0] ?? '');
  if (isset($sectionNodeCounts[$section])) {
    $sectionNodeCounts[$section]++;
  }
}
$visibleRows = array_values(array_filter(
  $rows,
  static fn (array $row): bool => $activeSection === 'all' || (string) ($row['parts'][0] ?? '') === $activeSection
));
$activeSectionLabel = match ($activeSection) {
  'ban-tin' => 'Bản tin',
  'all' => 'Tất cả',
  default => 'Thư viện',
};
$branchTabs = [
  [
    'key' => 'thu-vien',
    'label' => 'Thư viện',
    'icon' => 'fa-solid fa-book-open',
    'path' => taxonomy_admin_default_path($rows, 'thu-vien'),
    'articles' => $sectionCounts['thu-vien'],
    'nodes' => $sectionNodeCounts['thu-vien'],
  ],
  [
    'key' => 'ban-tin',
    'label' => 'Bản tin',
    'icon' => 'fa-regular fa-newspaper',
    'path' => taxonomy_admin_default_path($rows, 'ban-tin'),
    'articles' => $sectionCounts['ban-tin'],
    'nodes' => $sectionNodeCounts['ban-tin'],
  ],
  [
    'key' => 'all',
    'label' => 'Tất cả',
    'icon' => 'fa-solid fa-sitemap',
    'path' => $selectedPath,
    'articles' => $sectionCounts['thu-vien'] + $sectionCounts['ban-tin'],
    'nodes' => count($rows),
  ],
];

admin_layout_header([
  'title' => 'Phân loại',
  'active' => 'taxonomy',
  'description' => 'Thêm, sửa, đổi tên và xóa category an toàn cho Thư viện/Bản tin.',
]);
?>

<section class="admin-grid-cards compact taxonomy-metrics">
  <article class="metric-card">
    <span class="metric-icon"><i class="fa-solid fa-sitemap"></i></span>
    <div class="metric-body"><h3><?= number_format(count($rows), 0, ',', '.') ?></h3><p>Category nodes</p></div>
  </article>
  <article class="metric-card">
    <span class="metric-icon success"><i class="fa-solid fa-book-open"></i></span>
    <div class="metric-body"><h3><?= number_format($sectionCounts['thu-vien'], 0, ',', '.') ?></h3><p>Bài Thư viện</p></div>
  </article>
  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-regular fa-newspaper"></i></span>
    <div class="metric-body"><h3><?= number_format($sectionCounts['ban-tin'], 0, ',', '.') ?></h3><p>Bài Bản tin</p></div>
  </article>
  <article class="metric-card">
    <span class="metric-icon warning"><i class="fa-solid fa-shield-halved"></i></span>
    <div class="metric-body"><h3>Safe</h3><p>Backup trước khi ghi</p></div>
  </article>
</section>

<section class="taxonomy-layout">
  <article class="admin-panel taxonomy-tree-panel">
    <div class="panel-head">
      <h2>Cây phân loại</h2>
      <p>Đang xem <?= h($activeSectionLabel) ?>: <?= number_format(count($visibleRows), 0, ',', '.') ?> nodes. Chọn nhanh nhánh bên dưới để khỏi phải kéo dài.</p>
    </div>
    <div class="taxonomy-branch-switch" aria-label="Chọn nhanh nhánh phân loại">
      <?php foreach ($branchTabs as $tab): ?>
        <?php $isBranchActive = $activeSection === $tab['key']; ?>
        <a
          class="taxonomy-branch-tab <?= $isBranchActive ? 'is-active' : '' ?>"
          href="<?= h(taxonomy_admin_url_with(['section' => (string) $tab['key'], 'path' => (string) $tab['path']])) ?>"
        >
          <i class="<?= h((string) $tab['icon']) ?>"></i>
          <span><?= h((string) $tab['label']) ?></span>
          <strong><?= number_format((int) $tab['articles'], 0, ',', '.') ?> bài</strong>
          <small><?= number_format((int) $tab['nodes'], 0, ',', '.') ?> nodes</small>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap taxonomy-table-wrap">
      <table class="admin-table taxonomy-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>Key</th>
            <th>Bài</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($visibleRows as $row): ?>
            <?php $isSelected = $row['path'] === $selectedPath; ?>
            <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
              <td>
                <div class="taxonomy-node-label" style="--level: <?= max(0, (int) $row['depth'] - 1) ?>">
                  <span><?= h((string) $row['label']) ?></span>
                  <?php if (!empty($row['system'])): ?><small>system</small><?php endif; ?>
                </div>
              </td>
              <td><code><?= h((string) ($row['key'] === '' ? '__empty__' : $row['key'])) ?></code></td>
              <td><?= number_format((int) $row['count'], 0, ',', '.') ?></td>
              <td><a class="clear-filter-btn inline" href="<?= h(taxonomy_admin_url_with(['section' => $activeSection, 'path' => (string) $row['path']])) ?>">Chọn</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($visibleRows) === 0): ?>
            <tr><td colspan="4"><div class="empty-state">Không có category trong nhánh đang chọn.</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>

  <aside class="admin-panel taxonomy-edit-panel">
    <div class="panel-head">
      <h2>Thao tác</h2>
      <p><?= h(str_replace('/', ' › ', $selectedPath)) ?></p>
    </div>

    <?php if ($selectedNode === null): ?>
      <div class="empty-state">Không tìm thấy category được chọn.</div>
    <?php else: ?>
      <form class="taxonomy-form" method="post">
        <?= csrf_input_html() ?>
        <input type="hidden" name="taxonomy_action" value="edit-node">
        <input type="hidden" name="taxonomy_section" value="<?= h($activeSection) ?>">
        <input type="hidden" name="taxonomy_path" value="<?= h($selectedPath) ?>">
        <label class="filter-field">
          <span>Label</span>
          <input name="taxonomy_label" value="<?= h(taxonomy_admin_node_label($selectedNode)) ?>" data-taxonomy-edit-label <?= $canEdit ? '' : 'disabled' ?>>
        </label>
        <label class="filter-field">
          <span>Slug</span>
          <input
            name="taxonomy_key"
            value="<?= h(taxonomy_admin_node_key($selectedNode)) ?>"
            placeholder="slug-khong-dau"
            pattern="[a-z0-9][a-z0-9-]*"
            data-taxonomy-edit-slug
            <?= $canEditSlug ? '' : 'disabled' ?>
          >
          <small class="taxonomy-field-note">
            <?= $canEditSlug ? 'Có thể sửa slug; hệ thống tự cập nhật các bài đang dùng category này.' : 'Slug của node hệ thống đang bị khóa.' ?>
          </small>
        </label>
        <?php if ($selectedSection === 'thu-vien' && $selectedDepth === 2): ?>
          <label class="filter-field">
            <span>Icon FontAwesome</span>
            <input name="taxonomy_icon" value="<?= h((string) ($selectedNode['icon'] ?? '')) ?>" placeholder="fa-layer-group">
          </label>
          <label class="filter-field">
            <span>Mô tả</span>
            <input name="taxonomy_description" value="<?= h((string) ($selectedNode['description'] ?? '')) ?>">
          </label>
        <?php endif; ?>
        <button class="filter-submit-btn" type="submit" <?= $canEdit ? '' : 'disabled' ?>>
          <i class="fa-solid fa-floppy-disk"></i> Lưu sửa
        </button>
      </form>

      <details class="taxonomy-add-details">
        <summary class="publish-btn taxonomy-add-summary <?= $canAddChild ? '' : 'is-disabled' ?>">
          <i class="fa-solid fa-plus"></i> Thêm category con
        </summary>
        <?php if ($canAddChild): ?>
          <form class="taxonomy-form taxonomy-form--add" method="post">
            <?= csrf_input_html() ?>
            <input type="hidden" name="taxonomy_action" value="add-node">
            <input type="hidden" name="taxonomy_section" value="<?= h($activeSection) ?>">
            <input type="hidden" name="taxonomy_parent" value="<?= h($selectedPath) ?>">
            <label class="filter-field">
              <span>Label mới</span>
              <input name="taxonomy_label" placeholder="Tên category" data-taxonomy-label required>
            </label>
            <label class="filter-field">
              <span>Slug tự sinh</span>
              <input name="taxonomy_key" placeholder="tu-dong-theo-label" pattern="[a-z0-9][a-z0-9-]*" data-taxonomy-slug>
              <small class="taxonomy-field-note">Có thể để trống; hệ thống sẽ tự sinh slug từ Label.</small>
            </label>
            <button class="publish-btn" type="submit">
              <i class="fa-solid fa-check"></i> Tạo category
            </button>
          </form>
        <?php endif; ?>
        <?php if (!$canAddChild): ?>
          <small class="taxonomy-note">Không thể thêm con tại node này do đã tới cấp cuối hoặc đây là root Thư viện.</small>
        <?php endif; ?>
      </details>

      <form class="taxonomy-form taxonomy-form--delete" method="post" onsubmit="return confirm('Xóa category rỗng này?');">
        <?= csrf_input_html() ?>
        <input type="hidden" name="taxonomy_action" value="delete-node">
        <input type="hidden" name="taxonomy_section" value="<?= h($activeSection) ?>">
        <input type="hidden" name="taxonomy_path" value="<?= h($selectedPath) ?>">
        <button class="rollback-btn" type="submit" <?= $canDelete ? '' : 'disabled' ?>>
          <i class="fa-solid fa-trash"></i> Xóa category rỗng
        </button>
        <small class="taxonomy-note">
          <?= $canDelete ? 'Chỉ xóa khi 0 bài; hệ thống tự backup trước khi ghi.' : 'Category đang có bài hoặc là node hệ thống nên chưa được xóa.' ?>
        </small>
      </form>
    <?php endif; ?>
  </aside>
</section>

<script>
(function () {
  function slugify(value) {
    return (value || '')
      .toString()
      .trim()
      .toLowerCase()
      .replace(/đ/g, 'd')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  document.querySelectorAll('[data-taxonomy-edit-slug]').forEach(function (input) {
    var form = input.closest('form');
    var label = form ? form.querySelector('[data-taxonomy-edit-label]') : null;
    var autoFromLabel = true;
    function normalizeSlugField() {
      input.value = slugify(input.value);
    }
    function syncFromLabel() {
      if (!autoFromLabel || !label) return;
      input.value = slugify(label.value);
    }
    input.addEventListener('input', function () {
      normalizeSlugField();
      if (!label) return;
      var labelSlug = slugify(label.value);
      autoFromLabel = input.value === '' || input.value === labelSlug;
    });
    if (label && !input.disabled) {
      label.addEventListener('input', syncFromLabel);
    }
  });

  document.querySelectorAll('.taxonomy-form--add').forEach(function (form) {
    var label = form.querySelector('[data-taxonomy-label]');
    var slug = form.querySelector('[data-taxonomy-slug]');
    if (!label || !slug) return;
    var manualSlug = false;
    slug.addEventListener('input', function () {
      manualSlug = slug.value.trim() !== '';
      slug.value = slugify(slug.value);
    });
    label.addEventListener('input', function () {
      if (!manualSlug) {
        slug.value = slugify(label.value);
      }
    });
  });
})();
</script>

<?php admin_layout_footer(); ?>
