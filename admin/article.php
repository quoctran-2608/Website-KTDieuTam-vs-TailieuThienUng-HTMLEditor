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
  if (count($tags) < 2) {
    $errors['tags_text'] = 'Cần tối thiểu 2 tag.';
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

  $featuredImage = trim((string) ($payload['featured_image'] ?? ''));
  $clean['featured_image'] = $featuredImage;

  return [
    'ok' => empty($errors),
    'errors' => $errors,
    'data' => $clean,
  ];
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
          'prose_html' => (string) ($_POST['prose_html'] ?? ''),
          'featured_image' => (string) ($_POST['featured_image'] ?? ''),
        ];
        $validated = validate_draft_payload($posted);
        $form = array_merge($form, $posted);

        if (!empty($validated['ok'])) {
          $clean = is_array($validated['data'] ?? null) ? $validated['data'] : [];
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
                'message' => 'Đã lưu nháp.',
              ];
            } elseif ($intent === 'preview_only') {
              $status = [
                'type' => 'success',
                'message' => 'Đã lưu nháp.',
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
      images_file_types: 'jpg,jpeg,png,gif,webp',
      relative_urls: false,
      remove_script_host: false,
      convert_urls: false,
      plugins: 'advlist autolink lists link image table code charmap preview searchreplace visualblocks wordcount paste',
      toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image | removeformat code preview',
      content_style: 'body { font-family: Arial, sans-serif; font-size: 16px; line-height: 1.65; padding: 14px; } img { max-width: 100%; height: auto; }',
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
      $reviewStatusLabel = 'Lưu nháp';
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
      $appendTaxonomyItem('Cấp 4', (string) ($form['topic_lv3_label'] ?? ''), (string) ($form['topic_lv3_key'] ?? ''));
    } else {
      $appendTaxonomyItem('Cấp 1', (string) ($form['topic_lv1_label'] ?? ''), (string) ($form['topic_lv1_key'] ?? ''));
      $appendTaxonomyItem('Cấp 2', (string) ($form['topic_lv2_label'] ?? ''), (string) ($form['topic_lv2_key'] ?? ''));
      $appendTaxonomyItem('Cấp 3', (string) ($form['topic_lv3_label'] ?? ''), (string) ($form['topic_lv3_key'] ?? ''));
    }
    $currentTags = is_array($form['tags'] ?? null) ? array_values(array_filter(array_map('strval', $form['tags']))) : [];
    ?>

    <div class="editor-top-actions">
      <div class="editor-top-actions-row">
        <a class="clear-filter-btn inline" href="<?= h($listReturnUrl) ?>">
          <i class="fa-solid fa-arrow-left"></i>
          <span>Về danh sách bài</span>
        </a>
        <?php if ((string) ($currentUser['role'] ?? '') === 'admin'): ?>
          <form method="post" action="<?= h(admin_url('delete_article.php')) ?>" class="inline-action-form" onsubmit="return confirm('Xác nhận xóa bài này?\\n- Bài viết HTML sẽ bị xóa\\n- Ảnh upload liên quan cũng sẽ bị xóa\\n- Hành động này không hoàn tác tự động.');">
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
      || !empty($validationErrors['modified_date'])
      || !empty($validationErrors['tags_text']);
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

          <div class="editor-action-bar">
            <button type="submit" class="filter-submit-btn" onclick="document.getElementById('articleIntent').value='save_draft'">
              <i class="fa-solid fa-floppy-disk"></i>
              <span>Lưu nháp</span>
            </button>
            <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận cập nhật bài này ra trang? Hệ thống sẽ sao lưu trước khi ghi file thật.');">
              <i class="fa-solid fa-paper-plane"></i>
              <span>Đăng ngay</span>
            </button>
            <a class="clear-filter-btn inline" href="<?= h(article_public_url_detail($article)) ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-up-right-from-square"></i>
              <span>Xem bài</span>
            </a>
            <span class="editor-shortcut-hint">Ctrl+S để lưu nháp nhanh.</span>
          </div>

          <label class="filter-field">
            <span>Tiêu đề *</span>
            <input type="text" name="title" value="<?= h((string) ($form['title'] ?? '')) ?>" required>
            <?php if (!empty($validationErrors['title'])): ?><small class="field-error"><?= h((string) $validationErrors['title']) ?></small><?php endif; ?>
          </label>

          <label class="filter-field">
            <span>Nội dung bài viết *</span>
            <textarea id="proseEditor" name="prose_html" rows="20" class="prose-textarea" required><?= h((string) ($form['prose_html'] ?? '')) ?></textarea>
            <?php if (!empty($validationErrors['prose_html'])): ?><small class="field-error"><?= h((string) $validationErrors['prose_html']) ?></small><?php endif; ?>
          </label>

	          <details class="editor-info-panel" <?= $infoPanelOpen ? 'open' : '' ?>>
	            <summary>
	              <i class="fa-solid fa-circle-info"></i>
	              <span>Thông tin bài & tác vụ phụ</span>
	            </summary>

            <div class="editor-taxonomy-card">
              <div class="editor-taxonomy-card__head">
                <strong>Phân loại hiện tại</strong>
                <small>Đọc từ article-meta/data articles, dùng để đối chiếu cây menu 4 cấp.</small>
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
                <span>Thẻ (2-7 thẻ, ngăn cách bằng dấu phẩy) *</span>
                <input type="text" name="tags_text" value="<?= h((string) ($form['tags_text'] ?? '')) ?>" required>
                <?php if (!empty($validationErrors['tags_text'])): ?><small class="field-error"><?= h((string) $validationErrors['tags_text']) ?></small><?php endif; ?>
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
              <span>Lưu nháp</span>
            </button>
            <button type="submit" class="publish-btn inline" onclick="document.getElementById('articleIntent').value='publish_now'; return confirm('Xác nhận cập nhật bài này ra trang? Hệ thống sẽ sao lưu trước khi ghi file thật.');">
              <i class="fa-solid fa-paper-plane"></i>
              <span>Đăng ngay</span>
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
                  ?>
                  <article class="editor-history-item">
                    <div class="editor-history-head">
                      <strong><?= h($eventLabel) ?></strong>
                      <span><?= h($eventAt) ?></span>
                    </div>
                    <p>Người thao tác: <?= h($eventBy) ?></p>
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
