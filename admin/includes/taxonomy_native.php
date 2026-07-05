<?php
declare(strict_types=1);

/**
 * PHP-native taxonomy management — no Python CLI or exec() required.
 *
 * Handles add-node, edit-node, delete-node on taxonomy-master.json,
 * then syncs taxonomy.json and editor-taxonomy.json.
 *
 * Safe for Hostinger shared hosting where exec() is disabled.
 */

function taxonomy_native_root_path(string $relative = ''): string
{
  $root = dirname(dirname(__DIR__));
  $relative = ltrim($relative, '/');
  return $relative === '' ? $root : ($root . '/' . $relative);
}

/**
 * @return array<string,mixed>
 */
function taxonomy_native_read_json(string $path): array
{
  if (!file_exists($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') {
    return [];
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function taxonomy_native_write_json(string $path, array $data): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . "\n") !== false;
}

function taxonomy_native_now(): string
{
  return gmdate('Y-m-d\TH:i:s\Z');
}

function taxonomy_native_slugify(string $value): string
{
  $text = trim(mb_strtolower($value, 'UTF-8'));
  $text = str_replace(['đ', 'Đ'], 'd', $text);
  // Remove diacritical marks using intl Normalizer if available
  if (function_exists('normalizer_normalize') && class_exists('Normalizer')) {
    $normalized = normalizer_normalize($text, \Normalizer::FORM_D);
    if (is_string($normalized) && $normalized !== '') {
      $text = preg_replace('/\pM/u', '', $normalized) ?: $text;
    }
  }
  // If intl extension unavailable or didn't change anything, try iconv
  if ($text === trim(mb_strtolower($value, 'UTF-8')) && function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($converted) && $converted !== '') {
      $text = $converted;
    }
  }
  $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
  $text = trim($text, '-');
  return $text !== '' ? $text : 'category';
}

function taxonomy_native_is_valid_key(string $key): bool
{
  return (bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $key);
}

/**
 * @param array<int,array<string,mixed>> $nodes
 */
function taxonomy_native_node_key(array $node): string
{
  return trim((string) ($node['key'] ?? ($node['id'] ?? '')));
}

function taxonomy_native_node_label(array $node): string
{
  $label = trim((string) ($node['label'] ?? ''));
  return $label !== '' ? $label : taxonomy_native_node_key($node);
}

/**
 * @return array<int,array<string,mixed>>
 */
function taxonomy_native_children(array $node): array
{
  return is_array($node['children'] ?? null) ? array_values(array_filter($node['children'], 'is_array')) : [];
}

/**
 * @param array<int,string> $parts
 * @return array<string,mixed>|null
 */
function taxonomy_native_find_node(array $roots, array $parts): ?array
{
  $nodes = $roots;
  $current = null;
  foreach ($parts as $part) {
    $current = null;
    foreach ($nodes as $node) {
      if (taxonomy_native_node_key($node) === $part) {
        $current = $node;
        break;
      }
    }
    if ($current === null) {
      return null;
    }
    $nodes = taxonomy_native_children($current);
  }
  return $current;
}

/**
 * @param array<int,string> $parts
 */
function taxonomy_native_format_path(array $parts): string
{
  return implode('/', array_map(fn(string $p): string => $p === '' ? '__empty__' : $p, $parts));
}

/**
 * @return array<int,string>
 */
function taxonomy_native_split_path(string $path): array
{
  $path = trim($path, "/ \t\n\r\0\x0B");
  if ($path === '') {
    return [];
  }
  return array_map(fn(string $p): string => $p === '__empty__' ? '' : $p, explode('/', $path));
}

// ------------- Backup helpers ---------------

function taxonomy_native_backup(): bool
{
  $backupDir = taxonomy_native_root_path('.m/taxonomy-admin');
  if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
  }
  $ts = date('Ymd-His');
  $masterPath = taxonomy_native_root_path('data/taxonomy-master.json');
  $taxonomyPath = taxonomy_native_root_path('data/taxonomy.json');
  $ok = true;
  if (file_exists($masterPath)) {
    $ok = $ok && copy($masterPath, $backupDir . '/taxonomy-master-' . $ts . '.json');
  }
  if (file_exists($taxonomyPath)) {
    $ok = $ok && copy($taxonomyPath, $backupDir . '/taxonomy-' . $ts . '.json');
  }
  return $ok;
}

