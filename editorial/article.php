<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
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

        // Decode prose_html (base64 workaround for WAF)
        $proseHtml = (isset($_POST['prose_html_b64']) && $_POST['prose_html_b64'] !== '')
            ? (string) base64_decode((string) $_POST['prose_html_b64'], true)
            : (string) ($_POST['prose_html'] ?? '');

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            editorial_flash_set('danger', 'Tiêu đề không được để trống.');
            editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
        }

        $payload = [
            'title' => $title,
            'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
            'prose_html' => $proseHtml,
            'publish_date' => trim((string) ($_POST['publish_date'] ?? '')),
            'modified_date' => trim((string) ($_POST['modified_date'] ?? '')),
            'featured_image' => trim((string) ($_POST['featured_image'] ?? '')),
            'tags_text' => trim((string) ($_POST['tags_text'] ?? '')),
            'section_key' => trim((string) ($_POST['section_key'] ?? '')),
            'topic_lv1_key' => trim((string) ($_POST['topic_lv1_key'] ?? '')),
            'topic_lv2_key' => trim((string) ($_POST['topic_lv2_key'] ?? '')),
        ];

        $baseLiveHash = (string) ($state['base_live_hash'] ?? '');
        $result = editorial_save_draft($articleId, $currentUserId, $payload, $baseLiveHash, $expectedVersion, $lockToken);

        editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }

    if ($intent === 'exit_workspace') {
        editorial_release_article_lock($articleId, $currentUserId);
        editorial_flash_set('info', 'Đã thoát workspace biên tập.');
        editorial_redirect(editorial_url('my-work.php'));
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

// ─── Load draft or parse live HTML ───────────────────────────────

$draft = editorial_get_draft($articleId, $currentUserId);
$draftVersion = 0;
$draftSavedAt = null;

if ($draft !== null) {
    $form = $draft['payload'];
    $draftVersion = (int) ($draft['version'] ?? 0);
    $draftSavedAt = (string) ($draft['updated_at'] ?? '');
} else {
    // Parse live HTML to create initial form data
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

// ─── Public article URL ──────────────────────────────────────────

$publicUrl = editorial_public_article_url($article);

// ─── Render ──────────────────────────────────────────────────────

$siteBaseUrl = editorial_site_url('');

$innerScript = <<<JS
(() => {
  const form = document.getElementById('editorialEditorForm');
  const intent = document.getElementById('editorialIntent');
  const editor = document.getElementById('proseEditor');
  const host = document.getElementById('previewHost');
  if (!editor) return;

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

  /* ── Ctrl+S save draft ────────────────────────────── */
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      if (intent) intent.value = 'save_draft';
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
        instance.on('input change keyup setcontent', () => {
          if (window.__previewTimer) window.clearTimeout(window.__previewTimer);
          window.__previewTimer = window.setTimeout(syncPreview, 100);
        });
        instance.on('keydown', (e) => {
          if (e.key === 'Escape' && isFullscreen) { e.preventDefault(); exitFullscreen(); return; }
          if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') { e.preventDefault(); toggleFullscreen(); return; }
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); intent.value = 'save_draft'; form.requestSubmit(); }
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
        <input type="hidden" name="_intent" id="editorialIntent" value="save_draft">
        <input type="hidden" name="article_id" id="articleIdField" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" id="lockTokenField" value="<?= editorial_h($lockToken) ?>">
        <input type="hidden" name="expected_draft_version" value="<?= editorial_h((string) $draftVersion) ?>">
        <input type="hidden" name="prose_html_b64" id="proseHtmlB64" value="">

        <!-- Action bar -->
        <div class="editor-action-bar">
            <button type="submit" class="editorial-save-btn" onclick="document.getElementById('editorialIntent').value='save_draft'">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Lưu nháp</span>
            </button>

            <button type="button" class="editorial-fullscreen-btn" id="editorFullscreenToggle" title="Toàn màn hình (Ctrl+Shift+F)">
                <i class="fa-solid fa-expand"></i>
            </button>

            <span class="editor-shortcut-hint">Ctrl+S lưu nháp · Ctrl+Shift+F toàn màn hình</span>

            <button type="submit" class="editorial-exit-btn" onclick="document.getElementById('editorialIntent').value='exit_workspace'; return confirm('Thoát workspace? Nội dung chưa lưu sẽ mất.');">
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
                    <label>Mục (section)</label>
                    <input type="text" name="section_key" value="<?= editorial_h((string) ($form['section_key'] ?? '')) ?>" class="field-input" readonly style="background:#f8f9fa;">
                </div>
            </div>
        </details>

        <!-- Bottom actions -->
        <div class="editorial-bottom-actions">
            <button type="submit" class="editorial-save-btn" onclick="document.getElementById('editorialIntent').value='save_draft'">
                <i class="fa-solid fa-floppy-disk"></i> Lưu nháp
            </button>
            <button type="submit" class="editorial-exit-btn" onclick="document.getElementById('editorialIntent').value='exit_workspace'; return confirm('Thoát workspace?');">
                <i class="fa-solid fa-right-from-bracket"></i> Thoát
            </button>
        </div>
    </form>
</section>

<?php editorial_layout_footer(); ?>
