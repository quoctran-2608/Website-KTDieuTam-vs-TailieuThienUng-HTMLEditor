<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

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
 * Resolve view URL for public article page.
 *
 * @param array<string,mixed> $article
 */
function article_public_url_detail(array $article): string
{
  return public_article_url($article);
}

/**
 * Read full public taxonomy for the editor selector.
 *
 * Phase 2 validates and persists the selected category into admin drafts.
 *
 * @return array<string,mixed>
 */
function read_article_editor_taxonomy_payload(): array
{
  $path = dirname(__DIR__) . '/data/taxonomy.json';
  if (!file_exists($path)) {
    return ['roots' => []];
  }

  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') {
    return ['roots' => []];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded) || !is_array($decoded['roots'] ?? null)) {
    return ['roots' => []];
  }

  return [
    'generatedAt' => (string) ($decoded['generatedAt'] ?? ''),
    'roots' => $decoded['roots'],
  ];
}

function article_taxonomy_node_key(array $node): string
{
  return trim((string) ($node['key'] ?? ($node['id'] ?? '')));
}

function article_taxonomy_node_label(array $node): string
{
  $label = trim((string) ($node['label'] ?? ''));
  if ($label !== '') {
    return $label;
  }
  return article_taxonomy_node_key($node);
}

/**
 * @return array<int,array<string,mixed>>
 */
function article_taxonomy_children(array $node): array
{
  return is_array($node['children'] ?? null) ? array_values(array_filter($node['children'], 'is_array')) : [];
}

/**
 * @param array<int,array<string,mixed>> $nodes
 * @return array<string,mixed>|null
 */
function article_taxonomy_find_node(array $nodes, string $key): ?array
{
  foreach ($nodes as $node) {
    if (article_taxonomy_node_key($node) === $key) {
      return $node;
    }
  }
  return null;
}

function article_taxonomy_post_key(string $value): string
{
  $value = trim($value);
  return $value === '__empty__' ? '' : $value;
}

/**
 * Validate category selection against the public taxonomy tree.
 *
 * @param array<string,mixed> $payload
 * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
 */
function validate_article_taxonomy_payload(array $payload): array
{
  $errors = [];
  $clean = [
    'section_key' => '',
    'section_label' => '',
    'section_href' => '',
    'library_kind_key' => '',
    'library_kind_label' => '',
    'topic_lv1_key' => '',
    'topic_lv1_label' => '',
    'topic_lv2_key' => '',
    'topic_lv2_label' => '',
    'topic_lv3_key' => '',
    'topic_lv3_label' => '',
  ];

  $taxonomy = read_article_editor_taxonomy_payload();
  $roots = is_array($taxonomy['roots'] ?? null) ? array_values(array_filter($taxonomy['roots'], 'is_array')) : [];
  if (empty($roots)) {
    return [
      'ok' => false,
      'errors' => ['taxonomy' => 'Không đọc được cây phân loại public.'],
      'data' => $clean,
    ];
  }

  $sectionKey = article_taxonomy_post_key((string) ($payload['taxonomy_section_key'] ?? ($payload['section_key'] ?? '')));
  $section = article_taxonomy_find_node($roots, $sectionKey);
  if ($section === null || !in_array($sectionKey, ['thu-vien', 'ban-tin'], true)) {
    $errors['taxonomy'] = 'Vui lòng chọn khu vực hợp lệ.';
    return ['ok' => false, 'errors' => $errors, 'data' => $clean];
  }

  $clean['section_key'] = $sectionKey;
  $clean['section_label'] = article_taxonomy_node_label($section);
  $clean['section_href'] = $sectionKey . '.html';

  $lv1Source = article_taxonomy_children($section);
	  if ($sectionKey === 'thu-vien') {
	    $kindRaw = trim((string) ($payload['taxonomy_library_kind_key'] ?? ($payload['library_kind_key'] ?? '')));
	    $kindKey = article_taxonomy_post_key($kindRaw);
	    $kind = $kindRaw !== '' ? article_taxonomy_find_node(article_taxonomy_children($section), $kindKey) : null;
	    if ($kind === null) {
	      $errors['taxonomy'] = 'Vui lòng chọn phân loại Cấp 1 hợp lệ cho Thư viện.';
	      return ['ok' => false, 'errors' => $errors, 'data' => $clean];
	    }
    $clean['library_kind_key'] = $kindKey;
    $clean['library_kind_label'] = article_taxonomy_node_label($kind);
    $lv1Source = article_taxonomy_children($kind);

    // Kind has no sub-categories yet (e.g. phan-loai-moi) — accept at kind level
    if (empty($lv1Source)) {
      $lv1Raw = trim((string) ($payload['taxonomy_topic_lv1_key'] ?? ($payload['topic_lv1_key'] ?? '')));
      if ($lv1Raw !== '') {
        $errors['taxonomy'] = 'Phân loại Cấp 1 này chưa có Cấp 2.';
        return ['ok' => false, 'errors' => $errors, 'data' => $clean];
      }
      return [
        'ok' => true,
        'errors' => [],
        'data' => $clean,
      ];
    }
  }

  $lv1Raw = trim((string) ($payload['taxonomy_topic_lv1_key'] ?? ($payload['topic_lv1_key'] ?? '')));
  $lv1Key = article_taxonomy_post_key($lv1Raw);
  $lv1 = $lv1Raw !== '' ? article_taxonomy_find_node($lv1Source, $lv1Key) : null;
	  if ($lv1 === null) {
	    $errors['taxonomy'] = $sectionKey === 'thu-vien'
        ? 'Vui lòng chọn phân loại Cấp 2 hợp lệ cho Thư viện.'
        : 'Vui lòng chọn phân loại Cấp 1 hợp lệ.';
	    return ['ok' => false, 'errors' => $errors, 'data' => $clean];
	  }
  $clean['topic_lv1_key'] = $lv1Key;
  $clean['topic_lv1_label'] = article_taxonomy_node_label($lv1);

  $lv2Raw = trim((string) ($payload['taxonomy_topic_lv2_key'] ?? ($payload['topic_lv2_key'] ?? '')));
  $lv2Key = article_taxonomy_post_key($lv2Raw);
  $lv2Source = article_taxonomy_children($lv1);
  if ($sectionKey === 'thu-vien' && empty($lv2Source)) {
    if ($lv2Raw !== '') {
      $errors['taxonomy'] = 'Phân loại Cấp 2 này không có Cấp 3.';
      return ['ok' => false, 'errors' => $errors, 'data' => $clean];
    }
    return [
      'ok' => true,
      'errors' => [],
      'data' => $clean,
    ];
  }
	  $lv2 = $lv2Raw !== '' ? article_taxonomy_find_node($lv2Source, $lv2Key) : null;
	  if ($lv2 === null) {
	    $errors['taxonomy'] = $sectionKey === 'thu-vien'
        ? 'Vui lòng chọn phân loại Cấp 3 hợp lệ cho Thư viện.'
        : 'Vui lòng chọn phân loại Cấp 2 hợp lệ.';
	    return ['ok' => false, 'errors' => $errors, 'data' => $clean];
	  }
	  $clean['topic_lv2_key'] = $lv2Key;
	  $clean['topic_lv2_label'] = article_taxonomy_node_label($lv2);

		  // Phân loại cấp cuối theo từng section:
      // - Thư viện: dùng lv2 làm cấp cuối (kind→lv1→lv2), return sớm sau lv2.
      // - Bản tin: hỗ trợ lv3 → tiếp tục xử lý bên dưới.
	  if ($sectionKey === 'thu-vien') {
      // Thu-vien: lv2 là cấp cuối, preserve lv3 cũ nếu path không đổi.
      $preserveKindKey = article_taxonomy_post_key((string) ($payload['taxonomy_preserve_library_kind_key'] ?? ''));
      $preserveLv1Key  = article_taxonomy_post_key((string) ($payload['taxonomy_preserve_topic_lv1_key']  ?? ''));
      $preserveLv2Key  = article_taxonomy_post_key((string) ($payload['taxonomy_preserve_topic_lv2_key']  ?? ''));
      $preserveLv3Key  = article_taxonomy_post_key((string) ($payload['taxonomy_preserve_topic_lv3_key']  ?? ''));
      $sameVisiblePath = ($preserveKindKey === $kindKey && $preserveLv1Key === $lv1Key && $preserveLv2Key === $lv2Key);
      if ($sameVisiblePath && $preserveLv3Key !== '') {
        $preserveLv3 = article_taxonomy_find_node(article_taxonomy_children($lv2), $preserveLv3Key);
        if ($preserveLv3 !== null) {
          $clean['topic_lv3_key']   = $preserveLv3Key;
          $clean['topic_lv3_label'] = article_taxonomy_node_label($preserveLv3);
        }
      }
	    return [
	      'ok'     => true,
        'errors' => [],
        'data'   => $clean,
      ];
  }
  // Ban-tin: tiếp tục xử lý lv3 bên dưới (không return sớm).

	  $lv3Source = article_taxonomy_children($lv2);
  $lv3Raw = trim((string) ($payload['taxonomy_topic_lv3_key'] ?? ($payload['topic_lv3_key'] ?? '')));
  $lv3Key = article_taxonomy_post_key($lv3Raw);
  if (!empty($lv3Source)) {
    $lv3 = $lv3Raw !== '' ? article_taxonomy_find_node($lv3Source, $lv3Key) : null;
    if ($lv3 === null) {
      $errors['taxonomy'] = 'Vui lòng chọn phân loại Cấp 3 hợp lệ.';
      return ['ok' => false, 'errors' => $errors, 'data' => $clean];
    }
    $clean['topic_lv3_key'] = $lv3Key;
    $clean['topic_lv3_label'] = article_taxonomy_node_label($lv3);
  } elseif ($lv3Raw !== '') {
    $errors['taxonomy'] = 'Nhánh Cấp 2 này không có Cấp 3.';
    return ['ok' => false, 'errors' => $errors, 'data' => $clean];
  }

  return [
    'ok' => true,
    'errors' => [],
    'data' => $clean,
  ];
}

