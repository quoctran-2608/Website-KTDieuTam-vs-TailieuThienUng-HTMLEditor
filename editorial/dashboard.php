<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$user = editorial_current_user();
$db = editorial_db();

// Count users
$userCount = (int) $db->query('SELECT COUNT(*) FROM editorial_users WHERE is_active = 1')->fetchColumn();

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

editorial_layout_header([
    'title' => 'Tổng quan',
    'active' => 'dashboard',
    'description' => 'Editorial Admin V2 — Hệ thống biên tập nội dung đa người dùng.',
]);
?>

<section class="admin-grid-cards">
    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-user-check"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $userCount) ?></h3>
            <p>Thành viên đang hoạt động</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon info"><i class="fa-solid fa-clock-rotate-left"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) $activityCount) ?></h3>
            <p>Sự kiện đã ghi nhận</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon success"><i class="fa-solid fa-id-card-clip"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) ($user['role'] ?? '')) ?></h3>
            <p>Vai trò của bạn</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-database"></i></span>
        <div class="metric-body">
            <h3>v<?= editorial_h((string) $schemaVersion) ?></h3>
            <p>Schema version</p>
        </div>
    </article>
</section>

<section class="admin-panel">
    <div class="panel-head">
        <h2>Editorial V2 — Các module</h2>
        <p>Tình trạng triển khai của từng chức năng.</p>
    </div>
    <div class="editorial-module-grid">
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Đăng nhập & phân quyền</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card is-ready">
            <i class="fa-solid fa-circle-check"></i>
            <strong>Tài khoản thành viên</strong>
            <span>Đã sẵn sàng</span>
        </div>
        <div class="editorial-module-card">
            <i class="fa-solid fa-clock"></i>
            <strong>Nhận bài biên tập</strong>
            <span>Sắp mở</span>
        </div>
        <div class="editorial-module-card">
            <i class="fa-solid fa-clock"></i>
            <strong>Editing workspace</strong>
            <span>Sắp mở</span>
        </div>
        <div class="editorial-module-card">
            <i class="fa-solid fa-clock"></i>
            <strong>Revision & so sánh</strong>
            <span>Sắp mở</span>
        </div>
        <div class="editorial-module-card">
            <i class="fa-solid fa-clock"></i>
            <strong>Review & duyệt bài</strong>
            <span>Sắp mở</span>
        </div>
        <div class="editorial-module-card">
            <i class="fa-solid fa-clock"></i>
            <strong>Publish an toàn</strong>
            <span>Sắp mở</span>
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
