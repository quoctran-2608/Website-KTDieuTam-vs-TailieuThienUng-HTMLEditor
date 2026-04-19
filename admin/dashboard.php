<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();
require_role(['admin', 'editor']);

$data = storage_read();
$loginAttempts = $data['login_attempts'];
$auditLogs = array_reverse(array_slice($data['audit_logs'], -12));
$users = $data['users'];
$current = current_user();
$cache = read_articles_index_cache();
$articleIndexCount = (int) ($cache['meta']['count'] ?? 0);

$activeUsers = 0;
foreach ($users as $user) {
  if (is_array($user) && !empty($user['is_active'])) {
    $activeUsers++;
  }
}

admin_layout_header([
  'title' => 'Tổng quan hệ thống',
  'active' => 'dashboard',
  'description' => 'Theo dõi tài khoản, lượt đăng nhập lỗi và nhật ký thao tác gần đây.',
  'sidebar_note' => 'Khu vực quản trị nội dung',
]);
?>

<section class="admin-grid-cards">
  <article class="metric-card">
    <span class="metric-icon"><i class="fa-solid fa-user-check"></i></span>
    <div class="metric-body">
      <h3><?= h((string) $activeUsers) ?></h3>
      <p>Tài khoản đang kích hoạt</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon warning"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <div class="metric-body">
      <h3><?= h((string) count($loginAttempts)) ?></h3>
      <p>Lần đăng nhập lỗi gần đây</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-solid fa-clock-rotate-left"></i></span>
    <div class="metric-body">
      <h3><?= h((string) count($auditLogs)) ?></h3>
      <p>Nhật ký gần nhất</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon success"><i class="fa-solid fa-id-card-clip"></i></span>
    <div class="metric-body">
      <h3><?= h((string) ($current['role'] ?? '')) ?></h3>
      <p>Vai trò đang dùng</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-solid fa-file-lines"></i></span>
    <div class="metric-body">
      <h3><?= h(number_format($articleIndexCount, 0, ',', '.')) ?></h3>
      <p>Tổng số bài hiện có</p>
    </div>
  </article>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Trạng thái bảo mật</h2>
    <p>Các lớp bảo vệ đang bật trong hệ thống.</p>
  </div>
  <ul class="status-list">
    <li><i class="fa-solid fa-circle-check"></i> Phiên đăng nhập tự hết hạn sau 8 giờ</li>
    <li><i class="fa-solid fa-circle-check"></i> Biểu mẫu đăng nhập bắt buộc có mã xác thực</li>
    <li><i class="fa-solid fa-circle-check"></i> Sai nhiều lần sẽ bị khóa tạm thời</li>
    <li><i class="fa-solid fa-circle-check"></i> Ghi lại lịch sử đăng nhập, đăng xuất và truy cập sai quyền</li>
  </ul>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Nhật ký gần nhất</h2>
    <p>Dữ liệu đầy đủ được lưu tại <code>admin/storage/audit.log</code>.</p>
  </div>

  <?php if (empty($auditLogs)): ?>
    <div class="empty-state">
      <i class="fa-regular fa-folder-open"></i>
      <p>Chưa có nhật ký nào.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Thời điểm</th>
            <th>Sự kiện</th>
            <th>Tài khoản</th>
            <th>IP</th>
            <th>Chi tiết</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($auditLogs as $log): ?>
            <?php if (!is_array($log)) continue; ?>
            <tr>
              <td><?= h(format_admin_datetime((string) ($log['timestamp'] ?? ''))) ?></td>
              <td><span class="event-pill"><?= h((string) ($log['event'] ?? '')) ?></span></td>
              <td><?= h((string) ($log['username'] ?? '—')) ?></td>
              <td><?= h((string) ($log['ip'] ?? '—')) ?></td>
              <td>
                <?php
                $detail = [];
                if (!empty($log['reason'])) $detail[] = 'lý do: ' . (string) $log['reason'];
                if (!empty($log['uri'])) $detail[] = 'đường dẫn: ' . (string) $log['uri'];
                if (!empty($log['role'])) $detail[] = 'vai trò: ' . (string) $log['role'];
                ?>
                <?= h($detail ? implode(' | ', $detail) : '—') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Đi nhanh đến danh sách bài</h2>
    <p>Mở trang danh sách để tìm và sửa bài.</p>
  </div>
  <div class="next-phase-banner">
    <i class="fa-solid fa-arrow-right-long"></i>
    <div>
      <strong>Đi đến danh sách bài viết</strong>
      <p>Bạn có thể tìm theo tiêu đề, lọc theo mục và mở bài để chỉnh sửa ngay.</p>
    </div>
  </div>
  <p style="margin-top:12px;">
    <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
      <i class="fa-solid fa-arrow-right"></i>
      <span>Mở trang Bài viết</span>
    </a>
  </p>
</section>

<?php admin_layout_footer(); ?>
