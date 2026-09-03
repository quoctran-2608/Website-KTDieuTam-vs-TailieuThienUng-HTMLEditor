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
$fromId = trim((string) ($_GET['from'] ?? ''));
$toId = trim((string) ($_GET['to'] ?? ''));

// ─── Validate params ─────────────────────────────────────────────

if ($articleId === '' || $fromId === '' || $toId === '') {
    editorial_flash_set('warning', 'Thiếu tham số so sánh.');
    editorial_redirect(editorial_url('my-work.php'));
}

$article = editorial_find_article($articleId);
if ($article === null) {
    editorial_flash_set('danger', 'Không tìm thấy bài viết.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Authorization ───────────────────────────────────────────────

if (!$isAdmin) {
    $state = editorial_get_article_state($articleId);
    if ($state === null || (string) ($state['assigned_user_id'] ?? '') !== $currentUserId) {
        editorial_flash_set('warning', 'Bạn không có quyền xem so sánh phiên bản của bài viết này.');
        editorial_redirect(editorial_url('articles.php'));
    }
}

// ─── Load revisions ─────────────────────────────────────────────

$fromRev = editorial_get_revision($fromId);
$toRev = editorial_get_revision($toId);

if (!$fromRev || !$toRev) {
    editorial_flash_set('warning', 'Không tìm thấy phiên bản để so sánh.');
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

// Verify both belong to this article
if ((string) $fromRev['article_id'] !== $articleId || (string) $toRev['article_id'] !== $articleId) {
    editorial_flash_set('warning', 'Phiên bản không thuộc bài viết này.');
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

// ─── Load snapshots ──────────────────────────────────────────────

// A4: Use verified snapshot helper
$fromVerified = editorial_get_verified_revision_snapshot($fromRev);
$toVerified = editorial_get_verified_revision_snapshot($toRev);

if (!$fromVerified['ok'] || !$toVerified['ok']) {
    $failMsg = !$fromVerified['ok'] ? $fromVerified['message'] : $toVerified['message'];
    editorial_flash_set('danger', $failMsg);
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

$fromPayload = $fromVerified['payload'];
$toPayload = $toVerified['payload'];

// Live HTML is presentation context only; compared prose remains immutable snapshots.
$articlePath = editorial_resolve_article_path($article);
$liveArticleHtml = $articlePath ? file_get_contents($articlePath) : false;
if ($liveArticleHtml === false) {
    $liveArticleHtml = '';
}
$siteBaseUrl = editorial_site_url('');
$fromPreview = editorial_build_public_article_preview_document(
    $liveArticleHtml,
    (string) ($fromPayload['prose_html'] ?? ''),
    $siteBaseUrl
);
$toPreview = editorial_build_public_article_preview_document(
    $liveArticleHtml,
    (string) ($toPayload['prose_html'] ?? ''),
    $siteBaseUrl
);

// ─── Enrich revision with creator name ───────────────────────────

$fromCreator = editorial_find_user_by_id((string) $fromRev['created_by']);
$fromRev['creator_name'] = $fromCreator ? (string) ($fromCreator['display_name'] ?? $fromCreator['username']) : $fromRev['created_by'];

$toCreator = editorial_find_user_by_id((string) $toRev['created_by']);
$toRev['creator_name'] = $toCreator ? (string) ($toCreator['display_name'] ?? $toCreator['username']) : $toRev['created_by'];

// ─── Field-level metadata compare ────────────────────────────────

$metaFields = [
    'title' => 'Tiêu đề',
    'excerpt' => 'Mô tả',
    'publish_date' => 'Ngày đăng',
    'modified_date' => 'Ngày sửa',
    'featured_image' => 'Ảnh đại diện',
    'tags_text' => 'Tags',
    'section_label' => 'Mục',
    'library_kind_label' => 'Loại thư viện',
    'topic_lv1_label' => 'Chủ đề cấp 1',
    'topic_lv2_label' => 'Chủ đề cấp 2',
    'topic_lv3_label' => 'Chủ đề cấp 3',
];

$changedMeta = [];
foreach ($metaFields as $field => $label) {
    $oldVal = (string) ($fromPayload[$field] ?? '');
    $newVal = (string) ($toPayload[$field] ?? '');
    if ($oldVal !== $newVal) {
        $changedMeta[] = ['label' => $label, 'old' => $oldVal, 'new' => $newVal];
    }
}

// ─── Prose diff ──────────────────────────────────────────────────

$oldProse = strip_tags((string) ($fromPayload['prose_html'] ?? ''));
$newProse = strip_tags((string) ($toPayload['prose_html'] ?? ''));
$proseDiff = [];
$proseChanged = ($oldProse !== $newProse);
if ($proseChanged) {
    $proseDiff = editorial_simple_diff($oldProse, $newProse);
}

// ─── Render ──────────────────────────────────────────────────────

editorial_layout_header([
    'title' => 'So sánh phiên bản',
    'active' => 'my-work',
    'description' => $article['title'],
]);
?>

<section class="admin-panel">
    <div class="panel-head">
        <h2>So sánh phiên bản — <?= editorial_h($article['title']) ?></h2>
        <p>
            <a href="<?= editorial_h(editorial_url('revisions.php?id=' . urlencode($articleId))) ?>">
                <i class="fa-solid fa-arrow-left"></i> Quay lại lịch sử phiên bản
            </a>
        </p>
    </div>

    <div class="editorial-split-compare">
        <article class="editorial-split-pane">
            <header>
                <h3><?= editorial_h(editorial_revision_label($fromRev)) ?></h3>
                <p><strong>Revision #<?= editorial_h((string) $fromRev['revision_no']) ?></strong>
                    (<?= editorial_h(editorial_revision_label($fromRev)) ?>)</p>
                <p>Người tạo: <?= editorial_h((string) $fromRev['creator_name']) ?></p>
                <p>Thời gian: <?= editorial_h(editorial_format_datetime((string) $fromRev['created_at'])) ?></p>
            </header>
            <iframe sandbox="" srcdoc="<?= editorial_h($fromPreview) ?>" title="<?= editorial_h(editorial_revision_label($fromRev)) ?>"></iframe>
        </article>
        <article class="editorial-split-pane">
            <header>
                <h3><?= editorial_h(editorial_revision_label($toRev)) ?></h3>
                <p><strong>Revision #<?= editorial_h((string) $toRev['revision_no']) ?></strong>
                    (<?= editorial_h(editorial_revision_label($toRev)) ?>)</p>
                <p>Người tạo: <?= editorial_h((string) $toRev['creator_name']) ?></p>
                <p>Thời gian: <?= editorial_h(editorial_format_datetime((string) $toRev['created_at'])) ?></p>
            </header>
            <iframe sandbox="" srcdoc="<?= editorial_h($toPreview) ?>" title="<?= editorial_h(editorial_revision_label($toRev)) ?>"></iframe>
        </article>
    </div>

    <details class="editor-info-panel" style="margin-bottom:16px;">
        <summary><i class="fa-solid fa-eye"></i> So sánh hai bản hiển thị cạnh nhau</summary>
        <div style="padding:14px;">
            <p style="margin:0;color:#868e96;">Hai pane dùng snapshot đã xác thực và iframe sandbox, không cho script chạy trong trang so sánh.</p>
        </div>
    </details>

    <!-- Metadata changes -->
    <details class="editor-info-panel" style="margin-bottom:16px;" <?= !empty($changedMeta) ? 'open' : '' ?>>
        <summary><i class="fa-solid fa-list-check"></i> Thay đổi metadata</summary>
        <div style="padding:14px;">
            <?php if (empty($changedMeta)): ?>
                <p style="color:#868e96;">Không có thay đổi metadata.</p>
            <?php else: ?>
                <table class="admin-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>Trường</th>
                            <th>Bản trước</th>
                            <th>Bản sau</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changedMeta as $change): ?>
                            <tr>
                                <td><strong><?= editorial_h($change['label']) ?></strong></td>
                                <td class="meta-old"><?= editorial_h($change['old'] !== '' ? $change['old'] : '(trống)') ?></td>
                                <td class="meta-new"><?= editorial_h($change['new'] !== '' ? $change['new'] : '(trống)') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>

    <!-- Prose diff -->
    <details class="editor-info-panel" style="margin-bottom:16px;">
        <summary><i class="fa-solid fa-file-lines"></i> Diff văn bản</summary>
        <div style="padding:14px;">
            <?php if (!$proseChanged): ?>
                <p style="color:#868e96;">Nội dung không thay đổi.</p>
            <?php else: ?>
                <div class="editorial-diff-lines">
                    <?php foreach ($proseDiff as $line): ?>
                        <div class="diff-line-<?= editorial_h($line['type']) ?>"><?= editorial_h($line['line']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <!-- HTML source (collapsed) -->
    <details class="editor-info-panel" style="margin-bottom:16px;">
        <summary><i class="fa-solid fa-code"></i> HTML nguồn</summary>
        <div style="padding:14px;">
            <div style="display:flex; gap:16px;">
                <div style="flex:1; overflow-x:auto;">
                    <h4 style="font-size:0.85rem; color:#495057;">Bản trước</h4>
                    <pre style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px; font-size:0.8rem; white-space:pre-wrap; word-break:break-word; max-height:400px; overflow-y:auto;"><?= editorial_h((string) ($fromPayload['prose_html'] ?? '')) ?></pre>
                </div>
                <div style="flex:1; overflow-x:auto;">
                    <h4 style="font-size:0.85rem; color:#495057;">Bản sau</h4>
                    <pre style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px; font-size:0.8rem; white-space:pre-wrap; word-break:break-word; max-height:400px; overflow-y:auto;"><?= editorial_h((string) ($toPayload['prose_html'] ?? '')) ?></pre>
                </div>
            </div>
        </div>
    </details>
</section>

<?php editorial_layout_footer(); ?>