/**
 * Validate editable draft payload.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function validate_draft_payload(array $payload): array
{
  $errors = [];
  $clean = [];

  $title = trim((string) ($payload['title'] ?? ''));
  if ($title === '') {
    $errors['title'] = 'Tiêu đề không được để trống.';
  }
  $clean['title'] = $title;

  $excerpt = trim((string) ($payload['excerpt'] ?? ''));
  if ($excerpt === '') {
    $errors['excerpt'] = 'Mô tả ngắn không được để trống.';
  }
  $clean['excerpt'] = $excerpt;

  $publishDate = normalize_date_ymd((string) ($payload['publish_date'] ?? ''));
  if ($publishDate === '') {
    $errors['publish_date'] = 'Ngày đăng không hợp lệ.';
  }
  $clean['publish_date'] = $publishDate;

  $modifiedDateRaw = trim((string) ($payload['modified_date'] ?? ''));
  $modifiedDate = $modifiedDateRaw === '' ? '' : normalize_date_ymd($modifiedDateRaw);
  if ($modifiedDateRaw !== '' && $modifiedDate === '') {
    $errors['modified_date'] = 'Ngày sửa không hợp lệ.';
  }
  if ($modifiedDate === '') {
    $modifiedDate = date('Y-m-d');
  }
  $clean['modified_date'] = $modifiedDate;

  $tagsInput = trim((string) ($payload['tags_text'] ?? ''));
  $tags = [];
  $seenTags = [];
  foreach (preg_split('/[,;\n]+/', $tagsInput) ?: [] as $part) {
    $tag = trim((string) $part);
    if ($tag === '') {
      continue;
    }
    $tagKey = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
    if (isset($seenTags[$tagKey])) {
      continue;
    }
    $seenTags[$tagKey] = true;
    $tags[] = $tag;
  }
  if (count($tags) < 2) {
    $errors['tags_text'] = 'Cần tối thiểu 2 tag.';
  }
  if (count($tags) > 7) {
    $errors['tags_text'] = 'Tối đa 7 tag.';
  }
  $clean['tags'] = $tags;
  $clean['tags_text'] = implode(', ', $tags);

  $taxonomyValidation = validate_article_taxonomy_payload($payload);
  $clean = array_merge($clean, is_array($taxonomyValidation['data'] ?? null) ? $taxonomyValidation['data'] : []);
  if (empty($taxonomyValidation['ok'])) {
    $taxonomyErrors = is_array($taxonomyValidation['errors'] ?? null) ? $taxonomyValidation['errors'] : [];
    $errors = array_merge($errors, $taxonomyErrors);
  }

  $proseHtml = trim((string) ($payload['prose_html'] ?? ''));
  if ($proseHtml === '') {
    $errors['prose_html'] = 'Nội dung chính không được để trống.';
  }
  $clean['prose_html'] = $proseHtml;

  $featuredImage = trim((string) ($payload['featured_image'] ?? ''));
  $clean['featured_image'] = $featuredImage;

  return [
    'ok' => empty($errors),
    'errors' => $errors,
    'data' => $clean,
  ];
}

function article_editor_taxonomy_piece(string $label, string $key): string
{
  $label = trim($label);
  $key = trim($key);
  return $label !== '' ? $label : $key;
}

/**
 * @param array<string,mixed> $data
 */
function article_editor_taxonomy_path(array $data): string
{
  $sectionKey = trim((string) ($data['section_key'] ?? ($data['section'] ?? '')));
  $sectionLabel = article_editor_taxonomy_piece((string) ($data['section_label'] ?? ''), $sectionKey);
  if ($sectionLabel === '' && $sectionKey !== '') {
    $sectionLabel = $sectionKey === 'ban-tin' ? 'Bản tin' : ($sectionKey === 'thu-vien' ? 'Thư viện' : $sectionKey);
  }
  $parts = [];
  if ($sectionLabel !== '') {
    $parts[] = $sectionLabel;
  }
  if ($sectionKey === 'thu-vien') {
    $kind = article_editor_taxonomy_piece((string) ($data['library_kind_label'] ?? ''), (string) ($data['library_kind_key'] ?? ''));
    if ($kind !== '') {
      $parts[] = $kind;
    }
  }
  $levels = [1, 2];
  foreach ($levels as $level) {
    $part = article_editor_taxonomy_piece(
      (string) ($data['topic_lv' . $level . '_label'] ?? ''),
      (string) ($data['topic_lv' . $level . '_key'] ?? '')
    );
    if ($part !== '') {
      $parts[] = $part;
    }
  }
  return !empty($parts) ? implode(' › ', $parts) : '—';
}

/**
 * @param array<string,mixed> $data
 */
function article_editor_taxonomy_key_path(array $data): string
{
  $sectionKey = trim((string) ($data['section_key'] ?? ($data['section'] ?? '')));
  $keys = [$sectionKey];
  if ($sectionKey === 'thu-vien') {
    $keys[] = trim((string) ($data['library_kind_key'] ?? ''));
  }
  $levels = [1, 2];
  foreach ($levels as $level) {
    $keys[] = trim((string) ($data['topic_lv' . $level . '_key'] ?? ''));
  }
  return implode('|', $keys);
}

/**
 * @param array<int,mixed> $tags
 * @return array<int,string>
 */
function article_editor_normalize_tags(array $tags): array
{
  $out = [];
  $seen = [];
  foreach ($tags as $tag) {
    $clean = trim((string) $tag);
    if ($clean === '') {
      continue;
    }
    $key = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $out[] = $clean;
  }
  return $out;
}

/**
 * @param array<int,mixed> $tags
 */
function article_editor_tags_label(array $tags): string
{
  $clean = article_editor_normalize_tags($tags);
  return !empty($clean) ? implode(', ', array_map(static fn (string $tag): string => '#' . $tag, $clean)) : '—';
}

/**
 * @param array<string,mixed> $published
 * @param array<string,mixed> $draft
 * @return array<int,array{label:string,before:string,after:string}>
 */
function article_editor_change_summary(array $published, array $draft): array
{
  $changes = [];
  if (
    article_editor_taxonomy_key_path($published) !== article_editor_taxonomy_key_path($draft)
    || article_editor_taxonomy_path($published) !== article_editor_taxonomy_path($draft)
  ) {
    $changes[] = [
      'label' => 'Phân loại',
      'before' => article_editor_taxonomy_path($published),
      'after' => article_editor_taxonomy_path($draft),
    ];
  }

  $beforeTags = is_array($published['tags'] ?? null) ? $published['tags'] : [];
  $afterTags = is_array($draft['tags'] ?? null) ? $draft['tags'] : [];
  $beforeCompare = array_map(static fn (string $tag): string => function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag), article_editor_normalize_tags($beforeTags));
  $afterCompare = array_map(static fn (string $tag): string => function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag), article_editor_normalize_tags($afterTags));
  sort($beforeCompare);
  sort($afterCompare);
  if ($beforeCompare !== $afterCompare) {
    $changes[] = [
      'label' => 'Tags',
      'before' => article_editor_tags_label($beforeTags),
      'after' => article_editor_tags_label($afterTags),
    ];
  }
  return $changes;
}

/**
 * Build editable payload from parser output + article index defaults.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed> $parseResult
 * @return array<string,mixed>
 */
function build_editable_payload(array $article, array $parseResult): array
{
  $metaPayload = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
  $summaryFromHtml = trim((string) ($parseResult['summary_text'] ?? ''));

  $row = [
    'title' => (string) ($metaPayload['title'] ?? ($article['title'] ?? '')),
    'excerpt' => (string) ($summaryFromHtml !== '' ? $summaryFromHtml : ($article['card_badge_label'] ?? '')),
    'publish_date' => (string) ($metaPayload['publishDate'] ?? ''),
    'modified_date' => (string) (($metaPayload['modifiedDate'] ?? '') ?: ''),
    'section_key' => (string) ($metaPayload['section'] ?? $metaPayload['sectionKey'] ?? ($article['section'] ?? '')),
    'section_label' => (string) ($metaPayload['sectionLabel'] ?? ($article['section_label'] ?? '')),
    'library_kind_key' => (string) ($metaPayload['libraryKindKey'] ?? ($article['library_kind_key'] ?? '')),
    'library_kind_label' => (string) ($metaPayload['libraryKindLabel'] ?? ($article['library_kind_label'] ?? '')),
    'topic_lv1_key' => (string) ($metaPayload['topicLv1Key'] ?? ($article['topic_lv1_key'] ?? '')),
    'topic_lv1_label' => (string) ($metaPayload['topicLv1Label'] ?? ($article['topic_lv1_label'] ?? '')),
    'topic_lv2_key' => (string) ($metaPayload['topicLv2Key'] ?? ($article['topic_lv2_key'] ?? '')),
    'topic_lv2_label' => (string) ($metaPayload['topicLv2Label'] ?? ($article['topic_lv2_label'] ?? '')),
    'topic_lv3_key' => (string) ($metaPayload['topicLv3Key'] ?? ($article['topic_lv3_key'] ?? '')),
    'topic_lv3_label' => (string) ($metaPayload['topicLv3Label'] ?? ($article['topic_lv3_label'] ?? '')),
    'tags' => is_array($metaPayload['tags'] ?? null) ? array_values(array_filter(array_map('strval', $metaPayload['tags']))) : [],
    'tags_text' => is_array($metaPayload['tags'] ?? null) ? implode(', ', array_values(array_filter(array_map('strval', $metaPayload['tags'])))) : '',
    'prose_html' => (string) (($parseResult['prose']['inner'] ?? '') ?: ''),
    'featured_image' => trim((string) ($metaPayload['image'] ?? ($article['image'] ?? ''))),
  ];

  if ($row['excerpt'] === '') {
    $row['excerpt'] = (string) ($metaPayload['excerpt'] ?? ($metaPayload['description'] ?? ''));
  }

  return $row;
}

$id = trim((string) ($_GET['id'] ?? ''));
$article = find_article_index_item($id);
$listContext = [
  'section' => trim((string) ($_GET['section'] ?? '')),
  'library_kind_key' => trim((string) ($_GET['library_kind_key'] ?? '')),
  'topic_lv1_key' => trim((string) ($_GET['topic_lv1_key'] ?? '')),
  'topic_lv2_key' => trim((string) ($_GET['topic_lv2_key'] ?? '')),
  'topic_lv3_key' => trim((string) ($_GET['topic_lv3_key'] ?? '')),
  'tag' => trim((string) ($_GET['tag'] ?? '')),
  'review_status' => trim((string) ($_GET['review_status'] ?? '')),
  'q' => trim((string) ($_GET['q'] ?? '')),
  'sort' => trim((string) ($_GET['sort'] ?? '')),
  'per_page' => (int) ($_GET['per_page'] ?? 20),
  'page' => (int) ($_GET['page'] ?? 1),
  'from_edit' => 1,
];
$listReturnArticleId = trim((string) ($_GET['list_article_id'] ?? ''));
if ($listReturnArticleId === '') {
  $listReturnArticleId = $id;
}
$listContext['list_article_id'] = $listReturnArticleId;
$forceTopOnReturn = false;
$listContext['return_mode'] = 'exact';
$listReturnUrl = admin_url('articles.php' . build_article_query($listContext));

