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
  $tags = array_values(array_unique(array_filter(array_map(static function (string $part): string {
    return trim($part);
  }, preg_split('/[,;\n]+/', $tagsInput) ?: []), static function (string $value): bool {
    return $value !== '';
  })));
  if (count($tags) < 3) {
    $errors['tags_text'] = 'Cần tối thiểu 3 tag.';
  }
  if (count($tags) > 7) {
    $errors['tags_text'] = 'Tối đa 7 tag.';
  }
  $clean['tags'] = $tags;
  $clean['tags_text'] = implode(', ', $tags);

  $proseHtml = trim((string) ($payload['prose_html'] ?? ''));
  if ($proseHtml === '') {
    $errors['prose_html'] = 'Nội dung chính không được để trống.';
  }
  $clean['prose_html'] = $proseHtml;

  return [
    'ok' => empty($errors),
    'errors' => $errors,
    'data' => $clean,
  ];
}

/**
 * Build before/after diff rows for quick review.
 *
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @return array<int,array<string,string>>
 */
function build_diff_rows(array $before, array $after): array
{
  $rows = [];
  $fields = [
    'title' => 'Tiêu đề',
    'excerpt' => 'Mô tả ngắn',
    'publish_date' => 'Ngày đăng',
    'modified_date' => 'Ngày sửa',
    'tags_text' => 'Tags',
  ];

  foreach ($fields as $key => $label) {
    $old = (string) ($before[$key] ?? '');
    $new = (string) ($after[$key] ?? '');
    if ($old === $new) {
      continue;
    }
    $rows[] = [
      'label' => $label,
      'before' => $old,
      'after' => $new,
    ];
  }

  $oldContent = trim((string) ($before['prose_html'] ?? ''));
  $newContent = trim((string) ($after['prose_html'] ?? ''));
  if ($oldContent !== $newContent) {
    $rows[] = [
      'label' => 'Nội dung chính (.article-prose)',
      'before' => preview_text($oldContent, 220),
      'after' => preview_text($newContent, 220),
    ];
  }

  return $rows;
}

$id = trim((string) ($_GET['id'] ?? ''));
$article = find_article_index_item($id);
$forceAudit = isset($_GET['audit']) && (string) $_GET['audit'] === '1';
$audit = run_parser_audit($forceAudit);
$auditMeta = is_array($audit['meta'] ?? null) ? $audit['meta'] : [];
$auditFails = is_array($audit['fails'] ?? null) ? $audit['fails'] : [];
$auditSafeRate = (float) ($auditMeta['safe_rate_percent'] ?? 0);

$currentUser = current_user();
$parseResult = null;
$baseEditable = [];
$draftCurrent = null;
$form = [];
$validationErrors = [];
$diffRows = [];
$status = null;
$previewHtml = '';
$previewMeta = [];

