<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_role(['admin']);

$currentUser = editorial_current_user();
$adminUserId = (string) $currentUser['user_id'];
$articleId = trim((string) ($_GET['id'] ?? $_POST['article_id'] ?? ''));

// ─── POST handling ───────────────────────────────────────────────

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    switch ($intent) {
        case 'approve':
            $result = editorial_approve_review($articleId, $adminUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'return_review':
            if (array_key_exists('return_note_b64', $_POST)) {
                $encodedReturnNote = (string) $_POST['return_note_b64'];
                $decodedReturnNote = $encodedReturnNote === ''
                    ? false
                    : base64_decode($encodedReturnNote, true);
                if ($decodedReturnNote === false) {
                    editorial_flash_set('danger', 'Nội dung phản hồi gửi lên không hợp lệ. Bài viết chưa bị trả lại.');
                    break;
                }
                $returnNote = trim($decodedReturnNote);
            } else {
                // Legacy/internal callers can still use the previous raw field.
                // The production form below uses only return_note_b64.
                $returnNote = trim((string) ($_POST['return_note'] ?? ''));
            }
            $result = editorial_return_review($articleId, $adminUserId, $returnNote);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'force_unlock':
            $result = editorial_force_unlock($articleId, $adminUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'reassign':
            $newUserId = trim((string) ($_POST['new_user_id'] ?? ''));
            $result = editorial_reassign_article($articleId, $adminUserId, $newUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'release':
            $result = editorial_release_assignment($articleId, $adminUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'force_reassign':
            $newUserId = trim((string) ($_POST['new_user_id'] ?? ''));
            $result = editorial_reassign_article($articleId, $adminUserId, $newUserId, true);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        case 'force_release':
            $result = editorial_release_assignment($articleId, $adminUserId, true);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
            break;
        default:
            editorial_flash_set('danger', 'Hành động không hợp lệ.');
            break;
    }

    if ($articleId !== '') {
        editorial_redirect(editorial_url('review.php?id=' . urlencode($articleId)));
    } else {
        editorial_redirect(editorial_url('review.php'));
    }
}

// ─── Render ──────────────────────────────────────────────────────

if ($articleId !== '') {
    // Detail Mode
    $article = editorial_find_article($articleId);
    if ($article === null) {
        editorial_flash_set('danger', 'Không tìm thấy bài viết.');
        editorial_redirect(editorial_url('review.php'));
    }

    $state = editorial_get_article_state($articleId);
    if ($state === null) {
        editorial_flash_set('danger', 'Bài viết chưa có trạng thái.');
        editorial_redirect(editorial_url('review.php'));
    }

    $status = (string) $state['status'];
    $detailRevision = editorial_resolve_review_dossier_revision($articleId, $state);
    $revisionId = !empty($detailRevision['ok'])
        ? (string) (($detailRevision['revision']['id'] ?? ''))
        : '';

    $revision = null;
    $snapshot = null;
    $payload = [];
    $isVerified = false;

    if (!empty($detailRevision['ok'])) {
        $revision = (array) $detailRevision['revision'];
        $payload = (array) $detailRevision['snapshot'];
        $isVerified = true;
    }
    $reviewStageBundle = $revision !== null && $isVerified
        ? editorial_resolve_review_stage_bundle($articleId, $revision)
        : ['ok' => false, 'legacy' => false, 'message' => 'Không thể xác thực phiên bản gửi duyệt để đối chiếu.'];
    $submissionContext = $revision !== null
        ? editorial_get_review_submission_context($articleId, (string) ($revision['id'] ?? ''))
        : null;
    $reviewNote = trim((string) ($submissionContext['note'] ?? ''));
    $reviewRequestedAt = (string) (
        $submissionContext['created_at']
        ?? $state['review_requested_at']
        ?? $revision['created_at']
        ?? ''
    );

    $assignedUser = null;
    if (!empty($state['assigned_user_id'])) {
        $assignedUser = editorial_find_user_by_id((string) $state['assigned_user_id']);
    }

    $requester = null;
    if (!empty($submissionContext['actor_user_id'])) {
        $requester = editorial_find_user_by_id((string) $submissionContext['actor_user_id']);
    }
    if ($requester === null && !empty($state['review_requested_by'])) {
        $requester = editorial_find_user_by_id((string) $state['review_requested_by']);
    }
    $approver = null;
    if (!empty($state['approved_by'])) {
        $approver = editorial_find_user_by_id((string) $state['approved_by']);
    }
    $isHistoricalApprovedDossier = in_array($status, ['editing', 'returned'], true)
        && !empty($state['approved_revision_id']);

    $htmlPath = editorial_resolve_article_path($article);
    $liveConflict = true;
    $currentLiveHash = null;
    if ($htmlPath !== null) {
        $currentLiveHash = editorial_live_hash($htmlPath);
        $liveConflict = $currentLiveHash === null
            || !hash_equals((string) ($state['base_live_hash'] ?? ''), $currentLiveHash);
    }

    $lock = editorial_get_article_lock($articleId);
    $activeUsers = array_filter(editorial_list_users(), fn($u) => !empty($u['is_active']));
    $baseline = !empty($reviewStageBundle['ok']) ? (array) $reviewStageBundle['baseline'] : null;
    $stage1 = !empty($reviewStageBundle['ok']) ? (array) $reviewStageBundle['stage1'] : null;
    $stage2 = !empty($reviewStageBundle['ok']) ? (array) $reviewStageBundle['stage2'] : null;
    $stage1CompareUrl = $baseline !== null && $stage1 !== null
        ? editorial_url('compare.php?id=' . urlencode($articleId)
            . '&from=' . urlencode((string) $baseline['id'])
            . '&to=' . urlencode((string) $stage1['id']))
        : '';
    $stage2CompareUrl = $baseline !== null && $stage2 !== null
        ? editorial_url('compare.php?id=' . urlencode($articleId)
            . '&from=' . urlencode((string) $baseline['id'])
            . '&to=' . urlencode((string) $stage2['id']))
        : '';

    $latestReturnNote = '';
    if ($status === 'returned') {
        $note = editorial_get_latest_return_note($articleId);
        $latestReturnNote = $note ?? '';
    }

    $returnEditorScript = <<<'JS'
(() => {
  const textarea = document.getElementById('returnReviewNote');
  const wrapper = document.getElementById('returnReviewEditor');
  const expand = document.getElementById('returnReviewExpand');
  const collapse = document.getElementById('returnReviewCollapse');
  const counter = document.getElementById('returnReviewCounter');
  const form = document.getElementById('returnReviewForm');
  const encodedNote = document.getElementById('returnReviewNoteB64');
  const submitStatus = document.getElementById('returnReviewSubmitStatus');
  const decisionCard = document.getElementById('returnReviewDecisionCard');
  if (!textarea || !wrapper || !counter || !form || !encodedNote) return;

  const limit = Number(textarea.maxLength) || 10000;
  const format = new Intl.NumberFormat('vi-VN');
  let expanded = false;

  const updateCounter = () => {
    counter.textContent = format.format(textarea.value.length) + ' / ' + format.format(limit) + ' ký tự';
    counter.classList.toggle('is-near-limit', textarea.value.length >= limit * 0.9);
  };
  const collapseEditor = () => {
    if (!expanded) return;
    expanded = false;
    wrapper.classList.remove('is-expanded');
    if (decisionCard) decisionCard.classList.remove('is-return-editor-expanded');
    document.body.classList.remove('editorial-return-editor-open');
    textarea.focus();
  };
  const expandEditor = () => {
    if (expanded) return;
    expanded = true;
    wrapper.classList.add('is-expanded');
    if (decisionCard) decisionCard.classList.add('is-return-editor-expanded');
    document.body.classList.add('editorial-return-editor-open');
    textarea.focus();
  };
  const encodeUtf8Base64 = (value) => {
    if (typeof TextEncoder !== 'function') {
      throw new Error('Trình duyệt không hỗ trợ mã hóa UTF-8.');
    }
    const bytes = new TextEncoder().encode(value);
    let binary = '';
    const chunkSize = 0x8000;
    for (let index = 0; index < bytes.length; index += chunkSize) {
      binary += String.fromCharCode(...bytes.subarray(index, index + chunkSize));
    }
    return btoa(binary);
  };

  textarea.addEventListener('input', updateCounter);
  if (expand) expand.addEventListener('click', expandEditor);
  if (collapse) collapse.addEventListener('click', collapseEditor);
  form.addEventListener('submit', (event) => {
    try {
      encodedNote.value = encodeUtf8Base64(textarea.value);
      if (submitStatus) {
        submitStatus.hidden = true;
        submitStatus.textContent = '';
      }
    } catch (error) {
      event.preventDefault();
      encodedNote.value = '';
      if (submitStatus) {
        submitStatus.textContent = 'Không thể chuẩn bị nội dung phản hồi để gửi. Vui lòng thử lại.';
        submitStatus.hidden = false;
      }
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && expanded) {
      event.preventDefault();
      collapseEditor();
    }
  });
  updateCounter();
})();
JS;

    editorial_layout_header([
        'title' => 'Duyệt bài',
        'active' => 'review',
        'description' => 'Chi tiết duyệt: ' . $article['title'],
        'inner_script' => $returnEditorScript,
    ]);
    ?>
    <section class="admin-panel editorial-review-dossier">
        <header class="editorial-review-dossier__header">
            <div>
                <a href="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-review-back">
                    <i class="fa-solid fa-arrow-left"></i> Danh sách chờ duyệt
                </a>
                <h2><?= editorial_h($article['title']) ?></h2>
                <div class="editorial-review-header-meta">
                    <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($status)) ?>">
                        <?= editorial_h(editorial_status_label($status)) ?>
                    </span>
                    <code><?= editorial_h($articleId) ?></code>
                    <a href="<?= editorial_h(editorial_public_article_url($article)) ?>" target="_blank" rel="noopener">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem bài public
                    </a>
                </div>
            </div>
            <?php if ($status === 'approved'): ?>
                <a href="<?= editorial_h(editorial_url('publish.php?id=' . urlencode($articleId))) ?>" class="editorial-approve-btn">
                    <i class="fa-solid fa-rocket"></i> Chuẩn bị Publish
                </a>
            <?php endif; ?>
        </header>

        <?php if ($liveConflict): ?>
            <div class="flash flash-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong>Không thể duyệt an toàn:</strong> File HTML gốc đã thay đổi hoặc không thể xác thực.
            </div>
        <?php endif; ?>

        <?php if ($status === 'returned' && $latestReturnNote !== ''): ?>
            <div class="flash flash-warning">
                <strong>Lý do trả về gần nhất:</strong><br>
                <?= nl2br(editorial_h($latestReturnNote)) ?>
            </div>
        <?php endif; ?>

        <div class="editorial-review-cockpit">
            <section class="editorial-review-summary-card">
                <div class="editorial-review-summary-card__head">
                    <div>
                        <span>Hồ sơ đang xem</span>
                        <strong><?= editorial_h((string) ($detailRevision['label'] ?? 'Chưa có hồ sơ duyệt')) ?></strong>
                    </div>
                    <?php if ($isVerified): ?>
                        <span class="editorial-review-integrity is-valid">
                            <i class="fa-solid fa-circle-check"></i> Snapshot hợp lệ
                        </span>
                    <?php else: ?>
                        <span class="editorial-review-integrity is-invalid">
                            <i class="fa-solid fa-circle-xmark"></i> Không xác thực
                        </span>
                    <?php endif; ?>
                </div>

                <div class="editorial-review-key-facts">
                    <div>
                        <span>Người gửi</span>
                        <strong><?= editorial_h($requester ? (string) ($requester['display_name'] ?? $requester['username']) : (string) ($revision['created_by'] ?? 'Không rõ')) ?></strong>
                    </div>
                    <div>
                        <span>Gửi lúc</span>
                        <strong><?= $reviewRequestedAt !== '' ? editorial_h(editorial_format_datetime($reviewRequestedAt)) : '—' ?></strong>
                    </div>
                    <div>
                        <span>Phiên bản</span>
                        <strong><?= $revision ? 'Revision #' . editorial_h((string) $revision['revision_no']) : '—' ?></strong>
                    </div>
                    <div>
                        <span>Người phụ trách</span>
                        <strong><?= editorial_h($assignedUser ? (string) ($assignedUser['display_name'] ?? $assignedUser['username']) : 'Chưa có') ?></strong>
                    </div>
                </div>

                <div class="editorial-review-note <?= $reviewNote === '' ? 'is-empty' : '' ?>">
                    <span><i class="fa-solid fa-message"></i> Ghi chú của biên tập viên</span>
                    <?php if ($reviewNote !== ''): ?>
                        <p><?= nl2br(editorial_h($reviewNote)) ?></p>
                    <?php else: ?>
                        <p>Không có ghi chú kèm theo lần gửi duyệt này.</p>
                    <?php endif; ?>
                </div>

                <div class="editorial-review-stage-compare">
                    <h3>Đối chiếu bắt buộc</h3>
                    <?php if ($stage1CompareUrl !== '' && $stage2CompareUrl !== ''): ?>
                        <div class="editorial-review-compare-actions">
                            <a href="<?= editorial_h($stage1CompareUrl) ?>" class="editorial-compare-btn" target="_blank" rel="noopener">
                                <i class="fa-solid fa-code-compare"></i>
                                <span>Bài gốc ↔ Chặng 1<small>Revision #<?= editorial_h((string) ($stage1['revision_no'] ?? '')) ?></small></span>
                            </a>
                            <a href="<?= editorial_h($stage2CompareUrl) ?>" class="editorial-compare-btn is-primary" target="_blank" rel="noopener">
                                <i class="fa-solid fa-code-compare"></i>
                                <span>Bài gốc ↔ Chặng 2<small>Revision #<?= editorial_h((string) ($stage2['revision_no'] ?? '')) ?></small></span>
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="editorial-review-compare-warning"><?= editorial_h((string) ($reviewStageBundle['message'] ?? 'Chưa có đủ dữ liệu để đối chiếu.')) ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="editorial-review-decision-card" id="returnReviewDecisionCard">
                <?php if ($status === 'ready_review'): ?>
                    <h3>Quyết định duyệt</h3>
                    <p>Đối chiếu hai chặng trước khi chọn hành động.</p>
                    <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>">
                        <?= editorial_csrf_input() ?>
                        <input type="hidden" name="_intent" value="approve">
                        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                        <button type="submit" class="editorial-review-approve-action" <?= (!$isVerified || empty($reviewStageBundle['ok']) || $liveConflict) ? 'disabled title="Cần snapshot hợp lệ, đủ hai đối chiếu và không có xung đột file gốc."' : 'onclick="return confirm(\'Bạn xác nhận phê duyệt bài viết này?\');"' ?>>
                            <i class="fa-solid fa-check"></i> Phê duyệt bài
                        </button>
                    </form>
                    <details class="editorial-review-return-action">
                        <summary><i class="fa-solid fa-rotate-left"></i> Trả lại để chỉnh sửa</summary>
                        <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-review-return-form" id="returnReviewForm">
                            <?= editorial_csrf_input() ?>
                            <input type="hidden" name="_intent" value="return_review">
                            <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                            <input type="hidden" name="return_note_b64" id="returnReviewNoteB64" value="">
                            <div class="editorial-return-review-editor" id="returnReviewEditor">
                                <div class="editorial-return-review-editor__head">
                                    <label for="returnReviewNote">Lý do trả lại</label>
                                    <button type="button" class="editorial-return-review-expand" id="returnReviewExpand">
                                        <i class="fa-solid fa-expand"></i> Phóng to
                                    </button>
                                    <button type="button" class="editorial-return-review-collapse" id="returnReviewCollapse">
                                        <i class="fa-solid fa-compress"></i> Thu nhỏ
                                    </button>
                                </div>
                                <textarea id="returnReviewNote" required minlength="1" maxlength="10000" rows="10" placeholder="Nêu rõ các phần cần chỉnh, checklist, ảnh hoặc caption cần sửa..."></textarea>
                                <div class="editorial-return-review-editor__foot">
                                    <span class="editorial-return-review-counter" id="returnReviewCounter" aria-live="polite"></span>
                                </div>
                            </div>
                            <p class="editorial-return-review-submit-status" id="returnReviewSubmitStatus" role="alert" hidden></p>
                            <button type="submit" class="editorial-return-btn">Gửi yêu cầu chỉnh lại</button>
                        </form>
                    </details>
                <?php elseif ($status === 'approved' || $isHistoricalApprovedDossier): ?>
                    <h3>Thông tin phê duyệt</h3>
                    <dl>
                        <dt>Người duyệt</dt>
                        <dd><?= editorial_h($approver ? (string) ($approver['display_name'] ?? $approver['username']) : (string) ($state['approved_by'] ?? 'Không rõ')) ?></dd>
                        <dt>Thời gian</dt>
                        <dd><?= !empty($state['approved_at']) ? editorial_h(editorial_format_datetime((string) $state['approved_at'])) : '—' ?></dd>
                        <?php if ($isHistoricalApprovedDossier): ?>
                            <dt>Trạng thái hiện tại</dt>
                            <dd><?= editorial_h(editorial_status_label($status)) ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <h3>Hồ sơ chỉ đọc</h3>
                    <p>Trạng thái hiện tại không có hành động duyệt.</p>
                <?php endif; ?>
            </aside>
        </div>

        <details class="editor-info-panel editorial-review-admin-tools">
            <summary><i class="fa-solid fa-user-gear"></i> Quản lý phân công & khóa</summary>
            <div class="editorial-review-admin-tools__body">
                <p><strong>Người phụ trách:</strong> <?= editorial_h($assignedUser ? (string) ($assignedUser['display_name'] ?? $assignedUser['username']) : 'Chưa có') ?></p>
                <p><strong>Trạng thái khóa:</strong> <?= $lock ? '<span class="editorial-review-lock is-locked"><i class="fa-solid fa-lock"></i> Đang bị khóa</span>' : '<span class="editorial-review-lock"><i class="fa-solid fa-unlock"></i> Tự do</span>' ?></p>

                <?php if (in_array($status, ['editing', 'returned'], true)): ?>
                    <div class="editorial-review-admin-tool-actions">
                        <?php if ($lock): ?>
                            <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>">
                                <?= editorial_csrf_input() ?>
                                <input type="hidden" name="_intent" value="force_unlock">
                                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                                <button type="submit" class="admin-btn admin-btn-danger" onclick="return confirm('Bạn có chắc chắn muốn mở khóa bắt buộc?');">
                                    <i class="fa-solid fa-unlock-keyhole"></i> Mở khóa
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-review-reassign-form">
                            <?= editorial_csrf_input() ?>
                            <input type="hidden" name="_intent" value="reassign">
                            <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                            <select name="new_user_id" required>
                                <option value="">Chọn người phụ trách mới</option>
                                <?php foreach ($activeUsers as $u): ?>
                                    <option value="<?= editorial_h((string) $u['id']) ?>"><?= editorial_h((string) ($u['display_name'] ?? $u['username'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="admin-btn admin-btn-primary">Giao việc</button>
                        </form>
                        <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>">
                            <?= editorial_csrf_input() ?>
                            <input type="hidden" name="_intent" value="release">
                            <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                            <button type="submit" class="admin-btn" onclick="return confirm('Gỡ bỏ phụ trách bài viết này?');">Gỡ phụ trách</button>
                        </form>
                    </div>

                    <details class="editorial-review-force-tools">
                        <summary>Tùy chọn ép buộc</summary>
                        <p>Dùng khi bản nháp cũ không thể tiếp tục và chấp nhận xóa dữ liệu chưa bảo toàn.</p>
                        <div class="editorial-review-admin-tool-actions">
                            <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-review-reassign-form">
                                <?= editorial_csrf_input() ?>
                                <input type="hidden" name="_intent" value="force_reassign">
                                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                                <select name="new_user_id" required>
                                    <option value="">Chọn người phụ trách mới</option>
                                    <?php foreach ($activeUsers as $u): ?>
                                        <option value="<?= editorial_h((string) $u['id']) ?>"><?= editorial_h((string) ($u['display_name'] ?? $u['username'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="admin-btn admin-btn-danger" onclick="return confirm('Bản nháp sẽ bị xóa. Bạn chắc chắn muốn giao lại bắt buộc?');">Giao lại (Force)</button>
                            </form>
                            <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>">
                                <?= editorial_csrf_input() ?>
                                <input type="hidden" name="_intent" value="force_release">
                                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                                <button type="submit" class="admin-btn admin-btn-danger" onclick="return confirm('Bản nháp sẽ bị xóa. Bạn chắc chắn muốn gỡ bỏ bắt buộc?');">Gỡ bỏ (Force)</button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </details>

        <?php if ($payload): ?>
            <details class="editor-info-panel editorial-review-secondary-panel">
                <summary><i class="fa-solid fa-list"></i> Metadata</summary>
                <div style="padding:16px;">
                    <table class="admin-table" style="font-size:0.9rem;">
                        <tbody>
                            <tr><td style="width:150px;"><strong>Tiêu đề</strong></td><td><?= editorial_h((string)($payload['title'] ?? '')) ?></td></tr>
                            <tr><td><strong>Mô tả (Excerpt)</strong></td><td><?= editorial_h((string)($payload['excerpt'] ?? '')) ?></td></tr>
                            <tr><td><strong>Ngày đăng</strong></td><td><?= editorial_h((string)($payload['publish_date'] ?? '')) ?></td></tr>
                            <tr><td><strong>Ngày sửa</strong></td><td><?= editorial_h((string)($payload['modified_date'] ?? '')) ?></td></tr>
                            <tr><td><strong>Tags</strong></td><td><?= editorial_h((string)($payload['tags_text'] ?? '')) ?></td></tr>
                            <tr><td><strong>Mục</strong></td><td><?= editorial_h((string)($payload['section_label'] ?? '')) ?></td></tr>
                            <tr><td><strong>Loại thư viện</strong></td><td><?= editorial_h((string)($payload['library_kind_label'] ?? '')) ?></td></tr>
                            <tr><td><strong>Chủ đề cấp 1</strong></td><td><?= editorial_h((string)($payload['topic_lv1_label'] ?? '')) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </details>
            
            <details class="editor-info-panel editorial-review-secondary-panel" open>
                <summary><i class="fa-solid fa-file-lines"></i> Nội dung (Prose Preview)</summary>
                <div style="padding:16px; background:#fff;">
                    <?php
                    $proseHtml = (string) ($payload['prose_html'] ?? '');
                    $iframeHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif; line-height:1.6; color:#333; padding:10px; margin:0;} img{max-width:100%; height:auto;}</style></head><body>' . $proseHtml . '</body></html>';
                    ?>
                    <iframe sandbox="" srcdoc="<?= editorial_h($iframeHtml) ?>" style="width:100%; height:600px; border:1px solid #ccc; border-radius:4px; background:#fafafa;"></iframe>
                </div>
            </details>
        <?php endif; ?>

    </section>
    <?php
    editorial_layout_footer();

} else {
    // Queue Mode
    $filters = editorial_taxonomy_filter_params($_GET);
    $q = $filters['q'];

    $db = editorial_db();
    $readyStates = $db->query("SELECT * FROM editorial_article_state WHERE status = 'ready_review' ORDER BY review_requested_at ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $recentDecisions = editorial_get_recent_review_decisions(20);

    $userIdsToPreload = [];
    foreach ($readyStates as $s) {
        if (!empty($s['assigned_user_id'])) $userIdsToPreload[] = (string) $s['assigned_user_id'];
        if (!empty($s['review_requested_by'])) $userIdsToPreload[] = (string) $s['review_requested_by'];
        if (!empty($s['approved_by'])) $userIdsToPreload[] = (string) $s['approved_by'];
    }
    foreach ($recentDecisions as $dec) {
        if (!empty($dec['actor_user_id'])) $userIdsToPreload[] = (string) $dec['actor_user_id'];
    }
    $queueSubmissionContexts = editorial_get_review_submission_contexts(array_values(array_filter(
        array_map(static fn(array $state): string => (string) ($state['review_revision_id'] ?? ''), $readyStates)
    )));
    foreach ($queueSubmissionContexts as $submissionContext) {
        if (!empty($submissionContext['actor_user_id'])) {
            $userIdsToPreload[] = (string) $submissionContext['actor_user_id'];
        }
    }

    // Preload article states for recent decisions to show current status and
    // owner, and collect those user IDs too.
    $decisionStates = [];
    foreach ($recentDecisions as $dec) {
        $dState = editorial_get_article_state((string) $dec['article_id']);
        if ($dState !== null) {
            $decisionStates[(string) $dec['article_id']] = $dState;
            if (!empty($dState['assigned_user_id'])) {
                $userIdsToPreload[] = (string) $dState['assigned_user_id'];
            }
        }
    }

    $userNames = editorial_preload_user_names(array_values(array_unique($userIdsToPreload)));
    $filterStates = static function (array $states) use ($filters, $q): array {
        $items = [];
        foreach ($states as $state) {
            $article = editorial_find_article((string) ($state['article_id'] ?? ''));
            if ($article === null || !editorial_article_matches_taxonomy($article, $filters)) {
                continue;
            }
            if ($q !== '') {
                $qLower = mb_strtolower($q, 'UTF-8');
                $titleLower = mb_strtolower((string) $article['title'], 'UTF-8');
                if (!str_contains($titleLower, $qLower)
                    && !str_contains(mb_strtolower((string) $article['id'], 'UTF-8'), $qLower)) {
                    continue;
                }
            }
            $items[] = ['state' => $state, 'article' => $article];
        }
        return $items;
    };
    $filterDecisions = static function (array $decisions) use ($filters, $q): array {
        $items = [];
        foreach ($decisions as $dec) {
            $article = editorial_find_article((string) ($dec['article_id'] ?? ''));
            if ($article === null || !editorial_article_matches_taxonomy($article, $filters)) {
                continue;
            }
            if ($q !== '') {
                $qLower = mb_strtolower($q, 'UTF-8');
                $titleLower = mb_strtolower((string) $article['title'], 'UTF-8');
                if (!str_contains($titleLower, $qLower)
                    && !str_contains(mb_strtolower((string) $article['id'], 'UTF-8'), $qLower)) {
                    continue;
                }
            }
            $items[] = ['decision' => $dec, 'article' => $article];
        }
        return $items;
    };
    $readyItems = $filterStates($readyStates);
    $recentDecisionItems = $filterDecisions($recentDecisions);
    $returnFeedbackSourceNumber = 0;
    $sidebarTreeHtml = editorial_render_taxonomy_tree($filters, 'review.php', ['show_counts' => false]);

    editorial_layout_header([
        'title' => 'Danh sách chờ duyệt',
        'active' => 'review',
        'description' => 'Các bài viết đang chờ phê duyệt hoặc đã xử lý gần đây.',
        'sidebar_extra_html' => $sidebarTreeHtml,
        'sidebar_note' => 'Lọc hàng đợi theo phân loại',
    ]);
    ?>
    <section class="editorial-filter-bar">
        <form method="get" action="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-filter-form">
            <div class="editorial-filter-row">
                <div class="field-input editorial-filter-search">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" name="q" value="<?= editorial_h($q) ?>" placeholder="Tìm theo tiêu đề, ID bài viết…">
                </div>
                <input type="hidden" name="section" value="<?= editorial_h($filters['section']) ?>">
                <input type="hidden" name="library_kind_key" value="<?= editorial_h($filters['library_kind_key']) ?>">
                <input type="hidden" name="topic_lv1_key" value="<?= editorial_h($filters['topic_lv1_key']) ?>">
                <input type="hidden" name="topic_lv2_key" value="<?= editorial_h($filters['topic_lv2_key']) ?>">
                <input type="hidden" name="topic_lv3_key" value="<?= editorial_h($filters['topic_lv3_key']) ?>">
                <button type="submit" class="editorial-filter-btn"><i class="fa-solid fa-filter"></i> Lọc</button>
                <?php if (array_filter($filters, static fn(string $value): bool => $value !== '')): ?>
                    <a href="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-filter-clear">Xóa bộ lọc</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="admin-panel">
        <div class="panel-head">
            <h2>Chờ duyệt (<?= count($readyItems) ?>)</h2>
            <p>Kiểm tra hồ sơ và bằng chứng biên tập trước khi phê duyệt.</p>
        </div>
        <?php if (empty($readyItems)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-folder-open"></i>
                <p>Không có bài viết nào cần duyệt.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Bài viết</th>
                            <th>Người gửi</th>
                            <th>Phiên bản</th>
                            <th>Gửi duyệt lúc</th>
                            <th>Trạng thái</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($readyItems as $item):
                            $s = $item['state'];
                            $a = $item['article'];
                            $ownerId = (string) ($s['assigned_user_id'] ?? '');
                            $ownerName = $ownerId !== '' ? ($userNames[$ownerId] ?? $ownerId) : 'Không rõ';
                            $requesterId = (string) ($s['review_requested_by'] ?? '');
                            $requesterName = $requesterId !== '' ? ($userNames[$requesterId] ?? $requesterId) : $ownerName;
                            $queueRevisionId = (string) ($s['review_revision_id'] ?? '');
                            $queueSubmission = $queueSubmissionContexts[$queueRevisionId] ?? null;
                            $requesterId = is_array($queueSubmission)
                                && hash_equals((string) $a['id'], (string) ($queueSubmission['article_id'] ?? ''))
                                ? (string) ($queueSubmission['actor_user_id'] ?? $requesterId)
                                : $requesterId;
                            $requesterName = $requesterId !== '' ? ($userNames[$requesterId] ?? $requesterId) : $ownerName;
                            $queueNote = is_array($queueSubmission)
                                && hash_equals((string) $a['id'], (string) ($queueSubmission['article_id'] ?? ''))
                                ? trim((string) ($queueSubmission['note'] ?? ''))
                                : '';
                            $htmlPath = editorial_resolve_article_path($a);
                            $liveConflict = $htmlPath !== null
                                && ($liveHash = editorial_live_hash($htmlPath)) !== null
                                && $liveHash !== (string) ($s['base_live_hash'] ?? '');
                        ?>
                            <tr>
                                <td>
                                    <a class="editorial-article-title-link" href="<?= editorial_h(editorial_url('review.php?id=' . urlencode((string) $a['id']))) ?>">
                                        <strong><?= editorial_h($a['title']) ?></strong>
                                    </a>
                                    <br><small style="color:#868e96;"><?= editorial_h($a['id']) ?></small>
                                    <?php if ($queueNote !== ''): ?>
                                        <span class="editorial-review-queue-note" title="<?= editorial_h($queueNote) ?>">
                                            <i class="fa-solid fa-message"></i> <?= editorial_h($queueNote) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($liveConflict): ?>
                                        <br><small style="color:#dc3545;"><i class="fa-solid fa-triangle-exclamation"></i> Có thay đổi file gốc</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= editorial_h($requesterName) ?>
                                    <?php if ($ownerName !== $requesterName): ?>
                                        <br><small style="color:#868e96;">Phụ trách: <?= editorial_h($ownerName) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $queueRevision = $queueRevisionId !== '' ? editorial_get_revision($queueRevisionId) : null; ?>
                                    <?php if ($queueRevision): ?>
                                        <span class="editorial-badge"><?= editorial_h(editorial_revision_label($queueRevision)) ?></span>
                                        <br><code><?= editorial_h(substr((string) $queueRevision['id'], 0, 8)) ?></code>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($s['review_requested_at']) ? editorial_h(editorial_format_datetime((string) $s['review_requested_at'])) : '—' ?></td>
                                <td><span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css('ready_review')) ?>"><?= editorial_h(editorial_status_label('ready_review')) ?></span></td>
                                <td class="editorial-action-cell">
                                    <a href="<?= editorial_h(editorial_url('review.php?id=' . urlencode((string) $a['id']))) ?>" class="admin-btn admin-btn-sm">
                                        <i class="fa-solid fa-clipboard-check"></i> Mở duyệt
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel editorial-recent-approved-panel">
        <div class="panel-head">
            <h2>Đã xử lý gần đây</h2>
            <p>Các hồ sơ Admin đã xử lý gần nhất, gồm phê duyệt và trả lại để chỉnh sửa.</p>
        </div>
        <?php if (empty($recentDecisionItems)): ?>
            <p class="editorial-recent-approved-empty">Chưa có hồ sơ nào được xử lý gần đây.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Bài viết</th>
                            <th>Người phụ trách</th>
                            <th>Người xử lý</th>
                            <th>Kết quả</th>
                            <th>Xử lý lúc</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDecisionItems as $item):
                            $dec = $item['decision'];
                            $a = $item['article'];
                            $dArticleId = (string) $dec['article_id'];
                            $dState = $decisionStates[$dArticleId] ?? null;
                            $dOwnerId = $dState !== null ? (string) ($dState['assigned_user_id'] ?? '') : '';
                            $dOwnerName = $dOwnerId !== '' ? ($userNames[$dOwnerId] ?? $dOwnerId) : 'Không rõ';
                            $dActorId = (string) ($dec['actor_user_id'] ?? '');
                            $dActorName = $dActorId !== '' ? ($userNames[$dActorId] ?? $dActorId) : 'Không rõ';
                            $dDecision = (string) ($dec['decision'] ?? '');
                            $dCurrentStatus = $dState !== null ? (string) ($dState['status'] ?? '') : '';
                            $dReturnNote = $dDecision === 'returned'
                                ? trim((string) ($dec['payload']['note'] ?? ''))
                                : '';
                        ?>
                            <tr>
                                <td>
                                <?php if ($dDecision === 'approved'): ?>
                                    <a class="editorial-article-title-link" href="<?= editorial_h(editorial_url('review.php?id=' . urlencode((string) $a['id']))) ?>">
                                        <strong><?= editorial_h($a['title']) ?></strong>
                                    </a>
                                <?php else: ?>
                                    <strong class="editorial-article-title-link"><?= editorial_h($a['title']) ?></strong>
                                <?php endif; ?>
                                    <br><small style="color:#868e96;"><?= editorial_h($a['id']) ?></small>
                                    <?php if ($dReturnNote !== ''):
                                        $dReturnPreview = editorial_return_note_preview($dReturnNote);
                                        $returnFeedbackSourceNumber++;
                                        $dReturnFeedbackSourceId = 'reviewDecisionFeedbackSource' . $returnFeedbackSourceNumber;
                                    ?>
                                        <div class="editorial-return-feedback-preview editorial-return-feedback-preview--table">
                                            <span class="editorial-return-feedback-preview__text">
                                                <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
                                                <?= editorial_h($dReturnPreview['text']) ?>
                                            </span>
                                            <?php if ($dReturnPreview['truncated']): ?>
                                                <button
                                                    type="button"
                                                    class="editorial-return-feedback-preview__open"
                                                    data-return-feedback-source="<?= editorial_h($dReturnFeedbackSourceId) ?>"
                                                >Xem lý do</button>
                                                <template id="<?= editorial_h($dReturnFeedbackSourceId) ?>"><?= editorial_h($dReturnNote) ?></template>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= editorial_h($dOwnerName) ?></td>
                                <td><?= editorial_h($dActorName) ?></td>
                                <td>
                                    <?php if ($dDecision === 'approved'): ?>
                                        <span class="editorial-badge editorial-review-decision-approved">Đã duyệt</span>
                                    <?php else: ?>
                                        <span class="editorial-badge editorial-review-decision-returned">Trả lại chỉnh sửa</span>
                                    <?php endif; ?>
                                    <?php if ($dCurrentStatus !== '' && $dCurrentStatus !== $dDecision): ?>
                                        <br><small style="color:#64748b;">Hiện: <?= editorial_h(editorial_status_label($dCurrentStatus)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($dec['created_at']) ? editorial_h(editorial_format_datetime((string) $dec['created_at'])) : '—' ?></td>
                                <td class="editorial-action-cell">
                                    <?php if ($dDecision === 'approved'): ?>
                                        <a href="<?= editorial_h(editorial_url('review.php?id=' . urlencode((string) $a['id']))) ?>" class="admin-btn admin-btn-sm">
                                            <i class="fa-solid fa-clipboard-check"></i> Xem hồ sơ
                                        </a>
                                    <?php elseif ($dReturnNote !== '' && !$dReturnPreview['truncated']): ?>
                                        <a href="<?= editorial_h(editorial_public_article_url($a)) ?>" target="_blank" rel="noopener" class="admin-btn admin-btn-sm">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem bài
                                        </a>
                                    <?php elseif ($dReturnNote !== '' && $dReturnPreview['truncated']): ?>
                                        <button
                                            type="button"
                                            class="admin-btn admin-btn-sm"
                                            data-return-feedback-source="<?= editorial_h($dReturnFeedbackSourceId) ?>"
                                        ><i class="fa-solid fa-comment-dots"></i> Xem lý do</button>
                                    <?php else: ?>
                                        <a href="<?= editorial_h(editorial_public_article_url($a)) ?>" target="_blank" rel="noopener" class="admin-btn admin-btn-sm">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem bài
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php editorial_render_return_feedback_dialog(); ?>
    <?php
    editorial_layout_footer();
}
