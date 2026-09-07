<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/publication.php';
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
            (string) ($handoffResult['message'] ?? 'Không thể bàn giao Drive + Sheet.')
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
            'featured_image_alt' => trim((string) ($_POST['featured_image_alt'] ?? '')),
            'featured_image_title' => trim((string) ($_POST['featured_image_title'] ?? '')),
            'featured_image_caption' => trim((string) ($_POST['featured_image_caption'] ?? '')),
            'featured_image_credit' => trim((string) ($_POST['featured_image_credit'] ?? '')),
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
            $reviewResult = editorial_send_for_review(
                $articleId,
                $currentUserId,
                $lockToken,
                trim((string) ($_POST['review_note'] ?? ''))
            );
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
                editorial_flash_set('success', 'Đã Publish và rebuild dữ liệu public thành công.');
            } else {
                $safeCode = (string) ($rebuildResult['detail_code'] ?? $rebuildResult['code'] ?? 'unknown');
                if (preg_match('/^[a-z0-9_]+$/', $safeCode) !== 1) {
                    $safeCode = 'unknown';
                }
                try {
                    editorial_log_activity('article.publish.public_rebuild_failed', $articleId, $currentUserId, json_encode([
                        'code' => $rebuildResult['code'] ?? 'unknown',
                        'detail_code' => $safeCode,
                        'exit_code' => $rebuildResult['exit_code'] ?? null,
                        'publish_mode' => 'editor_direct',
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort: Publish success remains success.
                }
                editorial_flash_set(
                    'warning',
                    'Bài đã được Publish, nhưng dữ liệu public phụ trợ chưa rebuild hoàn tất. Mã: ' . $safeCode
                );
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
        $missingFeaturedMetadata = false;
        foreach ([
            'featured_image_alt',
            'featured_image_title',
            'featured_image_caption',
            'featured_image_credit',
        ] as $field) {
            if (!array_key_exists($field, $form)) {
                $missingFeaturedMetadata = true;
                break;
            }
        }
        if ($missingFeaturedMetadata) {
            $parsed = editorial_parse_article_file($htmlPath);
            if (!$parsed['ok']) {
                $abortWorkspaceInitialization(
                    'draft_featured_metadata_hydration',
                    'Không thể khởi tạo thông tin ảnh đại diện an toàn. Vui lòng thử lại hoặc báo Admin.'
                );
            }
            $form = editorial_hydrate_featured_image_metadata(
                $form,
                (array) ($parsed['meta_payload'] ?? [])
            );
        }
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
$handoffPublicationStatus = editorial_publication_handoff_status($articleId, $state);
$handoffPublicationReady = !empty($handoffPublicationStatus['eligible']);
$handoffEnabled = $handoffSettingsReady && $handoffPublicationReady;
$handoffDisabledReason = !$handoffPublicationReady
    ? (string) ($handoffPublicationStatus['message'] ?? 'Cần Publish hoàn tất trước khi bàn giao.')
    : (!$handoffSettingsReady
        ? (string) ($handoffConfigStatus['message'] ?? 'Drive + Sheet cần kiểm tra cấu hình.')
        : '');
$hasPublication = trim((string) ($state['published_revision_id'] ?? '')) !== '';
$publishedAt = trim((string) ($state['published_at'] ?? ''));
$approvedCheckpoint = null;
$approvedCheckpointUser = null;
if (trim((string) ($state['approved_revision_id'] ?? '')) !== '') {
    $approvedCheckpoint = editorial_get_revision((string) $state['approved_revision_id']);
    if (trim((string) ($state['approved_by'] ?? '')) !== '') {
        $approvedCheckpointUser = editorial_find_user_by_id((string) $state['approved_by']);
    }
}
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
  const articleIdField = document.getElementById('articleIdField');
  const lockTokenField = document.getElementById('lockTokenField');
  const csrfField = form ? form.querySelector('input[name="_csrf_token"]') : null;
  const previewTemplate = $previewTemplateJson;
  if (!editor) return;
  let draftDirty = false;
  let editorReady = false;
  let suppressDraftSignals = false;

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
    if (suppressDraftSignals) return;
    if (draftDirty) return;
    draftDirty = true;
    updateSaveStatus();
    document.querySelectorAll('button[data-editor-action="handoff_active_stage"]').forEach((button) => {
      button.disabled = true;
      button.title = 'Có thay đổi chưa Publish. Hãy lưu và Publish trước khi bàn giao.';
    });
  }

  function currentEditorContent() {
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      const instance = window.tinymce.get('proseEditor');
      if (instance) return instance.getContent();
    }
    return editor.value || '';
  }

  const siteBaseUrl = window.location.origin + window.location.pathname.replace(/\/editorial\/.*$/, '/');

  function resolveSitePreviewUrl(value) {
    const path = String(value || '').trim();
    if (!path) return '';
    if (/^(?:https?:)?\/\//i.test(path) || /^(?:data|blob):/i.test(path)) return path;
    if (path.startsWith('/')) return window.location.origin + path;
    return siteBaseUrl + path.replace(/^\/+/, '');
  }

  function normalizeImageCredit(value) {
    return String(value || '').trim().replace(/^(?:nguồn|source)\s*:\s*/i, '').trim();
  }

  function resolveSelectedEditorImage(instance) {
    const selectedNode = instance && instance.selection ? instance.selection.getNode() : null;
    const selected = selectedNode && selectedNode.nodeType === 1
      ? selectedNode
      : (selectedNode ? selectedNode.parentElement : null);
    if (!selected) return null;
    if (selected.nodeName === 'IMG') return selected;
    const nestedImages = selected.querySelectorAll ? selected.querySelectorAll('img') : [];
    if (nestedImages.length === 1) return nestedImages[0];
    const figure = selected.closest ? selected.closest('figure') : null;
    if (!figure) return null;
    const image = figure.querySelector('img');
    return image || null;
  }

  function readInlineImageMetadata(image) {
    const figure = image && image.closest ? image.closest('figure.article-image') : null;
    const compatibleFigure = figure && figure.classList.contains('article-image') ? figure : null;
    return {
      alt: image ? String(image.getAttribute('alt') || '') : '',
      title: image ? String(image.getAttribute('title') || '') : '',
      caption: compatibleFigure
        ? String((compatibleFigure.querySelector('.article-image-caption') || {}).textContent || '').trim()
        : '',
      credit: compatibleFigure
        ? normalizeImageCredit(String((compatibleFigure.querySelector('.article-image-credit') || {}).textContent || ''))
        : ''
    };
  }

  function isStandaloneImageParagraph(paragraph, image) {
    if (!paragraph || paragraph.nodeName !== 'P' || !paragraph.contains(image)) {
        return false;
    }
    let imageCount = 0;
    let safe = true;
    const inspectNode = (node) => {
      if (!safe) return;
      if (node.nodeType === 3) {
        if (String(node.nodeValue || '').trim()) safe = false;
        return;
      }
      if (node.nodeType !== 1) {
        safe = false;
        return;
      }
      if (node === image) {
        imageCount += 1;
        return;
      }
      if (node.nodeName === 'BR') {
        return;
      }
      if (node.nodeName !== 'SPAN') {
        safe = false;
        return;
      }
      Array.from(node.childNodes).forEach(inspectNode);
    };
    Array.from(paragraph.childNodes).forEach(inspectNode);
    return safe && imageCount === 1;
  }

  function canContainMetadataFigure(element) {
    return Boolean(element && [
      'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'BODY', 'DIV', 'LI',
      'MAIN', 'SECTION', 'TD', 'TH'
    ].includes(element.nodeName));
  }

  function createMetadataFigure(instance, image) {
    const parent = image.parentElement;
    if (!parent) return null;
    const doc = instance.getDoc();
    const createFigure = () => {
      const figure = doc.createElement('figure');
      figure.className = 'article-image';
      figure.setAttribute('data-editorial-image-meta', '1');
      return figure;
    };

    const standaloneParagraph = image.closest ? image.closest('p') : null;
    if (standaloneParagraph) {
      if (!isStandaloneImageParagraph(standaloneParagraph, image)
        || !canContainMetadataFigure(standaloneParagraph.parentElement)) {
        return null;
      }
      // Promote only a proven standalone image paragraph. Nested legacy SPAN
      // wrappers are removed together with the now-empty paragraph.
      const figure = createFigure();
      standaloneParagraph.parentElement.insertBefore(figure, standaloneParagraph);
      figure.appendChild(image);
      standaloneParagraph.remove();
      return figure;
    }

    // This branch is restricted to the block/flow allow-list above, never P or
    // a phrasing container such as A/SPAN/STRONG/EM.
    if (!canContainMetadataFigure(parent)) {
      return null;
    }
    const figure = createFigure();
    parent.insertBefore(figure, image);
    figure.appendChild(image);
    return figure;
  }

  function writeInlineImageMetadata(instance, image, values) {
    const alt = String(values.alt || '').trim();
    const title = String(values.title || '').trim();
    const caption = String(values.caption || '').trim();
    const credit = normalizeImageCredit(values.credit);
    image.setAttribute('alt', alt);
    if (title) image.setAttribute('title', title);
    else image.removeAttribute('title');

    let figure = image.closest ? image.closest('figure.article-image') : null;
    const legacyFigure = image.closest ? image.closest('figure') : null;
    if (!figure && (caption || credit)) {
      if (legacyFigure) {
        instance.notificationManager.open({
          text: 'Không thể thêm Caption/Nguồn vào cấu trúc figure cũ. Alt và Title đã được cập nhật.',
          type: 'warning',
          timeout: 5000
        });
      } else {
        figure = createMetadataFigure(instance, image);
        if (!figure) {
          instance.notificationManager.open({
            text: 'Ảnh cần nằm trên một dòng riêng để thêm Caption/Nguồn. Alt và Title đã được cập nhật.',
            type: 'warning',
            timeout: 5000
          });
        }
      }
    }

    if (figure) {
      let figcaption = figure.querySelector(':scope > figcaption');
      if (caption || credit) {
        if (!figcaption) {
          figcaption = instance.getDoc().createElement('figcaption');
          figure.appendChild(figcaption);
        }
        let captionNode = figcaption.querySelector('.article-image-caption');
        let creditNode = figcaption.querySelector('.article-image-credit');
        if (caption) {
          if (!captionNode) {
            captionNode = instance.getDoc().createElement('span');
            captionNode.className = 'article-image-caption';
            figcaption.insertBefore(captionNode, figcaption.firstChild);
          }
          captionNode.textContent = caption;
        } else if (captionNode) {
          captionNode.remove();
        }
        if (credit) {
          if (!creditNode) {
            creditNode = instance.getDoc().createElement('span');
            creditNode.className = 'article-image-credit';
            figcaption.appendChild(creditNode);
          }
          creditNode.textContent = 'Nguồn: ' + credit;
        } else if (creditNode) {
          creditNode.remove();
        }
      } else {
        if (figcaption) {
          const captionNode = figcaption.querySelector('.article-image-caption');
          const creditNode = figcaption.querySelector('.article-image-credit');
          if (captionNode) captionNode.remove();
          if (creditNode) creditNode.remove();
          if (figure.getAttribute('data-editorial-image-meta') === '1'
            && !figcaption.textContent.trim()) {
            figcaption.remove();
          }
        }
        if (figure.getAttribute('data-editorial-image-meta') === '1'
          && figure.children.length === 1
          && figure.children[0] === image
          && figure.parentNode) {
          figure.parentNode.insertBefore(image, figure);
          figure.remove();
        }
      }
    }

    instance.nodeChanged();
    if (!suppressDraftSignals) {
      markDraftDirty();
      syncPreview();
    }
  }

  function openInlineImageMetadataDialog(instance) {
    const image = resolveSelectedEditorImage(instance);
    if (!image) {
      instance.notificationManager.open({
        text: 'Vui lòng chọn một ảnh trong nội dung trước.',
        type: 'warning',
        timeout: 4000
      });
      return;
    }
    const current = readInlineImageMetadata(image);
    instance.windowManager.open({
      title: 'Thông tin ảnh',
      body: {
        type: 'panel',
        items: [
          { type: 'input', name: 'alt', label: 'Mô tả ảnh / Alt text *' },
          { type: 'input', name: 'title', label: 'Title' },
          { type: 'textarea', name: 'caption', label: 'Caption' },
          { type: 'input', name: 'credit', label: 'Credit / Nguồn ảnh' }
        ]
      },
      initialData: current,
      buttons: [
        { type: 'cancel', text: 'Hủy' },
        { type: 'submit', text: 'Lưu', primary: true }
      ],
      onSubmit: (api) => {
        const values = api.getData();
        if (!String(values.alt || '').trim()) {
          instance.notificationManager.open({
            text: 'Mô tả ảnh / Alt text không được để trống.',
            type: 'warning',
            timeout: 5000
          });
          return;
        }
        writeInlineImageMetadata(instance, image, values);
        api.close();
      }
    });
  }

  async function uploadEditorialImage(file, purpose, progress) {
    const csrfInput = form ? form.querySelector('input[name="_csrf_token"]') : null;
    const articleInput = document.getElementById('articleIdField');
    const lockInput = document.getElementById('lockTokenField');
    if (!file || !csrfInput || !articleInput || !lockInput) {
      throw new Error('Thiếu thông tin phiên chỉnh sửa để upload ảnh.');
    }
    const payload = new FormData();
    payload.append('_csrf_token', csrfInput.value);
    payload.append('article_id', articleInput.value);
    payload.append('lock_token', lockInput.value);
    payload.append('purpose', purpose || 'content');
    payload.append('image', file, file.name || 'image');

    const response = await fetch('upload.php', {
      method: 'POST',
      body: payload,
      credentials: 'same-origin',
    });
    let json = null;
    try {
      json = await response.json();
    } catch (error) {
      throw new Error('Máy chủ trả về phản hồi upload không hợp lệ.');
    }
    if (!response.ok || !json || !json.ok) {
      throw new Error((json && json.error) || 'Không thể upload ảnh.');
    }
    if (typeof progress === 'function') progress(100);
    return json;
  }

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
      if (action === 'save_then_review') {
        openReviewSubmissionDialog();
        return;
      }
      submitEditorAction(action);
    });
  });

  function submitEditorAction(action) {
      if (!form || !formIntent || form.dataset.submitting === '1') return;
      if (action === 'save_then_publish'
        && !window.confirm('Bạn sắp lưu nội dung hiện tại rồi Publish lên website. Tiếp tục?')) {
        return;
      }
      if (action === 'handoff_active_stage' && draftDirty) {
        window.alert('Có thay đổi chưa Publish. Hãy lưu và Publish trước khi bàn giao.');
        return;
      }
      formIntent.value = action;
      if (publishConfirmField) {
        publishConfirmField.value = action === 'save_then_publish' ? '1' : '';
      }
      form.requestSubmit();
  }

  const reviewSubmissionDialog = document.getElementById('reviewSubmissionDialog');
  const reviewSubmissionNote = document.getElementById('reviewSubmissionNote');
  const reviewSubmissionNoteField = document.getElementById('reviewSubmissionNoteField');
  const reviewSubmissionConfirm = document.getElementById('reviewSubmissionConfirm');
  const reviewSubmissionCancel = document.getElementById('reviewSubmissionCancel');

  function openReviewSubmissionDialog() {
    if (!reviewSubmissionDialog || !reviewSubmissionNote || !reviewSubmissionNoteField
      || typeof reviewSubmissionDialog.showModal !== 'function') {
      const fallbackNote = window.prompt('Ghi chú gửi Admin duyệt (không bắt buộc):', reviewSubmissionNote ? reviewSubmissionNote.value : '');
      if (fallbackNote === null) return;
      if (reviewSubmissionNote) reviewSubmissionNote.value = fallbackNote.slice(0, 2000);
      submitEditorAction('save_then_review');
      return;
    }
    reviewSubmissionNoteField.value = reviewSubmissionNote.value;
    reviewSubmissionDialog.showModal();
    window.setTimeout(() => reviewSubmissionNoteField.focus(), 0);
  }

  if (reviewSubmissionCancel && reviewSubmissionDialog) {
    reviewSubmissionCancel.addEventListener('click', () => reviewSubmissionDialog.close());
  }
  if (reviewSubmissionConfirm && reviewSubmissionDialog && reviewSubmissionNote && reviewSubmissionNoteField) {
    reviewSubmissionConfirm.addEventListener('click', () => {
      reviewSubmissionNote.value = reviewSubmissionNoteField.value.trim();
      reviewSubmissionDialog.close();
      submitEditorAction('save_then_review');
    });
  }

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
      toolbar: 'code | undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image imagemeta | removeformat preview',
      image_description: true,
      image_title: true,
      image_caption: false,
      extended_valid_elements: 'figure[class|data-editorial-image-meta],figcaption,span[class],img[src|alt|title|width|height|loading|decoding]',
      content_css: [
        siteBaseUrl + 'assets/css/editorial-design-system.css',
      ],
      body_class: 'ct-prose is-article mce-content-body',
      content_style: 'body { font-family: "Google Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 17.5px; line-height: 1.78; color: #33322C; padding: 18px 22px; -webkit-font-smoothing: antialiased; } img { max-width: 100%; height: auto; }',
      images_upload_handler: async (blobInfo, progress) => {
        const result = await uploadEditorialImage(blobInfo.blob(), 'content', progress);
        markDraftDirty();
        return result.location;
      },
      setup: (instance) => {
        instance.ui.registry.addButton('imagemeta', {
          icon: 'image',
          tooltip: 'Thông tin ảnh',
          onAction: () => openInlineImageMetadataDialog(instance)
        });
        instance.on('init', () => { editorReady = true; });
        instance.on('input change keyup', () => {
          if (suppressDraftSignals) return;
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

  /* ── Featured image upload / preview ──────────────── */
  const featuredImageInput = document.getElementById('featuredImageInput');
  const featuredImageFile = document.getElementById('featuredImageFile');
  const featuredImageUpload = document.getElementById('featuredImageUpload');
  const featuredImageClear = document.getElementById('featuredImageClear');
  const featuredImagePreview = document.getElementById('featuredImagePreview');
  const featuredImagePreviewEmpty = document.getElementById('featuredImagePreviewEmpty');
  const featuredImageAlt = document.getElementById('featuredImageAlt');
  const featuredImageTitle = document.getElementById('featuredImageTitle');
  const featuredImageCaption = document.getElementById('featuredImageCaption');
  const featuredImageCredit = document.getElementById('featuredImageCredit');

  function clearFeaturedImageMetadata() {
    [featuredImageAlt, featuredImageTitle, featuredImageCaption, featuredImageCredit].forEach((field) => {
      if (field) field.value = '';
    });
  }

  function syncFeaturedImagePreview() {
    if (!featuredImageInput || !featuredImagePreview || !featuredImagePreviewEmpty) return;
    const previewUrl = resolveSitePreviewUrl(featuredImageInput.value);
    if (!previewUrl) {
      featuredImagePreview.removeAttribute('src');
      featuredImagePreview.hidden = true;
      featuredImagePreviewEmpty.textContent = 'Chưa chọn ảnh đại diện';
      featuredImagePreviewEmpty.hidden = false;
      return;
    }
    featuredImagePreview.src = previewUrl;
    featuredImagePreview.hidden = false;
    featuredImagePreviewEmpty.hidden = true;
  }

  if (featuredImageInput) {
    featuredImageInput.addEventListener('input', syncFeaturedImagePreview);
    featuredImageInput.addEventListener('change', syncFeaturedImagePreview);
  }
  if (featuredImagePreview) {
    featuredImagePreview.addEventListener('error', () => {
      featuredImagePreview.hidden = true;
      if (featuredImagePreviewEmpty) {
        featuredImagePreviewEmpty.textContent = 'Không tải được ảnh xem trước';
        featuredImagePreviewEmpty.hidden = false;
      }
    });
    featuredImagePreview.addEventListener('load', () => {
      if (featuredImagePreviewEmpty) {
        featuredImagePreviewEmpty.textContent = 'Chưa chọn ảnh đại diện';
        featuredImagePreviewEmpty.hidden = true;
      }
      featuredImagePreview.hidden = false;
    });
  }
  if (featuredImageUpload && featuredImageFile) {
    featuredImageUpload.addEventListener('click', () => featuredImageFile.click());
    featuredImageFile.addEventListener('change', async () => {
      const file = featuredImageFile.files && featuredImageFile.files[0];
      if (!file || !featuredImageInput) return;
      featuredImageUpload.disabled = true;
      try {
        const result = await uploadEditorialImage(file, 'featured');
        featuredImageInput.value = result.public_path;
        clearFeaturedImageMetadata();
        markDraftDirty();
        syncFeaturedImagePreview();
        if (featuredImageAlt) featuredImageAlt.focus();
      } catch (error) {
        window.alert(error instanceof Error ? error.message : 'Không thể upload ảnh đại diện.');
      } finally {
        featuredImageFile.value = '';
        featuredImageUpload.disabled = false;
      }
    });
  }
  if (featuredImageClear && featuredImageInput) {
    featuredImageClear.addEventListener('click', () => {
      featuredImageInput.value = '';
      clearFeaturedImageMetadata();
      markDraftDirty();
      syncFeaturedImagePreview();
    });
  }
  syncFeaturedImagePreview();

  /* ── KTDT Image Pack import ─────────────────────────── */
  const imagePackOpen = document.getElementById('imagePackOpen');
  const imagePackDialog = document.getElementById('imagePackDialog');
  const imagePackClose = document.getElementById('imagePackClose');
  const imagePackJsonWrap = document.getElementById('imagePackJsonWrap');
  const imagePackJson = document.getElementById('imagePackJson');
  const imagePackToggleJson = document.getElementById('imagePackToggleJson');
  const imagePackValidate = document.getElementById('imagePackValidate');
  const imagePackApply = document.getElementById('imagePackApply');
  const imagePackArticle = document.getElementById('imagePackArticle');
  const imagePackFeatured = document.getElementById('imagePackFeatured');
  const imagePackInline = document.getElementById('imagePackInline');
  const imagePackMatch = document.getElementById('imagePackMatch');
  const imagePackConflict = document.getElementById('imagePackConflict');
  const imagePackWarnings = document.getElementById('imagePackWarnings');
  const imagePackErrors = document.getElementById('imagePackErrors');
  const imagePackStatus = document.getElementById('imagePackStatus');
  let currentImagePack = null;
  let imagePackBusy = false;

  function safeDecodePathname(pathname) {
    const normalized = String(pathname || '').replace(/\/+/g, '/');
    return normalized.split('/').map((part) => {
      try {
        const decoded = decodeURIComponent(part);
        if (decoded.includes('/') || decoded.includes(String.fromCharCode(92))) {
          return part.replace(/%[0-9a-f]{2}/gi, (encoded) => encoded.toUpperCase());
        }
        return decoded.normalize('NFC');
      } catch (error) {
        return part.normalize ? part.normalize('NFC') : part;
      }
    }).join('/');
  }

  function imagePackString(value) {
    return ['string', 'number', 'boolean'].includes(typeof value) ? String(value) : '';
  }

  function canonicalImageSrc(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    let url;
    let site;
    try {
      site = new URL(siteBaseUrl);
      url = new URL(raw, site);
    } catch (error) {
      return '';
    }
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    const pathname = safeDecodePathname(url.pathname || '/');
    const basePathRaw = safeDecodePathname(site.pathname || '/').replace(/\/+$/, '');
    const basePath = basePathRaw === '' ? '/' : basePathRaw;
    if (url.origin === site.origin) {
      if (basePath === '/') {
        return 'site:' + pathname.replace(/^\/+/, '');
      }
      if (pathname === basePath) {
        return 'site:';
      }
      if (pathname.startsWith(basePath + '/')) {
        return 'site:' + pathname.slice(basePath.length).replace(/^\/+/, '');
      }
    }
    return 'origin:' + url.origin.toLowerCase() + pathname;
  }

  function imagePackHttpUrl(value) {
    try {
      const url = new URL(String(value || '').trim());
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password;
    } catch (error) {
      return false;
    }
  }

  function normalizeImagePackItem(item, inline, index) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      throw new Error(inline ? 'Ảnh nội dung #' + (index + 1) + ' không hợp lệ.' : 'Featured không hợp lệ.');
    }
    const imageUrl = imagePackString(item.image_url).trim();
    if (!imagePackHttpUrl(imageUrl)) {
      throw new Error(inline
        ? 'Ảnh nội dung #' + (index + 1) + ' có image_url không hợp lệ.'
        : 'Featured có image_url không hợp lệ.');
    }
    const normalized = {
      image_url: imageUrl,
      filename: imagePackString(item.filename).trim() || 'image',
      alt: imagePackString(item.alt),
      title: imagePackString(item.title),
      caption: imagePackString(item.caption),
      credit: imagePackString(item.credit)
    };
    if (inline) {
      normalized.old_src = imagePackString(item.old_src).trim();
      if (!normalized.old_src) {
        throw new Error('Ảnh nội dung #' + (index + 1) + ' thiếu old_src.');
      }
    }
    return normalized;
  }

  function parseImagePack(text) {
    let raw;
    try {
      raw = JSON.parse(String(text || ''));
    } catch (error) {
      throw new Error('JSON gói ảnh không hợp lệ.');
    }
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
      throw new Error('Gói ảnh phải là một JSON object.');
    }
    if (raw.protocol !== 'KTDT_IMAGE_PACK' || raw.version !== 1) {
      throw new Error('Gói ảnh không đúng protocol KTDT_IMAGE_PACK v1.');
    }
    if (!raw.article || typeof raw.article !== 'object' || Array.isArray(raw.article)
      || !imagePackString(raw.article.title).trim()) {
      throw new Error('Gói ảnh thiếu article.title.');
    }
    if (!raw.featured || typeof raw.featured !== 'object' || Array.isArray(raw.featured)) {
      throw new Error('Gói ảnh thiếu Featured Image.');
    }
    if (!Array.isArray(raw.inline_images)) {
      throw new Error('inline_images phải là một mảng.');
    }
    return {
      protocol: 'KTDT_IMAGE_PACK',
      version: 1,
      article: {
        title: imagePackString(raw.article.title).trim(),
        slug: imagePackString(raw.article.slug).trim()
      },
      featured: normalizeImagePackItem(raw.featured, false, 0),
      inline_images: raw.inline_images.map((item, index) => normalizeImagePackItem(item, true, index))
    };
  }

  function inlineMetadataCompatible(image, item) {
    if (!String(item.caption || '').trim() && !String(item.credit || '').trim()) {
      return true;
    }
    const articleFigure = image.closest ? image.closest('figure.article-image') : null;
    if (articleFigure) {
      return articleFigure.querySelectorAll('img').length === 1;
    }
    if (image.closest && image.closest('figure')) {
      return false;
    }
    const standaloneParagraph = image.closest ? image.closest('p') : null;
    if (standaloneParagraph) {
      return isStandaloneImageParagraph(standaloneParagraph, image)
        && canContainMetadataFigure(standaloneParagraph.parentElement);
    }
    const parent = image.parentElement;
    if (!parent) return false;
    return canContainMetadataFigure(parent);
  }

  function normalizeCompareText(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('vi');
  }

  function normalizeCompareSlug(value) {
    let slug = String(value || '').trim();
    if (!slug) return '';
    try {
      const url = new URL(slug, siteBaseUrl);
      slug = url.pathname.split('/').filter(Boolean).pop() || '';
    } catch (error) {
      slug = slug.split(/[?#]/, 1)[0].split('/').filter(Boolean).pop() || '';
    }
    try {
      slug = decodeURIComponent(slug);
    } catch (error) {
      // Keep the original slug when percent-decoding is invalid.
    }
    return slug.replace(/\.html?$/i, '').toLocaleLowerCase('vi');
  }

  function inspectImagePack(pack) {
    const instance = window.tinymce && typeof window.tinymce.get === 'function'
      ? window.tinymce.get('proseEditor')
      : null;
    const conflicts = [];
    const warnings = [];
    const mappings = [];
    if (!instance || !instance.getBody()) {
      conflicts.push('Trình soạn thảo chưa sẵn sàng.');
      return { ok: false, conflicts, warnings, mappings, instance: null, html: '' };
    }
    if (!featuredImageInput || !featuredImageAlt || !featuredImageTitle
      || !featuredImageCaption || !featuredImageCredit) {
      conflicts.push('Không tìm thấy đầy đủ trường Featured Image trong Workspace.');
    }

    const identities = new Map();
    pack.inline_images.forEach((item, index) => {
      const identity = canonicalImageSrc(item.old_src);
      if (!identity) {
        conflicts.push('Ảnh nội dung #' + (index + 1) + ': old_src không thể chuẩn hóa.');
        return;
      }
      if (identities.has(identity)) {
        conflicts.push('Gói ảnh có nhiều ảnh mới cùng trỏ tới một ảnh cũ.');
        return;
      }
      identities.set(identity, index);
    });

    const currentImages = Array.from(instance.getBody().querySelectorAll('img'));
    pack.inline_images.forEach((item, index) => {
      const identity = canonicalImageSrc(item.old_src);
      if (!identity) return;
      const matches = currentImages.filter((image) => canonicalImageSrc(image.getAttribute('src')) === identity);
      if (matches.length === 0) {
        conflicts.push('Ảnh nội dung #' + (index + 1) + ': không tìm thấy old_src trong bài hiện tại.');
        return;
      }
      if (matches.length > 1) {
        conflicts.push('Ảnh nội dung #' + (index + 1) + ': old_src khớp nhiều ảnh trong bài hiện tại.');
        return;
      }
      if (!inlineMetadataCompatible(matches[0], item)) {
        conflicts.push('Ảnh nội dung #' + (index + 1) + ': cấu trúc HTML hiện tại không thể thêm Caption/Nguồn an toàn.');
        return;
      }
      mappings.push({
        index,
        identity,
        image: matches[0],
        raw_src: String(matches[0].getAttribute('src') || '')
      });
    });

    const titleInput = document.getElementById('titleInput');
    if (normalizeCompareText(pack.article.title) !== normalizeCompareText(titleInput ? titleInput.value : '')) {
      warnings.push('Gói ảnh có vẻ thuộc bài khác.');
    }
    const currentSlug = normalizeCompareSlug(articleIdField ? articleIdField.value : '');
    const packageSlug = normalizeCompareSlug(pack.article.slug);
    if (packageSlug && currentSlug && packageSlug !== currentSlug
      && !warnings.includes('Gói ảnh có vẻ thuộc bài khác.')) {
      warnings.push('Gói ảnh có vẻ thuộc bài khác.');
    }
    return {
      ok: conflicts.length === 0 && mappings.length === pack.inline_images.length,
      conflicts,
      warnings,
      mappings,
      instance,
      html: instance.getContent()
    };
  }

  function setImagePackStatus(message, type) {
    if (!imagePackStatus) return;
    imagePackStatus.textContent = message || '';
    imagePackStatus.className = 'editorial-image-pack-status' + (type ? ' is-' + type : '');
  }

  function renderImagePackList(element, messages) {
    if (!element) return;
    element.replaceChildren();
    messages.forEach((message) => {
      const item = document.createElement('li');
      item.textContent = message;
      element.appendChild(item);
    });
    element.hidden = messages.length === 0;
  }

  function renderImagePackPreview(pack, preflight) {
    if (imagePackArticle) imagePackArticle.textContent = pack.article.title;
    if (imagePackFeatured) imagePackFeatured.textContent = '✓ Sẵn sàng';
    if (imagePackInline) imagePackInline.textContent = String(pack.inline_images.length) + ' ảnh';
    if (imagePackMatch) imagePackMatch.textContent = '✓ Match: ' + preflight.mappings.length + '/' + pack.inline_images.length;
    if (imagePackConflict) imagePackConflict.textContent = '⚠ Conflict: ' + preflight.conflicts.length;
    renderImagePackList(imagePackWarnings, preflight.warnings);
    renderImagePackList(imagePackErrors, preflight.conflicts);
    if (imagePackApply) imagePackApply.disabled = !preflight.ok || imagePackBusy;
    setImagePackStatus(preflight.ok ? 'Gói ảnh đã sẵn sàng để tải và áp dụng.' : 'Cần xử lý conflict trước khi áp dụng.', preflight.ok ? 'ready' : 'error');
  }

  function resetImagePackPreview() {
    if (imagePackArticle) imagePackArticle.textContent = 'Chưa nhận gói ảnh';
    if (imagePackFeatured) imagePackFeatured.textContent = '—';
    if (imagePackInline) imagePackInline.textContent = '0 ảnh';
    if (imagePackMatch) imagePackMatch.textContent = '✓ Match: 0/0';
    if (imagePackConflict) imagePackConflict.textContent = '⚠ Conflict: 0';
    renderImagePackList(imagePackWarnings, []);
    renderImagePackList(imagePackErrors, []);
  }

  function prepareImagePack(text) {
    if (imagePackBusy) return;
    try {
      currentImagePack = parseImagePack(text);
      renderImagePackPreview(currentImagePack, inspectImagePack(currentImagePack));
    } catch (error) {
      currentImagePack = null;
      if (imagePackApply) imagePackApply.disabled = true;
      renderImagePackList(imagePackWarnings, []);
      renderImagePackList(imagePackErrors, [error instanceof Error ? error.message : 'Không thể đọc gói ảnh.']);
      setImagePackStatus('Gói ảnh chưa hợp lệ.', 'error');
    }
  }

  function openImagePackDialog() {
    if (!imagePackDialog) return;
    if (typeof imagePackDialog.showModal === 'function') {
      imagePackDialog.showModal();
    } else {
      imagePackDialog.setAttribute('open', '');
    }
  }

  function closeImagePackDialog() {
    if (!imagePackDialog || imagePackBusy) return;
    if (typeof imagePackDialog.close === 'function') {
      imagePackDialog.close();
    } else {
      imagePackDialog.removeAttribute('open');
    }
  }

  function setImagePackJsonVisible(visible) {
    if (imagePackJsonWrap) imagePackJsonWrap.hidden = !visible;
    if (imagePackToggleJson) imagePackToggleJson.textContent = visible ? 'Ẩn vùng JSON' : 'Dán JSON thủ công';
    if (visible && imagePackJson) window.setTimeout(() => imagePackJson.focus(), 0);
  }

  if (imagePackOpen) {
    imagePackOpen.addEventListener('click', async () => {
      currentImagePack = null;
      if (imagePackApply) imagePackApply.disabled = true;
      resetImagePackPreview();
      setImagePackStatus('Đang đọc Clipboard...', '');
      openImagePackDialog();
      let clipboardText = '';
      try {
        if (navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
          clipboardText = await navigator.clipboard.readText();
        }
      } catch (error) {
        clipboardText = '';
      }
      if (clipboardText.trim()) {
        if (imagePackJson) imagePackJson.value = clipboardText;
        setImagePackJsonVisible(false);
        prepareImagePack(clipboardText);
      } else {
        if (imagePackJson) imagePackJson.value = '';
        setImagePackJsonVisible(true);
        setImagePackStatus('Clipboard không khả dụng. Hãy dán JSON rồi bấm Kiểm tra gói.', '');
      }
    });
  }
  if (imagePackClose) imagePackClose.addEventListener('click', closeImagePackDialog);
  if (imagePackDialog) {
    imagePackDialog.addEventListener('cancel', (event) => {
      if (imagePackBusy) event.preventDefault();
    });
  }
  if (imagePackToggleJson) {
    imagePackToggleJson.addEventListener('click', () => {
      setImagePackJsonVisible(Boolean(imagePackJsonWrap && imagePackJsonWrap.hidden));
    });
  }
  if (imagePackValidate && imagePackJson) {
    imagePackValidate.addEventListener('click', () => prepareImagePack(imagePackJson.value));
    imagePackJson.addEventListener('input', () => {
      if (imagePackBusy) return;
      currentImagePack = null;
      if (imagePackApply) imagePackApply.disabled = true;
      resetImagePackPreview();
      setImagePackStatus('JSON đã thay đổi. Hãy bấm Kiểm tra gói.', '');
    });
  }

  async function transferImagePack(pack) {
    if (!csrfField || !articleIdField || !lockTokenField) {
      throw new Error('Thiếu thông tin phiên chỉnh sửa để nhập ảnh.');
    }
    const body = new FormData();
    body.append('_csrf_token', csrfField.value);
    body.append('article_id', articleIdField.value);
    body.append('lock_token', lockTokenField.value);
    body.append('pack_json', JSON.stringify(pack));
    const response = await fetch('image-pack-import.php', {
      method: 'POST',
      body,
      credentials: 'same-origin'
    });
    let json;
    try {
      json = await response.json();
    } catch (error) {
      throw new Error('Máy chủ trả về phản hồi Image Pack không hợp lệ.');
    }
    if (!response.ok || !json || !json.ok) {
      const suffix = json && json.item
        ? ' (' + json.item + (Number.isInteger(json.index) ? ' #' + (json.index + 1) : '') + ')'
        : '';
      throw new Error(((json && json.error) || 'Không thể tải gói ảnh.') + suffix);
    }
    return json;
  }

  function validateImagePackTransfer(pack, response) {
    if (!response.featured || !String(response.featured.public_path || '').trim()) {
      throw new Error('Máy chủ không trả về ảnh đại diện hợp lệ.');
    }
    if (!Array.isArray(response.inline_images) || response.inline_images.length !== pack.inline_images.length) {
      throw new Error('Máy chủ trả về thiếu ảnh nội dung.');
    }
    const byIndex = new Map();
    response.inline_images.forEach((item) => {
      if (!item || !Number.isInteger(item.index) || !String(item.public_path || '').trim()
        || byIndex.has(item.index)) {
        throw new Error('Mapping ảnh nội dung từ máy chủ không hợp lệ.');
      }
      byIndex.set(item.index, item);
    });
    pack.inline_images.forEach((item, index) => {
      const mapped = byIndex.get(index);
      if (!mapped || canonicalImageSrc(mapped.old_src) !== canonicalImageSrc(item.old_src)) {
        throw new Error('Mapping old_src từ máy chủ không khớp gói ảnh.');
      }
    });
    return byIndex;
  }

  function featuredImageSnapshot() {
    return {
      image: featuredImageInput ? featuredImageInput.value : '',
      alt: featuredImageAlt ? featuredImageAlt.value : '',
      title: featuredImageTitle ? featuredImageTitle.value : '',
      caption: featuredImageCaption ? featuredImageCaption.value : '',
      credit: featuredImageCredit ? featuredImageCredit.value : ''
    };
  }

  function restoreFeaturedImage(snapshot) {
    if (featuredImageInput) featuredImageInput.value = snapshot.image;
    if (featuredImageAlt) featuredImageAlt.value = snapshot.alt;
    if (featuredImageTitle) featuredImageTitle.value = snapshot.title;
    if (featuredImageCaption) featuredImageCaption.value = snapshot.caption;
    if (featuredImageCredit) featuredImageCredit.value = snapshot.credit;
    syncFeaturedImagePreview();
  }

  function applyFeaturedImagePack(pack, response) {
    if (featuredImageInput) featuredImageInput.value = String(response.featured.public_path);
    if (featuredImageAlt) featuredImageAlt.value = pack.featured.alt;
    if (featuredImageTitle) featuredImageTitle.value = pack.featured.title;
    if (featuredImageCaption) featuredImageCaption.value = pack.featured.caption;
    if (featuredImageCredit) featuredImageCredit.value = pack.featured.credit;
    syncFeaturedImagePreview();
  }

  if (imagePackApply) {
    imagePackApply.addEventListener('click', async () => {
      if (imagePackBusy || !currentImagePack) return;
      const pack = currentImagePack;
      const initial = inspectImagePack(pack);
      renderImagePackPreview(pack, initial);
      if (!initial.ok || !initial.instance) return;

      const featuredBefore = featuredImageSnapshot();
      imagePackBusy = true;
      imagePackApply.disabled = true;
      if (imagePackClose) imagePackClose.disabled = true;
      if (imagePackValidate) imagePackValidate.disabled = true;
      if (imagePackToggleJson) imagePackToggleJson.disabled = true;
      setImagePackStatus('Đang tải và kiểm tra toàn bộ ảnh...', 'busy');
      const initialHtml = initial.html;
      let transferCompleted = false;
      try {
        const response = await transferImagePack(pack);
        transferCompleted = true;
        const responseByIndex = validateImagePackTransfer(pack, response);
        const recheck = inspectImagePack(pack);
        const sameHtml = recheck.instance && recheck.instance.getContent() === initialHtml;
        const sameMappings = recheck.ok
          && recheck.mappings.length === initial.mappings.length
          && recheck.mappings.every((mapping, index) => (
            mapping.image === initial.mappings[index].image
            && mapping.raw_src === initial.mappings[index].raw_src
          ));
        const featuredUnchanged = JSON.stringify(featuredImageSnapshot()) === JSON.stringify(featuredBefore);
        if (!sameHtml || !sameMappings || !featuredUnchanged) {
          throw new Error('Nội dung bài đã thay đổi trong lúc nhập ảnh. Gói chưa được áp dụng vào Draft.');
        }

        suppressDraftSignals = true;
        try {
          recheck.instance.undoManager.transact(() => {
            recheck.mappings.forEach((mapping) => {
              const item = pack.inline_images[mapping.index];
              const remote = responseByIndex.get(mapping.index);
              mapping.image.setAttribute('src', String(remote.public_path));
              writeInlineImageMetadata(recheck.instance, mapping.image, item);
            });
          });
          applyFeaturedImagePack(pack, response);
        } catch (error) {
          recheck.instance.setContent(initialHtml);
          restoreFeaturedImage(featuredBefore);
          throw error;
        } finally {
          suppressDraftSignals = false;
        }

        recheck.instance.nodeChanged();
        markDraftDirty();
        syncPreview();
        currentImagePack = null;
        const inlineCount = recheck.mappings.length;
        setImagePackStatus(
          'Đã áp dụng 1 ảnh đại diện và ' + inlineCount + ' ảnh nội dung. Hãy kiểm tra lại bài rồi Lưu nháp.',
          'success'
        );
        imagePackApply.disabled = true;
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Không thể áp dụng gói ảnh.';
        renderImagePackList(imagePackErrors, [message]);
        setImagePackStatus(
          message + (transferCompleted ? ' Các file đã tải có thể còn lại trên server.' : ''),
          'error'
        );
      } finally {
        imagePackBusy = false;
        if (imagePackClose) imagePackClose.disabled = false;
        if (imagePackValidate) imagePackValidate.disabled = false;
        if (imagePackToggleJson) imagePackToggleJson.disabled = false;
        if (currentImagePack) {
          const latest = inspectImagePack(currentImagePack);
          if (imagePackApply) imagePackApply.disabled = !latest.ok;
        }
      }
    });
  }

  /* ── Heartbeat ────────────────────────────────────── */
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

    <?php if ($approvedCheckpoint !== null): ?>
        <div class="editorial-approved-checkpoint-banner">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <strong>Admin đã duyệt Revision #<?= editorial_h((string) ($approvedCheckpoint['revision_no'] ?? '')) ?></strong>
                <?php if ($approvedCheckpointUser !== null): ?>
                    · bởi <?= editorial_h((string) ($approvedCheckpointUser['display_name'] ?? $approvedCheckpointUser['username'])) ?>
                <?php endif; ?>
                <?php if (trim((string) ($state['approved_at'] ?? '')) !== ''): ?>
                    · <?= editorial_h(editorial_format_datetime((string) $state['approved_at'])) ?>
                <?php endif; ?>
                <small>Những thay đổi sau lần duyệt này chưa được Admin duyệt lại.</small>
            </div>
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
        <input type="hidden" name="review_note" id="reviewSubmissionNote" value="">

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
                    <button type="button" class="editorial-handoff-btn" data-editor-action="handoff_active_stage" <?= $handoffEnabled ? 'title="Bàn giao hồ sơ cuối của bản đã Publish lên Google Drive và Sheet."' : 'disabled title="' . editorial_h($handoffDisabledReason) . '"' ?>>
                        <i class="fa-solid fa-cloud-arrow-up"></i> Bàn giao Drive + Sheet
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
                <button type="button" class="editorial-workflow-help-btn" title="Chặng 1 và Chặng 2 là mốc biên tập. Publish đưa bản lên website. Bàn giao Drive + Sheet lưu hồ sơ cuối của bản đã Publish và rebuild public thành công." aria-label="Trợ giúp luồng biên tập">
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
        <div class="editorial-image-pack-entry">
            <button type="button" id="imagePackOpen" class="editorial-image-pack-open">
                <i class="fa-solid fa-images"></i> Nhận ảnh từ Image Creator
            </button>
            <small>Tải Featured và thay đúng các ảnh nội dung theo <code>old_src</code>; chưa tự lưu Draft.</small>
        </div>
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
                    <div class="editorial-featured-image-control">
                        <input type="text" id="featuredImageInput" name="featured_image" value="<?= editorial_h((string) ($form['featured_image'] ?? '')) ?>" class="field-input" placeholder="VD: uploads/articles/2026/09/anh.jpg hoặc URL ảnh">
                        <input type="file" id="featuredImageFile" accept="image/jpeg,image/png,image/gif,image/webp" data-nondraft-field hidden>
                        <div class="editorial-featured-image-actions">
                            <button type="button" id="featuredImageUpload" class="editorial-media-button">
                                <i class="fa-solid fa-upload"></i> Tải ảnh đại diện
                            </button>
                            <button type="button" id="featuredImageClear" class="editorial-media-button editorial-media-button--muted">
                                <i class="fa-solid fa-xmark"></i> Xóa lựa chọn
                            </button>
                        </div>
                        <div class="editorial-featured-image-preview">
                            <img id="featuredImagePreview" alt="Xem trước ảnh đại diện" hidden>
                            <span id="featuredImagePreviewEmpty">Chưa chọn ảnh đại diện</span>
                        </div>
                        <div class="filter-field editorial-featured-image-alt-field">
                            <label for="featuredImageAlt">Mô tả ảnh / Alt text</label>
                            <input type="text" id="featuredImageAlt" name="featured_image_alt" value="<?= editorial_h((string) ($form['featured_image_alt'] ?? '')) ?>" class="field-input">
                        </div>
                        <details class="editorial-featured-image-extra">
                            <summary>Thông tin bổ sung</summary>
                            <div class="editorial-featured-image-extra__fields">
                                <div class="filter-field">
                                    <label for="featuredImageTitle">Title</label>
                                    <input type="text" id="featuredImageTitle" name="featured_image_title" value="<?= editorial_h((string) ($form['featured_image_title'] ?? '')) ?>" class="field-input">
                                </div>
                                <div class="filter-field">
                                    <label for="featuredImageCaption">Caption</label>
                                    <textarea id="featuredImageCaption" name="featured_image_caption" rows="2" class="field-input"><?= editorial_h((string) ($form['featured_image_caption'] ?? '')) ?></textarea>
                                </div>
                                <div class="filter-field">
                                    <label for="featuredImageCredit">Credit / Nguồn ảnh</label>
                                    <input type="text" id="featuredImageCredit" name="featured_image_credit" value="<?= editorial_h((string) ($form['featured_image_credit'] ?? '')) ?>" class="field-input">
                                </div>
                            </div>
                        </details>
                    </div>
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
            <button type="button" class="editorial-handoff-btn" data-editor-action="handoff_active_stage" <?= $handoffEnabled ? 'title="Bàn giao hồ sơ cuối của bản đã Publish lên Google Drive và Sheet."' : 'disabled title="' . editorial_h($handoffDisabledReason) . '"' ?>>
                <i class="fa-solid fa-cloud-arrow-up"></i> Bàn giao Drive + Sheet
            </button>
            <button type="submit" form="exitWorkspaceForm" class="editorial-exit-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Thoát
            </button>
        </section>

        <dialog id="imagePackDialog" class="editorial-image-pack-dialog">
            <div class="editorial-image-pack-dialog__head">
                <div>
                    <strong>NHẬP ẢNH TỪ KTDT IMAGE CREATOR</strong>
                    <small>Clipboard được đọc trước. Gói chỉ thay ảnh trong Draft hiện tại sau khi kiểm tra toàn bộ.</small>
                </div>
                <button type="button" id="imagePackClose" class="editorial-dialog-close" aria-label="Đóng">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="editorial-image-pack-summary">
                <div class="editorial-image-pack-summary__article">
                    <span>Bài</span>
                    <strong id="imagePackArticle">Chưa nhận gói ảnh</strong>
                </div>
                <div><span>Featured</span><strong id="imagePackFeatured">—</strong></div>
                <div><span>Inline</span><strong id="imagePackInline">0 ảnh</strong></div>
                <div><span>Đối chiếu</span><strong id="imagePackMatch">✓ Match: 0/0</strong></div>
                <div><span>Xung đột</span><strong id="imagePackConflict">⚠ Conflict: 0</strong></div>
            </div>

            <ul id="imagePackWarnings" class="editorial-image-pack-messages is-warning" hidden></ul>
            <ul id="imagePackErrors" class="editorial-image-pack-messages is-error" hidden></ul>
            <p id="imagePackStatus" class="editorial-image-pack-status">Đang chờ gói ảnh...</p>

            <div id="imagePackJsonWrap" class="editorial-image-pack-json" hidden>
                <label for="imagePackJson">Dán JSON từ KTDT Image Creator</label>
                <textarea
                    id="imagePackJson"
                    rows="9"
                    spellcheck="false"
                    data-nondraft-field
                    placeholder='{"protocol":"KTDT_IMAGE_PACK","version":1,...}'
                ></textarea>
                <button type="button" id="imagePackValidate" class="editorial-media-button">
                    <i class="fa-solid fa-magnifying-glass"></i> Kiểm tra gói
                </button>
            </div>

            <div class="editorial-image-pack-dialog__actions">
                <button type="button" id="imagePackToggleJson" class="editorial-media-button editorial-media-button--muted">
                    Dán JSON thủ công
                </button>
                <button type="button" id="imagePackApply" class="editorial-image-pack-apply" disabled>
                    <i class="fa-solid fa-cloud-arrow-down"></i> ÁP DỤNG GÓI ẢNH
                </button>
            </div>
        </dialog>

        <dialog id="reviewSubmissionDialog" class="editorial-review-submit-dialog">
            <div class="editorial-review-submit-dialog__head">
                <div>
                    <strong>Gửi Admin duyệt bài</strong>
                    <small>Hệ thống sẽ lưu nội dung hiện tại trước khi gửi.</small>
                </div>
                <button type="button" id="reviewSubmissionCancel" class="editorial-dialog-close" aria-label="Đóng">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <label for="reviewSubmissionNoteField">Ghi chú cho Admin <span>(không bắt buộc)</span></label>
            <textarea
                id="reviewSubmissionNoteField"
                rows="5"
                maxlength="2000"
                data-nondraft-field
                placeholder="Ví dụ: Đã cập nhật số liệu 2026, nhờ Admin kiểm tra kỹ bảng ở mục 3..."
            ></textarea>
            <small class="editorial-review-submit-dialog__hint">Ghi chú được lưu cùng đúng phiên bản Chặng 2 gửi duyệt.</small>
            <div class="editorial-review-submit-dialog__actions">
                <button type="button" class="editorial-return-btn" onclick="document.getElementById('reviewSubmissionDialog').close()">Hủy</button>
                <button type="button" id="reviewSubmissionConfirm" class="editorial-review-submit-btn">
                    <i class="fa-solid fa-paper-plane"></i> Lưu và gửi duyệt
                </button>
            </div>
        </dialog>
    </form>

    <form id="exitWorkspaceForm" method="post" action="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="exit_workspace">
        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
        <input type="hidden" name="lock_token" value="<?= editorial_h($lockToken) ?>">
    </form>

</section>

<?php editorial_layout_footer(); ?>
