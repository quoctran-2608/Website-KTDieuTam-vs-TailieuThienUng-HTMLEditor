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
    'tags_text' => 'Thẻ',
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
$publishStatus = null;
$latestPublish = null;
$recentHistory = [];
$reviewRow = null;

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
            $status = [
              'type' => 'success',
              'message' => 'Đã khôi phục thành công từ bản sao lưu gần nhất.',
            ];
            $publishStatus = $result;
        } else {
          $status = [
            'type' => 'danger',
            'message' => 'Khôi phục thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
          ];
          $publishStatus = $result;
        }

        $parseResult = parse_article_file($path);
        if (is_array($parseResult) && !empty($parseResult['ok'])) {
          $metaPayloadAfterRollback = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
          $summaryAfterRollback = trim((string) ($parseResult['summary_text'] ?? ''));
          $baseEditable = [
            'title' => (string) ($metaPayloadAfterRollback['title'] ?? ($article['title'] ?? '')),
            'excerpt' => (string) ($summaryAfterRollback !== '' ? $summaryAfterRollback : ($article['card_badge_label'] ?? '')),
            'publish_date' => (string) ($metaPayloadAfterRollback['publishDate'] ?? ''),
            'modified_date' => (string) (($metaPayloadAfterRollback['modifiedDate'] ?? '') ?: ''),
            'tags' => is_array($metaPayloadAfterRollback['tags'] ?? null) ? array_values(array_filter(array_map('strval', $metaPayloadAfterRollback['tags']))) : [],
            'tags_text' => is_array($metaPayloadAfterRollback['tags'] ?? null) ? implode(', ', array_values(array_filter(array_map('strval', $metaPayloadAfterRollback['tags'])))) : '',
            'prose_html' => (string) (($parseResult['prose']['inner'] ?? '') ?: ''),
          ];
          if ($baseEditable['excerpt'] === '') {
            $baseEditable['excerpt'] = (string) ($metaPayloadAfterRollback['excerpt'] ?? ($metaPayloadAfterRollback['description'] ?? ''));
          }

          $form = $baseEditable;
          $previewHtml = (string) ($baseEditable['prose_html'] ?? '');
          $previewMeta = [
            'title' => (string) ($baseEditable['title'] ?? ''),
            'excerpt' => (string) ($baseEditable['excerpt'] ?? ''),
            'publishDate' => (string) ($baseEditable['publish_date'] ?? ''),
            'modifiedDate' => (string) ($baseEditable['modified_date'] ?? ''),
            'tags' => is_array($baseEditable['tags'] ?? null) ? $baseEditable['tags'] : [],
          ];
          $diffRows = [];
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
          'prose_html' => (string) ($_POST['prose_html'] ?? ''),
        ];
        $validated = validate_draft_payload($posted);
        $form = array_merge($form, $posted);

        if (!empty($validated['ok'])) {
          $clean = is_array($validated['data'] ?? null) ? $validated['data'] : [];
          if ($intent === 'publish_now') {
            $result = publish_article_draft($article, $clean, $currentUser);
            if (!empty($result['ok'])) {
              // Keep draft snapshot for traceability after publish
              $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
              $draftCurrent = $saved;
              $reviewRow = mark_article_reviewed((string) ($article['id'] ?? ''), $currentUser, 'publish_now');
              $form = array_merge($form, $clean);
              $status = [
                'type' => 'success',
                'message' => 'Đã cập nhật bài viết ra trang.',
              ];
              $publishStatus = $result;
            } else {
              $status = [
                'type' => 'danger',
                'message' => 'Cập nhật thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
              ];
              $publishStatus = $result;
            }
          } else {
            $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
            $draftCurrent = $saved;
            $reviewRow = mark_article_reviewed((string) ($article['id'] ?? ''), $currentUser, $intent);
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
    if ($reviewRow === null) {
      $reviewRow = read_article_review_status((string) ($article['id'] ?? ''));
    }
    $latestPublish = find_latest_publish_record((string) ($article['id'] ?? ''));
    $recentHistory = list_recent_publish_records((string) ($article['id'] ?? ''), 8);
  }
}

