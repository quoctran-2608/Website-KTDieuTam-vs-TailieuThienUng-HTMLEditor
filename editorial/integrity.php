<?php
declare(strict_types=1);

/**
 * Editorial V2 — Integrity Center.
 *
 * Admin-only page for system diagnostics, rebuild retry, and expired lock cleanup.
 * GET = read-only scan. POST = safe actions only (with CSRF).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/publish.php';
require_once __DIR__ . '/includes/integrity.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_role(['admin']);

$currentUser = editorial_current_user();
$adminUserId = (string) $currentUser['user_id'];

// ── POST Actions ──

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    // Retry public rebuild for a published article
    if ($intent === 'retry_public_rebuild') {
        $articleId = trim((string) ($_POST['article_id'] ?? ''));

        $rebuildResult = editorial_retry_public_rebuild($articleId, $adminUserId);
        editorial_flash_set(
            !empty($rebuildResult['ok']) ? 'success' : 'warning',
            !empty($rebuildResult['ok'])
                ? 'Public rebuild thành công.'
                : 'Public rebuild thất bại: ' . ($rebuildResult['message'] ?? 'Lỗi không xác định.')
        );
        editorial_redirect(editorial_url('integrity.php'));
    }

    // Cleanup expired locks
    if ($intent === 'cleanup_expired_locks') {
        try {
            $result = editorial_cleanup_expired_locks();
            $count = $result['count'] ?? 0;
            if ($count > 0) {
                editorial_flash_set('success', 'Đã xóa ' . $count . ' khóa hết hạn.');
                try {
                    editorial_log_activity('maintenance.expired_locks_cleaned', null, $adminUserId, json_encode([
                        'count' => $count,
                        'article_ids' => $result['article_ids'] ?? [],
                    ]));
                } catch (\Throwable $e) { /* best-effort */ }
            } else {
                editorial_flash_set('info', 'Không có khóa hết hạn nào.');
            }
        } catch (\Throwable $e) {
            editorial_flash_set('danger', 'Lỗi xóa khóa: ' . $e->getMessage());
        }
        editorial_redirect(editorial_url('integrity.php'));
    }

    editorial_redirect(editorial_url('integrity.php'));
}

// ── GET: Run Scan ──

$filterSeverity = trim((string) ($_GET['severity'] ?? ''));
$filterComponent = trim((string) ($_GET['component'] ?? ''));
$filterArticle = trim((string) ($_GET['q'] ?? ''));

$scanResult = editorial_run_integrity_scan();
$issues = $scanResult['issues'];
$summary = $scanResult['summary'];

// Apply filters
if ($filterSeverity !== '') {
    $issues = array_filter($issues, fn($i) => ($i['severity'] ?? '') === $filterSeverity);
}
if ($filterComponent !== '') {
    $issues = array_filter($issues, fn($i) => ($i['component'] ?? '') === $filterComponent);
}
if ($filterArticle !== '') {
    $issues = array_filter($issues, fn($i) =>
        stripos($i['article_id'] ?? '', $filterArticle) !== false
        || stripos($i['message'] ?? '', $filterArticle) !== false
    );
}
$issues = array_values($issues);

// Count expired locks for maintenance button
$db = editorial_db();
$expiredLockStmt = $db->prepare('SELECT COUNT(*) FROM editorial_locks WHERE expires_at < :now');
$expiredLockStmt->execute([':now' => date('c')]);
$expiredLockCount = (int) $expiredLockStmt->fetchColumn();

editorial_layout_header([
    'title' => 'Toàn vẹn hệ thống',
    'active' => 'integrity',
    'description' => 'Kiểm tra tính nhất quán dữ liệu Editorial',
]);
?>

<!-- Summary Cards -->
<div class="admin-grid-cards" style="margin-bottom: 24px;">
    <div class="admin-card <?= $summary['critical'] > 0 ? 'integrity-card-critical' : '' ?>">
        <div class="admin-card-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="admin-card-body">
            <div class="admin-card-title">Critical</div>
            <div class="admin-card-value"><?= $summary['critical'] ?></div>
        </div>
    </div>
    <div class="admin-card <?= $summary['warning'] > 0 ? 'integrity-card-warning' : '' ?>">
        <div class="admin-card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="admin-card-body">
            <div class="admin-card-title">Warning</div>
            <div class="admin-card-value"><?= $summary['warning'] ?></div>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="admin-card-body">
            <div class="admin-card-title">Info</div>
            <div class="admin-card-value"><?= $summary['info'] ?></div>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="admin-card-body">
            <div class="admin-card-title">Đã kiểm tra</div>
            <div class="admin-card-value"><?= $summary['total_checked'] ?></div>
        </div>
    </div>
</div>

