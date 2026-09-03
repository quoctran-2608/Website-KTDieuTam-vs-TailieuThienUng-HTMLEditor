<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$currentUserId = (string) $currentUser['user_id'];
$articleId = trim((string) ($_GET['id'] ?? $_POST['article_id'] ?? ''));

// ─── Validate article ────────────────────────────────────────────

if ($articleId === '') {
    editorial_flash_set('danger', 'Thiếu mã bài viết.');
    editorial_redirect(editorial_url('my-work.php'));
}

$article = editorial_find_article($articleId);
if ($article === null) {
    editorial_flash_set('danger', 'Không tìm thấy bài viết.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Authorization: must be owner, editable status ───────────────

$state = editorial_get_article_state($articleId);
if ($state === null || (string) ($state['assigned_user_id'] ?? '') !== $currentUserId) {
    $ownerName = '';
    if ($state !== null && !empty($state['assigned_user_id'])) {
        $owner = editorial_find_user_by_id((string) $state['assigned_user_id']);
        $ownerName = $owner ? (string) $owner['display_name'] : 'người khác';
    }
    $msg = $ownerName !== ''
        ? 'Bài viết hiện đang được ' . $ownerName . ' phụ trách.'
        : 'Bạn chưa nhận biên tập bài này.';
    editorial_flash_set('warning', $msg);
    editorial_redirect(editorial_url('articles.php'));
}

$articleStatus = (string) ($state['status'] ?? 'available');
$editableStatuses = ['editing', 'returned'];
if (!in_array($articleStatus, $editableStatuses, true)) {
    editorial_flash_set('info', 'Bài viết ở trạng thái "' . editorial_status_label($articleStatus) . '" không thể chỉnh sửa.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Resolve HTML file ───────────────────────────────────────────

$htmlPath = editorial_resolve_article_path($article);
if ($htmlPath === null) {
    editorial_flash_set('danger', 'Không thể đọc file HTML gốc.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Handle POST actions ─────────────────────────────────────────

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    if ($intent === 'save_draft') {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        $expectedVersion = (int) ($_POST['expected_draft_version'] ?? 0);

        // FIX A2: Strict base64 decode
        $proseHtml = null;
        if (isset($_POST['prose_html_b64']) && $_POST['prose_html_b64'] !== '') {
            $decoded = base64_decode((string) $_POST['prose_html_b64'], true);
            if ($decoded === false) {
                editorial_flash_set('danger', 'Dữ liệu nội dung gửi lên không hợp lệ. Bản nháp chưa được lưu.');
                editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
            }
            $proseHtml = $decoded;
        } else {
            $proseHtml = (string) ($_POST['prose_html'] ?? '');
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            editorial_flash_set('danger', 'Tiêu đề không được để trống.');
            editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
        }

        // FIX A1: Editable fields only from POST. Taxonomy from server-side.
        $editablePost = [
            'title' => $title,
            'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
            'prose_html' => $proseHtml,
            'publish_date' => trim((string) ($_POST['publish_date'] ?? '')),
            'modified_date' => trim((string) ($_POST['modified_date'] ?? '')),
            'featured_image' => trim((string) ($_POST['featured_image'] ?? '')),
            'tags_text' => trim((string) ($_POST['tags_text'] ?? '')),
        ];

        // Get existing draft payload for taxonomy preservation
        $existingDraft = editorial_get_draft($articleId, $currentUserId);
        $existingPayload = $existingDraft ? ($existingDraft['payload'] ?? null) : null;

        $payload = editorial_merge_draft_payload($editablePost, $article, $existingPayload);

        $baseLiveHash = (string) ($state['base_live_hash'] ?? '');
        $result = editorial_save_draft($articleId, $currentUserId, $payload, $baseLiveHash, $expectedVersion, $lockToken);

        editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }

    if ($intent === 'exit_workspace') {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        editorial_release_article_lock($articleId, $currentUserId, $lockToken);
        editorial_flash_set('info', 'Đã thoát workspace biên tập.');
        editorial_redirect(editorial_url('my-work.php'));
    }

    if ($intent === 'send_for_review') {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        $result = editorial_send_for_review($articleId, $currentUserId, $lockToken);
        editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        if ($result['ok']) {
            editorial_redirect(editorial_url('my-work.php'));
        }
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }

    if (in_array($intent, ['create_stage1_revision', 'create_stage2_revision'], true)) {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        $expectedVersion = (int) ($_POST['expected_draft_version'] ?? 0);
        $revisionNote = trim((string) ($_POST['revision_note'] ?? ''));
        $milestoneKey = $intent === 'create_stage1_revision' ? 'stage1' : 'stage2';

        $result = editorial_create_stage_milestone_revision($articleId, $currentUserId, $lockToken, $expectedVersion, $milestoneKey, $revisionNote);

        editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message'] ?? 'Không thể lưu mốc phiên bản.');
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }

    editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
}

// ─── Acquire lock ────────────────────────────────────────────────

$lockResult = editorial_acquire_article_lock($articleId, $currentUserId);
if (!$lockResult['ok']) {
    editorial_flash_set('warning', $lockResult['message']);
    editorial_redirect(editorial_url('my-work.php'));
}
$lockToken = $lockResult['lock_token'];
$lockExpires = $lockResult['expires_at'];

// ─── Lazy baseline creation (Phase 5) ───────────────────────────

$assignment = editorial_get_active_assignment($articleId);
if ($assignment) {
    $existingBaseline = editorial_get_article_revisions($articleId, 50);
    $hasBaseline = false;
    foreach ($existingBaseline as $rev) {
        if (($rev['assignment_id'] ?? '') === $assignment['id'] && $rev['revision_type'] === 'baseline') {
            $hasBaseline = true;
            break;
        }
    }
    if (!$hasBaseline) {
        $baselineResult = editorial_create_baseline_revision($articleId, $currentUserId);
        // Silently log if skipped due to conflict (no flash spam)
    }
}

// ─── Load draft or parse live HTML ───────────────────────────────

$draft = editorial_get_draft($articleId, $currentUserId);
$draftVersion = 0;
$draftSavedAt = null;

if ($draft !== null) {
    $form = $draft['payload'];
    $draftVersion = (int) ($draft['version'] ?? 0);
    $draftSavedAt = (string) ($draft['updated_at'] ?? '');
} else {
    $parsed = editorial_parse_article_file($htmlPath);
    if (!$parsed['ok']) {
        editorial_flash_set('danger', 'Không thể parse file HTML: ' . ($parsed['message'] ?? ''));
        editorial_redirect(editorial_url('my-work.php'));
    }
    $form = editorial_build_initial_payload($parsed, $article, $parsed['meta_payload'] ?? []);
}

// ─── Live hash conflict check ────────────────────────────────────

$currentLiveHash = editorial_live_hash($htmlPath);
$baseLiveHash = (string) ($state['base_live_hash'] ?? '');
$liveHashConflict = ($baseLiveHash !== '' && $currentLiveHash !== null && $currentLiveHash !== $baseLiveHash);

// ─── Recent revisions (Phase 5) ─────────────────────────────────

$recentRevisions = editorial_get_article_revisions($articleId, 5);
$assignmentMilestones = $assignment
    ? editorial_get_assignment_milestones($articleId, (string) $assignment['id'])
    : ['stage1' => null, 'stage2' => null];
$assignmentBaseline = null;
foreach (editorial_get_article_revisions($articleId, 50) as $revision) {
    if (($revision['assignment_id'] ?? '') === ($assignment['id'] ?? '')
        && ($revision['revision_type'] ?? '') === 'baseline') {
        $assignmentBaseline = $revision;
        break;
    }
}

// ─── Public article URL ──────────────────────────────────────────

$publicUrl = editorial_public_article_url($article);

// ─── Render ──────────────────────────────────────────────────────

$siteBaseUrl = editorial_site_url('');

$innerScript = <<<JS
(() => {
  const form = document.getElementById('editorialEditorForm');
  const editor = document.getElementById('proseEditor');
  const host = document.getElementById('previewHost');
  if (!editor) return;
  let draftDirty = false;
  let editorReady = false;

  function markDraftDirty() { draftDirty = true; }

  const siteBaseUrl = window.location.origin + window.location.pathname.replace(/\/editorial\/.*$/, '/');

  /* ── Preview sync ─────────────────────────────────── */
  function syncPreview() {
    if (!host) return;
    let html = '';
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const instance = window.tinymce.get('proseEditor');
      if (instance) {
        html = instance.getContent();
      }
    }
    if (!html) {
      html = editor.value || '';
    }
    if (!html) {
      host.innerHTML = '<p><em>Chưa có nội dung preview.</em></p>';
      return;
    }
    html = html.replace(
      /(src=["'])(?!https?:\/\/|\/|data:|blob:)([^"']+)(["'])/gi,
      (_, pre, url, post) => pre + siteBaseUrl + url + post
    );
    host.innerHTML = html;
  }

  /* ── Base64 encode on submit ──────────────────────── */
  form.addEventListener('submit', (e) => {
    if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
      window.tinymce.triggerSave();
    }
    const b64Field = document.getElementById('proseHtmlB64');
    try {
      const raw = editor.value || '';
      b64Field.value = btoa(unescape(encodeURIComponent(raw)));
      editor.removeAttribute('name');
    } catch (err) {
      editor.setAttribute('name', 'prose_html');
      b64Field.value = '';
    }
  });

  form.querySelectorAll('input:not([type="hidden"]), textarea:not(#proseEditor)').forEach((field) => {
    field.addEventListener('input', markDraftDirty);
    field.addEventListener('change', markDraftDirty);
  });
  document.querySelectorAll('button[form="stage1MilestoneForm"], button[form="stage2MilestoneForm"], button[form="sendReviewForm"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!draftDirty) return;
      event.preventDefault();
      window.alert('Nội dung trên màn hình có thể chưa được lưu. Hãy Lưu nháp trước khi hoàn tất chặng hoặc gửi duyệt.');
    });
  });

  /* ── Ctrl+S save draft ────────────────────────────── */
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  /* ── Fullscreen toggle ────────────────────────────── */
  let isFullscreen = false;
  const fsToggle = document.getElementById('editorFullscreenToggle');

  function enterFullscreen() {
    isFullscreen = true;
    document.body.classList.add('editor-fullscreen-active');
    if (fsToggle) fsToggle.title = 'Thu nhỏ (Ctrl+Shift+F)';
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
  }

  function exitFullscreen() {
    isFullscreen = false;
    document.body.classList.remove('editor-fullscreen-active');
    if (fsToggle) fsToggle.title = 'Toàn màn hình (Ctrl+Shift+F)';
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const inst = window.tinymce.get('proseEditor');
      if (inst) inst.getBody().style.minHeight = '';
    }
  }

  function toggleFullscreen() { isFullscreen ? exitFullscreen() : enterFullscreen(); }

  if (fsToggle) fsToggle.addEventListener('click', toggleFullscreen);
  const fsToggleBottom = document.getElementById('editorFullscreenToggleBottom');
  if (fsToggleBottom) fsToggleBottom.addEventListener('click', toggleFullscreen);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isFullscreen) { e.preventDefault(); exitFullscreen(); }
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') { e.preventDefault(); toggleFullscreen(); }
  });

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

  /* ── TinyMCE init ─────────────────────────────────── */
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
        siteBaseUrl + 'assets/css/editorial-official-form-rhythm.css',
      ],
      body_class: 'ct-prose is-article mce-content-body',
      content_style: 'body { font-family: "Google Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 17.5px; line-height: 1.78; color: #33322C; padding: 18px 22px; -webkit-font-smoothing: antialiased; } img { max-width: 100%; height: auto; }',
      images_upload_handler: async (blobInfo, progress) => {
        throw new Error('Upload ảnh chưa được hỗ trợ trong Editorial V2. Vui lòng sử dụng Admin legacy hoặc URL ảnh có sẵn.');
      },
      setup: (instance) => {
        instance.on('init', () => { editorReady = true; });
        instance.on('input change keyup', () => {
          if (editorReady) markDraftDirty();
          if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
          window.__previewTimer = window.setTimeout(syncPreview, 100);
        });
        instance.on('keydown', (e) => {
          if (e.key === 'Escape' && isFullscreen) { e.preventDefault(); exitFullscreen(); return; }
          if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') { e.preventDefault(); toggleFullscreen(); return; }
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); form.requestSubmit(); }
        });
      }
    });
  }

  syncPreview();

  /* ── Heartbeat ────────────────────────────────────── */
  const lockTokenField = document.getElementById('lockTokenField');
  const csrfField = form ? form.querySelector('input[name="_csrf_token"]') : null;
  const articleIdField = document.getElementById('articleIdField');
  const lockStatus = document.getElementById('lockStatusText');
  let heartbeatFails = 0;

  setInterval(async () => {
    if (!lockTokenField || !csrfField || !articleIdField) return;
    try {
      const body = new URLSearchParams();
      body.append('_csrf_token', csrfField.value);
      body.append('article_id', articleIdField.value);
      body.append('lock_token', lockTokenField.value);

      const res = await fetch('lock-heartbeat.php', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (json.ok) {
        heartbeatFails = 0;
        if (lockStatus) lockStatus.textContent = 'Đang hoạt động';
        if (lockStatus) lockStatus.className = 'editorial-lock-ok';
      } else {
        heartbeatFails++;
        if (lockStatus) lockStatus.textContent = 'Khóa hết hạn';
        if (lockStatus) lockStatus.className = 'editorial-lock-expired';
      }
    } catch (err) {
      heartbeatFails++;
      if (heartbeatFails >= 3 && lockStatus) {
        lockStatus.textContent = 'Mất kết nối';
        lockStatus.className = 'editorial-lock-lost';
      }
    }
  }, 60000);
})();
JS;

