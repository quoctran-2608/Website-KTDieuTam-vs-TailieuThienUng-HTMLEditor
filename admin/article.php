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

$id = trim((string) ($_GET['id'] ?? ''));
$article = find_article_index_item($id);

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
        } else {
          $status = [
            'type' => 'danger',
            'message' => 'Khôi phục thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
          ];
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
            } else {
              $status = [
                'type' => 'danger',
                'message' => 'Cập nhật thất bại: ' . (string) ($result['message'] ?? 'không rõ lỗi'),
              ];
            }
          } else {
            $saved = save_article_draft((string) ($article['id'] ?? ''), $clean, $currentUser);
            $draftCurrent = $saved;
            $reviewRow = mark_article_reviewed((string) ($article['id'] ?? ''), $currentUser, $intent);
            $form = array_merge($form, $clean);
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
  }
}

$innerScript = <<<'JS'
(() => {
  const form = document.getElementById('articleEditorForm');
  const intent = document.getElementById('articleIntent');
  const editor = document.getElementById('proseEditor');
  const host = document.getElementById('previewHost');
  if (!editor) return;

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
  'body_class' => 'admin-mode-simple-editor admin-editor-no-sidebar',
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
    </div>

    <?php if ($status !== null): ?>
      <div class="flash flash-<?= h((string) ($status['type'] ?? 'warning')) ?>">
        <?= h((string) ($status['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <?php
    $infoPanelOpen = !empty($validationErrors['excerpt'])
      || !empty($validationErrors['publish_date'])
      || !empty($validationErrors['modified_date'])
      || !empty($validationErrors['tags_text']);
    ?>
    <article class="admin-panel">
      <div class="panel-head">
        <h2>Soạn thảo nội dung</h2>
        <p>Giữ 2 thao tác chính ở trên cùng để biên tập nhanh.</p>
      </div>

      <form method="post" class="article-editor-form editor-v4-form" id="articleEditorForm" novalidate>
        <?= csrf_input_html() ?>
        <input type="hidden" name="_intent" value="save_draft" id="articleIntent">

        <div class="editor-action-bar">
          <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
            <i class="fa-solid fa-floppy-disk"></i>
            <span>Lưu</span>
          </button>
          <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận cập nhật bài này ra trang? Hệ thống sẽ sao lưu trước khi ghi file thật.');">
            <i class="fa-solid fa-paper-plane"></i>
            <span>Cập nhật ra trang</span>
          </button>
          <span class="editor-shortcut-hint">Ctrl+S để lưu nhanh.</span>
        </div>

        <label class="filter-field">
          <span>Tiêu đề *</span>
          <input type="text" name="title" value="<?= h((string) ($form['title'] ?? '')) ?>" required>
          <?php if (!empty($validationErrors['title'])): ?><small class="field-error"><?= h((string) $validationErrors['title']) ?></small><?php endif; ?>
        </label>

        <label class="filter-field">
          <span>Nội dung chính (.article-prose) *</span>
          <textarea id="proseEditor" name="prose_html" rows="20" class="prose-textarea" required><?= h((string) ($form['prose_html'] ?? '')) ?></textarea>
          <?php if (!empty($validationErrors['prose_html'])): ?><small class="field-error"><?= h((string) $validationErrors['prose_html']) ?></small><?php endif; ?>
        </label>

        <details class="editor-info-panel" <?= $infoPanelOpen ? 'open' : '' ?>>
          <summary>
            <i class="fa-solid fa-circle-info"></i>
            <span>Thông tin bài & tác vụ phụ</span>
          </summary>

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
              <span>Thẻ (3-7 thẻ, ngăn cách bằng dấu phẩy) *</span>
              <input type="text" name="tags_text" value="<?= h((string) ($form['tags_text'] ?? '')) ?>" required>
              <?php if (!empty($validationErrors['tags_text'])): ?><small class="field-error"><?= h((string) $validationErrors['tags_text']) ?></small><?php endif; ?>
            </label>
          </div>

          <div class="editor-rare-actions">
            <button type="submit" class="clear-filter-btn inline" onclick="document.getElementById('articleIntent').value='preview_only'">
              <i class="fa-solid fa-eye"></i>
              <span>Lưu và xem trước</span>
            </button>
            <button type="submit" class="rollback-btn inline" onclick="document.getElementById('articleIntent').value='rollback_latest'; return confirm('Xác nhận khôi phục từ bản sao lưu gần nhất?');">
              <i class="fa-solid fa-rotate-left"></i>
              <span>Khôi phục gần nhất</span>
            </button>
            <button type="submit" class="mark-unreviewed-btn inline" onclick="document.getElementById('articleIntent').value='mark_unreviewed'; return confirm('Đánh dấu bài này là Chưa sửa?');">
              <i class="fa-solid fa-rotate-left"></i>
              <span>Đánh dấu chưa sửa</span>
            </button>
          </div>

          <div class="editor-status-inline">
            <p><strong>Trạng thái:</strong> <?= h($reviewStatusLabel) ?><?= $reviewIsEdited ? (' · ' . h($reviewStatusAt) . ' · ' . h($reviewStatusBy)) : '' ?></p>
            <p><strong>Lần thao tác gần nhất:</strong> <?= h($latestEventLabel) ?> · <?= h($latestEventAt) ?> · <?= h($latestEventBy) ?></p>
            <p><strong>Đường dẫn:</strong> <code><?= h((string) ($article['href'] ?? '')) ?></code></p>
          </div>
        </details>
      </form>
    </article>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<?php admin_layout_footer(); ?>