$currentUser = current_user();
$parseResult = null;
$baseEditable = [];
$draftCurrent = null;
$form = [];
$validationErrors = [];
$status = null;
$previewHtml = '';
$previewMeta = [];
$latestPublish = null;
$recentPublishRecords = [];
$reviewRow = null;
$uploads = [];
$revisions = [];
$taxonomyEditorData = [
  'phase' => 3,
  'note' => 'Validated category selections are saved to draft and published to article-meta/data articles.',
  'taxonomy' => read_article_editor_taxonomy_payload(),
  'state' => [],
];

if ($article !== null) {
  $path = resolve_article_file_path($article);
  $parseResult = parse_article_file($path);
  if (is_array($parseResult) && !empty($parseResult['ok'])) {
    $baseEditable = build_editable_payload($article, $parseResult);

    $draftCurrent = read_article_draft((string) ($article['id'] ?? ''));
    $reviewRow = read_article_review_status((string) ($article['id'] ?? ''));
    if (is_array($draftCurrent) && is_array($draftCurrent['data'] ?? null)) {
      $form = array_merge($baseEditable, $draftCurrent['data']);
      $form['tags_text'] = implode(', ', array_values(array_filter(array_map('strval', is_array($form['tags'] ?? null) ? $form['tags'] : []))));
    } else {
      $form = $baseEditable;
    }

    if (is_post_request()) {
      enforce_post_csrf_or_reject();
      $intent = trim((string) ($_POST['_intent'] ?? 'save_draft'));
      if ($intent === 'rollback_latest') {
        $result = rollback_latest_publish($article, $currentUser);
          if (!empty($result['ok'])) {
            $rollbackRebuild = is_array($result['public_rebuild'] ?? null) ? $result['public_rebuild'] : [];
            if (!empty($rollbackRebuild) && empty($rollbackRebuild['ok'])) {
              $status = [
                'type' => 'warning',
                'message' => 'Đã khôi phục file bài, nhưng đồng bộ dữ liệu public chưa xong: ' . (string) ($rollbackRebuild['message'] ?? 'không rõ lỗi'),
              ];
            } else {
              $status = [
                'type' => 'success',
                'message' => 'Đã khôi phục thành công và đồng bộ dữ liệu public.',
              ];
            }
        } else {
          $status = [
            'type' => 'danger',
            'message' => 'Khôi phục thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
          ];
        }

        $parseResult = parse_article_file($path);
        if (is_array($parseResult) && !empty($parseResult['ok'])) {
          $baseEditable = build_editable_payload($article, $parseResult);

          $form = $baseEditable;
          $previewHtml = (string) ($baseEditable['prose_html'] ?? '');
          $previewMeta = [
            'title' => (string) ($baseEditable['title'] ?? ''),
            'excerpt' => (string) ($baseEditable['excerpt'] ?? ''),
            'publishDate' => (string) ($baseEditable['publish_date'] ?? ''),
            'modifiedDate' => (string) ($baseEditable['modified_date'] ?? ''),
            'tags' => is_array($baseEditable['tags'] ?? null) ? $baseEditable['tags'] : [],
            'featuredImage' => (string) ($baseEditable['featured_image'] ?? ''),
          ];
        }
      } elseif ($intent === 'mark_unreviewed') {
        $marked = mark_article_unreviewed((string) ($article['id'] ?? ''), $currentUser, 'manual_reset');
        $reviewRow = read_article_review_status((string) ($article['id'] ?? ''));
        if ($marked) {
          $status = [
            'type' => 'success',
            'message' => 'Đã chuyển trạng thái bài về Chưa sửa.',
          ];
        } else {
          $status = [
            'type' => 'warning',
            'message' => 'Bài đang ở trạng thái Chưa sửa.',
          ];
        }
      } else {
        $posted = [
          'title' => (string) ($_POST['title'] ?? ''),
          'excerpt' => (string) ($_POST['excerpt'] ?? ''),
          'publish_date' => (string) ($_POST['publish_date'] ?? ''),
          'modified_date' => (string) ($_POST['modified_date'] ?? ''),
          'tags_text' => (string) ($_POST['tags_text'] ?? ''),
          'prose_html' => (isset($_POST['prose_html_b64']) && $_POST['prose_html_b64'] !== '')
            ? (string) base64_decode($_POST['prose_html_b64'])
            : (string) ($_POST['prose_html'] ?? ''),
          'featured_image' => (string) ($_POST['featured_image'] ?? ''),
          'taxonomy_section_key' => (string) ($_POST['taxonomy_section_key'] ?? ''),
          'taxonomy_library_kind_key' => (string) ($_POST['taxonomy_library_kind_key'] ?? ''),
	          'taxonomy_topic_lv1_key' => (string) ($_POST['taxonomy_topic_lv1_key'] ?? ''),
	          'taxonomy_topic_lv2_key' => (string) ($_POST['taxonomy_topic_lv2_key'] ?? ''),
	          'taxonomy_topic_lv3_key' => (string) ($_POST['taxonomy_topic_lv3_key'] ?? ''),
	          'taxonomy_preserve_library_kind_key' => (string) ($_POST['taxonomy_preserve_library_kind_key'] ?? ''),
	          'taxonomy_preserve_topic_lv1_key' => (string) ($_POST['taxonomy_preserve_topic_lv1_key'] ?? ''),
	          'taxonomy_preserve_topic_lv2_key' => (string) ($_POST['taxonomy_preserve_topic_lv2_key'] ?? ''),
	          'taxonomy_preserve_topic_lv3_key' => (string) ($_POST['taxonomy_preserve_topic_lv3_key'] ?? ''),
	          'section_key' => article_taxonomy_post_key((string) ($_POST['taxonomy_section_key'] ?? '')),
          'library_kind_key' => article_taxonomy_post_key((string) ($_POST['taxonomy_library_kind_key'] ?? '')),
          'topic_lv1_key' => article_taxonomy_post_key((string) ($_POST['taxonomy_topic_lv1_key'] ?? '')),
          'topic_lv2_key' => article_taxonomy_post_key((string) ($_POST['taxonomy_topic_lv2_key'] ?? '')),
          'topic_lv3_key' => article_taxonomy_post_key((string) ($_POST['taxonomy_topic_lv3_key'] ?? '')),
        ];
        $validated = validate_draft_payload($posted);
        $validatedData = is_array($validated['data'] ?? null) ? $validated['data'] : [];
        $form = array_merge($form, $posted, $validatedData);

        if (!empty($validated['ok'])) {
          $clean = $validatedData;
          $currentHtmlForRevision = file_get_contents($path);
          if (is_string($currentHtmlForRevision) && trim($currentHtmlForRevision) !== '') {
            // Backup current HTML before any publish/preview/restore draft mutation.
            try {
              save_article_revision_snapshot((string) ($article['id'] ?? ''), $currentHtmlForRevision);
            } catch (Throwable $revisionError) {
              append_audit_log([
                'event' => 'article.revision.snapshot_failed',
                'article_id' => (string) ($article['id'] ?? ''),
                'reason' => $revisionError->getMessage(),
                'username' => (string) (($currentUser['username'] ?? '') ?: ''),
              ]);
            }
          }
          if ($intent === 'publish_now') {
            $result = publish_article_draft($article, $clean, $currentUser);
            if (!empty($result['ok'])) {
              // Keep draft snapshot for traceability after publish
              $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
              $draftCurrent = $saved;
              $reviewRow = mark_article_reviewed((string) ($article['id'] ?? ''), $currentUser, 'publish_now');
              $form = array_merge($form, $clean);
              $forceTopOnReturn = true;
              $publicRebuild = is_array($result['public_rebuild'] ?? null) ? $result['public_rebuild'] : [];
              if (!empty($publicRebuild) && empty($publicRebuild['ok'])) {
                $status = [
                  'type' => 'warning',
                  'message' => 'Đã cập nhật bài viết, nhưng đồng bộ dữ liệu public chưa xong: ' . (string) ($publicRebuild['message'] ?? 'không rõ lỗi'),
                ];
              } else {
                $status = [
                  'type' => 'success',
                  'message' => 'Đã cập nhật bài viết ra trang và đồng bộ dữ liệu public.',
                ];
              }
            } else {
              $status = [
                'type' => 'danger',
                'message' => 'Cập nhật thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
              ];
            }
          } elseif ($intent === 'save_draft' || $intent === 'preview_only') {
            $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
            $draftCurrent = $saved;
            $reviewRow = mark_article_reviewed((string) ($article['id'] ?? ''), $currentUser, $intent);
            $form = array_merge($form, $clean);
            $forceTopOnReturn = true;
            $previewHtml = (string) ($clean['prose_html'] ?? '');
            $previewMeta = [
              'title' => (string) ($clean['title'] ?? ''),
              'excerpt' => (string) ($clean['excerpt'] ?? ''),
              'publishDate' => (string) ($clean['publish_date'] ?? ''),
              'modifiedDate' => (string) ($clean['modified_date'] ?? ''),
              'tags' => is_array($clean['tags'] ?? null) ? $clean['tags'] : [],
              'featuredImage' => (string) ($clean['featured_image'] ?? ''),
            ];

	            if ($intent === 'save_draft') {
	              $status = [
	                'type' => 'success',
	                'message' => 'Đã lưu nháp trong admin. Bản ngoài user chưa đổi; bấm “Đăng ra ngoài” để cập nhật frontend.',
	              ];
	            } elseif ($intent === 'preview_only') {
	              $status = [
	                'type' => 'success',
	                'message' => 'Đã lưu nháp trong admin. Bản ngoài user chưa đổi; bấm “Đăng ra ngoài” để cập nhật frontend.',
	              ];
	            }
          } else {
            $status = [
              'type' => 'warning',
              'message' => 'Không xác định được thao tác lưu.',
            ];
          }
        } else {
          $validationErrors = is_array($validated['errors'] ?? null) ? $validated['errors'] : [];
          $status = [
            'type' => 'danger',
            'message' => 'Dữ liệu chưa hợp lệ. Vui lòng kiểm tra các trường được đánh dấu.',
          ];
        }
      }
    } else {
      if (is_array($draftCurrent) && is_array($draftCurrent['data'] ?? null)) {
        $cleanDraft = $draftCurrent['data'];
        $previewHtml = (string) ($cleanDraft['prose_html'] ?? '');
        $previewMeta = [
          'title' => (string) ($cleanDraft['title'] ?? ''),
          'excerpt' => (string) ($cleanDraft['excerpt'] ?? ''),
          'publishDate' => (string) ($cleanDraft['publish_date'] ?? ''),
          'modifiedDate' => (string) ($cleanDraft['modified_date'] ?? ''),
          'tags' => is_array($cleanDraft['tags'] ?? null) ? $cleanDraft['tags'] : [],
          'featuredImage' => (string) ($cleanDraft['featured_image'] ?? ''),
        ];
      } else {
        $previewHtml = (string) ($baseEditable['prose_html'] ?? '');
        $previewMeta = [
          'title' => (string) ($baseEditable['title'] ?? ''),
          'excerpt' => (string) ($baseEditable['excerpt'] ?? ''),
          'publishDate' => (string) ($baseEditable['publish_date'] ?? ''),
          'modifiedDate' => (string) ($baseEditable['modified_date'] ?? ''),
          'tags' => is_array($baseEditable['tags'] ?? null) ? $baseEditable['tags'] : [],
          'featuredImage' => (string) ($baseEditable['featured_image'] ?? ''),
        ];
      }
    }
    $form['featured_image'] = trim((string) ($form['featured_image'] ?? ($baseEditable['featured_image'] ?? '')));
    if ($reviewRow === null) {
      $reviewRow = read_article_review_status((string) ($article['id'] ?? ''));
    }
    if ($forceTopOnReturn) {
      $listContext['review_status'] = '';
      $listContext['q'] = '';
      $listContext['focus_article_id'] = (string) ($listContext['list_article_id'] ?? '');
      $listContext['return_mode'] = 'fresh';
    }
    $listReturnUrl = admin_url('articles.php' . build_article_query($listContext));
    $latestPublish = find_latest_publish_record((string) ($article['id'] ?? ''));
    $recentPublishRecords = list_recent_publish_records((string) ($article['id'] ?? ''), 8);
    $uploads = list_article_uploaded_images((string) ($article['id'] ?? ''));
    $revisions = list_article_revisions((string) ($article['id'] ?? ''));
    $taxonomyEditorData['state'] = [
      'sectionKey' => (string) ($form['section_key'] ?? ($article['section'] ?? '')),
      'sectionValue' => (string) ($form['taxonomy_section_key'] ?? ($form['section_key'] ?? ($article['section'] ?? ''))),
      'libraryKindKey' => (string) ($form['library_kind_key'] ?? ($article['library_kind_key'] ?? '')),
      'libraryKindValue' => (string) ($form['taxonomy_library_kind_key'] ?? ($form['library_kind_key'] ?? ($article['library_kind_key'] ?? ''))),
      'topicLv1Key' => (string) ($form['topic_lv1_key'] ?? ($article['topic_lv1_key'] ?? '')),
      'topicLv1Value' => (string) ($form['taxonomy_topic_lv1_key'] ?? ($form['topic_lv1_key'] ?? ($article['topic_lv1_key'] ?? ''))),
      'topicLv2Key' => (string) ($form['topic_lv2_key'] ?? ($article['topic_lv2_key'] ?? '')),
      'topicLv2Value' => (string) ($form['taxonomy_topic_lv2_key'] ?? ($form['topic_lv2_key'] ?? ($article['topic_lv2_key'] ?? ''))),
      'topicLv2Label' => (string) ($form['topic_lv2_label'] ?? ($article['topic_lv2_label'] ?? '')),
      'topicLv3Key' => (string) ($form['topic_lv3_key'] ?? ($article['topic_lv3_key'] ?? '')),
      'topicLv3Value' => (string) ($form['taxonomy_topic_lv3_key'] ?? ($form['topic_lv3_key'] ?? ($article['topic_lv3_key'] ?? ''))),
      'topicLv3Label' => (string) ($form['topic_lv3_label'] ?? ($article['topic_lv3_label'] ?? '')),
    ];
  }
}