// ------------- Modify master ---------------

/**
 * Set a node value in the master tree by path, modifying the provided array in-place.
 *
 * @param array<string,mixed> $master   The master data structure (passed by reference)
 * @param array<int,string>   $parts    Path parts to the target node
 * @param string              $field    Field name to set
 * @param mixed               $value    Value to set
 * @return bool  Whether the node was found and updated
 */
function taxonomy_native_set_node_field(array &$master, array $parts, string $field, $value): bool
{
  $nodes = &$master['roots'];
  $current = null;
  foreach ($parts as $i => $part) {
    $found = false;
    foreach ($nodes as &$node) {
      if (taxonomy_native_node_key($node) === $part) {
        if ($i === count($parts) - 1) {
          $node[$field] = $value;
          return true;
        }
        if (!isset($node['children'])) {
          $node['children'] = [];
        }
        $nodes = &$node['children'];
        $found = true;
        break;
      }
    }
    unset($node);
    if (!$found) {
      return false;
    }
  }
  return false;
}

/**
 * @param array<string,mixed> $master
 * @param array<int,string>   $parentParts
 * @param array<string,mixed> $newNode
 */
function taxonomy_native_add_child(array &$master, array $parentParts, array $newNode): bool
{
  if (empty($parentParts)) {
    $master['roots'][] = $newNode;
    return true;
  }
  $nodes = &$master['roots'];
  foreach ($parentParts as $i => $part) {
    $found = false;
    foreach ($nodes as &$node) {
      if (taxonomy_native_node_key($node) === $part) {
        if ($i === count($parentParts) - 1) {
          if (!isset($node['children'])) {
            $node['children'] = [];
          }
          $node['children'][] = $newNode;
          return true;
        }
        if (!isset($node['children'])) {
          $node['children'] = [];
        }
        $nodes = &$node['children'];
        $found = true;
        break;
      }
    }
    unset($node);
    if (!$found) {
      return false;
    }
  }
  return false;
}

/**
 * @param array<string,mixed> $master
 * @param array<int,string>   $parts  Path to node to delete
 */
function taxonomy_native_delete_node(array &$master, array $parts): bool
{
  if (count($parts) < 2) {
    return false; // Cannot delete root nodes
  }
  $parentParts = array_slice($parts, 0, -1);
  $targetKey = end($parts);

  $nodes = &$master['roots'];
  foreach ($parentParts as $i => $part) {
    $found = false;
    foreach ($nodes as &$node) {
      if (taxonomy_native_node_key($node) === $part) {
        if ($i === count($parentParts) - 1) {
          // Found parent, remove child
          $children = taxonomy_native_children($node);
          $newChildren = [];
          $deleted = false;
          foreach ($children as $child) {
            if (taxonomy_native_node_key($child) === $targetKey && !$deleted) {
              $deleted = true;
              continue;
            }
            $newChildren[] = $child;
          }
          if ($deleted) {
            $node['children'] = $newChildren;
            if (empty($node['children'])) {
              unset($node['children']);
            }
            return true;
          }
          return false;
        }
        if (!isset($node['children'])) {
          return false;
        }
        $nodes = &$node['children'];
        $found = true;
        break;
      }
    }
    unset($node);
    if (!$found) {
      return false;
    }
  }
  return false;
}

/**
 * Rename a node's key, updating the key field.
 */
function taxonomy_native_rename_key(array &$master, array $parts, string $newKey): bool
{
  if (empty($parts)) {
    return false;
  }
  $parentParts = array_slice($parts, 0, -1);
  $oldKey = end($parts);

  // Navigate to parent
  if (empty($parentParts)) {
    // Root level
    foreach ($master['roots'] as &$root) {
      $rootKey = taxonomy_native_node_key($root);
      if ($rootKey === $oldKey) {
        if (isset($root['id'])) {
          $root['id'] = $newKey;
        }
        $root['key'] = $newKey;
        return true;
      }
    }
    unset($root);
    return false;
  }

  $nodes = &$master['roots'];
  foreach ($parentParts as $i => $part) {
    $found = false;
    foreach ($nodes as &$node) {
      if (taxonomy_native_node_key($node) === $part) {
        if ($i === count($parentParts) - 1) {
          // Found parent, rename child
          if (!isset($node['children'])) {
            return false;
          }
          foreach ($node['children'] as &$child) {
            if (taxonomy_native_node_key($child) === $oldKey) {
              if (isset($child['id'])) {
                $child['id'] = $newKey;
              }
              $child['key'] = $newKey;
              return true;
            }
          }
          unset($child);
          return false;
        }
        $nodes = &$node['children'];
        $found = true;
        break;
      }
    }
    unset($node);
    if (!$found) {
      return false;
    }
  }
  return false;
}

