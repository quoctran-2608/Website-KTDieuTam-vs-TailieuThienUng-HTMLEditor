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
  'description' => 'Theo dõi trạng thái đăng nhập, bảo mật phiên và nhật ký truy cập của admin panel.',
  'phase_label' => 'Phase 1 — Auth shell',
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
      <p>Lần đăng nhập lỗi trong cửa sổ khóa</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-solid fa-clock-rotate-left"></i></span>
    <div class="metric-body">
      <h3><?= h((string) count($auditLogs)) ?></h3>
      <p>Audit log gần nhất</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon success"><i class="fa-solid fa-id-card-clip"></i></span>
    <div class="metric-body">
      <h3><?= h((string) ($current['role'] ?? '')) ?></h3>
      <p>Vai trò phiên hiện tại</p>
    </div>
  </article>

  <article class="metric-card">
    <span class="metric-icon info"><i class="fa-solid fa-file-lines"></i></span>
    <div class="metric-body">
      <h3><?= h(number_format($articleIndexCount, 0, ',', '.')) ?></h3>
      <p>Bài đã index cho module filter</p>
    </div>
  </article>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Checklist bảo mật Phase 1</h2>
    <p>Đây là các năng lực nền đã bật trong phiên bản hiện tại.</p>
  </div>
  <ul class="status-list">
    <li><i class="fa-solid fa-circle-check"></i> Session với timeout 8 giờ đã kích hoạt</li>
    <li><i class="fa-solid fa-circle-check"></i> CSRF token bắt buộc cho form login</li>
    <li><i class="fa-solid fa-circle-check"></i> Lockout tạm sau 5 lần sai trong 10 phút</li>
    <li><i class="fa-solid fa-circle-check"></i> Audit log cho login success/failed/logout/forbidden</li>
  </ul>
</section>

<section class="admin-panel">
  <div class="panel-head">
    <h2>Nhật ký gần nhất</h2>
    <p>Giữ tối đa 500 bản ghi trong storage snapshot và stream đầy đủ tại <code>admin/storage/audit.log</code>.</p>
  </div>

  <?php if (empty($auditLogs)): ?>
    <div class="empty-state">
      <i class="fa-regular fa-folder-open"></i>
      <p>Chưa có log nào được ghi. Hãy thử đăng xuất/đăng nhập để kiểm tra pipeline log.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Thời điểm</th>
            <th>Sự kiện</th>
            <th>User</th>
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
                if (!empty($log['reason'])) $detail[] = 'reason: ' . (string) $log['reason'];
                if (!empty($log['uri'])) $detail[] = 'uri: ' . (string) $log['uri'];
                if (!empty($log['role'])) $detail[] = 'role: ' . (string) $log['role'];
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
    <h2>Kế tiếp</h2>
    <p>Phase 2 đã mở module list bài và filter. Mời bạn trải nghiệm trực tiếp.</p>
  </div>
  <div class="next-phase-banner">
    <i class="fa-solid fa-arrow-right-long"></i>
    <div>
      <strong>Đi đến danh sách bài viết</strong>
      <p>Search title/id/href, filter section/library/topic/date, sort + pagination + chip clear đã sẵn sàng.</p>
    </div>
  </div>
  <p style="margin-top:12px;">
    <a class="clear-filter-btn inline" href="<?= h(admin_url('articles.php')) ?>">
      <i class="fa-solid fa-arrow-right"></i>
      <span>Mở module Bài viết</span>
    </a>
  </p>
</section>

<?php admin_layout_footer(); ?>
