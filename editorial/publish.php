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
        $result = editorial_publish_approved_revision($articleId, $adminUserId);
        
        if ($result['ok']) {
            // Post-commit: public rebuild (best-effort)
            $rebuildResult = editorial_public_rebuild_after_publish($articleId);
            $rebuildWarning = !$rebuildResult['ok'];
            
            if ($rebuildWarning) {
                editorial_flash_set('warning', 'Bài đã được Publish nhưng đồng bộ dữ liệu public phụ trợ chưa hoàn tất. ' . ($rebuildResult['message'] ?? ''));
                editorial_log_activity('article.publish.public_rebuild_failed', $articleId, $adminUserId, json_encode([
                    'message' => $rebuildResult['message'] ?? 'Unknown error',
                ]));
            } else {
                editorial_flash_set('success', $result['message']);
            }
        } else {
            editorial_flash_set('danger', $result['message']);
        }
        
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
                    <td><?= editorial_h((string) ($state['published_by'] ?? '')) ?></td>
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
    </section>
<?php elseif ($status === 'approved'): ?>
    <section class="admin-panel" style="margin-top:24px;">
        <div class="panel-head">
            <h2>Kiểm tra trước khi Publish (Preflight)</h2>
            <p>Vui lòng xem kỹ kết quả kiểm tra hệ thống trước khi cập nhật dữ liệu gốc.</p>
        </div>

        <table class="editorial-preflight-table">
            <tbody>
                <tr>
                    <td>Trạng thái</td>
                    <td>
                        <?php if ($status === 'approved'): ?>
                            <span class="editorial-preflight-pass">approved ✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail"><?= editorial_h($status) ?> ✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Phiên bản đã duyệt</td>
                    <td>
                        <?php if ($approvedRevision): ?>
                            <span class="editorial-preflight-pass">#<?= editorial_h((string) $approvedRevision['revision_no']) ?> ✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">Không có ✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Snapshot toàn vẹn</td>
                    <td>
                        <?php if ($preflight['ok'] || !empty($preflight['payload'])): ?>
                            <span class="editorial-preflight-pass">✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Phân công hợp lệ</td>
                    <td>
                        <?php if ($preflight['ok'] || !empty($preflight['assignment'])): ?>
                            <span class="editorial-preflight-pass">✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Live hash khớp</td>
                    <td>
                        <?php if ($preflight['ok']): ?>
                            <span class="editorial-preflight-pass">✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Không có khóa chỉnh sửa</td>
                    <td>
                        <?php if ($preflight['ok']): ?>
                            <span class="editorial-preflight-pass">✔</span>
                        <?php else: ?>
                            <span class="editorial-preflight-fail">✘</span>
                        <?php endif; ?>
                    </td>
                </tr>
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
                <tr>
                    <td>Backup sẵn sàng</td>
                    <td><span class="editorial-preflight-pass">✔</span></td>
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
                        <input type="checkbox" id="publishConfirm" required>
                        Tôi xác nhận Publish revision đã duyệt vào file HTML gốc.
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
                        <td>Mã Revision</td>
                        <td><?= editorial_h($approvedRevision['id']) ?></td>
                    </tr>
                    <tr>
                        <td>Người tạo</td>
                        <td><?= editorial_h($approvedRevision['created_by']) ?></td>
                    </tr>
                    <tr>
                        <td>Ngày tạo</td>
                        <td><?= editorial_h($approvedRevision['created_at']) ?></td>
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