$innerScript = <<<'JS'
(() => {
  const form = document.getElementById('articleEditorForm');
  const intent = document.getElementById('articleIntent');
  const editor = document.getElementById('proseEditor');
  const featuredImageInput = document.getElementById('featuredImageInput');
  const host = document.getElementById('previewHost');
  if (!editor) return;

  /* Compute site base URL (one level up from /admin/) for resolving relative image paths */
  const siteBaseUrl = window.location.origin + window.location.pathname.replace(/\/admin\/.*$/, '/');

  const initTaxonomyEditor = () => {
    const taxonomyHost = document.querySelector('[data-taxonomy-editor]');
    const dataNode = document.getElementById('editorTaxonomyData');
    if (!taxonomyHost || !dataNode) return;

    let payload = {};
    try {
      payload = JSON.parse(dataNode.textContent || '{}');
    } catch (error) {
      payload = {};
    }

    const roots = Array.isArray(payload.taxonomy && payload.taxonomy.roots) ? payload.taxonomy.roots : [];
    const state = payload.state || {};
    const fields = {
      section: taxonomyHost.querySelector('[data-taxonomy-select="section"]'),
      kind: taxonomyHost.querySelector('[data-taxonomy-select="library_kind"]'),
      lv1: taxonomyHost.querySelector('[data-taxonomy-select="topic_lv1"]'),
      lv2: taxonomyHost.querySelector('[data-taxonomy-select="topic_lv2"]'),
      lv3: taxonomyHost.querySelector('[data-taxonomy-select="topic_lv3"]'),
    };
	    const rows = {
	      kind: taxonomyHost.querySelector('[data-taxonomy-row="library_kind"]'),
	      lv2: taxonomyHost.querySelector('[data-taxonomy-row="topic_lv2"]'),
	      lv3: taxonomyHost.querySelector('[data-taxonomy-row="topic_lv3"]'),
	    };
	    const labels = {
	      kind: taxonomyHost.querySelector('[data-taxonomy-label="library_kind"]'),
	      lv1: taxonomyHost.querySelector('[data-taxonomy-label="topic_lv1"]'),
	      lv2: taxonomyHost.querySelector('[data-taxonomy-label="topic_lv2"]'),
	      lv3: taxonomyHost.querySelector('[data-taxonomy-label="topic_lv3"]'),
	    };
	    const pathHost = taxonomyHost.querySelector('[data-taxonomy-path]');

    const emptyKeyValue = '__empty__';
    const keyOf = (node) => String((node && (node.key || node.id)) || '');
    const labelOf = (node) => String((node && (node.label || node.key || node.id)) || '');
    const valueOf = (node) => keyOf(node) || emptyKeyValue;
    const childrenOf = (node) => Array.isArray(node && node.children) ? node.children : [];
    const findByKey = (nodes, key) => (nodes || []).find((node) => keyOf(node) === key) || null;
    const findByValue = (nodes, value) => (nodes || []).find((node) => valueOf(node) === value) || null;
    const preferredValue = (nodes, rawValue, label) => {
      if (rawValue) return rawValue;
      const emptyKeyNode = findByKey(nodes, '');
      if (!emptyKeyNode) return '';
      if (label && labelOf(emptyKeyNode) === label) return emptyKeyValue;
      return nodes.length === 1 ? emptyKeyValue : '';
    };

    let firstRender = true;

    const setOptions = (select, nodes, placeholder, preferredValue) => {
      if (!select) return null;
      const current = preferredValue !== undefined ? preferredValue : select.value;
      select.innerHTML = '';
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = placeholder;
      select.appendChild(empty);
      (nodes || []).forEach((node) => {
        const key = keyOf(node);
        const label = labelOf(node);
        if (!key && !label) return;
        const option = document.createElement('option');
        option.value = valueOf(node);
        option.textContent = label;
        select.appendChild(option);
      });
      select.value = findByValue(nodes, current) ? current : '';
      return findByValue(nodes, select.value);
    };

    const inferKindKey = (root, lv1Key, lv2Key, lv3Key) => {
      lv2Key = lv2Key === emptyKeyValue ? '' : lv2Key;
      lv3Key = lv3Key === emptyKeyValue ? '' : lv3Key;
      if (!root || !lv1Key) return '';
      const matches = [];
      childrenOf(root).forEach((kind) => {
        const lv1 = findByKey(childrenOf(kind), lv1Key);
        if (!lv1) return;
        if (lv2Key) {
          const lv2 = findByKey(childrenOf(lv1), lv2Key);
          if (!lv2) return;
          if (lv3Key && !findByKey(childrenOf(lv2), lv3Key)) return;
        }
        matches.push(keyOf(kind));
      });
      return matches.length === 1 ? matches[0] : '';
    };

    const renderPath = (nodes) => {
      if (!pathHost) return;
      const labels = nodes.map(labelOf).filter(Boolean);
      pathHost.textContent = labels.length ? labels.join(' › ') : 'Chưa chọn đủ phân loại.';
    };

    const render = () => {
      if (!fields.section) return;
      setOptions(fields.section, roots, 'Chọn khu vực', firstRender ? (state.sectionValue || state.sectionKey || '') : undefined);
      if (!fields.section.value) {
        fields.section.value = findByKey(roots, state.sectionKey) ? state.sectionKey : (keyOf(roots[0]) || '');
      }

		      const section = findByKey(roots, fields.section.value);
		      const isLibrary = keyOf(section) === 'thu-vien';
		      // hideDeepLevel chỉ áp dụng cho thu-vien (dùng kind thay lv3)
		      // ban-tin giờ hỗ trợ lv3 đầy đủ
		      const hideDeepLevel = isLibrary;
		      let kind = null;
		      let lv1Source = childrenOf(section);
	      if (labels.kind) labels.kind.textContent = 'Cấp 1';
	      if (labels.lv1) labels.lv1.textContent = isLibrary ? 'Cấp 2' : 'Cấp 1';
	      if (labels.lv2) labels.lv2.textContent = isLibrary ? 'Cấp 3' : 'Cấp 2';
	      if (labels.lv3) labels.lv3.textContent = isLibrary ? 'Không dùng' : 'Cấp 3';

	      if (rows.kind) rows.kind.hidden = !isLibrary;
	      if (isLibrary) {
        const preferredKind = firstRender
          ? (state.libraryKindValue || state.libraryKindKey || inferKindKey(section, state.topicLv1Value || state.topicLv1Key || '', state.topicLv2Value || state.topicLv2Key || '', state.topicLv3Value || state.topicLv3Key || ''))
          : undefined;
	        setOptions(fields.kind, childrenOf(section), 'Chọn cấp 1', preferredKind);
	        if (!fields.kind.value) {
	          fields.kind.value = inferKindKey(section, fields.lv1 ? fields.lv1.value : '', fields.lv2 ? fields.lv2.value : '', fields.lv3 ? fields.lv3.value : '');
	        }
        kind = findByKey(childrenOf(section), fields.kind ? fields.kind.value : '');
        lv1Source = childrenOf(kind);
        if (kind && lv1Source.length === 0) {
          if (fields.lv1) { fields.lv1.innerHTML = '<option value="">Chưa có cấp 2</option>'; fields.lv1.value = ''; }
          if (fields.lv2) { fields.lv2.innerHTML = ''; fields.lv2.value = ''; }
          if (fields.lv3) { fields.lv3.value = ''; }
          if (rows.lv2) rows.lv2.hidden = true;
          if (rows.lv3) rows.lv3.hidden = true;
          renderPath([section, kind].filter(Boolean));
          firstRender = false;
          return;
        }
      } else if (fields.kind) {
        fields.kind.value = '';
      }

		      const lv1 = setOptions(fields.lv1, lv1Source, isLibrary ? 'Chọn cấp 2' : 'Chọn cấp 1', firstRender ? (state.topicLv1Value || state.topicLv1Key || '') : undefined);
		      const lv2Source = childrenOf(lv1);
		      const canSelectLv2 = !isLibrary || lv2Source.length > 0;
		      if (rows.lv2) rows.lv2.hidden = !canSelectLv2;
		      let lv2 = null;
		      if (canSelectLv2) {
		        lv2 = setOptions(
		          fields.lv2,
		          lv2Source,
		          isLibrary ? 'Chọn cấp 3' : 'Chọn cấp 2',
		          firstRender ? preferredValue(lv2Source, state.topicLv2Value || state.topicLv2Key || '', state.topicLv2Label || '') : undefined
		        );
		      } else if (fields.lv2) {
		        fields.lv2.innerHTML = '<option value="">Không có cấp 3</option>';
		        fields.lv2.value = '';
		      }
		      const lv3Source = childrenOf(lv2);
		      let lv3 = null;
		      if (hideDeepLevel) {
		        if (fields.lv3) fields.lv3.value = '';
		        if (rows.lv3) rows.lv3.hidden = true;
		      } else {
	        lv3 = setOptions(
	          fields.lv3,
	          lv3Source,
	          'Chọn cấp 3',
	          firstRender ? preferredValue(lv3Source, state.topicLv3Value || state.topicLv3Key || '', state.topicLv3Label || '') : undefined
	        );
	        if (rows.lv3) rows.lv3.hidden = lv3Source.length === 0 && !(fields.lv3 && fields.lv3.value);
	      }

	      renderPath([section, kind, lv1, lv2, lv3].filter(Boolean));
      firstRender = false;
    };

    if (fields.section) fields.section.addEventListener('change', () => {
      if (fields.kind) fields.kind.value = '';
      if (fields.lv1) fields.lv1.value = '';
      if (fields.lv2) fields.lv2.value = '';
      if (fields.lv3) fields.lv3.value = '';
      render();
    });
    if (fields.kind) fields.kind.addEventListener('change', () => {
      if (fields.lv1) fields.lv1.value = '';
      if (fields.lv2) fields.lv2.value = '';
      if (fields.lv3) fields.lv3.value = '';
      render();
    });
    if (fields.lv1) fields.lv1.addEventListener('change', () => {
      if (fields.lv2) fields.lv2.value = '';
      if (fields.lv3) fields.lv3.value = '';
      render();
    });
    if (fields.lv2) fields.lv2.addEventListener('change', () => {
      if (fields.lv3) fields.lv3.value = '';
      render();
    });
    if (fields.lv3) fields.lv3.addEventListener('change', render);

    render();
  };

  const getEditorHtml = () => {
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const instance = window.tinymce.get('proseEditor');
      if (instance) {
        return instance.getContent();
      }
    }
    return editor.value;
  };

  const syncPreview = () => {
    if (!host) return;
    let html = getEditorHtml().trim();
    if (html === '') {
      host.innerHTML = '<p><em>Chưa có nội dung preview.</em></p>';
      return;
    }
    /* Resolve relative image/source URLs so they display correctly in the preview panel.
       Relative paths like "assets/images/..." or "uploads/articles/..." are stored relative
       to the site root, but the admin page is at /admin/ so the browser would resolve them
       to /admin/assets/... which doesn't exist. We prefix them with the site base URL. */
    html = html.replace(
      /(src=["'])(?!https?:\/\/|\/|data:|blob:)([^"']+)(["'])/gi,
      (_, pre, url, post) => pre + siteBaseUrl + url + post
    );
    host.innerHTML = html;
  };

  editor.addEventListener('input', () => {
    if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
    window.__previewTimer = window.setTimeout(syncPreview, 120);
  });

  if (form) {
    form.addEventListener('submit', () => {
      if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
        window.tinymce.triggerSave();
      }
      /* Base64 encode prose_html to bypass Hostinger ModSecurity/WAF 406 */
      const b64Field = document.getElementById('proseHtmlB64');
      if (b64Field && editor) {
        try {
          const raw = editor.value || '';
          b64Field.value = btoa(unescape(encodeURIComponent(raw)));
          editor.removeAttribute('name');
        } catch (e) {
          /* Fallback: keep original name so normal POST works */
          console.warn('Base64 encode failed, falling back to raw POST', e);
          editor.setAttribute('name', 'prose_html');
          b64Field.value = '';
        }
      }
      syncPreview();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (!form || !intent) return;
    const isSave = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's';
    if (!isSave) return;
    event.preventDefault();
    intent.value = 'save_draft';
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });

  /* === Fullscreen editor toggle === */
  const fsToggle = document.getElementById('editorFullscreenToggle');
  const fsToggleBottom = document.getElementById('editorFullscreenToggleBottom');
  const fsBackdrop = document.getElementById('editorFullscreenBackdrop');
  const fsStatus = document.getElementById('editorFsStatus');
  let isFullscreen = false;

  const enterFullscreen = () => {
    isFullscreen = true;
    document.body.classList.add('editor-fullscreen-active');
    if (fsToggle) {
      fsToggle.querySelector('i').className = 'fa-solid fa-compress';
    }
    if (fsToggleBottom) {
      fsToggleBottom.querySelector('i').className = 'fa-solid fa-compress';
      fsToggleBottom.title = 'Thu nhỏ (Ctrl+Shift+F)';
    }
    /* Resize TinyMCE if available */
    requestAnimationFrame(() => {
      if (window.tinymce && typeof window.tinymce.get === 'function') {
        const inst = window.tinymce.get('proseEditor');
        if (inst) {
          const editorArea = document.querySelector('.tox.tox-tinymce');
          if (editorArea) {
            const toolbarH = editorArea.querySelector('.tox-editor-header');
            const toolbarHeight = toolbarH ? toolbarH.offsetHeight : 0;
            const availH = window.innerHeight - 94 - toolbarHeight;
            inst.getBody().style.minHeight = availH + 'px';
          }
        }
      }
    });
  };

  const exitFullscreen = () => {
    isFullscreen = false;
    document.body.classList.remove('editor-fullscreen-active');
    if (fsToggle) {
      fsToggle.querySelector('i').className = 'fa-solid fa-expand';
    }
    if (fsToggleBottom) {
      fsToggleBottom.querySelector('i').className = 'fa-solid fa-expand';
      fsToggleBottom.title = 'Toàn màn hình (Ctrl+Shift+F)';
    }
    /* Reset TinyMCE sizing */
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const inst = window.tinymce.get('proseEditor');
      if (inst) {
        inst.getBody().style.minHeight = '';
      }
    }
  };

  const toggleFullscreen = () => {
    if (isFullscreen) {
      exitFullscreen();
    } else {
      enterFullscreen();
    }
  };

  if (fsToggle) {
    fsToggle.addEventListener('click', (e) => {
      e.preventDefault();
      toggleFullscreen();
    });
  }

  /* Bottom fullscreen toggle button */
  if (fsToggleBottom) {
    fsToggleBottom.addEventListener('click', (e) => {
      e.preventDefault();
      toggleFullscreen();
    });
  }

  document.addEventListener('keydown', (event) => {
    /* Escape to exit fullscreen */
    if (event.key === 'Escape' && isFullscreen) {
      event.preventDefault();
      exitFullscreen();
      return;
    }
    /* Ctrl+Shift+F to toggle fullscreen */
    if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === 'f') {
      event.preventDefault();
      toggleFullscreen();
      return;
    }
  });

  /* Handle window resize in fullscreen */
  window.addEventListener('resize', () => {
    if (!isFullscreen) return;
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const inst = window.tinymce.get('proseEditor');
      if (inst) {
        const editorArea = document.querySelector('.tox.tox-tinymce');
        if (editorArea) {
          const toolbarH = editorArea.querySelector('.tox-editor-header');
          const toolbarHeight = toolbarH ? toolbarH.offsetHeight : 0;
          const availH = window.innerHeight - 94 - toolbarHeight;
          inst.getBody().style.minHeight = availH + 'px';
        }
      }
    }
  });

  if (window.tinymce && typeof window.tinymce.init === 'function') {
    window.tinymce.init({
      selector: '#proseEditor',
      menubar: true,
      height: 620,
      branding: false,
      images_file_types: 'jpg,jpeg,png,gif,webp',
      document_base_url: siteBaseUrl,
      relative_urls: false,
      remove_script_host: false,
      convert_urls: false,
      plugins: 'advlist autolink lists link image table code charmap preview searchreplace visualblocks wordcount paste',
      toolbar: 'code | undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image | removeformat preview',
      content_css: [
        siteBaseUrl + 'assets/css/editorial-content.css',
        siteBaseUrl + 'assets/css/editorial-structured-content.css',
        siteBaseUrl + 'assets/css/article-editorial-system.css',
      ],
      body_class: 'ct-prose is-article mce-content-body',
      content_style: 'body { font-family: "Google Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 17.5px; line-height: 1.78; color: #33322C; padding: 18px 22px; -webkit-font-smoothing: antialiased; } img { max-width: 100%; height: auto; }',
      images_upload_handler: async (blobInfo, progress) => {
        const articleIdInput = document.getElementById('articleIdInput');
        const csrfInput = form ? form.querySelector('input[name="_csrf_token"]') : null;
        const articleId = articleIdInput ? articleIdInput.value : '';
        const csrfToken = csrfInput ? csrfInput.value : '';

        if (!articleId || !csrfToken) {
          throw new Error('Thiếu thông tin phiên để upload ảnh.');
        }

        const payload = new FormData();
        payload.append('_csrf_token', csrfToken);
        payload.append('article_id', articleId);
        payload.append('image', blobInfo.blob(), blobInfo.filename());

        const response = await fetch('upload.php', {
          method: 'POST',
          body: payload,
          credentials: 'same-origin',
        });
        const json = await response.json();
        if (!response.ok || !json.location) {
          throw new Error(json.error || 'Upload ảnh thất bại.');
        }
        progress(100);
        return json.location;
      },
      setup: (instance) => {
        instance.on('input change keyup setcontent', () => {
          if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
          window.__previewTimer = window.setTimeout(syncPreview, 100);
        });
        /* Keyboard shortcuts inside TinyMCE iframe */
        instance.on('keydown', (e) => {
          if (e.key === 'Escape' && isFullscreen) {
            e.preventDefault();
            exitFullscreen();
            return;
          }
          if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            toggleFullscreen();
            return;
          }
        });
      }
    });
  }

  document.querySelectorAll('[data-upload-select]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!featuredImageInput) return;
      const next = button.getAttribute('data-upload-select') || '';
      featuredImageInput.value = next;
    });
  });

  initTaxonomyEditor();
  syncPreview();
})();
JS;

