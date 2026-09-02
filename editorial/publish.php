<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/publish.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_role(['admin']);

$currentUser = editorial_current_user();
$adminUserId = (string) $currentUser['user_id'];
$articleId = trim((string) ($_GET['id'] ?? $_POST['article_id'] ?? ''));

if ($articleId === '') {
    editorial_flash_set('danger', 'Thiếu article ID.');
    editorial_redirect(editorial_url('review.php'));
}

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    if ($intent === 'publish') {
        // Server-side checkbox confirmation check (UX safety)
        if (empty($_POST['confirm_publish'])) {
            editorial_flash_set('danger', 'Vui lòng xác nhận trước khi Publish.');
            editorial_redirect(editorial_url('publish.php?id=' . urlencode($articleId)));
        }

        $result = editorial_publish_approved_revision($articleId, $adminUserId);

        if ($result['ok']) {
            // Post-commit: public rebuild (best-effort, non-fatal)
            $rebuildResult = editorial_public_rebuild_after_publish($articleId);
            $rebuildWarning = !$rebuildResult['ok'];

            if ($rebuildWarning) {
                editorial_flash_set('warning', 'Bài đã được Publish nhưng đồng bộ dữ liệu public phụ trợ chưa hoàn tất. ' . ($rebuildResult['message'] ?? ''));
                try {
                    editorial_log_activity('article.publish.public_rebuild_failed', $articleId, $adminUserId, json_encode([
                        'code' => $rebuildResult['code'] ?? 'unknown',
                        'message' => $rebuildResult['message'] ?? 'Unknown error',
                        'exit_code' => $rebuildResult['exit_code'] ?? null,
                        'output_tail' => $rebuildResult['output_tail'] ?? null,
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort: don't crash page over logging failure
                }
            } else {
                try {
                    editorial_log_activity('article.publish.public_rebuild_succeeded', $articleId, $adminUserId, json_encode([
                        'exit_code' => $rebuildResult['exit_code'] ?? 0,
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort
                }
                editorial_flash_set('success', $result['message']);
            }
        } else {
            editorial_flash_set('danger', $result['message']);
        }

        editorial_redirect(editorial_url('publish.php?id=' . urlencode($articleId)));
    }

    if ($intent === 'retry_public_rebuild') {
        $result = editorial_retry_public_rebuild($articleId, $adminUserId);
        editorial_flash_set(
            !empty($result['ok']) ? 'success' : 'warning',
            !empty($result['ok'])
                ? 'Đã rebuild lại dữ liệu public.'
                : 'Không thể rebuild lại dữ liệu public: ' . ($result['message'] ?? 'Lỗi không xác định.')
        );
        editorial_redirect(editorial_url('publish.php?id=' . urlencode($articleId)));
    }
}

// GET rendering — Preflight + Detail
$article = editorial_find_article($articleId);
if (!$article) {
    editorial_flash_set('danger', 'Không tìm thấy bài viết trong danh mục.');
    editorial_redirect(editorial_url('review.php'));
}

$state = editorial_get_article_state($articleId);
$status = $state['status'] ?? 'available';

$preflight = editorial_publish_preflight($articleId, $adminUserId);
$preflightChecks = $preflight['checks'] ?? [];

$approvedRevision = null;
if (!empty($state['approved_revision_id'])) {
    $approvedRevision = editorial_get_revision($state['approved_revision_id']);
}

editorial_layout_header([
    'title' => 'Xuất bản - ' . $article['title'],
    'active' => 'review',
    'description' => 'Xuất bản bài viết',
]);
?>

<div class="editorial-workspace-header">
    <div style="display:flex; align-items:center; gap:16px;">
        <a href="<?= editorial_h(editorial_url('review.php')) ?>" class="editorial-back-link">
            <i class="fa-solid fa-arrow-left"></i> Trở lại
        </a>
        <h1 style="margin:0; font-size:1.4rem;">
            <?= editorial_h($article['title']) ?>
        </h1>
        <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($status)) ?>">
            <?= editorial_h(editorial_status_label($status)) ?>
        </span>
    </div>
</div>

<?php if ($status === 'published'): ?>
    <section class="admin-panel" style="margin-top:24px;">
        <div class="panel-head">
            <h2>Thông tin xuất bản</h2>
            <p>Bài viết đang ở trạng thái đã xuất bản.</p>
        </div>
        <div style="padding:16px; background:#e8f5e9; border:1px solid #c8e6c9; border-radius:8px; margin-bottom:16px;">
            <p style="margin:0; color:#2e7d32;">
                <i class="fa-solid fa-check-circle"></i> Đã xuất bản
            </p>
        </div>
        <table class="editorial-preflight-table">
            <tbody>
                <tr>
                    <td>Revision đã xuất bản</td>
                    <td><?= editorial_h((string) ($state['published_revision_id'] ?? '')) ?></td>
                </tr>
                <tr>
                    <td>Người xuất bản</td>
                    <td>
                        <?php
                        $publisher = !empty($state['published_by']) ? editorial_find_user_by_id((string) $state['published_by']) : null;
                        echo editorial_h($publisher ? (string) ($publisher['display_name'] ?? $publisher['username']) : (string) ($state['published_by'] ?? ''));
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Thời điểm xuất bản</td>
                    <td><?= editorial_h((string) ($state['published_at'] ?? '')) ?></td>
                </tr>
                <tr>
                    <td>Hash Live</td>
                    <td><code><?= editorial_h(substr((string) ($state['published_live_hash'] ?? ''), 0, 8)) ?></code></td>
                </tr>
            </tbody>
        </table>
        <form method="post" style="margin-top:16px;">
            <?= editorial_csrf_input() ?>
            <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
            <input type="hidden" name="_intent" value="retry_public_rebuild">
            <button type="submit" class="editorial-publish-btn" onclick="return confirm('Chỉ rebuild dữ liệu public phụ trợ. File HTML và trạng thái bài viết sẽ không bị sửa. Tiếp tục?');">
                <i class="fa-solid fa-arrows-rotate"></i> Thử rebuild lại dữ liệu public
            </button>
        </form>
    </section>
<?php elseif ($status === 'approved'): ?>
    <section class="admin-panel" style="margin-top:24px;">
        <div class="panel-head">
            <h2>Kiểm tra trước khi Publish (Preflight)</h2>
            <p>Vui lòng xem kỹ kết quả kiểm tra hệ thống trước khi cập nhật dữ liệu gốc.</p>
        </div>

        <table class="editorial-preflight-table">
            <tbody>
                <?php foreach ($preflightChecks as $key => $check): ?>
                <tr>
                    <td><?= editorial_h($check['label']) ?></td>
                    <td>
                        <?php if ($check['pass']): ?>
                            <span class="editorial-preflight-pass">✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td>File HTML target</td>
                    <td><?= editorial_h((string) ($article['href'] ?? '')) ?></td>
                </tr>
                <tr>
                    <td>Base hash</td>
                    <td><code><?= editorial_h(substr((string) ($state['base_live_hash'] ?? ''), 0, 8)) ?></code></td>
                </tr>
                <tr>
                    <td>Live hash</td>
                    <td><code><?= editorial_h(substr((string) ($preflight['live_hash'] ?? $state['base_live_hash'] ?? ''), 0, 8)) ?></code></td>
                </tr>
            </tbody>
        </table>

        <?php if ($preflight['ok']): ?>
            <form method="post">
                <input type="hidden" name="article_id" value="<?= editorial_h($articleId) ?>">
                <input type="hidden" name="_intent" value="publish">
                <?= editorial_csrf_input() ?>
                <div class="editorial-publish-confirm">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; width:100%;">
                        <input type="checkbox" name="confirm_publish" value="1" id="publishConfirm" required>
                        Tôi xác nhận Publish revision đã duyệt vào file HTML gốc. Backup sẽ được tạo tự động trước khi ghi.
                    </label>
                </div>
                <button type="submit" class="editorial-publish-btn"
                        onclick="return confirm('Bạn sắp cập nhật trực tiếp file HTML đang phục vụ website. Tiếp tục?');">
                    <i class="fa-solid fa-rocket"></i> XUẤT BẢN
                </button>
            </form>
        <?php else: ?>
            <div class="flash flash-danger">
                <i class="fa-solid fa-ban"></i>
                <strong>Không thể Publish:</strong> <?= editorial_h($preflight['message']) ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($approvedRevision): ?>
        <section class="admin-panel" style="margin-top:24px;">
            <div class="panel-head">
                <h2>Bản phê duyệt (Revision)</h2>
            </div>
            <table class="editorial-preflight-table">
                <tbody>
                    <tr>
                        <td>Revision #</td>
                        <td>#<?= editorial_h((string) $approvedRevision['revision_no']) ?></td>
                    </tr>
                    <tr>
                        <td>Mã Revision</td>
                        <td><?= editorial_h($approvedRevision['id']) ?></td>
                    </tr>
                    <tr>
                        <td>Người tạo</td>
                        <td>
                            <?php
                            $revCreator = editorial_find_user_by_id((string) $approvedRevision['created_by']);
                            echo editorial_h($revCreator ? (string) ($revCreator['display_name'] ?? $revCreator['username']) : $approvedRevision['created_by']);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Ngày tạo</td>
                        <td><?= editorial_h(editorial_format_datetime($approvedRevision['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td>Hash nội dung</td>
                        <td><code><?= editorial_h(substr($approvedRevision['content_hash'] ?? '', 0, 8)) ?></code></td>
                    </tr>
                </tbody>
            </table>
            <div style="margin-top:16px;">
                <a href="<?= editorial_h(editorial_url('review.php?id=' . urlencode($articleId))) ?>" class="editorial-admin-btn">
                    <i class="fa-solid fa-eye"></i> Xem nội dung
                </a>
            </div>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="admin-panel" style="margin-top:24px;">
        <div class="flash flash-warning">
            Bài viết chưa được duyệt. Không thể Publish lúc này.
        </div>
    </section>
<?php endif; ?>

<?php editorial_layout_footer(); ?>
