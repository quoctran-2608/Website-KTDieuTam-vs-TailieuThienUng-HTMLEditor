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
            $returnNote = trim((string) ($_POST['return_note'] ?? ''));
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
    $revisionId = (string) ($state['review_revision_id'] ?? '');

    $revision = null;
    $snapshot = null;
    $payload = [];
    $isVerified = false;

    if ($revisionId !== '') {
        $revision = editorial_get_revision($revisionId);
        if ($revision) {
            $snapshot = editorial_get_verified_revision_snapshot($revision);
            if (!empty($snapshot['ok'])) {
                $payload = $snapshot['payload'] ?? [];
                $isVerified = true;
            }
        }
    }

    $assignedUser = null;
    if (!empty($state['assigned_user_id'])) {
        $assignedUser = editorial_find_user_by_id((string) $state['assigned_user_id']);
    }

    $requester = null;
    if (!empty($state['review_requested_by'])) {
        $requester = editorial_find_user_by_id((string) $state['review_requested_by']);
    }

    $htmlPath = editorial_resolve_article_path($article);
    $liveConflict = false;
    $currentLiveHash = null;
    if ($htmlPath !== null) {
        $currentLiveHash = editorial_live_hash($htmlPath);
        if ($currentLiveHash !== null && $currentLiveHash !== (string)($state['base_live_hash'] ?? '')) {
            $liveConflict = true;
        }
    }

    $lock = editorial_get_article_lock($articleId);
    $activeUsers = array_filter(editorial_list_users(), fn($u) => !empty($u['is_active']));

    $latestReturnNote = '';
    if ($status === 'returned') {
        $note = editorial_get_latest_return_note($articleId);
        $latestReturnNote = $note ?? '';
    }

    editorial_layout_header([
        'title' => 'Duyệt bài',
        'active' => 'review',
        'description' => 'Chi tiết duyệt: ' . $article['title'],
    ]);
    ?>
    <section class="admin-panel">
        <div class="panel-head">
            <h2><?= editorial_h($article['title']) ?></h2>
            <p>
                <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($status)) ?>">
                    <?= editorial_h(editorial_status_label($status)) ?>
                </span>
                &nbsp;
                <a href="<?= editorial_h(editorial_public_article_url($article)) ?>" target="_blank" rel="noopener" style="font-size:0.85rem;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem trên website
                </a>
                &nbsp;
                <a href="<?= editorial_h(editorial_url('review.php')) ?>" style="font-size:0.85rem;">
                    <i class="fa-solid fa-arrow-left"></i> Về danh sách chờ duyệt
                </a>
            </p>
        </div>

        <?php if ($liveConflict): ?>
            <div class="flash flash-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong>Cảnh báo:</strong> File HTML gốc đã bị thay đổi bên ngoài luồng biên tập. Có thể gây mất dữ liệu nếu phê duyệt.
            </div>
        <?php endif; ?>

        <?php if ($revision): ?>
            <div class="editor-info-panel" style="margin-bottom:20px; padding:16px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px;">
                <h3 style="margin-top:0; font-size:1rem;">Thông tin phiên bản gửi duyệt</h3>
                <p><strong>Revision #:</strong> <?= editorial_h((string) $revision['revision_no']) ?></p>
                <p><strong>Loại:</strong> <?= editorial_h(editorial_revision_type_label((string) $revision['revision_type'])) ?></p>
                <p><strong>Người gửi:</strong> <?= editorial_h($requester ? (string) ($requester['display_name'] ?? $requester['username']) : (string) $revision['created_by']) ?></p>
                <p><strong>Thời gian gửi:</strong> <?= editorial_h(editorial_format_datetime((string) $revision['created_at'])) ?></p>
                <p><strong>Ghi chú:</strong> <?= editorial_h((string) ($revision['note'] ?? '')) ?: '<span style="color:#868e96;">—</span>' ?></p>
                <p><strong>Hash:</strong> <code><?= editorial_h(substr((string) ($revision['content_hash'] ?? ''), 0, 8)) ?></code></p>
                <p><strong>Snapshot:</strong> 
                    <?php if ($isVerified): ?>
                        <span style="color:#28a745;"><i class="fa-solid fa-check-circle"></i> Đã xác thực toàn vẹn</span>
                    <?php else: ?>
                        <span style="color:#dc3545;"><i class="fa-solid fa-times-circle"></i> Không thể xác thực hoặc mất dữ liệu</span>
                    <?php endif; ?>
                </p>
                
                <?php
                $db = editorial_db();
                $stmt = $db->prepare("SELECT id FROM editorial_revisions WHERE article_id = :aid AND revision_no < :revno ORDER BY revision_no DESC LIMIT 1");
                $stmt->execute(['aid' => $articleId, 'revno' => $revision['revision_no']]);
                $prevRev = $stmt->fetch();
                if ($prevRev): ?>
                    <p style="margin-top:10px;">
                        <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string)$prevRev['id']) . '&to=' . urlencode((string)$revision['id']))) ?>" class="editorial-workspace-btn" style="display:inline-block; padding:4px 8px; font-size:0.85rem;">
                            <i class="fa-solid fa-code-compare"></i> So sánh với bản trước
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p style="color:#868e96;">Không có thông tin phiên bản gắn với trạng thái duyệt này.</p>
        <?php endif; ?>

        <?php if ($status === 'returned'): ?>
            <div class="flash flash-warning">
                <strong>Lý do trả về (gần nhất):</strong> <?= editorial_h($latestReturnNote) ?>
            </div>
        <?php endif; ?>

        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
            <div style="flex:1; min-width:300px; border:1px solid #dee2e6; border-radius:8px; padding:16px; background:#f8f9fa;">
                <h3 style="margin-top:0; font-size:0.95rem; border-bottom:1px solid #dee2e6; padding-bottom:8px;">Quản lý Phân công & Khóa</h3>
                <p><strong>Người phụ trách:</strong> <?= editorial_h($assignedUser ? (string) ($assignedUser['display_name'] ?? $assignedUser['username']) : 'Chưa có') ?></p>
                <p><strong>Trạng thái khóa:</strong> <?= $lock ? '<span style="color:#dc3545;"><i class="fa-solid fa-lock"></i> Đang bị khóa</span>' : '<span style="color:#28a745;"><i class="fa-solid fa-unlock"></i> Tự do</span>' ?></p>
                
                <?php if (in_array($status, ['editing', 'returned'], true)): ?>
                    <?php if ($lock): ?>
                        <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="margin-top:10px;">
                            <?= editorial_csrf_input() ?>
                            <input type="hidden" name="_intent" value="force_unlock">
                            <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                            <button type="submit" class="admin-btn admin-btn-danger" style="padding:4px 8px; font-size:0.85rem;" onclick="return confirm('Bạn có chắc chắn muốn mở khóa bắt buộc?');">
                                <i class="fa-solid fa-unlock-keyhole"></i> Mở khóa bắt buộc
                            </button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="margin-top:10px; display:flex; gap:8px;">
                        <?= editorial_csrf_input() ?>
                        <input type="hidden" name="_intent" value="reassign">
                        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                        <select name="new_user_id" required style="padding:4px; border:1px solid #ccc; border-radius:4px; font-size:0.85rem;">
                            <option value="">-- Chọn người mới --</option>
                            <?php foreach ($activeUsers as $u): ?>
                                <option value="<?= editorial_h($u['id']) ?>"><?= editorial_h($u['display_name'] ?? $u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="admin-btn admin-btn-primary" style="padding:4px 8px; font-size:0.85rem;">Giao việc</button>
                    </form>
                    
                    <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="margin-top:10px;">
                        <?= editorial_csrf_input() ?>
                        <input type="hidden" name="_intent" value="release">
                        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                        <button type="submit" class="admin-btn" style="padding:4px 8px; font-size:0.85rem; background:#6c757d; color:white;" onclick="return confirm('Gỡ bỏ phụ trách bài viết này?');">
                            Gỡ bỏ phụ trách
                        </button>
                    </form>
                    
                    <details style="margin-top:10px;">
                        <summary style="font-size:0.85rem; color:#dc3545; cursor:pointer;">Hiển thị tùy chọn ép buộc (Force)</summary>
                        <div style="padding:10px; border:1px solid #f5c6cb; background:#f8d7da; border-radius:4px; margin-top:8px;">
                            <p style="font-size:0.85rem; margin-top:0; color:#721c24;">Dùng khi có bản nháp chưa lưu nhưng người dùng cũ không thể tiếp tục.</p>
                            <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="display:flex; gap:8px; margin-bottom:8px;">
                                <?= editorial_csrf_input() ?>
                                <input type="hidden" name="_intent" value="force_reassign">
                                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                                <select name="new_user_id" required style="padding:4px; border:1px solid #ccc; border-radius:4px; font-size:0.85rem;">
                                    <option value="">-- Chọn người mới --</option>
                                    <?php foreach ($activeUsers as $u): ?>
                                        <option value="<?= editorial_h($u['id']) ?>"><?= editorial_h($u['display_name'] ?? $u['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="admin-btn admin-btn-danger" style="padding:4px 8px; font-size:0.85rem;" onclick="return confirm('Bản nháp sẽ bị xóa. Bạn chắc chắn muốn giao lại bắt buộc?');">Giao lại (Force)</button>
                            </form>
                            <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>">
                                <?= editorial_csrf_input() ?>
                                <input type="hidden" name="_intent" value="force_release">
                                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                                <button type="submit" class="admin-btn admin-btn-danger" style="padding:4px 8px; font-size:0.85rem;" onclick="return confirm('Bản nháp sẽ bị xóa. Bạn chắc chắn muốn gỡ bỏ bắt buộc?');">Gỡ bỏ (Force)</button>
                            </form>
                        </div>
                    </details>
                <?php elseif ($status === 'approved'): ?>
                    <p><strong>Người duyệt:</strong> <?= editorial_h($state['approved_by'] ?? '') ?></p>
                    <p><strong>Thời gian duyệt:</strong> <?= editorial_h(editorial_format_datetime((string)($state['approved_at'] ?? ''))) ?></p>
                <?php endif; ?>
            </div>
            
            <?php if ($status === 'ready_review'): ?>
            <div style="flex:1; min-width:300px; border:1px solid #dee2e6; border-radius:8px; padding:16px; background:#f8f9fa;">
                <h3 style="margin-top:0; font-size:0.95rem; border-bottom:1px solid #dee2e6; padding-bottom:8px;">Hành động Duyệt</h3>
                <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="margin-bottom:16px;">
                    <?= editorial_csrf_input() ?>
                    <input type="hidden" name="_intent" value="approve">
                    <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                    <button type="submit" class="admin-btn admin-btn-primary" style="width:100%; font-size:1rem; padding:10px;" onclick="return confirm('Bạn xác nhận phê duyệt bài viết này?');">
                        <i class="fa-solid fa-check"></i> Phê duyệt
                    </button>
                </form>
                
                <details>
                    <summary style="font-weight:600; cursor:pointer; color:#dc3545;"><i class="fa-solid fa-rotate-left"></i> Yêu cầu chỉnh lại (Return)</summary>
                    <form method="post" action="<?= editorial_h(editorial_url('review.php')) ?>" style="margin-top:10px;">
                        <?= editorial_csrf_input() ?>
                        <input type="hidden" name="_intent" value="return_review">
                        <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                        <textarea name="return_note" required minlength="1" maxlength="2000" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; margin-bottom:8px; font-family:inherit;" placeholder="Lý do cần chỉnh lại (bắt buộc)..."></textarea>
                        <button type="submit" class="admin-btn admin-btn-danger" style="width:100%;">Gửi yêu cầu chỉnh lại</button>
                    </form>
                </details>
            </div>
            <?php endif; ?>

        <?php if ($status === 'approved'): ?>
            <div class="editorial-review-actions">
                <p style="margin:0;">
                    <i class="fa-solid fa-circle-check" style="color:#2e7d32;"></i>
                    <strong>Đã duyệt</strong> — chờ Publish.
                    <?php if (!empty($state['approved_by'])): ?>
                        <?php $approver = editorial_find_user_by_id((string) $state['approved_by']); ?>
                        Bởi: <?= editorial_h($approver ? (string) ($approver['display_name'] ?? $approver['username']) : (string) $state['approved_by']) ?>
                        vào <?= editorial_h(editorial_format_datetime((string) ($state['approved_at'] ?? ''))) ?>
                    <?php endif; ?>
                </p>
                <a href="<?= editorial_h(editorial_url('publish.php?id=' . urlencode($articleId))) ?>" class="editorial-approve-btn">
                    <i class="fa-solid fa-rocket"></i> Chuẩn bị Publish
                </a>
            </div>
        <?php endif; ?>
        </div>

        <?php if ($payload): ?>
            <details class="editor-info-panel" style="margin-bottom:20px;" open>
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
            
            <details class="editor-info-panel" style="margin-bottom:20px;" open>
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
    $q = trim((string) ($_GET['q'] ?? ''));

    $db = editorial_db();
    $stmt = $db->query("SELECT * FROM editorial_article_state WHERE status IN ('ready_review','approved') ORDER BY review_requested_at ASC");
    $reviewStates = $stmt->fetchAll();

    $items = [];
    $userIdsToPreload = [];
    foreach ($reviewStates as $s) {
        if (!empty($s['assigned_user_id'])) $userIdsToPreload[] = (string) $s['assigned_user_id'];
        if (!empty($s['approved_by'])) $userIdsToPreload[] = (string) $s['approved_by'];
        
        $art = editorial_find_article((string) $s['article_id']);
        if ($art !== null) {
            if ($q !== '') {
                $qLower = mb_strtolower($q, 'UTF-8');
                $titleLower = mb_strtolower($art['title'], 'UTF-8');
                if (!str_contains($titleLower, $qLower) && !str_contains(mb_strtolower($art['id'], 'UTF-8'), $qLower)) {
                    continue;
                }
            }
            $items[] = ['state' => $s, 'article' => $art];
        }
    }
    $userNames = editorial_preload_user_names($userIdsToPreload);

    editorial_layout_header([
        'title' => 'Danh sách chờ duyệt',
        'active' => 'review',
        'description' => 'Các bài viết đang chờ phê duyệt hoặc đã duyệt gần đây.',
    ]);
    ?>
    <section class="editorial-filter-bar">
        <form method="get" action="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-filter-form">
            <div class="editorial-filter-row">
                <div class="field-input editorial-filter-search">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" name="q" value="<?= editorial_h($q) ?>" placeholder="Tìm theo tiêu đề, ID bài viết…">
                </div>
                <button type="submit" class="editorial-filter-btn"><i class="fa-solid fa-filter"></i> Lọc</button>
                <?php if ($q !== ''): ?>
                    <a href="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-filter-clear">Xóa bộ lọc</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="admin-panel">
        <?php if (empty($items)): ?>
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
                            <th>Người phụ trách</th>
                            <th>Phiên bản</th>
                            <th>Gửi duyệt lúc</th>
                            <th>Trạng thái</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $s = $item['state'];
                            $a = $item['article'];
                            $status = (string) $s['status'];
                            
                            $ownerId = (string) ($s['assigned_user_id'] ?? '');
                            $ownerName = $ownerId !== '' ? ($userNames[$ownerId] ?? $ownerId) : 'Không rõ';
                            
                            $approvedById = (string) ($s['approved_by'] ?? '');
                            $approvedByName = $approvedById !== '' ? ($userNames[$approvedById] ?? $approvedById) : 'Không rõ';
                            
                            $htmlPath = editorial_resolve_article_path($a);
                            $liveConflict = false;
                            if ($htmlPath !== null) {
                                $lh = editorial_live_hash($htmlPath);
                                if ($lh !== null && $lh !== (string)($s['base_live_hash'] ?? '')) {
                                    $liveConflict = true;
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?= editorial_h($a['title']) ?></strong>
                                <br><small style="color:#868e96;"><?= editorial_h($a['id']) ?></small>
                                <?php if ($liveConflict): ?>
                                    <br><small style="color:#dc3545;"><i class="fa-solid fa-triangle-exclamation"></i> Có thay đổi file gốc</small>
                                <?php endif; ?>
                            </td>
                            <td><?= editorial_h($ownerName) ?></td>
                            <td>
                                <?php if ($s['review_revision_id']): ?>
                                    <code><?= editorial_h(substr((string)$s['review_revision_id'], 0, 8)) ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['review_requested_at']): ?>
                                    <?= editorial_h(editorial_format_datetime((string)$s['review_requested_at'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($status)) ?>">
                                    <?= editorial_h(editorial_status_label($status)) ?>
                                </span>
                                <?php if ($status === 'approved' && $s['approved_at']): ?>
                                    <br><small style="color:#868e96;">bởi <?= editorial_h($approvedByName) ?> lúc <?= editorial_h(date('H:i d/m', strtotime((string)$s['approved_at']))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= editorial_h(editorial_url('review.php?id=' . urlencode((string)$a['id']))) ?>" class="admin-btn admin-btn-sm" style="display:inline-block; padding:4px 8px; border:1px solid #dee2e6; border-radius:4px; text-decoration:none; color:#495057;">
                                    <i class="fa-solid fa-eye"></i> Xem duyệt
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
    editorial_layout_footer();
}
