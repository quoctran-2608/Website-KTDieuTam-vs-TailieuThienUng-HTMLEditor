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
$workspaceLockToken = '';

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

// ─── Authorization and path initialization ───────────────────────

try {
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

    $htmlPath = editorial_resolve_article_path($article);
    if ($htmlPath === null) {
        editorial_flash_set('danger', 'Không thể đọc file HTML gốc.');
        editorial_redirect(editorial_url('my-work.php'));
    }
} catch (\Throwable $e) {
    error_log('Editorial workspace initialization failed: article_id=' . $articleId
        . ' user_id=' . $currentUserId
        . ' operation=authorization_or_path'
        . ' exception=' . get_class($e)
        . ' message=' . $e->getMessage());
    editorial_flash_set('danger', 'Không thể mở Workspace do lỗi khởi tạo. Chi tiết đã được ghi vào log hệ thống.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Handle POST actions ─────────────────────────────────────────

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    $saveThenIntents = [
        'save_draft',
        'save_then_stage1',
        'save_then_stage2',
        'save_then_review',
        'save_then_publish',
    ];
    if ($intent === 'handoff_active_stage') {
        require_once __DIR__ . '/includes/handoff.php';
        $handoffResult = editorial_handoff_article(
            $articleId,
            trim((string) ($_POST['handoff_note'] ?? '')),
            $currentUser
        );
        editorial_flash_set(
            $handoffResult['ok'] ? 'success' : 'danger',
            (string) ($handoffResult['message'] ?? 'Không thể lưu Drive + Sheet.')
        );
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }
    if (in_array($intent, $saveThenIntents, true)) {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        $expectedVersion = (int) ($_POST['expected_draft_version'] ?? 0);
        $expectedDraftHash = trim((string) ($_POST['expected_draft_hash'] ?? ''));
        if ($intent === 'save_then_stage2') {
            $assignment = editorial_get_active_assignment($articleId);
            $activeStages = $assignment === null
                ? ['stage1' => null, 'stage2' => null]
                : editorial_get_active_stage_bundle($articleId, (string) $assignment['id']);
            if ($activeStages['stage1'] === null) {
                editorial_flash_set('danger', 'Bạn cần hoàn tất Chặng 1 trước khi lưu Chặng 2.');
                editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
            }
        }

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
        $result = editorial_save_draft(
            $articleId,
            $currentUserId,
            $payload,
            $baseLiveHash,
            $expectedVersion,
            $lockToken,
            $expectedDraftHash
        );

        if (!$result['ok']) {
            editorial_flash_set('danger', $result['message']);
            editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
        }

        if (in_array($intent, ['save_then_stage1', 'save_then_stage2'], true)) {
            $milestoneKey = $intent === 'save_then_stage1' ? 'stage1' : 'stage2';

            $stageResult = editorial_create_stage_milestone_revision(
                $articleId,
                $currentUserId,
                $lockToken,
                (int) ($result['version'] ?? 0),
                $milestoneKey
            );
            $stageRevisionId = (string) ($stageResult['revision_id'] ?? $stageResult['duplicate_revision_id'] ?? '');
            if (empty($stageResult['ok']) || $stageRevisionId === '') {
                editorial_flash_set('danger', $stageResult['message'] ?? 'Không thể lưu mốc phiên bản.');
                editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
            }
            editorial_flash_set('success', (string) ($stageResult['message'] ?? 'Đã lưu mốc phiên bản.'));
            editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
        }

        if ($intent === 'save_then_review') {
            $reviewResult = editorial_send_for_review($articleId, $currentUserId, $lockToken);
            editorial_flash_set($reviewResult['ok'] ? 'success' : 'danger', (string) ($reviewResult['message'] ?? 'Không thể gửi duyệt.'));
            editorial_redirect($reviewResult['ok']
                ? editorial_url('my-work.php')
                : editorial_url('article.php?id=' . urlencode($articleId)));
        }

        if ($intent === 'save_then_publish') {
            require_once __DIR__ . '/includes/publish.php';
            if (empty($_POST['confirm_direct_publish'])) {
                editorial_flash_set('danger', 'Vui lòng xác nhận trước khi Publish trực tiếp.');
                editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
            }
            $publishResult = editorial_publish_editor_revision($articleId, $currentUserId, $lockToken);
            if (!$publishResult['ok']) {
                editorial_flash_set('danger', (string) ($publishResult['message'] ?? 'Không thể Publish trực tiếp.'));
                editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
            }
            $rebuildResult = editorial_public_rebuild_after_publish($articleId);
            if (!empty($rebuildResult['ok'])) {
                try {
                    editorial_log_activity('article.publish.public_rebuild_succeeded', $articleId, $currentUserId, json_encode([
                        'exit_code' => $rebuildResult['exit_code'] ?? 0,
                        'publish_mode' => 'editor_direct',
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort: Publish success remains success.
                }
                editorial_flash_set('success', 'Đã Publish bài viết. Bạn có thể tiếp tục biên tập, gửi duyệt hoặc Lưu Drive + Sheet.');
            } else {
                try {
                    editorial_log_activity('article.publish.public_rebuild_failed', $articleId, $currentUserId, json_encode([
                        'code' => $rebuildResult['code'] ?? 'unknown',
                        'exit_code' => $rebuildResult['exit_code'] ?? null,
                        'publish_mode' => 'editor_direct',
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort: Publish success remains success.
                }
                editorial_flash_set('warning', 'Bài đã được Publish, nhưng dữ liệu public phụ trợ chưa rebuild hoàn tất.');
            }
            editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
        }

        editorial_flash_set('success', $result['message']);
        editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
    }

    if ($intent === 'exit_workspace') {
        $lockToken = trim((string) ($_POST['lock_token'] ?? ''));
        editorial_release_article_lock($articleId, $currentUserId, $lockToken);
        editorial_flash_set('info', 'Đã thoát workspace biên tập.');
        editorial_redirect(editorial_url('my-work.php'));
    }

    editorial_redirect(editorial_url('article.php?id=' . urlencode($articleId)));
}

// ─── Workspace initialization ────────────────────────────────────

$abortWorkspaceInitialization = static function (string $operation, string $message, ?\Throwable $exception = null) use (
    $articleId,
    $currentUserId,
    &$workspaceLockToken
): void {
    $log = 'Editorial workspace initialization failed: article_id=' . $articleId
        . ' user_id=' . $currentUserId
        . ' operation=' . $operation;
    if ($exception !== null) {
        $log .= ' exception=' . get_class($exception) . ' message=' . $exception->getMessage();
    }
    error_log($log);

    if ($workspaceLockToken !== '') {
        try {
            editorial_release_article_lock($articleId, $currentUserId, $workspaceLockToken);
        } catch (\Throwable $releaseError) {
            error_log('Editorial workspace lock cleanup failed: article_id=' . $articleId
                . ' user_id=' . $currentUserId
                . ' operation=' . $operation
                . ' exception=' . get_class($releaseError)
                . ' message=' . $releaseError->getMessage());
        }
    }

    editorial_flash_set('danger', $message);
    editorial_redirect(editorial_url('my-work.php'));
};

try {
    $lockResult = editorial_acquire_article_lock($articleId, $currentUserId);
    if (!$lockResult['ok']) {
        editorial_flash_set('warning', $lockResult['message']);
        editorial_redirect(editorial_url('my-work.php'));
    }
    $workspaceLockToken = (string) $lockResult['lock_token'];
    $lockToken = $workspaceLockToken;
    $lockExpires = $lockResult['expires_at'];

    $assignment = editorial_get_active_assignment($articleId);
    if ($assignment === null || (string) ($assignment['user_id'] ?? '') !== $currentUserId) {
        $abortWorkspaceInitialization(
            'assignment_initialization',
            'Không thể xác minh phân công an toàn cho bài viết này. Vui lòng thử lại hoặc báo Admin.'
        );
    }

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
        if (!$baselineResult['ok']) {
            // A concurrent request may have created the baseline after our first check.
            $hasBaseline = false;
            foreach (editorial_get_article_revisions($articleId, 50) as $rev) {
                if (($rev['assignment_id'] ?? '') === $assignment['id'] && $rev['revision_type'] === 'baseline') {
                    $hasBaseline = true;
                    break;
                }
            }
            if (!$hasBaseline) {
                error_log('Editorial workspace initialization failed: article_id=' . $articleId
                    . ' user_id=' . $currentUserId
                    . ' operation=baseline_initialization'
                    . ' result=baseline_not_created');
                $abortWorkspaceInitialization(
                    'baseline_initialization',
                    'Không thể khởi tạo Bản gốc an toàn cho bài viết này. Vui lòng thử lại hoặc báo Admin.'
                );
            }
        }
    }

    $draft = editorial_get_draft($articleId, $currentUserId);
    $draftVersion = 0;
    $draftContentHash = '';
    $draftSavedAt = null;
    if ($draft !== null) {
        $form = $draft['payload'];
        $draftVersion = (int) ($draft['version'] ?? 0);
        $draftContentHash = editorial_revision_content_hash($draft['payload']);
        $draftSavedAt = (string) ($draft['updated_at'] ?? '');
    } else {
        $parsed = editorial_parse_article_file($htmlPath);
        if (!$parsed['ok']) {
            $abortWorkspaceInitialization(
                'draft_or_live_initialization',
                'Không thể khởi tạo nội dung Workspace an toàn. Vui lòng thử lại hoặc báo Admin.'
            );
        }
        $form = editorial_build_initial_payload($parsed, $article, $parsed['meta_payload'] ?? []);
    }

    $currentLiveHash = editorial_live_hash($htmlPath);
    $baseLiveHash = (string) ($state['base_live_hash'] ?? '');
    $liveHashConflict = ($baseLiveHash !== '' && $currentLiveHash !== null && $currentLiveHash !== $baseLiveHash);

    $recentRevisions = editorial_get_article_revisions($articleId, 5);
    $activeStages = editorial_get_active_stage_bundle($articleId, (string) $assignment['id']);
$isEditorWorkspace = (($currentUser['role'] ?? '') === 'editor');
$hasSavedDraft = $draftVersion > 0;
$handoffConfigStatus = editorial_handoff_config_status();
$handoffSettingsReady = !empty($handoffConfigStatus['ok']);
$handoffEnabled = $handoffSettingsReady && $activeStages['stage1'] !== null;
$handoffDisabledReason = !$handoffSettingsReady
    ? (string) ($handoffConfigStatus['message'] ?? 'Drive + Sheet cần kiểm tra cấu hình.')
    : 'Hãy hoàn tất Chặng 1 trước khi lưu Drive + Sheet.';
$hasPublication = trim((string) ($state['published_revision_id'] ?? '')) !== '';
$publishedAt = trim((string) ($state['published_at'] ?? ''));
$saveStatusText = $hasSavedDraft
    ? '✓ v' . $draftVersion
    : 'Chưa có bản nháp đã lưu';
    $assignmentBaseline = null;
    foreach (editorial_get_article_revisions($articleId, 50) as $revision) {
        if (($revision['assignment_id'] ?? '') === $assignment['id']
            && ($revision['revision_type'] ?? '') === 'baseline'
            && !empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            $assignmentBaseline = $revision;
            break;
        }
    }
    $stage1CompareUrl = $assignmentBaseline !== null && $activeStages['stage1'] !== null
        ? editorial_url(
            'compare.php?id=' . urlencode($articleId)
            . '&from=' . urlencode((string) $assignmentBaseline['id'])
            . '&to=' . urlencode((string) $activeStages['stage1']['id'])
        )
        : '';
    $stage2CompareUrl = $assignmentBaseline !== null && $activeStages['stage2'] !== null
        ? editorial_url(
            'compare.php?id=' . urlencode($articleId)
            . '&from=' . urlencode((string) $assignmentBaseline['id'])
            . '&to=' . urlencode((string) $activeStages['stage2']['id'])
        )
        : '';
} catch (\Throwable $e) {
    $abortWorkspaceInitialization(
        'workspace_initialization',
        'Không thể mở Workspace do lỗi khởi tạo. Chi tiết đã được ghi vào log hệ thống.',
        $e
    );
}

// ─── Public article URL ──────────────────────────────────────────

$publicUrl = editorial_public_article_url($article);
$liveArticleHtml = file_get_contents($htmlPath);
if ($liveArticleHtml === false) {
    $liveArticleHtml = '';
}
$previewPlaceholder = '__EDITORIAL_PREVIEW_PROSE__';
$previewTemplate = editorial_build_public_article_preview_document(
    $liveArticleHtml,
    $previewPlaceholder,
    editorial_site_url('')
);
$initialPreviewDocument = str_replace($previewPlaceholder, (string) ($form['prose_html'] ?? ''), $previewTemplate);
$previewTemplateJson = json_encode($previewTemplate, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($previewTemplateJson === false) {
    $previewTemplateJson = '""';
}

// ─── Render ──────────────────────────────────────────────────────

$siteBaseUrl = editorial_site_url('');

$innerScript = <<<JS
(() => {
  const form = document.getElementById('editorialEditorForm');
  const editor = document.getElementById('proseEditor');
  const formIntent = document.getElementById('editorialFormIntent');
  const publishConfirmField = document.getElementById('confirmDirectPublish');
  const previewFrame = document.getElementById('previewFrame');
  const previewTemplate = $previewTemplateJson;
  if (!editor) return;
  let draftDirty = false;
  let editorReady = false;

  function updateSaveStatus() {
    document.querySelectorAll('[data-save-status]').forEach((status) => {
      status.textContent = '● Chưa lưu';
      status.classList.add('is-dirty');
      status.classList.remove('is-saved');
    });
  }

  function updateSavedStatus(version) {
    document.querySelectorAll('[data-save-status]').forEach((status) => {
      status.textContent = '✓ v' + version;
      status.classList.remove('is-dirty');
      status.classList.add('is-saved');
    });
  }

  function markDraftDirty() {
    if (draftDirty) return;
    draftDirty = true;
    updateSaveStatus();
  }

  function currentEditorContent() {
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const instance = window.tinymce.get('proseEditor');
      if (instance) return instance.getContent();
    }
    return editor.value || '';
  }

  const siteBaseUrl = window.location.origin + window.location.pathname.replace(/\/editorial\/.*$/, '/');

  /* ── Preview sync ─────────────────────────────────── */
  function syncPreview() {
    if (!previewFrame || !previewTemplate) return;
    let html = currentEditorContent();
    if (!html) {
      html = '<p><em>Chưa có nội dung preview.</em></p>';
    }
    html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '');
    previewFrame.srcdoc = previewTemplate.replace('__EDITORIAL_PREVIEW_PROSE__', () => html);
  }

  /* ── Base64 encode on submit ──────────────────────── */
  form.addEventListener('submit', (e) => {
    if (form.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
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

  form.querySelectorAll('input:not([type="hidden"]):not([data-nondraft-field]), textarea:not(#proseEditor):not([data-nondraft-field])').forEach((field) => {
    field.addEventListener('input', markDraftDirty);
    field.addEventListener('change', markDraftDirty);
  });
  document.querySelectorAll('button[data-editor-action]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      if (!form || !formIntent || form.dataset.submitting === '1') return;
      const action = button.dataset.editorAction || 'save_draft';
      if (action === 'save_then_publish'
        && !window.confirm('Bạn sắp lưu nội dung hiện tại rồi Publish lên website. Tiếp tục?')) {
        return;
      }
      if (action === 'handoff_active_stage' && draftDirty
        && !window.confirm('Có thay đổi hiện tại chưa được lưu vào Chặng. Drive + Sheet sẽ dùng Chặng đã lưu gần nhất. Tiếp tục?')) {
        return;
      }
      formIntent.value = action;
      if (publishConfirmField) {
        publishConfirmField.value = action === 'save_then_publish' ? '1' : '';
      }
      form.requestSubmit();
    });
  });

  document.querySelectorAll('button[form="exitWorkspaceForm"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!draftDirty) return;
      if (!window.confirm('Bạn có thay đổi chưa lưu. Thoát bây giờ sẽ mất các thay đổi này. Vẫn thoát?')) {
        event.preventDefault();
      }
    }, true);
  });

  /* ── Ctrl+S save draft ────────────────────────────── */
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      if (!form || !formIntent || form.dataset.submitting === '1') return;
      formIntent.value = 'save_draft';
      if (publishConfirmField) publishConfirmField.value = '';
      form.requestSubmit();
    }
  });

  /* ── Fullscreen toggle ────────────────────────────── */
  let isFullscreen = false;
  const fsToggle = document.getElementById('editorFullscreenToggle');
  const fsToggleIcon = fsToggle ? fsToggle.querySelector('i') : null;
  const fsToggleText = fsToggle ? fsToggle.querySelector('span') : null;
  const fsToggleBottom = document.getElementById('editorFullscreenToggleBottom');

  function setFullscreenControls(fullscreen) {
    if (fsToggleIcon) fsToggleIcon.className = fullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
    if (fsToggleText) fsToggleText.textContent = fullscreen ? 'Thu nhỏ' : 'Toàn màn hình';
    if (fsToggle) fsToggle.title = fullscreen ? 'Thu nhỏ (Ctrl+Shift+F)' : 'Toàn màn hình (Ctrl+Shift+F)';
    if (fsToggleBottom) fsToggleBottom.title = fullscreen ? 'Thu nhỏ (Ctrl+Shift+F)' : 'Toàn màn hình (Ctrl+Shift+F)';
  }

  function enterFullscreen() {
    isFullscreen = true;
    document.body.classList.add('editor-fullscreen-active');
    setFullscreenControls(true);
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
    setFullscreenControls(false);
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const inst = window.tinymce.get('proseEditor');
      if (inst) inst.getBody().style.minHeight = '';
    }
  }

  function toggleFullscreen() { isFullscreen ? exitFullscreen() : enterFullscreen(); }

  if (fsToggle) fsToggle.addEventListener('click', toggleFullscreen);
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
        siteBaseUrl + 'assets/css/editorial-design-system.css',
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
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            if (!form || !formIntent || form.dataset.submitting === '1') return;
            formIntent.value = 'save_draft';
            if (publishConfirmField) publishConfirmField.value = '';
            form.requestSubmit();
          }
        });
      }
    });
  }

  syncPreview();

  /* ── Heartbeat ────────────────────────────────────── */
  const lockTokenField = document.getElementById('lockTokenField');
  const csrfField = form ? form.querySelector('input[name="_csrf_token"]') : null;
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
            <?php if ($hasPublication): ?>
                <span class="editorial-publication-indicator">
                    <i class="fa-solid fa-circle-check"></i>
                    Đã Publish<?= $publishedAt !== '' ? ' lúc ' . editorial_h(editorial_format_datetime($publishedAt)) : ' trước đó' ?>
                </span>
                &nbsp;
            <?php endif; ?>
            <a href="<?= editorial_h($publicUrl) ?>" target="_blank" rel="noopener" style="font-size:0.85rem;" title="Chỉ hiển thị nội dung đã Publish.">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem bản đang xuất bản
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
        <input type="hidden" name="_intent" id="editorialFormIntent" value="save_draft">
        <input type="hidden" name="article_id" id="articleIdField" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" id="lockTokenField" value="<?= editorial_h($lockToken) ?>">
        <input type="hidden" name="expected_draft_version" id="expectedDraftVersion" value="<?= editorial_h((string) $draftVersion) ?>">
        <input type="hidden" name="expected_draft_hash" id="expectedDraftHash" value="<?= editorial_h($draftContentHash) ?>">
        <input type="hidden" name="prose_html_b64" id="proseHtmlB64" value="">
        <input type="hidden" name="confirm_direct_publish" id="confirmDirectPublish" value="">

        <!-- Action bar -->
        <section class="editorial-workflow-bar editorial-workflow-top">
            <div class="editorial-workflow-groups">
                <div class="editorial-workflow-group editorial-workflow-save">
                    <button type="button" class="editorial-save-btn" data-editor-action="save_draft">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Lưu nháp</span>
                    </button>
                    <small class="editorial-save-status <?= $hasSavedDraft ? 'is-saved' : '' ?>" data-save-status><?= editorial_h($saveStatusText) ?></small>
                </div>

                <section class="editorial-stage-card editorial-stage-card--stage1">
                    <p class="editorial-stage-card__label">Chặng 1 <span>· Chuẩn hóa trình bày</span></p>
                    <button type="button" class="editorial-revision-btn editorial-stage1-btn" data-editor-action="save_then_stage1" title="Hoàn tất Chặng 1 — tự lưu nội dung hiện tại rồi lưu mốc chuẩn hóa trình bày.">
                        <i class="fa-solid fa-code-branch"></i>
                        <span>Hoàn tất Chặng 1</span>
                    </button>
                    <div class="editorial-stage-card__footer">
                    <?php if ($activeStages['stage1'] !== null && $stage1CompareUrl !== ''): ?>
                        <small class="editorial-stage-status">✓ Revision #<?= editorial_h((string) $activeStages['stage1']['revision_no']) ?></small>
                        <a class="editorial-compare-btn" href="<?= editorial_h($stage1CompareUrl) ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 1
                        </a>
                    <?php elseif ($activeStages['stage1'] !== null): ?>
                        <small class="editorial-stage-status">✓ Revision #<?= editorial_h((string) $activeStages['stage1']['revision_no']) ?></small>
                        <button type="button" class="editorial-compare-btn" disabled title="Không tìm thấy Bản gốc hợp lệ để so sánh.">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 1
                        </button>
                    <?php else: ?>
                        <small class="editorial-stage-card__pending">Chưa lưu</small>
                        <button type="button" class="editorial-compare-btn" disabled title="Hoàn tất Chặng 1 trước khi xem so sánh.">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 1
                        </button>
                    <?php endif; ?>
                    </div>
                </section>

                <section class="editorial-stage-card editorial-stage-card--stage2 <?= $activeStages['stage1'] === null ? 'is-disabled' : '' ?>">
                    <p class="editorial-stage-card__label">Chặng 2 <span>· Biên tập nội dung</span></p>
                    <button
                        type="button"
                        class="editorial-revision-btn editorial-stage2-btn"
                        data-editor-action="save_then_stage2"
                        title="<?= $activeStages['stage1'] === null ? 'Bạn cần hoàn tất Chặng 1 trước khi lưu Chặng 2.' : 'Hoàn tất Chặng 2 — tự lưu nội dung hiện tại rồi lưu mốc biên tập nội dung.' ?>"
                        <?= $activeStages['stage1'] === null ? 'disabled' : '' ?>
                    >
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Hoàn tất Chặng 2</span>
                    </button>
                    <div class="editorial-stage-card__footer">
                    <?php if ($activeStages['stage2'] !== null && $stage2CompareUrl !== ''): ?>
                        <small class="editorial-stage-status">✓ Revision #<?= editorial_h((string) $activeStages['stage2']['revision_no']) ?></small>
                        <a class="editorial-compare-btn" href="<?= editorial_h($stage2CompareUrl) ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 2
                        </a>
                    <?php elseif ($activeStages['stage2'] !== null): ?>
                        <small class="editorial-stage-status">✓ Revision #<?= editorial_h((string) $activeStages['stage2']['revision_no']) ?></small>
                        <button type="button" class="editorial-compare-btn" disabled title="Không tìm thấy Bản gốc hợp lệ để so sánh.">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 2
                        </button>
                    <?php elseif ($activeStages['stage1'] !== null): ?>
                        <small class="editorial-stage-card__pending">Chưa lưu lại</small>
                        <button type="button" class="editorial-compare-btn" disabled title="Hoàn tất Chặng 2 trước khi xem so sánh.">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 2
                        </button>
                    <?php else: ?>
                        <small class="editorial-stage-card__pending">Cần Chặng 1</small>
                        <button type="button" class="editorial-compare-btn" disabled title="Hoàn tất Chặng 1 trước khi xem so sánh.">
                            <i class="fa-solid fa-code-compare"></i> Bản gốc ↔ Chặng 2
                        </button>
                    <?php endif; ?>
                    </div>
                </section>

                <div class="editorial-workflow-group editorial-workflow-publish">
                    <?php if ($isEditorWorkspace): ?>
                        <button type="button" data-editor-action="save_then_publish" class="editorial-direct-publish-btn" title="Tự lưu nội dung hiện tại rồi Publish lên website.">
                            <i class="fa-solid fa-rocket"></i> Publish
                        </button>
                    <?php else: ?>
                        <button type="button" class="editorial-direct-publish-btn" disabled title="Publish trực tiếp trong Workspace dành cho Editor.">
                            <i class="fa-solid fa-rocket"></i> Publish
                        </button>
                    <?php endif; ?>
                </div>

                <div class="editorial-workflow-group editorial-workflow-review">
                    <button type="button" data-editor-action="save_then_review" class="editorial-review-submit-btn" title="Tự lưu nội dung hiện tại. Yêu cầu Chặng 1 và Chặng 2 đầy đủ; Chặng 2 phải khớp bản hiện tại.">
                        <i class="fa-solid fa-paper-plane"></i> Gửi Admin duyệt
                    </button>
                </div>

                <div class="editorial-workflow-group editorial-workflow-handoff">
                    <button type="button" class="editorial-handoff-btn" data-editor-action="handoff_active_stage" <?= $handoffEnabled ? 'title="Lưu Chặng đang active lên Google Drive và cập nhật Google Sheet."' : 'disabled title="' . editorial_h($handoffDisabledReason) . '"' ?>>
                        <i class="fa-solid fa-cloud-arrow-up"></i> Lưu Drive + Sheet
                    </button>
                    <details class="editorial-handoff-note-menu">
                        <summary title="Ghi chú bàn giao" aria-label="Ghi chú bàn giao">
                            <i class="fa-solid fa-pen"></i>
                        </summary>
                        <div>
                            <label for="handoffNote">Ghi chú bàn giao</label>
                            <input id="handoffNote" type="text" name="handoff_note" maxlength="2000" data-nondraft-field class="editorial-workspace-handoff-note" placeholder="Ghi chú bàn giao (nếu có)">
                        </div>
                    </details>
                    <?php if (!$handoffEnabled): ?>
                        <?php if (!$handoffSettingsReady && ($currentUser['role'] ?? '') === 'admin'): ?>
                            <a class="editorial-handoff-config-hint" href="<?= editorial_h(editorial_url('google-handoff-settings.php')) ?>" title="<?= editorial_h($handoffDisabledReason) ?>" aria-label="<?= editorial_h($handoffDisabledReason) ?>">
                                <i class="fa-solid fa-circle-info"></i>
                            </a>
                        <?php else: ?>
                            <span class="editorial-handoff-config-hint" title="<?= editorial_h($handoffDisabledReason) ?>" aria-label="<?= editorial_h($handoffDisabledReason) ?>">
                                <i class="fa-solid fa-circle-info"></i>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="editorial-workflow-utilities">
                <button type="button" class="editorial-workflow-help-btn" title="Bạn có thể Lưu nháp nhiều lần. Chặng 1 và Chặng 2 là các mốc cố định. Publish và Lưu Drive + Sheet là độc lập. Gửi Admin duyệt cần Chặng 1 + Chặng 2, và Chặng 2 phải khớp nội dung hiện tại." aria-label="Trợ giúp luồng biên tập">
                    <i class="fa-solid fa-circle-info"></i>
                </button>
                <button type="button" class="editorial-fullscreen-btn editorial-icon-btn" id="editorFullscreenToggle" title="Toàn màn hình (Ctrl+Shift+F)" aria-label="Toàn màn hình">
                    <i class="fa-solid fa-expand"></i>
                </button>
                <button type="submit" form="exitWorkspaceForm" class="editorial-exit-btn">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Thoát</span>
                </button>
            </div>
        </section>

        <div class="editor-fullscreen-backdrop" id="editorFullscreenBackdrop"></div>
        <div class="editor-fs-status" id="editorFsStatus">
            <span>Đang ở chế độ toàn màn hình</span>
            <button type="button" id="editorFullscreenToggleBottom" title="Thu nhỏ">
                <i class="fa-solid fa-compress"></i> <span>Thu nhỏ</span>
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
            <iframe id="previewFrame" class="editorial-workspace-preview-frame" sandbox="" srcdoc="<?= editorial_h($initialPreviewDocument) ?>" title="Xem trước nội dung theo giao diện website"></iframe>
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

        <!-- Bottom workflow actions -->
        <section class="editorial-workflow-bar editorial-workflow-bottom">
            <button type="button" class="editorial-save-btn" data-editor-action="save_draft">
                <i class="fa-solid fa-floppy-disk"></i> Lưu nháp
            </button>
            <button type="button" class="editorial-revision-btn editorial-stage1-btn" data-editor-action="save_then_stage1" title="Hoàn tất Chặng 1 — tự lưu nội dung hiện tại rồi lưu mốc chuẩn hóa trình bày.">
                <i class="fa-solid fa-code-branch"></i> Chặng 1
            </button>
            <?php if ($stage1CompareUrl !== ''): ?>
                <a class="editorial-compare-btn editorial-icon-btn" href="<?= editorial_h($stage1CompareUrl) ?>" target="_blank" rel="noopener" title="Bản gốc ↔ Chặng 1" aria-label="Bản gốc ↔ Chặng 1"><i class="fa-solid fa-code-compare"></i></a>
            <?php else: ?>
                <button type="button" class="editorial-compare-btn editorial-icon-btn" disabled title="<?= $activeStages['stage1'] !== null ? 'Không tìm thấy Bản gốc hợp lệ để so sánh.' : 'Hoàn tất Chặng 1 trước khi xem so sánh.' ?>" aria-label="Bản gốc ↔ Chặng 1 chưa sẵn sàng"><i class="fa-solid fa-code-compare"></i></button>
            <?php endif; ?>
            <button type="button" class="editorial-revision-btn editorial-stage2-btn" data-editor-action="save_then_stage2" title="<?= $activeStages['stage1'] === null ? 'Bạn cần hoàn tất Chặng 1 trước khi lưu Chặng 2.' : 'Hoàn tất Chặng 2 — tự lưu nội dung hiện tại rồi lưu mốc biên tập nội dung.' ?>" <?= $activeStages['stage1'] === null ? 'disabled' : '' ?>>
                <i class="fa-solid fa-pen-to-square"></i> Chặng 2
            </button>
            <?php if ($stage2CompareUrl !== ''): ?>
                <a class="editorial-compare-btn editorial-icon-btn" href="<?= editorial_h($stage2CompareUrl) ?>" target="_blank" rel="noopener" title="Bản gốc ↔ Chặng 2" aria-label="Bản gốc ↔ Chặng 2"><i class="fa-solid fa-code-compare"></i></a>
            <?php else: ?>
                <button type="button" class="editorial-compare-btn editorial-icon-btn" disabled title="<?= $activeStages['stage2'] !== null ? 'Không tìm thấy Bản gốc hợp lệ để so sánh.' : 'Hoàn tất Chặng 2 trước khi xem so sánh.' ?>" aria-label="Bản gốc ↔ Chặng 2 chưa sẵn sàng"><i class="fa-solid fa-code-compare"></i></button>
            <?php endif; ?>
            <?php if ($isEditorWorkspace): ?>
                <button type="button" data-editor-action="save_then_publish" class="editorial-direct-publish-btn" title="Tự lưu nội dung hiện tại rồi Publish lên website.">
                    <i class="fa-solid fa-rocket"></i> Publish
                </button>
            <?php else: ?>
                <button type="button" class="editorial-direct-publish-btn" disabled title="Publish trực tiếp trong Workspace dành cho Editor.">
                    <i class="fa-solid fa-rocket"></i> Publish
                </button>
            <?php endif; ?>
            <button type="button" data-editor-action="save_then_review" class="editorial-review-submit-btn" title="Tự lưu nội dung hiện tại. Yêu cầu Chặng 1 và Chặng 2 đầy đủ; Chặng 2 phải khớp bản hiện tại.">
                <i class="fa-solid fa-paper-plane"></i> Gửi Admin duyệt
            </button>
            <button type="button" class="editorial-handoff-btn" data-editor-action="handoff_active_stage" <?= $handoffEnabled ? 'title="Lưu Chặng đang active lên Google Drive và cập nhật Google Sheet."' : 'disabled title="Hãy hoàn tất Chặng 1 và kiểm tra cấu hình Drive + Sheet."' ?>>
                <i class="fa-solid fa-cloud-arrow-up"></i> Lưu Drive + Sheet
            </button>
            <button type="submit" form="exitWorkspaceForm" class="editorial-exit-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Thoát
            </button>
        </section>
    </form>

    <form id="exitWorkspaceForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="exit_workspace">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
    </form>

</section>

<?php editorial_layout_footer(); ?>