// ------------- Count articles per node ---------------

/**
 * @return array<string,int>
 */
function taxonomy_native_count_articles(array $master, array $articles): array
{
  $counts = [];

  foreach ($articles as $article) {
    $section = trim((string) ($article['section'] ?? ''));
    if (!in_array($section, ['thu-vien', 'ban-tin'], true)) {
      continue;
    }

    $parts = [$section];
    if ($section === 'thu-vien') {
      $kindKey = trim((string) ($article['libraryKindKey'] ?? ($article['library_kind_key'] ?? '')));
      if ($kindKey !== '') {
        $parts[] = $kindKey;
      }
    }

    $topicLv1 = trim((string) ($article['topicLv1Key'] ?? ($article['topic_lv1_key'] ?? '')));
    if ($topicLv1 !== '') {
      $parts[] = $topicLv1;
    }

    $topicLv2 = trim((string) ($article['topicLv2Key'] ?? ($article['topic_lv2_key'] ?? '')));
    if ($topicLv2 !== '') {
      $parts[] = $topicLv2;
    }

    $topicLv3 = trim((string) ($article['topicLv3Key'] ?? ($article['topic_lv3_key'] ?? '')));
    if ($topicLv3 !== '') {
      $parts[] = $topicLv3;
    }

    // Increment count for each prefix of the path
    for ($i = 1; $i <= count($parts); $i++) {
      $prefix = taxonomy_native_format_path(array_slice($parts, 0, $i));
      $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
    }
  }
  return $counts;
}

// ------------- Build public taxonomy ---------------

/**
 * Build public taxonomy node with article counts.
 */
function taxonomy_native_public_node(array $node, array $pathPrefix, array $counts): array
{
  $key = taxonomy_native_node_key($node);
  $currentPath = array_merge($pathPrefix, [$key]);
  $pathStr = taxonomy_native_format_path($currentPath);

  $out = [
    'key' => $key,
    'label' => taxonomy_native_node_label($node),
    'count' => $counts[$pathStr] ?? 0,
  ];

  $children = taxonomy_native_children($node);
  if (!empty($children)) {
    $publicChildren = [];
    foreach ($children as $child) {
      if (!empty($child['hidden'])) {
        continue;
      }
      $publicChildren[] = taxonomy_native_public_node($child, $currentPath, $counts);
    }
    if (!empty($publicChildren)) {
      $out['children'] = $publicChildren;
    }
  }
  return $out;
}

/**
 * Build the complete public taxonomy.json from master.
 */
function taxonomy_native_build_public(array $master, array $articles): array
{
  $counts = taxonomy_native_count_articles($master, $articles);
  $roots = [];
  foreach (($master['roots'] ?? []) as $root) {
    if (!is_array($root) || !empty($root['hidden'])) {
      continue;
    }
    $roots[] = taxonomy_native_public_node($root, [], $counts);
  }
  return [
    'generatedAt' => taxonomy_native_now(),
    'roots' => $roots,
    'toolVariants' => is_array($master['toolVariants'] ?? null) ? $master['toolVariants'] : new \stdClass(),
  ];
}

/**
 * Build editor-taxonomy.json from public taxonomy.
 */
function taxonomy_native_build_editor(array $publicTaxonomy): array
{
  $roots = [];
  foreach (($publicTaxonomy['roots'] ?? []) as $root) {
    if (!is_array($root)) {
      continue;
    }
    $editorRoot = [
      'id' => taxonomy_native_node_key($root),
      'label' => taxonomy_native_node_label($root),
    ];
    $children = taxonomy_native_children($root);
    if (!empty($children)) {
      $editorChildren = [];
      foreach ($children as $child) {
        $editorChildren[] = taxonomy_native_editor_node($child, true);
      }
      $editorRoot['children'] = $editorChildren;
    }
    $roots[] = $editorRoot;
  }
  return [
    'generatedAt' => $publicTaxonomy['generatedAt'] ?? taxonomy_native_now(),
    'roots' => $roots,
    'variants' => ['cong-cu' => $publicTaxonomy['toolVariants'] ?? new \stdClass()],
    'fieldMap' => [
      'section' => 'primary_category_id',
      'library_kind' => 'library_kind',
      'topic_lv1' => 'domain',
      'topic_lv2' => 'subdomain',
      'tool_lv3' => 'variant',
    ],
  ];
}