editorial_layout_header([
    'title' => 'Biên tập bài viết',
    'active' => 'my-work',
    'description' => editorial_h($article['title']),
    'inner_script' => $innerScript,
    'body_class' => 'admin-mode-simple-editor admin-editor-hide-left-sidebar',
]);
?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<section class="admin-panel">
    <div class="panel-head">
        <h2><?= editorial_h($article['title']) ?></h2>
        <p>
            <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($articleStatus)) ?>">
                <?= editorial_h(editorial_status_label($articleStatus)) ?>
            </span>
            &nbsp;
            <span class="editorial-lock-indicator">
                Khóa: <span id="lockStatusText" class="editorial-lock-ok">Đang hoạt động</span>
            </span>
            &nbsp;
            <?php if ($draftSavedAt): ?>
                <span style="color:#868e96;">Nháp lưu lúc: <?= editorial_h(editorial_format_datetime($draftSavedAt)) ?></span>
            <?php else: ?>
                <span style="color:#868e96;">Chưa lưu nháp</span>
            <?php endif; ?>
            &nbsp;
            <a href="<?= editorial_h($publicUrl) ?>" target="_blank" rel="noopener" style="font-size:0.85rem;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem trên website
            </a>
        </p>
    </div>

    <?php if ($liveHashConflict): ?>
        <div class="flash flash-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            File HTML trên website đã thay đổi kể từ khi bạn nhận bài. Bạn vẫn có thể xem/lưu bản nháp, nhưng Publish sau này sẽ phải xử lý xung đột trước.
        </div>
    <?php endif; ?>

    <form id="editorialEditorForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="save_draft">
        <input type="hidden" name="article_id" id="articleIdField" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" id="lockTokenField" value="<?= editorial_h($lockToken) ?>">
        <input type="hidden" name="expected_draft_version" id="expectedDraftVersion" value="<?= editorial_h((string) $draftVersion) ?>">
        <input type="hidden" name="prose_html_b64" id="proseHtmlB64" value="">

        <!-- Action bar -->
        <div class="editor-action-bar">
            <button type="submit" class="editorial-save-btn">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Lưu nháp</span>
            </button>

            <?php if ($draftVersion > 0): ?>
            <button type="submit" form="stage1MilestoneForm" class="editorial-revision-btn editorial-stage1-btn" title="Lưu một bản cố định sau khi đã làm sạch và chuẩn hóa HTML/trình bày." onclick="return confirm('Lưu Chặng 1 từ bản nháp đã lưu?');">
                <i class="fa-solid fa-code-branch"></i>
                <span>Hoàn tất Chặng 1</span>
            </button>
            <span class="editorial-stage-helper">Chuẩn hóa trình bày</span>
            <button type="submit" form="stage2MilestoneForm" class="editorial-revision-btn editorial-stage2-btn" title="Lưu một bản cố định sau khi đã research, cập nhật và biên tập nội dung." onclick="return confirm('Lưu Chặng 2 từ bản nháp đã lưu?');">
                <i class="fa-solid fa-pen-to-square"></i>
                <span>Hoàn tất Chặng 2</span>
            </button>
            <span class="editorial-stage-helper">Biên tập nội dung</span>
            <?php endif; ?>

            <?php if ($draftVersion > 0 && ($assignmentMilestones['stage1'] || $assignmentMilestones['stage2'])): ?>
            <button type="submit" form="sendReviewForm" class="editorial-review-submit-btn" onclick="return confirm('Gửi phiên bản đã chốt để duyệt? Bạn sẽ không thể chỉnh sửa cho đến khi reviewer phản hồi.');">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Gửi duyệt</span>
            </button>
            <?php endif; ?>

            <button type="button" class="editorial-fullscreen-btn" id="editorFullscreenToggle" title="Toàn màn hình (Ctrl+Shift+F)">
                <i class="fa-solid fa-expand"></i>
            </button>

            <span class="editor-shortcut-hint">Ctrl+S lưu nháp · Ctrl+Shift+F toàn màn hình</span>

            <button type="submit" form="exitWorkspaceForm" class="editorial-exit-btn" onclick="return confirm('Thoát workspace? Nội dung chưa lưu sẽ mất.');">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Thoát workspace</span>
            </button>
        </div>

        <div class="editor-fullscreen-backdrop" id="editorFullscreenBackdrop"></div>
        <div class="editor-fs-status" id="editorFsStatus">
            <span>Esc hoặc Ctrl+Shift+F để thoát toàn màn hình</span>
            <button type="button" id="editorFullscreenToggleBottom" title="Thu nhỏ">
                <i class="fa-solid fa-compress"></i>
            </button>
        </div>

        <!-- Title -->
        <div class="filter-field" style="margin-bottom:12px;">
            <label for="titleInput">Tiêu đề</label>
            <input type="text" id="titleInput" name="title" value="<?= editorial_h((string) ($form['title'] ?? '')) ?>" required class="field-input" style="font-size:1.1rem;font-weight:600;">
        </div>

        <!-- TinyMCE editor -->
        <textarea id="proseEditor" name="prose_html" class="prose-textarea" required style="min-height:400px;"><?= editorial_h((string) ($form['prose_html'] ?? '')) ?></textarea>

        <!-- Preview -->
        <details class="editor-info-panel" style="margin-top:16px;">
            <summary><i class="fa-solid fa-eye"></i> Xem trước nội dung</summary>
            <div id="previewHost" class="ct-prose is-article" style="padding:16px;border:1px solid #dee2e6;border-radius:8px;margin-top:8px;"></div>
        </details>

        <!-- Meta fields -->
        <details class="editor-info-panel" style="margin-top:12px;" open>
            <summary><i class="fa-solid fa-circle-info"></i> Thông tin bài viết</summary>
            <div class="editorial-meta-grid" style="margin-top:12px;">
                <div class="filter-field">
                    <label>Mô tả ngắn</label>
                    <input type="text" name="excerpt" value="<?= editorial_h((string) ($form['excerpt'] ?? '')) ?>" class="field-input">
                </div>
                <div class="filter-field">
                    <label>Ngày đăng</label>
                    <input type="date" name="publish_date" value="<?= editorial_h((string) ($form['publish_date'] ?? '')) ?>" class="field-input">
                </div>
                <div class="filter-field">
                    <label>Ngày sửa</label>
                    <input type="date" name="modified_date" value="<?= editorial_h((string) ($form['modified_date'] ?? '')) ?>" class="field-input">
                </div>
                <div class="filter-field">
                    <label>Tags (phân cách bằng dấu phẩy)</label>
                    <input type="text" name="tags_text" value="<?= editorial_h((string) ($form['tags_text'] ?? '')) ?>" class="field-input">
                </div>
                <div class="filter-field">
                    <label>Ảnh đại diện</label>
                    <input type="text" name="featured_image" value="<?= editorial_h((string) ($form['featured_image'] ?? '')) ?>" class="field-input" placeholder="Đường dẫn ảnh">
                </div>
                <div class="filter-field">
                    <label>Mục (section) — chỉ đọc</label>
                    <input type="text" value="<?= editorial_h((string) ($form['section_label'] ?? ($form['section_key'] ?? ''))) ?>" class="field-input" readonly style="background:#f8f9fa;">
                </div>
            </div>
        </details>

        <!-- Revision history panel (Phase 5) -->
        <details class="editor-info-panel" style="margin-top:12px;">
            <summary><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử phiên bản</summary>
            <div style="padding:14px;">
                <?php if (empty($recentRevisions)): ?>
                    <p style="color:#868e96;">Chưa có phiên bản nào. Lưu nháp rồi hoàn tất Chặng 1 hoặc Chặng 2 để tạo bản cố định.</p>
                <?php else: ?>
                    <table class="admin-table" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Loại</th>
                                <th>Người tạo</th>
                                <th>Thời gian</th>
                                <th>Hash</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRevisions as $rev): ?>
                                <tr>
                                    <td><?= editorial_h((string) $rev['revision_no']) ?></td>
                                    <td><?= editorial_h(editorial_revision_label($rev)) ?></td>
                                    <td><?= editorial_h((string) ($rev['creator_name'] ?? $rev['created_by'])) ?></td>
                                    <td><?= editorial_h(editorial_format_datetime((string) $rev['created_at'])) ?></td>
                                    <td><code><?= editorial_h(substr((string) ($rev['content_hash'] ?? ''), 0, 8)) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:8px;">
                        <a href="<?= editorial_h(editorial_url('revisions.php?id=' . urlencode($articleId))) ?>">
                            <i class="fa-solid fa-list"></i> Xem toàn bộ lịch sử
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </details>

        <!-- Bottom actions -->
        <div class="editorial-bottom-actions">
            <button type="submit" class="editorial-save-btn">
                <i class="fa-solid fa-floppy-disk"></i> Lưu nháp
            </button>
            <?php if ($draftVersion > 0): ?>
            <button type="submit" form="stage1MilestoneForm" class="editorial-revision-btn editorial-stage1-btn" onclick="return confirm('Lưu Chặng 1 từ bản nháp đã lưu?');">
                <i class="fa-solid fa-code-branch"></i> Hoàn tất Chặng 1
            </button>
            <button type="submit" form="stage2MilestoneForm" class="editorial-revision-btn editorial-stage2-btn" onclick="return confirm('Lưu Chặng 2 từ bản nháp đã lưu?');">
                <i class="fa-solid fa-pen-to-square"></i> Hoàn tất Chặng 2
            </button>
            <?php endif; ?>
            <?php if ($draftVersion > 0 && ($assignmentMilestones['stage1'] || $assignmentMilestones['stage2'])): ?>
            <button type="submit" form="sendReviewForm" class="editorial-review-submit-btn" onclick="return confirm('Gửi duyệt?');">
                <i class="fa-solid fa-paper-plane"></i> Gửi duyệt
            </button>
            <?php endif; ?>
            <button type="submit" form="exitWorkspaceForm" class="editorial-exit-btn" onclick="return confirm('Thoát workspace?');">
                <i class="fa-solid fa-right-from-bracket"></i> Thoát
            </button>
        </div>
    </form>

    <div class="editorial-workflow-help">
        <strong>Quy trình:</strong> 1. Lưu nháp · 2. Hoàn tất Chặng 1 — Chuẩn hóa trình bày ·
        3. Hoàn tất Chặng 2 — Biên tập nội dung · 4. Gửi Admin duyệt.
        <span>Sau khi chốt bản cuối, bài có thể gửi Admin duyệt.</span>
    </div>

    <form id="stage1MilestoneForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="create_stage1_revision">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
        <input type="hidden" name="expected_draft_version" value="<?= editorial_h((string) $draftVersion) ?>">
    </form>
    <form id="stage2MilestoneForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="create_stage2_revision">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
        <input type="hidden" name="expected_draft_version" value="<?= editorial_h((string) $draftVersion) ?>">
    </form>
    <form id="sendReviewForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="send_for_review">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
    </form>
    <form id="exitWorkspaceForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="exit_workspace">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
    </form>

    <?php if ($assignmentBaseline || $assignmentMilestones['stage1'] || $assignmentMilestones['stage2']): ?>
        <div class="editorial-milestone-links">
            <?php if ($assignmentBaseline && $assignmentMilestones['stage1']): ?>
                <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $assignmentBaseline['id']) . '&to=' . urlencode((string) $assignmentMilestones['stage1']['id'])) ?>">So với bản gốc</a>
            <?php endif; ?>
            <?php if ($assignmentBaseline && $assignmentMilestones['stage2']): ?>
                <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $assignmentBaseline['id']) . '&to=' . urlencode((string) $assignmentMilestones['stage2']['id'])) ?>">Bản gốc ↔ Chặng 2</a>
            <?php endif; ?>
            <?php if ($assignmentMilestones['stage1'] && $assignmentMilestones['stage2']): ?>
                <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $assignmentMilestones['stage1']['id']) . '&to=' . urlencode((string) $assignmentMilestones['stage2']['id'])) ?>">So Chặng 1 với Chặng 2</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php editorial_layout_footer(); ?>