<!-- Maintenance Actions -->
<div class="editorial-section" style="margin-bottom: 24px;">
    <h3>Bảo trì</h3>
    <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <?php if ($expiredLockCount > 0): ?>
            <form method="POST" style="display:inline;">
                <?= editorial_csrf_input() ?>
                <input type="hidden" name="_intent" value="cleanup_expired_locks">
                <button type="submit" class="editorial-btn editorial-btn-secondary">
                    <i class="fa-solid fa-broom"></i> Xóa <?= $expiredLockCount ?> khóa hết hạn
                </button>
            </form>
        <?php else: ?>
            <span class="editorial-badge editorial-badge-ok">Không có khóa hết hạn</span>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="editorial-section" style="margin-bottom: 24px;">
    <form method="GET" class="editorial-filter-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div>
            <label>Mức độ</label>
            <select name="severity">
                <option value="">Tất cả</option>
                <option value="critical" <?= $filterSeverity === 'critical' ? 'selected' : '' ?>>Critical</option>
                <option value="warning" <?= $filterSeverity === 'warning' ? 'selected' : '' ?>>Warning</option>
                <option value="info" <?= $filterSeverity === 'info' ? 'selected' : '' ?>>Info</option>
            </select>
        </div>
        <div>
            <label>Thành phần</label>
            <select name="component">
                <option value="">Tất cả</option>
                <option value="state" <?= $filterComponent === 'state' ? 'selected' : '' ?>>State</option>
                <option value="assignment" <?= $filterComponent === 'assignment' ? 'selected' : '' ?>>Assignment</option>
                <option value="revision" <?= $filterComponent === 'revision' ? 'selected' : '' ?>>Revision</option>
                <option value="snapshot" <?= $filterComponent === 'snapshot' ? 'selected' : '' ?>>Snapshot</option>
                <option value="live" <?= $filterComponent === 'live' ? 'selected' : '' ?>>Live HTML</option>
                <option value="catalog" <?= $filterComponent === 'catalog' ? 'selected' : '' ?>>Catalog</option>
                <option value="lock" <?= $filterComponent === 'lock' ? 'selected' : '' ?>>Lock</option>
                <option value="draft" <?= $filterComponent === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="backup" <?= $filterComponent === 'backup' ? 'selected' : '' ?>>Backup</option>
                <option value="user" <?= $filterComponent === 'user' ? 'selected' : '' ?>>User</option>
            </select>
        </div>
        <div>
            <label>Tìm kiếm</label>
            <input type="text" name="q" value="<?= editorial_h($filterArticle) ?>" placeholder="Article ID hoặc từ khóa...">
        </div>
        <button type="submit" class="editorial-btn editorial-btn-primary">
            <i class="fa-solid fa-filter"></i> Lọc
        </button>
        <?php if ($filterSeverity !== '' || $filterComponent !== '' || $filterArticle !== ''): ?>
            <a href="<?= editorial_h(editorial_url('integrity.php')) ?>" class="editorial-btn editorial-btn-secondary">Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- Issues Table -->
<div class="editorial-section">
    <h3>Kết quả kiểm tra <?= count($issues) > 0 ? '(' . count($issues) . ' vấn đề)' : '' ?></h3>

    <?php if (empty($issues)): ?>
        <div class="editorial-empty-state">
            <i class="fa-solid fa-check-circle" style="font-size:48px; color: var(--color-success, #28a745);"></i>
            <p>Không phát hiện vấn đề nào<?= ($filterSeverity !== '' || $filterComponent !== '' || $filterArticle !== '') ? ' (với bộ lọc hiện tại)' : '' ?>.</p>
        </div>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:90px;">Mức độ</th>
                    <th style="width:140px;">Bài viết</th>
                    <th style="width:100px;">Thành phần</th>
                    <th>Vấn đề</th>
                    <th style="width:160px;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td>
                            <?php
                            $sevClass = match($issue['severity']) {
                                'critical' => 'integrity-sev-critical',
                                'warning' => 'integrity-sev-warning',
                                default => 'integrity-sev-info',
                            };
                            ?>
                            <span class="editorial-badge <?= $sevClass ?>">
                                <?= editorial_h(ucfirst($issue['severity'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($issue['article_id'])): ?>
                                <code title="<?= editorial_h($issue['article_id']) ?>"><?= editorial_h(mb_strimwidth($issue['article_id'], 0, 18, '…')) ?></code>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= editorial_h($issue['component']) ?></code></td>
                        <td>
                            <?= editorial_h($issue['message']) ?>
                            <?php if (!empty($issue['details_safe'])): ?>
                                <br><small class="text-muted"><?= editorial_h($issue['details_safe']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            // Rebuild retry for published_live_drift
                            if ($issue['code'] === 'published_live_drift' && !empty($issue['article_id'])):
                            ?>
                                <small class="text-muted">Cần kiểm tra thủ công</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php editorial_layout_footer(); ?>