function taxonomy_native_editor_node(array $node, bool $useId = false): array
{
  $out = [
    ($useId ? 'id' : 'key') => taxonomy_native_node_key($node),
    'label' => taxonomy_native_node_label($node),
  ];
  $children = taxonomy_native_children($node);
  if (!empty($children)) {
    $editorChildren = [];
    foreach ($children as $child) {
      $editorChildren[] = taxonomy_native_editor_node($child, false);
    }
    $out['children'] = $editorChildren;
  }
  return $out;
}

// ------------- Sync all files ---------------

/**
 * Write master, public taxonomy, and editor taxonomy files.
 *
 * @return array<string,mixed>
 */
function taxonomy_native_sync(array $master): array
{
  $errors = [];

  $masterPath = taxonomy_native_root_path('data/taxonomy-master.json');
  if (!taxonomy_native_write_json($masterPath, $master)) {
    $errors[] = 'Không ghi được taxonomy-master.json';
  }

  // Read articles
  $articlesPath = taxonomy_native_root_path('data/articles.json');
  $articles = [];
  if (file_exists($articlesPath)) {
    $raw = file_get_contents($articlesPath);
    if ($raw !== false) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $articles = $decoded;
      }
    }
  }

  // Build and write public taxonomy
  $publicTaxonomy = taxonomy_native_build_public($master, $articles);
  $taxonomyPath = taxonomy_native_root_path('data/taxonomy.json');
  if (!taxonomy_native_write_json($taxonomyPath, $publicTaxonomy)) {
    $errors[] = 'Không ghi được taxonomy.json';
  }

  // Build and write editor taxonomy
  $editorTaxonomy = taxonomy_native_build_editor($publicTaxonomy);
  $editorPath = taxonomy_native_root_path('data/editor-taxonomy.json');
  if (!taxonomy_native_write_json($editorPath, $editorTaxonomy)) {
    $errors[] = 'Không ghi được editor-taxonomy.json';
  }

  // Rebuild hubs library kinds
  taxonomy_native_rebuild_hub_kinds($master, $articles);

  if (!empty($errors)) {
    return ['ok' => false, 'error' => implode('; ', $errors)];
  }
  return ['ok' => true, 'message' => 'Đã đồng bộ taxonomy master, public và editor.'];
}

/**
 * Rebuild the libraryKinds in data/hubs/thu-vien.json.
 */
function taxonomy_native_rebuild_hub_kinds(array $master, array $articles): void
{
  $hubPath = taxonomy_native_root_path('data/hubs/thu-vien.json');
  if (!file_exists($hubPath)) {
    return;
  }
  $hub = taxonomy_native_read_json($hubPath);
  if (empty($hub)) {
    return;
  }

  // Find thu-vien root
  $thuVien = null;
  foreach (($master['roots'] ?? []) as $root) {
    if (taxonomy_native_node_key($root) === 'thu-vien') {
      $thuVien = $root;
      break;
    }
  }
  if ($thuVien === null) {
    return;
  }

  $counts = taxonomy_native_count_articles($master, $articles);
  $kinds = [];
  $defaultMeta = [
    'phan-loai-moi' => ['icon' => 'fa-layer-group', 'description' => 'Nhóm phân loại mới đang chuẩn bị nội dung'],
    'huong-dan' => ['icon' => 'fa-compass-drafting', 'description' => 'Quy trình, cách làm, nghiệp vụ thực tế'],
    'bieu-mau' => ['icon' => 'fa-file-lines', 'description' => 'Mẫu biểu, hồ sơ, tờ khai dùng ngay'],
    'cong-cu' => ['icon' => 'fa-screwdriver-wrench', 'description' => 'Excel, HTKK, MISA và file hỗ trợ'],
    'van-ban' => ['icon' => 'fa-scale-balanced', 'description' => 'Luật, nghị định, thông tư, công văn và cập nhật pháp lý'],
  ];

  foreach (taxonomy_native_children($thuVien) as $kind) {
    $key = taxonomy_native_node_key($kind);
    $pathStr = taxonomy_native_format_path(['thu-vien', $key]);
    $meta = $defaultMeta[$key] ?? ['icon' => 'fa-layer-group', 'description' => ''];
    $kinds[] = [
      'key' => $key,
      'label' => taxonomy_native_node_label($kind),
      'count' => $counts[$pathStr] ?? 0,
      'href' => 'thu-vien.html?kind=' . $key,
      'icon' => (string) ($kind['icon'] ?? $meta['icon']),
      'description' => (string) ($kind['description'] ?? $meta['description']),
    ];
  }

  $hub['libraryKinds'] = $kinds;
  taxonomy_native_write_json($hubPath, $hub);

  // Also write .js store
  $jsPath = taxonomy_native_root_path('data/hubs/thu-vien.js');
  if (file_exists($jsPath)) {
    $payload = json_encode($hub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload !== false) {
      file_put_contents($jsPath, 'window.KetoanDieuTamHubStore=window.KetoanDieuTamHubStore||{};window.KetoanDieuTamHubStore["thu-vien"]=' . $payload . ";\n");
    }
  }
}