if ($article !== null) {
  $path = resolve_article_file_path($article);
  $parseResult = parse_article_file($path);
  if (is_array($parseResult) && !empty($parseResult['ok'])) {
    $metaPayload = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
    $summaryFromHtml = trim((string) ($parseResult['summary_text'] ?? ''));
    $baseEditable = [
      'title' => (string) ($metaPayload['title'] ?? ($article['title'] ?? '')),
      'excerpt' => (string) ($summaryFromHtml !== '' ? $summaryFromHtml : ($article['card_badge_label'] ?? '')),
      'publish_date' => (string) ($metaPayload['publishDate'] ?? ''),
      'modified_date' => (string) (($metaPayload['modifiedDate'] ?? '') ?: ''),
      'tags' => is_array($metaPayload['tags'] ?? null) ? array_values(array_filter(array_map('strval', $metaPayload['tags']))) : [],
      'tags_text' => is_array($metaPayload['tags'] ?? null) ? implode(', ', array_values(array_filter(array_map('strval', $metaPayload['tags'])))) : '',
      'prose_html' => (string) (($parseResult['prose']['inner'] ?? '') ?: ''),
    ];
    if ($baseEditable['excerpt'] === '') {
      $baseEditable['excerpt'] = (string) ($metaPayload['excerpt'] ?? ($metaPayload['description'] ?? ''));
    }

    $draftCurrent = read_article_draft((string) ($article['id'] ?? ''));
    if (is_array($draftCurrent) && is_array($draftCurrent['data'] ?? null)) {
      $form = array_merge($baseEditable, $draftCurrent['data']);
      $form['tags_text'] = implode(', ', array_values(array_filter(array_map('strval', is_array($form['tags'] ?? null) ? $form['tags'] : []))));
    } else {
      $form = $baseEditable;
    }

    if (is_post_request()) {
      enforce_post_csrf_or_reject();
      $intent = trim((string) ($_POST['_intent'] ?? 'save_draft'));
      $posted = [
        'title' => (string) ($_POST['title'] ?? ''),
        'excerpt' => (string) ($_POST['excerpt'] ?? ''),
        'publish_date' => (string) ($_POST['publish_date'] ?? ''),
        'modified_date' => (string) ($_POST['modified_date'] ?? ''),
        'tags_text' => (string) ($_POST['tags_text'] ?? ''),
        'prose_html' => (string) ($_POST['prose_html'] ?? ''),
      ];
      $validated = validate_draft_payload($posted);
      $form = array_merge($form, $posted);

      if (!empty($validated['ok'])) {
        $clean = is_array($validated['data'] ?? null) ? $validated['data'] : [];
        $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
        $draftCurrent = $saved;
        $form = array_merge($form, $clean);
        $diffRows = build_diff_rows($baseEditable, $clean);
        $previewHtml = (string) ($clean['prose_html'] ?? '');
        $previewMeta = [
          'title' => (string) ($clean['title'] ?? ''),
          'excerpt' => (string) ($clean['excerpt'] ?? ''),
          'publishDate' => (string) ($clean['publish_date'] ?? ''),
          'modifiedDate' => (string) ($clean['modified_date'] ?? ''),
          'tags' => is_array($clean['tags'] ?? null) ? $clean['tags'] : [],
        ];

        if ($intent === 'save_draft') {
          $status = [
            'type' => 'success',
            'message' => 'Đã lưu draft thành công.',
          ];
        } elseif ($intent === 'preview_only') {
          $status = [
            'type' => 'success',
            'message' => 'Đã tạo preview từ dữ liệu draft mới nhất.',
          ];
        }
      } else {
        $validationErrors = is_array($validated['errors'] ?? null) ? $validated['errors'] : [];
        $status = [
          'type' => 'danger',
          'message' => 'Dữ liệu chưa hợp lệ. Vui lòng kiểm tra các trường được đánh dấu.',
        ];
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
        ];
        $diffRows = build_diff_rows($baseEditable, is_array($cleanDraft) ? $cleanDraft : []);
      } else {
        $previewHtml = (string) ($baseEditable['prose_html'] ?? '');
        $previewMeta = [
          'title' => (string) ($baseEditable['title'] ?? ''),
          'excerpt' => (string) ($baseEditable['excerpt'] ?? ''),
          'publishDate' => (string) ($baseEditable['publish_date'] ?? ''),
          'modifiedDate' => (string) ($baseEditable['modified_date'] ?? ''),
          'tags' => is_array($baseEditable['tags'] ?? null) ? $baseEditable['tags'] : [],
        ];
      }
    }
  }
}

$innerScript = <<<'JS'
(() => {
  const editor = document.getElementById('proseEditor');
  const host = document.getElementById('previewHost');
  if (!editor || !host) return;

  const syncPreview = () => {
    host.innerHTML = editor.value || '<p><em>Chưa có nội dung preview.</em></p>';
  };

  editor.addEventListener('input', () => {
    if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
    window.__previewTimer = window.setTimeout(syncPreview, 120);
  });

  syncPreview();
})();
JS;