admin_layout_header([
  'title' => 'Sửa bài viết',
  'active' => 'articles',
  'description' => 'Sửa nội dung bài viết, xem trước và cập nhật ra trang thật.',
  'sidebar_note' => 'Khu vực quản trị nội dung',
  'inner_script' => $innerScript,
  'body_class' => 'admin-mode-simple-editor admin-editor-hide-left-sidebar',
]);
?>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Trang sửa bài viết</h2>
    <p>Tập trung vào phần quan trọng: tiêu đề, nội dung, lưu và cập nhật.</p>
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
  <?php elseif (!is_array($parseResult) || empty($parseResult['ok'])): ?>
    <div class="parse-fail-banner">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>
        <strong>Lỗi xử lý nội dung: <?= h((string) ($parseResult['code'] ?? 'không rõ lỗi')) ?></strong>
        <p><?= h((string) ($parseResult['message'] ?? 'Không xác định được lỗi xử lý nội dung.')) ?></p>
      </div>
    </div>
  <?php else: ?>
    <?php
    $latestEventRaw = (string) ($latestPublish['event'] ?? '');
    if ($latestEventRaw === 'publish') {
      $latestEventLabel = 'Cập nhật ra trang';
    } elseif ($latestEventRaw === 'rollback') {
      $latestEventLabel = 'Khôi phục';
    } elseif ($latestEventRaw === 'revision_restore') {
      $latestEventLabel = 'Khôi phục revision';
    } elseif ($latestEventRaw !== '') {
      $latestEventLabel = ucfirst($latestEventRaw);
    } else {
      $latestEventLabel = 'Chưa có';
    }
    $latestEventAt = format_admin_datetime((string) ($latestPublish['published_at'] ?? $latestPublish['rolled_back_at'] ?? $latestPublish['restored_at'] ?? ''));
    if ($latestEventAt === '') {
      $latestEventAt = '—';
    }
    $latestEventBy = (string) (($latestPublish['actor']['username'] ?? '') ?: ($latestPublish['actor']['display_name'] ?? ''));
    if ($latestEventBy === '') {
      $latestEventBy = '—';
    }

    $reviewStatusKey = is_array($reviewRow) ? normalize_article_review_status((string) ($reviewRow['status'] ?? 'unreviewed')) : 'unreviewed';
    $reviewHasActor = $reviewStatusKey !== 'unreviewed';
    if ($reviewStatusKey === 'edited') {
      $reviewStatusLabel = 'Đã sửa';
	    } elseif ($reviewStatusKey === 'draft_saved') {
	      $reviewStatusLabel = 'Nháp admin';
    } else {
      $reviewStatusLabel = 'Chưa sửa';
    }
    $reviewStatusAt = $reviewHasActor
      ? format_admin_datetime((string) ($reviewRow['edited_at'] ?? ''))
      : '—';
    if ($reviewStatusAt === '') {
      $reviewStatusAt = '—';
    }
    $reviewStatusBy = $reviewHasActor
      ? (string) (($reviewRow['edited_by']['username'] ?? '') ?: ($reviewRow['edited_by']['display_name'] ?? ''))
      : '';
    if ($reviewStatusBy === '') {
      $reviewStatusBy = '—';
    }

    $taxonomyItems = [];
    $appendTaxonomyItem = static function (string $level, string $label, string $key) use (&$taxonomyItems): void {
      $label = trim($label);
      $key = trim($key);
      if ($label === '' && $key === '') {
        return;
      }
      $taxonomyItems[] = [
        'level' => $level,
        'label' => $label !== '' ? $label : $key,
        'key' => $key,
      ];
    };
    $sectionKey = (string) ($form['section_key'] ?? ($article['section'] ?? ''));
    $sectionLabel = (string) ($form['section_label'] ?? ($article['section_label'] ?? ''));
    if ($sectionLabel === '' && $sectionKey !== '') {
      $sectionLabel = $sectionKey === 'ban-tin' ? 'Bản tin' : ($sectionKey === 'thu-vien' ? 'Thư viện' : $sectionKey);
    }
	    $appendTaxonomyItem('Section', $sectionLabel, $sectionKey);
	    if ($sectionKey === 'thu-vien') {
	      $appendTaxonomyItem('Cấp 1', (string) ($form['library_kind_label'] ?? ''), (string) ($form['library_kind_key'] ?? ''));
	      $appendTaxonomyItem('Cấp 2', (string) ($form['topic_lv1_label'] ?? ''), (string) ($form['topic_lv1_key'] ?? ''));
	      $appendTaxonomyItem('Cấp 3', (string) ($form['topic_lv2_label'] ?? ''), (string) ($form['topic_lv2_key'] ?? ''));
		    } else {
	      $appendTaxonomyItem('Cấp 1', (string) ($form['topic_lv1_label'] ?? ''), (string) ($form['topic_lv1_key'] ?? ''));
	      $appendTaxonomyItem('Cấp 2', (string) ($form['topic_lv2_label'] ?? ''), (string) ($form['topic_lv2_key'] ?? ''));
		    }
	    $currentTags = is_array($form['tags'] ?? null) ? array_values(array_filter(array_map('strval', $form['tags']))) : [];
    $draftChangeSummary = article_editor_change_summary($baseEditable, $form);
	    $taxonomyEditorJson = json_encode($taxonomyEditorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($taxonomyEditorJson === false) {
      $taxonomyEditorJson = '{"taxonomy":{"roots":[]},"state":{}}';
    }
    ?>

    <div class="editor-top-actions">
      <div class="editor-top-actions-row">
        <a class="clear-filter-btn inline" href="<?= h($listReturnUrl) ?>">
          <i class="fa-solid fa-arrow-left"></i>
          <span>Về danh sách bài</span>
        </a>
        <?php if ((string) ($currentUser['role'] ?? '') === 'admin'): ?>
          <form method="post" action="<?= h(admin_url('delete_article.php')) ?>" class="inline-action-form" onsubmit="return confirm('Xác nhận kiểm tra và xóa bài này?\nHệ thống sẽ quét internal link trước. Nếu có bài khác đang trỏ tới, bạn sẽ thấy danh sách cảnh báo trước khi xóa.');">
            <?= csrf_input_html() ?>
            <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>">
            <input type="hidden" name="section" value="<?= h((string) ($listContext['section'] ?? '')) ?>">
            <input type="hidden" name="library_kind_key" value="<?= h((string) ($listContext['library_kind_key'] ?? '')) ?>">
            <input type="hidden" name="topic_lv1_key" value="<?= h((string) ($listContext['topic_lv1_key'] ?? '')) ?>">
            <input type="hidden" name="topic_lv2_key" value="<?= h((string) ($listContext['topic_lv2_key'] ?? '')) ?>">
            <input type="hidden" name="topic_lv3_key" value="<?= h((string) ($listContext['topic_lv3_key'] ?? '')) ?>">
            <input type="hidden" name="tag" value="<?= h((string) ($listContext['tag'] ?? '')) ?>">
            <input type="hidden" name="review_status" value="<?= h((string) ($listContext['review_status'] ?? '')) ?>">
            <input type="hidden" name="q" value="<?= h((string) ($listContext['q'] ?? '')) ?>">
            <input type="hidden" name="sort" value="<?= h((string) ($listContext['sort'] ?? '')) ?>">
            <input type="hidden" name="per_page" value="<?= h((string) ($listContext['per_page'] ?? 20)) ?>">
            <input type="hidden" name="page" value="<?= h((string) ($listContext['page'] ?? 1)) ?>">
            <input type="hidden" name="list_article_id" value="<?= h((string) ($listContext['list_article_id'] ?? '')) ?>">
            <button type="submit" class="rollback-btn inline">
              <i class="fa-solid fa-trash-can"></i>
              <span>Xóa bài</span>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($status !== null): ?>
      <div class="flash flash-<?= h((string) ($status['type'] ?? 'warning')) ?>">
        <?= h((string) ($status['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <?php
	    $infoPanelOpen = !empty($validationErrors['excerpt'])
	      || !empty($validationErrors['publish_date'])
	      || !empty($validationErrors['modified_date']);
    ?>
    <div class="editor-workspace">
      <article class="admin-panel">
        <div class="panel-head">
          <h2>Soạn thảo nội dung</h2>
          <p>Sửa nội dung rồi lưu nháp hoặc cập nhật ra trang.</p>
        </div>
        <form method="post" class="article-editor-form editor-v4-form" id="articleEditorForm" novalidate>
          <?= csrf_input_html() ?>
	          <input type="hidden" name="_intent" value="save_draft" id="articleIntent">
	          <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>" id="articleIdInput">
	          <input type="hidden" name="taxonomy_preserve_library_kind_key" value="<?= h((string) ($form['library_kind_key'] ?? ($article['library_kind_key'] ?? ''))) ?>">
	          <input type="hidden" name="taxonomy_preserve_topic_lv1_key" value="<?= h((string) ($form['topic_lv1_key'] ?? ($article['topic_lv1_key'] ?? ''))) ?>">
	          <input type="hidden" name="taxonomy_preserve_topic_lv2_key" value="<?= h((string) ($form['topic_lv2_key'] ?? ($article['topic_lv2_key'] ?? ''))) ?>">
	          <input type="hidden" name="taxonomy_preserve_topic_lv3_key" value="<?= h((string) ($form['topic_lv3_key'] ?? ($article['topic_lv3_key'] ?? ''))) ?>">

	          <div class="editor-action-bar">
            <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
              <i class="fa-solid fa-floppy-disk"></i>
	              <span>Lưu nháp admin</span>
	            </button>
	            <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận ĐĂNG RA NGOÀI USER?\\nHệ thống sẽ ghi HTML thật, cập nhật data/articles.json và rebuild frontend.');">
	              <i class="fa-solid fa-paper-plane"></i>
	              <span>Đăng ra ngoài</span>
	            </button>
            <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-up-right-from-square"></i>
              <span>Xem bài</span>
            </a>
            <button type="button" class="editor-fullscreen-toggle" id="editorFullscreenToggle">
              <i class="fa-solid fa-expand"></i>
              <span class="fs-label-expand">Toàn màn hình</span>
              <span class="fs-label-collapse">Thu nhỏ</span>
            </button>
            <span class="editor-shortcut-hint">Ctrl+S lưu nháp · Ctrl+Shift+F toàn màn hình</span>
          </div>
          <div class="editor-fullscreen-backdrop" id="editorFullscreenBackdrop"></div>
          <div class="editor-fs-status" id="editorFsStatus">
            <i class="fa-solid fa-keyboard"></i>
            <span>Esc hoặc Ctrl+Shift+F để thoát toàn màn hình</span>
          </div>

	          <label class="filter-field">
            <span>Tiêu đề *</span>
            <input type="text" name="title" value="<?= h((string) ($form['title'] ?? '')) ?>" required>
            <?php if (!empty($validationErrors['title'])): ?><small class="field-error"><?= h((string) $validationErrors['title']) ?></small><?php endif; ?>
          </label>

	          <label class="filter-field">
            <span>Nội dung bài viết *</span>
            <textarea id="proseEditor" name="prose_html" rows="20" class="prose-textarea" required><?= h((string) ($form['prose_html'] ?? '')) ?></textarea>
            <input type="hidden" name="prose_html_b64" id="proseHtmlB64" value="">
            <?php if (!empty($validationErrors['prose_html'])): ?><small class="field-error"><?= h((string) $validationErrors['prose_html']) ?></small><?php endif; ?>
          </label>
          <div class="editor-bottom-fs-bar">
            <button type="button" class="editor-fullscreen-toggle-bottom" id="editorFullscreenToggleBottom" title="Toàn màn hình (Ctrl+Shift+F)">
              <i class="fa-solid fa-expand"></i>
            </button>
          </div>

	          <details class="editor-info-panel" <?= $infoPanelOpen ? 'open' : '' ?>>
	            <summary>
	              <i class="fa-solid fa-circle-info"></i>
	              <span>Thông tin bài & tác vụ phụ</span>
	            </summary>

	            <div class="editor-taxonomy-card">
	              <div class="editor-taxonomy-card__head">
	                <strong>Phân loại hiện tại / nháp</strong>
	                <small>Khu vực này chỉ tóm tắt nhanh. Phần chỉnh phân loại và tags nằm ở sidebar bên phải.</small>
              </div>
              <?php if (!empty($taxonomyItems)): ?>
                <div class="editor-pill-row editor-taxonomy-row">
                  <?php foreach ($taxonomyItems as $item): ?>
                    <span class="editor-pill editor-pill--taxonomy">
                      <small><?= h((string) ($item['level'] ?? '')) ?></small>
                      <span><?= h((string) ($item['label'] ?? '')) ?></span>
                      <?php if (!empty($item['key'])): ?><code><?= h((string) $item['key']) ?></code><?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="editor-taxonomy-empty">Chưa đọc được phân loại từ dữ liệu bài viết.</p>
              <?php endif; ?>
              <?php if (!empty($currentTags)): ?>
                <div class="editor-tag-preview" aria-label="Tags hiện tại">
                  <?php foreach ($currentTags as $tag): ?>
                    <span>#<?= h($tag) ?></span>
                  <?php endforeach; ?>
                </div>
	              <?php endif; ?>
	            </div>

            <div class="editor-change-card <?= !empty($draftChangeSummary) ? 'has-changes' : 'is-clean' ?>">
	              <div class="editor-taxonomy-card__head">
	                <strong>So sánh nháp với bản đang publish</strong>
	                <small>Chỉ theo dõi nhanh phân loại và tags trước khi bấm “Đăng ra ngoài”.</small>
              </div>
              <?php if (!empty($draftChangeSummary)): ?>
                <div class="editor-change-list">
                  <?php foreach ($draftChangeSummary as $change): ?>
                    <div class="editor-change-row">
                      <small><?= h((string) ($change['label'] ?? '')) ?></small>
                      <p><span>Từ:</span> <?= h((string) ($change['before'] ?? '—')) ?></p>
                      <p><span>Thành:</span> <?= h((string) ($change['after'] ?? '—')) ?></p>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="editor-change-clean">Chưa có thay đổi phân loại hoặc tags so với bản đang publish.</p>
              <?php endif; ?>
            </div>

		            <div class="editor-meta-grid">
              <label class="filter-field span-2">
                <span>Mô tả ngắn *</span>
                <input type="text" name="excerpt" value="<?= h((string) ($form['excerpt'] ?? '')) ?>" required>
                <?php if (!empty($validationErrors['excerpt'])): ?><small class="field-error"><?= h((string) $validationErrors['excerpt']) ?></small><?php endif; ?>
              </label>

              <label class="filter-field">
                <span>Ngày đăng *</span>
                <input type="date" name="publish_date" value="<?= h((string) ($form['publish_date'] ?? '')) ?>" required>
                <?php if (!empty($validationErrors['publish_date'])): ?><small class="field-error"><?= h((string) $validationErrors['publish_date']) ?></small><?php endif; ?>
              </label>

              <label class="filter-field">
                <span>Ngày sửa</span>
                <input type="date" name="modified_date" value="<?= h((string) ($form['modified_date'] ?? '')) ?>">
                <?php if (!empty($validationErrors['modified_date'])): ?><small class="field-error"><?= h((string) $validationErrors['modified_date']) ?></small><?php endif; ?>
              </label>

	              <label class="filter-field span-2">
                <span>Ảnh đại diện (Featured image)</span>
                <input type="text" name="featured_image" id="featuredImageInput" value="<?= h((string) ($form['featured_image'] ?? '')) ?>" placeholder="VD: assets/images/content/abc.jpg hoặc uploads/articles/2026/04/anh.jpg">
                <small>Có thể nhập thủ công, hoặc bấm “Dùng làm ảnh đại diện” từ danh sách ảnh upload mới ở sidebar.</small>
              </label>
            </div>

            <div class="editor-status-inline">
              <p><strong>Trạng thái:</strong> <?= h($reviewStatusLabel) ?><?= $reviewHasActor ? (' · ' . h($reviewStatusAt) . ' · ' . h($reviewStatusBy)) : '' ?></p>
              <p><strong>Lần thao tác gần nhất:</strong> <?= h($latestEventLabel) ?> · <?= h($latestEventAt) ?> · <?= h($latestEventBy) ?></p>
              <p><strong>Đường dẫn:</strong> <code><?= h((string) ($article['href'] ?? '')) ?></code></p>
            </div>
          </details>

          <div class="editor-bottom-actions">
            <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
              <i class="fa-solid fa-floppy-disk"></i>
	              <span>Lưu nháp admin</span>
	            </button>
	            <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận ĐĂNG RA NGOÀI USER?\\nHệ thống sẽ ghi HTML thật, cập nhật data/articles.json và rebuild frontend.');">
	              <i class="fa-solid fa-paper-plane"></i>
	              <span>Đăng ra ngoài</span>
	            </button>
            <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-up-right-from-square"></i>
              <span>Xem bài</span>
            </a>
          </div>
        </form>
      </article>

      <aside class="editor-workspace-side">
        <section class="admin-panel editor-side-card">
          <div class="panel-head">
            <h3>Trạng thái bài viết</h3>
            <p>Theo dõi nhanh tình trạng biên tập hiện tại.</p>
          </div>
          <div class="editor-status-inline">
            <p><strong>Review:</strong> <?= h($reviewStatusLabel) ?><?= $reviewHasActor ? (' · ' . h($reviewStatusAt) . ' · ' . h($reviewStatusBy)) : '' ?></p>
            <p><strong>Tác vụ gần nhất:</strong> <?= h($latestEventLabel) ?> · <?= h($latestEventAt) ?> · <?= h($latestEventBy) ?></p>
            <p><strong>ID:</strong> <code><?= h((string) ($article['id'] ?? '')) ?></code></p>
            <p><strong>Đường dẫn:</strong> <code><?= h((string) ($article['href'] ?? '')) ?></code></p>
            <p><strong>Số ảnh upload riêng:</strong> <?= number_format(count($uploads), 0, ',', '.') ?></p>
            <p><strong>Số revision draft:</strong> <?= number_format(count($revisions), 0, ',', '.') ?></p>
          </div>
          <div class="editor-side-actions">
            <button type="submit" form="articleEditorForm" class="mark-unreviewed-btn inline" onclick="document.getElementById('articleIntent').value='mark_unreviewed'; return confirm('Đánh dấu bài này là Chưa sửa?');">
              <i class="fa-solid fa-rotate-left"></i>
              <span>Đánh dấu chưa sửa</span>
            </button>
          </div>
	        </section>

	        <section class="admin-panel editor-side-card editor-sidebar-meta-card">
	          <div class="panel-head">
	            <h3>Phân loại & thẻ</h3>
	            <p>Sửa category/tags tại đây. Bấm “Đăng ra ngoài” để frontend nhận thay đổi.</p>
	          </div>
	          <script id="editorTaxonomyData" type="application/json"><?= $taxonomyEditorJson ?></script>
	          <div class="editor-taxonomy-card editor-taxonomy-card--edit" data-taxonomy-editor>
	            <div class="editor-taxonomy-card__head">
	              <strong>Chỉnh phân loại bài viết</strong>
	              <small>Lưu nháp chỉ giữ trong admin; “Đăng ra ngoài” mới cập nhật trang user và rebuild frontend.</small>
	            </div>
	            <div class="editor-taxonomy-select-grid">
	              <label class="filter-field">
	                <span>Khu vực</span>
	                <select name="taxonomy_section_key" form="articleEditorForm" data-taxonomy-select="section"></select>
	              </label>
	              <label class="filter-field" data-taxonomy-row="library_kind">
	                <span data-taxonomy-label="library_kind">Cấp 1</span>
	                <select name="taxonomy_library_kind_key" form="articleEditorForm" data-taxonomy-select="library_kind"></select>
	              </label>
	              <label class="filter-field">
		                <span data-taxonomy-label="topic_lv1"><?= $sectionKey === 'thu-vien' ? 'Cấp 2' : 'Cấp 1' ?></span>
	                <select name="taxonomy_topic_lv1_key" form="articleEditorForm" data-taxonomy-select="topic_lv1"></select>
	              </label>
		              <label class="filter-field" data-taxonomy-row="topic_lv2">
			                <span data-taxonomy-label="topic_lv2"><?= $sectionKey === 'thu-vien' ? 'Cấp 3' : 'Cấp 2' ?></span>
	                <select name="taxonomy_topic_lv2_key" form="articleEditorForm" data-taxonomy-select="topic_lv2"></select>
	              </label>
	              <label class="filter-field" data-taxonomy-row="topic_lv3" hidden>
	                <span data-taxonomy-label="topic_lv3">Cấp 3</span>
	                <select name="taxonomy_topic_lv3_key" form="articleEditorForm" data-taxonomy-select="topic_lv3"></select>
	              </label>
	            </div>
	            <div class="editor-taxonomy-path" data-taxonomy-path>Đang tải cây phân loại...</div>
	            <?php if (!empty($validationErrors['taxonomy'])): ?>
	              <small class="field-error"><?= h((string) $validationErrors['taxonomy']) ?></small>
	            <?php endif; ?>
	          </div>
	          <label class="filter-field editor-sidebar-tags-field">
	            <span>Thẻ (2-7 thẻ, ngăn cách bằng dấu phẩy) *</span>
	            <input type="text" name="tags_text" form="articleEditorForm" value="<?= h((string) ($form['tags_text'] ?? '')) ?>" required>
	            <?php if (!empty($validationErrors['tags_text'])): ?><small class="field-error"><?= h((string) $validationErrors['tags_text']) ?></small><?php endif; ?>
	          </label>
	        </section>

	        <section class="admin-panel editor-side-card">
	          <div class="panel-head">
	            <h3>Ảnh upload mới</h3>
            <p>Dùng nút chèn ảnh trong editor để upload. Ảnh thuộc riêng bài này.</p>
          </div>
          <?php if (empty($uploads)): ?>
            <div class="empty-state">
              <p>Chưa có ảnh upload riêng.</p>
            </div>
          <?php else: ?>
            <div class="editor-upload-list">
              <?php foreach ($uploads as $upload): ?>
                <div class="editor-upload-item">
                  <img class="editor-upload-thumb" src="<?= h((string) ($upload['url'] ?? '')) ?>" alt="">
                  <div class="editor-upload-meta">
                    <strong><?= h((string) ($upload['name'] ?? '')) ?></strong>
                    <small><?= number_format(((int) ($upload['size'] ?? 0)) / 1024, 1) ?> KB</small>
                  </div>
                  <div class="editor-upload-actions">
                    <button type="button" class="clear-filter-btn inline" data-upload-select="<?= h((string) ($upload['public_path'] ?? '')) ?>">
                      Dùng làm ảnh đại diện
                    </button>
                    <form method="post" action="<?= h(admin_url('delete_upload.php')) ?>" class="inline-action-form" onsubmit="return confirm('Xóa file ảnh này khỏi uploads?');">
                      <?= csrf_input_html() ?>
                      <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>">
                      <input type="hidden" name="upload_id" value="<?= h((string) ($upload['id'] ?? '')) ?>">
                      <input type="hidden" name="upload_name" value="<?= h((string) ($upload['name'] ?? '')) ?>">
                      <input type="hidden" name="upload_year" value="<?= h((string) ($upload['year'] ?? '')) ?>">
                      <input type="hidden" name="upload_month" value="<?= h((string) ($upload['month'] ?? '')) ?>">
                      <button type="submit" class="rollback-btn inline">Xóa</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="admin-panel editor-side-card">
          <div class="panel-head">
            <h3>Lịch sử chỉnh sửa</h3>
            <p>Mỗi lần publish/restore hệ thống đều backup trước khi ghi file.</p>
          </div>
          <div class="editor-history-actions">
            <button type="submit" form="articleEditorForm" class="rollback-btn inline" onclick="document.getElementById('articleIntent').value='rollback_latest'; return confirm('Xác nhận khôi phục từ bản sao lưu gần nhất?');">
              <i class="fa-solid fa-rotate-left"></i>
              <span>Khôi phục gần nhất</span>
            </button>
          </div>
          <?php if (empty($recentPublishRecords) && empty($revisions)): ?>
            <div class="empty-state">
              <p>Chưa có lịch sử gần đây.</p>
            </div>
          <?php else: ?>
            <?php if (!empty($recentPublishRecords)): ?>
              <div class="editor-history-list">
                <?php foreach ($recentPublishRecords as $record): ?>
                  <?php
                  $event = trim((string) ($record['event'] ?? ''));
                  $eventLabel = $event === 'publish'
                    ? 'Publish'
                    : ($event === 'rollback'
                      ? 'Rollback'
                      : ($event === 'revision_restore' ? 'Restore revision' : ucfirst($event)));
                  $eventAt = format_admin_datetime((string) ($record['published_at'] ?? $record['rolled_back_at'] ?? $record['restored_at'] ?? ''));
                  $eventBy = (string) (($record['actor']['username'] ?? '') ?: ($record['actor']['display_name'] ?? ''));
	                  if ($eventBy === '') {
	                    $eventBy = '—';
	                  }
                    $historyTaxonomyBefore = is_array($record['taxonomy_before'] ?? null) ? $record['taxonomy_before'] : [];
                    $historyTaxonomyAfter = is_array($record['taxonomy_after'] ?? null) ? $record['taxonomy_after'] : [];
                    $historyTagsBefore = is_array($record['tags_before'] ?? null) ? $record['tags_before'] : [];
                    $historyTagsAfter = is_array($record['tags_after'] ?? null) ? $record['tags_after'] : [];
                    $historyTaxonomyChanged = !empty($record['taxonomy_changed']);
                    $historyTagsChanged = !empty($record['tags_changed']);
                    $historyPublicRebuild = is_array($record['public_rebuild'] ?? null) ? $record['public_rebuild'] : [];
	                  ?>
	                  <article class="editor-history-item">
	                    <div class="editor-history-head">
	                      <strong><?= h($eventLabel) ?></strong>
	                      <span><?= h($eventAt) ?></span>
	                    </div>
	                    <p>Người thao tác: <?= h($eventBy) ?></p>
                      <?php if ($historyTaxonomyChanged || $historyTagsChanged || !empty($historyPublicRebuild)): ?>
                        <div class="editor-history-diff">
                          <?php if ($historyTaxonomyChanged): ?>
                            <div>
                              <small>Phân loại</small>
                              <p><span>Trước:</span> <?= h(article_editor_taxonomy_path($historyTaxonomyBefore)) ?></p>
                              <p><span>Sau:</span> <?= h(article_editor_taxonomy_path($historyTaxonomyAfter)) ?></p>
                            </div>
                          <?php endif; ?>
                          <?php if ($historyTagsChanged): ?>
                            <div>
                              <small>Tags</small>
                              <p><span>Trước:</span> <?= h(article_editor_tags_label($historyTagsBefore)) ?></p>
                              <p><span>Sau:</span> <?= h(article_editor_tags_label($historyTagsAfter)) ?></p>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($historyPublicRebuild)): ?>
                            <div class="<?= !empty($historyPublicRebuild['ok']) ? 'is-ok' : 'is-warning' ?>">
                              <small>Public data</small>
                              <p><?= !empty($historyPublicRebuild['ok']) ? 'Đã đồng bộ frontend.' : h((string) ($historyPublicRebuild['message'] ?? 'Chưa đồng bộ public data.')) ?></p>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
	                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($revisions)): ?>
              <div class="editor-revision-list">
                <?php foreach ($revisions as $revision): ?>
                  <div class="editor-revision-item">
                    <div>
                      <strong><?= h((string) ($revision['display'] ?? '')) ?></strong>
                      <small><?= h((string) ($revision['name'] ?? '')) ?> · <?= number_format(((int) ($revision['size'] ?? 0)) / 1024, 1) ?> KB</small>
                    </div>
                    <form method="post" action="<?= h(admin_url('restore_revision.php')) ?>" onsubmit="return confirm('Khôi phục revision này? Bản hiện tại sẽ được backup trước.');">
                      <?= csrf_input_html() ?>
                      <input type="hidden" name="article_id" value="<?= h((string) ($article['id'] ?? '')) ?>">
                      <input type="hidden" name="revision_name" value="<?= h((string) ($revision['name'] ?? '')) ?>">
                      <button class="clear-filter-btn inline" type="submit">Khôi phục</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section class="admin-panel editor-side-card">
          <a class="clear-filter-btn inline editor-side-back-btn" href="<?= h($listReturnUrl) ?>">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Về danh sách bài</span>
          </a>
        </section>
      </aside>
    </div>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<?php admin_layout_footer(); ?>