// ------------- High-level operations ---------------

/**
 * Add a new child node.
 *
 * @return array<string,mixed>
 */
function taxonomy_native_op_add(string $parentPath, string $key, string $label): array
{
  $parentParts = taxonomy_native_split_path($parentPath);
  if (empty($parentParts)) {
    return ['ok' => false, 'error' => 'Parent path không hợp lệ.'];
  }

  $master = taxonomy_native_read_json(taxonomy_native_root_path('data/taxonomy-master.json'));
  if (empty($master['roots'])) {
    return ['ok' => false, 'error' => 'Không đọc được taxonomy-master.json.'];
  }

  // Check parent exists
  $parent = taxonomy_native_find_node($master['roots'], $parentParts);
  if ($parent === null) {
    return ['ok' => false, 'error' => 'Không tìm thấy parent: ' . $parentPath];
  }

  // Validate label
  $label = trim($label);
  if ($label === '') {
    return ['ok' => false, 'error' => 'Label không được rỗng.'];
  }

  // Generate or validate key
  $key = trim($key);
  if ($key === '') {
    $key = taxonomy_native_slugify($label);
  }
  if (!taxonomy_native_is_valid_key($key)) {
    $key = taxonomy_native_slugify($key);
  }
  if (!taxonomy_native_is_valid_key($key)) {
    return ['ok' => false, 'error' => 'Slug không hợp lệ: ' . $key];
  }

  // Check for duplicate
  $existingKeys = array_map(fn($c) => taxonomy_native_node_key($c), taxonomy_native_children($parent));
  if (in_array($key, $existingKeys, true)) {
    // Make unique
    $base = $key;
    $index = 2;
    while (in_array($key, $existingKeys, true)) {
      $key = $base . '-' . $index;
      $index++;
    }
  }

  // Check depth
  $section = $parentParts[0] ?? '';
  $maxDepth = $section === 'ban-tin' ? 4 : 5;
  $newDepth = count($parentParts) + 1;
  if ($newDepth > $maxDepth) {
    return ['ok' => false, 'error' => 'Đã đạt cấp sâu nhất (' . $maxDepth . ').'];
  }

  // Backup
  taxonomy_native_backup();

  // Add node
  $newNode = ['key' => $key, 'label' => $label];
  $added = taxonomy_native_add_child($master, $parentParts, $newNode);
  if (!$added) {
    return ['ok' => false, 'error' => 'Không thể thêm node con.'];
  }

  // Sync
  $syncResult = taxonomy_native_sync($master);
  if (empty($syncResult['ok'])) {
    return $syncResult;
  }

  $newPath = taxonomy_native_format_path(array_merge($parentParts, [$key]));
  return ['ok' => true, 'path' => $newPath, 'key' => $key, 'label' => $label];
}

/**
 * Edit an existing node.
 *
 * @return array<string,mixed>
 */
