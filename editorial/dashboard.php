<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$user = editorial_current_user();
$currentUserId = (string) $user['user_id'];
$db = editorial_db();

// Count users
$userCount = (int) $db->query('SELECT COUNT(*) FROM editorial_users WHERE is_active = 1')->fetchColumn();

// Article assignment counts
$counts = editorial_assignment_counts($currentUserId);

// Count recent activity
$activityCount = (int) $db->query('SELECT COUNT(*) FROM editorial_activity')->fetchColumn();

// Recent activity logs
$recentActivity = $db->query('
    SELECT * FROM editorial_activity
    ORDER BY created_at DESC
    LIMIT 12
')->fetchAll();

// Schema version
$schemaVersion = editorial_schema_version();

// Review counts
$reviewCount = (int) $db->query("SELECT COUNT(*) FROM editorial_article_state WHERE status = 'ready_review'")->fetchColumn();
$approvedCount = (int) $db->query("SELECT COUNT(*) FROM editorial_article_state WHERE status = 'approved'")->fetchColumn();

editorial_layout_header([
    'title' => 'Tổng quan',
    'active' => 'dashboard',
    'description' => 'Editorial Admin V2 — Hệ thống biên tập nội dung đa người dùng.',
]);
?>

<section class="admin-grid-cards">
    <article class="metric-card">
        <span class="metric-icon info"><i class="fa-solid fa-file-lines"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h(number_format($counts['total'], 0, ',', '.')) ?></h3>
            <p>Tổng bài viết</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon success"><i class="fa-solid fa-folder-open"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $counts['available']) ?></h3>
            <p>Chưa có người nhận</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon warning"><i class="fa-solid fa-pen"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $counts['assigned']) ?></h3>
            <p>Đang được phân công</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-clipboard-list"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $counts['mine']) ?></h3>
            <p>Công việc của tôi</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-user-check"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $userCount) ?></h3>
            <p>Thành viên hoạt động</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-database"></i></span>
        <div class="metric-body">
            <h3>v<?= editorial_h((string) $schemaVersion) ?></h3>
            <p>Schema version</p>
        </div>
    </article>

    <?php if ($reviewCount > 0): ?>
    <article class="metric-card">
        <span class="metric-icon warning"><i class="fa-solid fa-clipboard-check"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $reviewCount) ?></h3>
            <p>Chờ duyệt</p>
        </div>
    </article>
    <?php endif; ?>

    <?php if ($approvedCount > 0): ?>
    <article class="metric-card">
        <span class="metric-icon success"><i class="fa-solid fa-circle-check"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $approvedCount) ?></h3>
            <p>Đã duyệt chờ Publish</p>
        </div>
    </article>
    <?php endif; ?>

    <?php
    $publishedCount = (int) $db->query("SELECT COUNT(*) FROM editorial_article_state WHERE status = 'published'")->fetchColumn();
    ?>
    <?php if ($publishedCount > 0): ?>
    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-rocket"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $publishedCount) ?></h3>
            <p>Đã xuất bản</p>
        </div>
    </article>
    <?php endif; ?>
</section>

<section class="admin-panel">
    <div class="panel-head">
        <h2>Editorial V2 — Các module</h2>
        <p>Tình trạng triển khai của từng chức năng.</p>
    </div>
    <div class="editorial-module-grid">
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Đăng nhập &amp; phân quyền</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Tài khoản thành viên</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Nhận bài biên tập</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Editing workspace</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Revision &amp; so sánh</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Review &amp; duyệt bài</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Safe Publish</strong>
            <span>Đã sẵn sàng</span>
        </div>
    </div>
</section>

<?php if (!empty($recentActivity)): ?>
<section class="admin-panel">
    <div class="panel-head">
        <h2>Hoạt động gần đây</h2>
        <p>12 sự kiện mới nhất trong hệ thống.</p>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Thời điểm</th>
                    <th>Sự kiện</th>
                    <th>Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentActivity as $log): ?>
                    <tr>
                        <td><?= editorial_h(editorial_format_datetime((string) ($log['created_at'] ?? ''))) ?></td>
                        <td><span class="event-pill"><?= editorial_h((string) ($log['event_type'] ?? '')) ?></span></td>
                        <td>
                            <?php
                            $payload = json_decode((string) ($log['payload_json'] ?? '{}'), true);
                            $details = [];
                            if (!empty($payload['username'])) $details[] = 'tài khoản: ' . (string) $payload['username'];
                            if (!empty($payload['role'])) $details[] = 'vai trò: ' . (string) $payload['role'];
                            if (!empty($payload['source'])) $details[] = 'nguồn: ' . (string) $payload['source'];
                            if (!empty($log['article_id'])) $details[] = 'bài: ' . (string) $log['article_id'];
                            ?>
                            <?= editorial_h($details ? implode(' | ', $details) : '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php editorial_layout_footer(); ?>