admin_layout_header([
  'title' => 'Chi tiết bài & parser safety',
  'active' => 'articles',
  'description' => 'Phase 4: Form edit + draft + before/after diff + preview render.',
  'phase_label' => 'Phase 4 — Draft & preview',
  'inner_script' => $innerScript,
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
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Chi tiết bài theo ID</h2>
    <p>Hiển thị parser detail và form draft theo contract v1.</p>
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
        <strong>Parser lỗi: <?= h((string) ($parseResult['code'] ?? 'unknown')) ?></strong>
        <p><?= h((string) ($parseResult['message'] ?? 'Không xác định được lỗi parse.')) ?></p>
      </div>
    </div>
  <?php else: ?>
    <?php
    $sectionLabel = trim((string) ($article['section_label'] ?? ''));
    if ($sectionLabel === '') {
      $sectionLabel = (string) ($article['section'] ?? '');
    }
    $prose = is_array($parseResult['prose'] ?? null) ? $parseResult['prose'] : [];
    $meta = is_array($parseResult['meta'] ?? null) ? $parseResult['meta'] : [];
    $metaPayload = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
    ?>

    <div class="article-summary-card">
      <h3><?= h((string) ($article['title'] ?? '')) ?></h3>
      <p><strong>ID:</strong> <code><?= h((string) ($article['id'] ?? '')) ?></code></p>
      <p><strong>Section:</strong> <?= h($sectionLabel) ?></p>
      <p><strong>Href:</strong> <code><?= h((string) ($article['href'] ?? '')) ?></code></p>
      <p><strong>Publish:</strong> <?= h((string) ($article['publish_date'] ?? '—')) ?> · <strong>Modified:</strong> <?= h((string) ($article['modified_date'] ?? '—')) ?></p>
    </div>

    <?php if ($status !== null): ?>
      <div class="flash flash-<?= h((string) ($status['type'] ?? 'warning')) ?>">
        <?= h((string) ($status['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <div class="parse-ok-banner">
      <i class="fa-solid fa-circle-check"></i>
      <div>
        <strong>Parser-safe</strong>
        <p>Bài này đủ điều kiện parse để đi tiếp luồng draft/preview.</p>
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
          <summary>Xem JSON article-meta hiện tại</summary>
          <pre class="json-preview"><?= h(pretty_json($metaPayload)) ?></pre>
        </details>
      </article>
    </div>

    <form method="post" class="article-editor-form" novalidate>
      <?= csrf_input_html() ?>
      <input type="hidden" name="_intent" value="save_draft" id="articleIntent">

      <div class="editor-grid">
        <label class="filter-field span-2">
          <span>Tiêu đề *</span>
          <input type="text" name="title" value="<?= h((string) ($form['title'] ?? '')) ?>" required>
          <?php if (!empty($validationErrors['title'])): ?><small class="field-error"><?= h((string) $validationErrors['title']) ?></small><?php endif; ?>
        </label>

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
          <span>Tags (3-7, phân tách bằng dấu phẩy) *</span>
          <input type="text" name="tags_text" value="<?= h((string) ($form['tags_text'] ?? '')) ?>" required>
          <?php if (!empty($validationErrors['tags_text'])): ?><small class="field-error"><?= h((string) $validationErrors['tags_text']) ?></small><?php endif; ?>
        </label>
      </div>

      <label class="filter-field">
        <span>Nội dung chính (.article-prose) *</span>
        <textarea id="proseEditor" name="prose_html" rows="18" class="prose-textarea" required><?= h((string) ($form['prose_html'] ?? '')) ?></textarea>
        <?php if (!empty($validationErrors['prose_html'])): ?><small class="field-error"><?= h((string) $validationErrors['prose_html']) ?></small><?php endif; ?>
      </label>

      <div class="editor-actions">
        <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
          <i class="fa-solid fa-floppy-disk"></i>
          <span>Lưu Draft</span>
        </button>
        <button type="submit" class="clear-filter-btn inline" onclick="document.getElementById('articleIntent').value='preview_only'">
          <i class="fa-solid fa-eye"></i>
          <span>Cập nhật Preview</span>
        </button>
      </div>
    </form>

    <div class="editor-preview-grid">
      <article class="admin-panel">
        <div class="panel-head">
          <h2>Before / After diff</h2>
          <p>Hiển thị field thay đổi giữa bản gốc parse và draft hiện tại.</p>
        </div>
        <?php if (empty($diffRows)): ?>
          <div class="empty-state">
            <i class="fa-regular fa-clone"></i>
            <p>Chưa có thay đổi khác biệt hoặc chưa lưu draft.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Field</th>
                  <th>Before</th>
                  <th>After</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($diffRows as $row): ?>
                  <tr>
                    <td><strong><?= h((string) ($row['label'] ?? '')) ?></strong></td>
                    <td><?= h((string) ($row['before'] ?? '')) ?></td>
                    <td><?= h((string) ($row['after'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>

      <article class="admin-panel">
        <div class="panel-head">
          <h2>Preview render</h2>
          <p>Render nhanh nội dung prose từ draft hiện tại để rà soát bố cục.</p>
        </div>
        <div class="preview-meta">
          <p><strong>Title:</strong> <?= h((string) ($previewMeta['title'] ?? '')) ?></p>
          <p><strong>Excerpt:</strong> <?= h((string) ($previewMeta['excerpt'] ?? '')) ?></p>
          <p><strong>Publish:</strong> <?= h((string) ($previewMeta['publishDate'] ?? '')) ?> · <strong>Modified:</strong> <?= h((string) ($previewMeta['modifiedDate'] ?? '')) ?></p>
          <p><strong>Tags:</strong> <?= h(implode(', ', is_array($previewMeta['tags'] ?? null) ? array_map('strval', $previewMeta['tags']) : [])) ?></p>
        </div>
        <div class="preview-host" id="previewHost">
          <?= $previewHtml !== '' ? $previewHtml : '<p><em>Chưa có nội dung preview.</em></p>' ?>
        </div>
      </article>
    </div>

    <p style="margin-top:12px;">
      <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-up-right-from-square"></i>
        <span>Mở bài viết public</span>
      </a>
    </p>
  <?php endif; ?>
</section>

<?php admin_layout_footer(); ?>