function taxonomy_native_op_edit(string $nodePath, string $label, string $newKey = '', string $description = '', string $icon = ''): array
{
  $parts = taxonomy_native_split_path($nodePath);
  if (empty($parts)) {
    return ['ok' => false, 'error' => 'Node path không hợp lệ.'];
  }

  $master = taxonomy_native_read_json(taxonomy_native_root_path('data/taxonomy-master.json'));
  if (empty($master['roots'])) {
    return ['ok' => false, 'error' => 'Không đọc được taxonomy-master.json.'];
  }

  $node = taxonomy_native_find_node($master['roots'], $parts);
  if ($node === null) {
    return ['ok' => false, 'error' => 'Không tìm thấy node: ' . $nodePath];
  }

  $label = trim($label);
  if ($label === '') {
    return ['ok' => false, 'error' => 'Label không được rỗng.'];
  }

  // Backup
  taxonomy_native_backup();

  // Update label
  taxonomy_native_set_node_field($master, $parts, 'label', $label);

  // Update description if provided
  if ($description !== '') {
    taxonomy_native_set_node_field($master, $parts, 'description', $description);
  }

  // Update icon if provided
  if ($icon !== '') {
    taxonomy_native_set_node_field($master, $parts, 'icon', $icon);
  }

  // Rename key if different and not locked
  $newPath = $nodePath;
  $oldKey = end($parts);
  $newKey = trim($newKey);
  if ($newKey !== '' && $newKey !== $oldKey) {
    if (!taxonomy_native_is_valid_key($newKey)) {
      $newKey = taxonomy_native_slugify($newKey);
    }
    if (!taxonomy_native_is_valid_key($newKey)) {
      return ['ok' => false, 'error' => 'Slug mới không hợp lệ.'];
    }

    // Check the node is not system/locked
    $isLocked = !empty($node['system']) || !empty($node['locked']) || !empty($node['lockedKey']);
    if ($isLocked) {
      return ['ok' => false, 'error' => 'Node hệ thống không được đổi slug.'];
    }

    // Check sibling duplicate
    if (count($parts) > 1) {
      $parentParts = array_slice($parts, 0, -1);
      $parent = taxonomy_native_find_node($master['roots'], $parentParts);
      if ($parent !== null) {
        $siblingKeys = array_map(fn($c) => taxonomy_native_node_key($c), taxonomy_native_children($parent));
        $siblingKeys = array_filter($siblingKeys, fn($k) => $k !== $oldKey);
        if (in_array($newKey, $siblingKeys, true)) {
          return ['ok' => false, 'error' => 'Đã tồn tại sibling với slug: ' . $newKey];
        }
      }
    }

    taxonomy_native_rename_key($master, $parts, $newKey);

    // Update articles that reference old path
    $newParts = array_slice($parts, 0, -1);
    $newParts[] = $newKey;
    $newPath = taxonomy_native_format_path($newParts);
    taxonomy_native_update_article_keys($parts, $newParts);
  }

  // Sync
  $syncResult = taxonomy_native_sync($master);
  if (empty($syncResult['ok'])) {
    return $syncResult;
  }

  return ['ok' => true, 'path' => $newPath];
}

/**
 * Delete an empty node.
 *
 * @return array<string,mixed>
 */
function taxonomy_native_op_delete(string $nodePath): array
{
  $parts = taxonomy_native_split_path($nodePath);
  if (empty($parts) || count($parts) < 2) {
    return ['ok' => false, 'error' => 'Không thể xóa root node.'];
  }

  $master = taxonomy_native_read_json(taxonomy_native_root_path('data/taxonomy-master.json'));
  if (empty($master['roots'])) {
    return ['ok' => false, 'error' => 'Không đọc được taxonomy-master.json.'];
  }

  $node = taxonomy_native_find_node($master['roots'], $parts);
  if ($node === null) {
    return ['ok' => false, 'error' => 'Không tìm thấy node: ' . $nodePath];
  }

  // Check system
  if (!empty($node['system']) || !empty($node['locked']) || !empty($node['lockedKey'])) {
    return ['ok' => false, 'error' => 'Không thể xóa node hệ thống.'];
  }

  // Check article count
  $articlesPath = taxonomy_native_root_path('data/articles.json');
  $articles = [];
  if (file_exists($articlesPath)) {
    $raw = file_get_contents($articlesPath);
    if ($raw !== false) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $articles = $decoded;
      }
    }
  }
  $counts = taxonomy_native_count_articles($master, $articles);
  $pathStr = taxonomy_native_format_path($parts);
  $articleCount = $counts[$pathStr] ?? 0;
  if ($articleCount > 0) {
    return ['ok' => false, 'error' => 'Category đang có ' . $articleCount . ' bài, không thể xóa.'];
  }

  // Backup
  taxonomy_native_backup();

  // Delete
  $deleted = taxonomy_native_delete_node($master, $parts);
  if (!$deleted) {
    return ['ok' => false, 'error' => 'Không thể xóa node.'];
  }

  // Sync
  $syncResult = taxonomy_native_sync($master);
  if (empty($syncResult['ok'])) {
    return $syncResult;
  }

  return ['ok' => true];
}