$innerScript = <<<'JS'
(() => {
  const form = document.getElementById('articleEditorForm');
  const intent = document.getElementById('articleIntent');
  const editor = document.getElementById('proseEditor');
  const host = document.getElementById('previewHost');
  if (!editor || !host) return;

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
    const html = getEditorHtml().trim();
    host.innerHTML = html !== '' ? html : '<p><em>Chưa có nội dung preview.</em></p>';
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

  if (window.tinymce && typeof window.tinymce.init === 'function') {
    window.tinymce.init({
      selector: '#proseEditor',
      menubar: true,
      height: 620,
      branding: false,
      relative_urls: false,
      remove_script_host: false,
      convert_urls: false,
      plugins: 'advlist autolink lists link image table code charmap preview searchreplace visualblocks wordcount paste',
      toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image | removeformat code preview',
      content_style: 'body { font-family: Arial, sans-serif; font-size: 16px; line-height: 1.65; padding: 14px; } img { max-width: 100%; height: auto; }',
      setup: (instance) => {
        instance.on('input change keyup setcontent', () => {
          if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
          window.__previewTimer = window.setTimeout(syncPreview, 100);
        });
      }
    });
  }

  syncPreview();
})();
JS;

admin_layout_header([
  'title' => 'Sửa bài viết',
  'active' => 'articles',
  'description' => 'Sửa nội dung bài viết, xem trước và cập nhật ra trang thật.',
  'sidebar_note' => 'Khu vực quản trị nội dung',
  'inner_script' => $innerScript,
]);
?>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Trang sửa bài viết</h2>
    <p>Bên trái để sửa nội dung, bên phải để theo dõi trạng thái và lịch sử thao tác.</p>
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
    $sectionLabel = trim((string) ($article['section_label'] ?? ''));
    if ($sectionLabel === '') {
      $sectionLabel = (string) ($article['section'] ?? '');
    }
    $prose = is_array($parseResult['prose'] ?? null) ? $parseResult['prose'] : [];
    $meta = is_array($parseResult['meta'] ?? null) ? $parseResult['meta'] : [];
    $metaPayload = is_array($parseResult['meta_payload'] ?? null) ? $parseResult['meta_payload'] : [];
    ?>

    <?php
    $draftUpdatedAt = is_array($draftCurrent) ? format_admin_datetime((string) ($draftCurrent['updated_at'] ?? '')) : '';
    if ($draftUpdatedAt === '') {
      $draftUpdatedAt = 'Chưa có bản nháp';
    }
    $draftUpdatedBy = '—';
    if (is_array($draftCurrent)) {
      $draftUpdatedBy = (string) (($draftCurrent['updated_by']['username'] ?? '') ?: ($draftCurrent['updated_by']['display_name'] ?? ''));
      if ($draftUpdatedBy === '') {
        $draftUpdatedBy = '—';
      }
    }

    $latestEventRaw = (string) ($latestPublish['event'] ?? '');
    if ($latestEventRaw === 'publish') {
      $latestEventLabel = 'Cập nhật ra trang';
    } elseif ($latestEventRaw === 'rollback') {
      $latestEventLabel = 'Khôi phục';
    } elseif ($latestEventRaw !== '') {
      $latestEventLabel = ucfirst($latestEventRaw);
    } else {
      $latestEventLabel = 'Chưa có';
    }
    $latestEventAt = format_admin_datetime((string) ($latestPublish['published_at'] ?? $latestPublish['rolled_back_at'] ?? ''));
    if ($latestEventAt === '') {
      $latestEventAt = '—';
    }
    $latestEventBy = (string) (($latestPublish['actor']['username'] ?? '') ?: ($latestPublish['actor']['display_name'] ?? ''));
    if ($latestEventBy === '') {
      $latestEventBy = '—';
    }

    $reviewIsEdited = is_array($reviewRow) && (string) ($reviewRow['status'] ?? '') === 'edited';
    $reviewStatusLabel = $reviewIsEdited ? 'Đã sửa' : 'Chưa sửa';
    $reviewStatusAt = $reviewIsEdited
      ? format_admin_datetime((string) ($reviewRow['edited_at'] ?? ''))
      : '—';
    if ($reviewStatusAt === '') {
      $reviewStatusAt = '—';
    }
    $reviewStatusBy = $reviewIsEdited
      ? (string) (($reviewRow['edited_by']['username'] ?? '') ?: ($reviewRow['edited_by']['display_name'] ?? ''))
      : '';
    if ($reviewStatusBy === '') {
      $reviewStatusBy = '—';
    }
    ?>

    <div class="parse-ok-banner">
      <i class="fa-solid fa-circle-check"></i>
      <div>
        <strong>Bài viết sẵn sàng để chỉnh sửa</strong>
        <p>Bạn có thể sửa trực tiếp, xem trước và cập nhật ra trang.</p>
      </div>
    </div>

    <div class="editor-top-actions">
      <div class="editor-top-actions-row">
        <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
          <i class="fa-solid fa-arrow-left"></i>
          <span>Về danh sách bài</span>
        </a>
        <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-up-right-from-square"></i>
          <span>Mở bài public</span>
        </a>
      </div>
      <div class="editor-pill-row">
        <span class="editor-pill"><strong>ID:</strong> <code><?= h((string) ($article['id'] ?? '')) ?></code></span>
        <span class="editor-pill"><strong>Mục:</strong> <?= h($sectionLabel) ?></span>
        <span class="editor-pill"><strong>Bản nháp:</strong> <?= h($draftUpdatedAt) ?></span>
        <span class="editor-pill"><strong>Người sửa:</strong> <?= h($draftUpdatedBy) ?></span>
        <span class="editor-pill"><strong>Trạng thái:</strong> <?= h($reviewStatusLabel) ?><?= $reviewIsEdited ? (' · ' . h($reviewStatusAt)) : '' ?></span>
        <span class="editor-pill"><strong>Lần thao tác gần nhất:</strong> <?= h($latestEventLabel) ?> · <?= h($latestEventAt) ?></span>
      </div>
    </div>

    <?php if ($status !== null): ?>
      <div class="flash flash-<?= h((string) ($status['type'] ?? 'warning')) ?>">
        <?= h((string) ($status['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <div class="editor-workspace">
      <section class="editor-workspace-main">
        <article class="admin-panel">
          <div class="panel-head">
            <h2>Soạn thảo nội dung</h2>
            <p>Nhấn tổ hợp phím lưu nhanh để lưu bản nháp. Các nút cập nhật và khôi phục nằm ngay phía trên.</p>
          </div>

          <form method="post" class="article-editor-form editor-v4-form" id="articleEditorForm" novalidate>
            <?= csrf_input_html() ?>
            <input type="hidden" name="_intent" value="save_draft" id="articleIntent">

            <div class="editor-action-bar">
              <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Lưu bản nháp</span>
              </button>
              <button type="submit" class="clear-filter-btn inline" onclick="document.getElementById('articleIntent').value='preview_only'">
                <i class="fa-solid fa-eye"></i>
                <span>Lưu và xem trước</span>
              </button>
              <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận cập nhật bài này ra trang? Hệ thống sẽ sao lưu trước khi ghi file thật.');">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Cập nhật ra trang</span>
              </button>
              <button type="submit" class="rollback-btn inline" onclick="document.getElementById('articleIntent').value='rollback_latest'; return confirm('Xác nhận khôi phục từ bản sao lưu gần nhất?');">
                <i class="fa-solid fa-rotate-left"></i>
                <span>Khôi phục gần nhất</span>
              </button>
              <button type="submit" class="mark-unreviewed-btn inline" onclick="document.getElementById('articleIntent').value='mark_unreviewed'; return confirm('Đánh dấu bài này là Chưa sửa?');">
                <i class="fa-solid fa-rotate-left"></i>
                <span>Đánh dấu chưa sửa</span>
              </button>
              <span class="editor-shortcut-hint">Mẹo: bấm tổ hợp phím lưu để lưu nhanh bản nháp.</span>
            </div>

            <div class="editor-meta-grid">
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
                <span>Thẻ (3-7 thẻ, ngăn cách bằng dấu phẩy) *</span>
                <input type="text" name="tags_text" value="<?= h((string) ($form['tags_text'] ?? '')) ?>" required>
                <?php if (!empty($validationErrors['tags_text'])): ?><small class="field-error"><?= h((string) $validationErrors['tags_text']) ?></small><?php endif; ?>
              </label>
            </div>

            <label class="filter-field">
              <span>Nội dung chính (.article-prose) *</span>
              <textarea id="proseEditor" name="prose_html" rows="20" class="prose-textarea" required><?= h((string) ($form['prose_html'] ?? '')) ?></textarea>
              <?php if (!empty($validationErrors['prose_html'])): ?><small class="field-error"><?= h((string) $validationErrors['prose_html']) ?></small><?php endif; ?>
            </label>
          </form>
        </article>

        <article class="admin-panel preview-panel-v4">
          <div class="panel-head">
            <h2>Xem trước nội dung</h2>
            <p>Xem nhanh kết quả hiển thị trước khi cập nhật ra trang.</p>
          </div>
          <div class="preview-meta">
            <p><strong>Tiêu đề:</strong> <?= h((string) ($previewMeta['title'] ?? '')) ?></p>
            <p><strong>Mô tả ngắn:</strong> <?= h((string) ($previewMeta['excerpt'] ?? '')) ?></p>
            <p><strong>Ngày đăng:</strong> <?= h((string) ($previewMeta['publishDate'] ?? '')) ?> · <strong>Ngày sửa:</strong> <?= h((string) ($previewMeta['modifiedDate'] ?? '')) ?></p>
            <p><strong>Thẻ:</strong> <?= h(implode(', ', is_array($previewMeta['tags'] ?? null) ? array_map('strval', $previewMeta['tags']) : [])) ?></p>
          </div>
          <div class="preview-host" id="previewHost">
            <?= $previewHtml !== '' ? $previewHtml : '<p><em>Chưa có nội dung preview.</em></p>' ?>
          </div>
        </article>

        <details class="advanced-section">
          <summary><i class="fa-regular fa-clone"></i> Xem nội dung đã đổi</summary>
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
                    <th>Mục</th>
                    <th>Trước khi sửa</th>
                    <th>Sau khi sửa</th>
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
        </details>
      </section>

      <aside class="editor-workspace-side">
        <article class="admin-panel editor-side-card editor-side-sticky">
          <div class="panel-head">
            <h2>Trạng thái biên tập</h2>
            <p>Thông tin nhanh của bài hiện tại.</p>
          </div>
          <div class="editor-fact-list">
            <div>
              <strong>Tiêu đề</strong>
              <p><?= h((string) ($article['title'] ?? '')) ?></p>
            </div>
            <div>
              <strong>Đường dẫn</strong>
              <code><?= h((string) ($article['href'] ?? '')) ?></code>
            </div>
            <div>
              <strong>Trạng thái biên tập</strong>
              <p><?= h($reviewStatusLabel) ?><?= $reviewIsEdited ? (' · ' . h($reviewStatusAt) . ' · ' . h($reviewStatusBy)) : '' ?></p>
            </div>
            <div>
              <strong>Sự kiện gần nhất</strong>
              <p><?= h($latestEventLabel) ?> · <?= h($latestEventAt) ?> · <?= h($latestEventBy) ?></p>
            </div>
            <div>
              <strong>Ngày đăng / Ngày sửa</strong>
              <p><?= h((string) ($article['publish_date'] ?? '—')) ?> / <?= h((string) ($article['modified_date'] ?? '—')) ?></p>
            </div>
          </div>
        </article>

        <article class="admin-panel editor-side-card">
          <div class="panel-head">
            <h2>Lịch sử cập nhật gần đây</h2>
            <p>Danh sách lần cập nhật và khôi phục gần nhất.</p>
          </div>
          <?php if (empty($recentHistory)): ?>
            <div class="empty-state">
              <i class="fa-regular fa-folder-open"></i>
              <p>Chưa có lịch sử cập nhật cho bài này.</p>
            </div>
          <?php else: ?>
            <div class="editor-history-list">
              <?php foreach ($recentHistory as $row): ?>
                <?php
                if (!is_array($row)) {
                  continue;
                }
                $rowEvent = (string) ($row['event'] ?? '');
                if ($rowEvent === 'publish') {
                  $rowEventLabel = 'Cập nhật ra trang';
                } elseif ($rowEvent === 'rollback') {
                  $rowEventLabel = 'Khôi phục';
                } elseif ($rowEvent !== '') {
                  $rowEventLabel = ucfirst($rowEvent);
                } else {
                  $rowEventLabel = 'Không rõ';
                }
                $rowTime = format_admin_datetime((string) ($row['published_at'] ?? $row['rolled_back_at'] ?? ''));
                if ($rowTime === '') {
                  $rowTime = '—';
                }
                $rowActor = (string) (($row['actor']['username'] ?? '') ?: ($row['actor']['display_name'] ?? ''));
                if ($rowActor === '') {
                  $rowActor = '—';
                }
                ?>
                <article class="editor-history-item">
                  <div class="editor-history-head">
                    <span class="event-pill"><?= h($rowEventLabel) ?></span>
                    <span><?= h($rowTime) ?></span>
                  </div>
                  <p><?= h($rowActor) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <details class="advanced-section">
          <summary><i class="fa-solid fa-screwdriver-wrench"></i> Thông tin kỹ thuật nâng cao</summary>

          <div class="parser-audit-grid">
            <article class="metric-card">
              <span class="metric-icon success"><i class="fa-solid fa-shield-check"></i></span>
              <div class="metric-body">
                <h3><?= h((string) ($auditMeta['safe_count'] ?? 0)) ?></h3>
                <p>Bài xử lý đúng</p>
              </div>
            </article>
            <article class="metric-card">
              <span class="metric-icon warning"><i class="fa-solid fa-bug"></i></span>
              <div class="metric-body">
                <h3><?= h((string) ($auditMeta['fail_count'] ?? 0)) ?></h3>
                <p>Bài xử lý lỗi</p>
              </div>
            </article>
            <article class="metric-card">
              <span class="metric-icon info"><i class="fa-solid fa-percent"></i></span>
              <div class="metric-body">
                <h3><?= h(number_format($auditSafeRate, 2, ',', '.')) ?>%</h3>
                <p>Tỷ lệ xử lý đúng</p>
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
              <span>Chạy kiểm tra lại</span>
            </a>
          </p>

          <div class="parser-detail-grid">
            <article class="parser-detail-card">
              <h4>Vùng .article-prose</h4>
              <ul>
                <li>Vị trí bắt đầu: <code><?= h((string) ($prose['start'] ?? '')) ?></code></li>
                <li>Cuối thẻ mở: <code><?= h((string) ($prose['open_tag_end'] ?? '')) ?></code></li>
                <li>Đầu thẻ đóng: <code><?= h((string) ($prose['close_tag_start'] ?? '')) ?></code></li>
                <li>Vị trí kết thúc: <code><?= h((string) ($prose['end'] ?? '')) ?></code></li>
                <li>Độ dài nội dung: <code><?= h((string) ($prose['inner_length'] ?? '')) ?></code></li>
              </ul>
              <p><strong>Xem nhanh:</strong> <?= h(preview_text((string) ($prose['inner'] ?? ''))) ?></p>
            </article>

            <article class="parser-detail-card">
              <h4>Vùng article-meta</h4>
              <ul>
                <li>Vị trí bắt đầu: <code><?= h((string) ($meta['start'] ?? '')) ?></code></li>
                <li>Cuối thẻ mở: <code><?= h((string) ($meta['open_tag_end'] ?? '')) ?></code></li>
                <li>Đầu thẻ đóng: <code><?= h((string) ($meta['close_tag_start'] ?? '')) ?></code></li>
                <li>Vị trí kết thúc: <code><?= h((string) ($meta['end'] ?? '')) ?></code></li>
                <li>Độ dài nội dung: <code><?= h((string) ($meta['inner_length'] ?? '')) ?></code></li>
              </ul>
              <details>
                <summary>Xem dữ liệu article-meta hiện tại</summary>
                <pre class="json-preview"><?= h(pretty_json($metaPayload)) ?></pre>
              </details>
            </article>
          </div>

          <article class="admin-panel" style="margin-top:12px;">
            <div class="panel-head">
              <h2>Thông tin cập nhật và khôi phục</h2>
              <p>Theo dõi chi tiết lần thao tác gần nhất.</p>
            </div>
            <?php if (is_array($publishStatus) && !empty($publishStatus)): ?>
              <div class="json-preview" style="margin-top:10px;"><?= h(pretty_json(is_array($publishStatus['record'] ?? null) ? $publishStatus['record'] : $publishStatus)) ?></div>
            <?php endif; ?>
            <?php if (is_array($latestPublish)): ?>
              <div class="publish-status-grid">
                <p><strong>Lần thao tác gần nhất:</strong> <?= h((string) ($latestPublish['event'] ?? '')) ?></p>
                <p><strong>Thời gian:</strong> <?= h(format_admin_datetime((string) ($latestPublish['published_at'] ?? $latestPublish['rolled_back_at'] ?? ''))) ?></p>
                <p><strong>Bản sao lưu:</strong> <code><?= h((string) ($latestPublish['backup_path'] ?? $latestPublish['restored_from'] ?? '')) ?></code></p>
                <p><strong>Tệp đích:</strong> <code><?= h((string) ($latestPublish['target_path'] ?? '')) ?></code></p>
                <?php if (isset($latestPublish['hash_before'], $latestPublish['hash_after'])): ?>
                  <p><strong>Mã kiểm tra trước:</strong> <code><?= h((string) $latestPublish['hash_before']) ?></code></p>
                  <p><strong>Mã kiểm tra sau:</strong> <code><?= h((string) $latestPublish['hash_after']) ?></code></p>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="empty-state">
                <i class="fa-regular fa-folder-open"></i>
                <p>Chưa có dữ liệu cập nhật cho bài này.</p>
              </div>
            <?php endif; ?>

            <?php if (!empty($recentHistory)): ?>
              <div class="table-wrap">
                <table class="admin-table">
                  <thead>
                    <tr>
                      <th>Thời gian</th>
                      <th>Sự kiện</th>
                      <th>Người thao tác</th>
                      <th>Bản sao lưu/khôi phục</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentHistory as $row): ?>
                      <?php if (!is_array($row)) continue; ?>
                      <tr>
                        <td><?= h(format_admin_datetime((string) ($row['published_at'] ?? $row['rolled_back_at'] ?? ''))) ?></td>
                        <td><span class="event-pill"><?= h((string) ($row['event'] ?? '')) ?></span></td>
                        <td><?= h((string) (($row['actor']['username'] ?? '') ?: '—')) ?></td>
                        <td><code><?= h((string) ($row['backup_path'] ?? $row['restored_from'] ?? '')) ?></code></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </article>
        </details>
      </aside>
    </div>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<?php admin_layout_footer(); ?>
