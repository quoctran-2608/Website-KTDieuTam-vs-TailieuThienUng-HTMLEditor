<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$currentUserId = (string) $currentUser['user_id'];
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$articleId = trim((string) ($_GET['id'] ?? ''));

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

// ─── Authorization ───────────────────────────────────────────────

$state = editorial_get_article_state($articleId);

if (!$isAdmin) {
    if ($state === null || (string) ($state['assigned_user_id'] ?? '') !== $currentUserId) {
        editorial_flash_set('warning', 'Bạn không có quyền xem lịch sử phiên bản của bài viết này.');
        editorial_redirect(editorial_url('articles.php'));
    }
}

// ─── Load revisions ─────────────────────────────────────────────

$revisions = editorial_get_article_revisions($articleId);
$milestones = ['baseline' => null, 'stage1' => null, 'stage2' => null];
$activeAssignment = editorial_get_active_assignment($articleId);
foreach ($revisions as $revision) {
    if ($activeAssignment === null || ($revision['assignment_id'] ?? '') !== $activeAssignment['id']) {
        continue;
    }
    $key = (string) ($revision['milestone_key'] ?? '');
    if (($revision['revision_type'] ?? '') === 'baseline' && $milestones['baseline'] === null) {
        $milestones['baseline'] = $revision;
    }
    if (in_array($key, ['stage1', 'stage2'], true) && $milestones[$key] === null) {
        $milestones[$key] = $revision;
    }
}

$articleStatus = (string) ($state['status'] ?? 'available');
$isOwner = ($state !== null && (string) ($state['assigned_user_id'] ?? '') === $currentUserId);
$isEditable = in_array($articleStatus, ['editing', 'returned'], true);

// ─── Render ──────────────────────────────────────────────────────

editorial_layout_header([
    'title' => 'Lịch sử phiên bản',
    'active' => 'my-work',
    'description' => $article['title'],
]);
?>

<section class="admin-panel">
    <div class="panel-head">
        <h2><?= editorial_h($article['title']) ?></h2>
        <p>
            <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($articleStatus)) ?>">
                <?= editorial_h(editorial_status_label($articleStatus)) ?>
            </span>
            &nbsp;
            <a href="<?= editorial_h(editorial_public_article_url($article)) ?>" target="_blank" rel="noopener" style="font-size:0.85rem;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Xem trên website
            </a>
            <?php if ($isOwner && $isEditable): ?>
                &nbsp;
                <a href="<?= editorial_h(editorial_url('article.php?id=' . urlencode($articleId))) ?>" style="font-size:0.85rem;">
                    <i class="fa-solid fa-pen-to-square"></i> Quay lại workspace
                </a>
            <?php endif; ?>
        </p>
    </div>

    <?php if (empty($revisions)): ?>
        <div class="empty-state" style="padding:24px;">
            <i class="fa-regular fa-folder-open"></i>
            <p>Chưa có phiên bản nào. Lưu nháp rồi hoàn tất Chặng 1 hoặc Chặng 2 trong workspace.</p>
        </div>
    <?php else: ?>
        <?php if ($milestones['baseline'] && ($milestones['stage1'] || $milestones['stage2'])): ?>
            <div class="editorial-milestone-links" style="margin-bottom:16px;">
                <?php if ($milestones['stage1']): ?>
                    <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $milestones['baseline']['id']) . '&to=' . urlencode((string) $milestones['stage1']['id'])) ?>">Bản gốc ↔ Chặng 1</a>
                <?php endif; ?>
                <?php if ($milestones['stage2']): ?>
                    <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $milestones['baseline']['id']) . '&to=' . urlencode((string) $milestones['stage2']['id'])) ?>">Bản gốc ↔ Chặng 2</a>
                <?php endif; ?>
                <?php if ($milestones['stage1'] && $milestones['stage2']): ?>
                    <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $milestones['stage1']['id']) . '&to=' . urlencode((string) $milestones['stage2']['id'])) ?>">Chặng 1 ↔ Chặng 2</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Phiên bản #</th>
                        <th>Loại</th>
                        <th>Người tạo</th>
                        <th>Thời gian</th>
                        <th>Draft v.</th>
                        <th>Ghi chú</th>
                        <th>Hash ngắn</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revisions as $index => $rev): ?>
                        <tr>
                            <td><strong><?= editorial_h((string) $rev['revision_no']) ?></strong></td>
                            <td>
                                <span class="editorial-badge">
                                    <?= editorial_h(editorial_revision_label($rev)) ?>
                                </span>
                            </td>
                            <td><?= editorial_h((string) ($rev['creator_name'] ?? $rev['created_by'])) ?></td>
                            <td><?= editorial_h(editorial_format_datetime((string) $rev['created_at'])) ?></td>
                            <td>
                                <?php if ($rev['source_draft_version'] !== null): ?>
                                    v<?= editorial_h((string) $rev['source_draft_version']) ?>
                                <?php else: ?>
                                    <span style="color:#868e96;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= editorial_h((string) ($rev['note'] ?? '')) ?: '<span style="color:#868e96;">—</span>' ?></td>
                            <td><code><?= editorial_h(substr((string) ($rev['content_hash'] ?? ''), 0, 8)) ?></code></td>
                            <td>
                                <?php
                                // Previous revision is the next item in the array (sorted DESC)
                                $prevRev = $revisions[$index + 1] ?? null;
                                if ($prevRev !== null):
                                ?>
                                    <a href="<?= editorial_h(editorial_url('compare.php?id=' . urlencode($articleId) . '&from=' . urlencode((string) $prevRev['id']) . '&to=' . urlencode((string) $rev['id']))) ?>">
                                        <i class="fa-solid fa-code-compare"></i> So sánh với bản trước
                                    </a>
                                <?php elseif ($rev['revision_type'] !== 'baseline'): ?>
                                    <span style="color:#868e96;">Không có bản trước để so sánh.</span>
                                <?php else: ?>
                                    <span style="color:#868e96;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php editorial_layout_footer(); ?>