/**
 * Update article keys when a node is renamed.
 */
function taxonomy_native_update_article_keys(array $oldParts, array $newParts): void
{
  $articlesPath = taxonomy_native_root_path('data/articles.json');
  if (!file_exists($articlesPath)) {
    return;
  }
  $raw = file_get_contents($articlesPath);
  if ($raw === false) {
    return;
  }
  $articles = json_decode($raw, true);
  if (!is_array($articles)) {
    return;
  }

  $section = $oldParts[0] ?? '';
  $depth = count($oldParts);
  $oldKey = end($oldParts);
  $newKey = end($newParts);

  $fieldMap = [];
  if ($section === 'thu-vien') {
    $fieldMap = [
      2 => ['libraryKindKey', 'library_kind_key'],
      3 => ['topicLv1Key', 'topic_lv1_key'],
      4 => ['topicLv2Key', 'topic_lv2_key'],
      5 => ['topicLv3Key', 'topic_lv3_key'],
    ];
  } elseif ($section === 'ban-tin') {
    $fieldMap = [
      2 => ['topicLv1Key', 'topic_lv1_key'],
      3 => ['topicLv2Key', 'topic_lv2_key'],
      4 => ['topicLv3Key', 'topic_lv3_key'],
    ];
  }

  if (!isset($fieldMap[$depth])) {
    return;
  }

  $fields = $fieldMap[$depth];
  $changed = false;

  foreach ($articles as &$article) {
    if (trim((string) ($article['section'] ?? '')) !== $section) {
      continue;
    }
    // Check article matches parent prefix
    $matchesPrefix = true;
    $parentParts = array_slice($oldParts, 1, -1); // Skip section, skip target
    if ($section === 'thu-vien' && $depth >= 3) {
      $kindKey = trim((string) ($article['libraryKindKey'] ?? ($article['library_kind_key'] ?? '')));
      if (isset($oldParts[1]) && $kindKey !== $oldParts[1]) {
        $matchesPrefix = false;
      }
      if ($depth >= 4) {
        $lv1Key = trim((string) ($article['topicLv1Key'] ?? ($article['topic_lv1_key'] ?? '')));
        if (isset($oldParts[2]) && $lv1Key !== $oldParts[2]) {
          $matchesPrefix = false;
        }
      }
      if ($depth >= 5) {
        $lv2Key = trim((string) ($article['topicLv2Key'] ?? ($article['topic_lv2_key'] ?? '')));
        if (isset($oldParts[3]) && $lv2Key !== $oldParts[3]) {
          $matchesPrefix = false;
        }
      }
    } elseif ($section === 'ban-tin' && $depth >= 3) {
      $lv1Key = trim((string) ($article['topicLv1Key'] ?? ($article['topic_lv1_key'] ?? '')));
      if (isset($oldParts[1]) && $lv1Key !== $oldParts[1]) {
        $matchesPrefix = false;
      }
      if ($depth >= 4) {
        $lv2Key = trim((string) ($article['topicLv2Key'] ?? ($article['topic_lv2_key'] ?? '')));
        if (isset($oldParts[2]) && $lv2Key !== $oldParts[2]) {
          $matchesPrefix = false;
        }
      }
    }

    if (!$matchesPrefix) {
      continue;
    }

    foreach ($fields as $field) {
      if (isset($article[$field]) && trim((string) $article[$field]) === $oldKey) {
        $article[$field] = $newKey;
        $changed = true;
      }
    }
  }
  unset($article);

  if ($changed) {
    $json = json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
      file_put_contents($articlesPath, $json . "\n");
    }
  }
}
