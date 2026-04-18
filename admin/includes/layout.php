<?php
declare(strict_types=1);

/**
 * Render admin page header and shell opener.
 *
 * @param array{title?:string,active?:string,description?:string} $options
 */
function admin_layout_header(array $options = []): void
{
  $title = $options['title'] ?? 'Admin Editor';
  $active = $options['active'] ?? '';
  $description = $options['description'] ?? '';
  $user = current_user();
  $sidebarPhase = $options['phase_label'] ?? 'Phase 2 — Article list & filter';
  $articleCount = null;
  if (function_exists('read_articles_index_cache')) {
    $cache = read_articles_index_cache();
    if (isset($cache['meta']['count'])) {
      $articleCount = (int) $cache['meta']['count'];
    }
  }

  $menu = [
    [
      'key' => 'dashboard',
      'label' => 'Tổng quan',
      'href' => admin_url('dashboard.php'),
      'icon' => 'fa-solid fa-gauge-high',
    ],
    [
      'key' => 'articles',
      'label' => 'Bài viết',
      'href' => admin_url('articles.php'),
      'icon' => 'fa-solid fa-file-lines',
      'badge' => $articleCount !== null ? number_format($articleCount, 0, ',', '.') : null,
    ],
  ];
  ?>
  <!DOCTYPE html>
  <html lang="vi">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> | Admin Editor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= h(admin_url('assets/css/admin.css')) ?>">
  </head>
  <body class="admin-body">
    <div class="admin-shell">
      <aside class="admin-sidebar">
        <div class="admin-brand">
          <a href="<?= h(admin_url('dashboard.php')) ?>" class="admin-brand-logo" aria-label="Admin Home">
            <i class="fa-solid fa-shield-halved"></i>
          </a>
          <div class="admin-brand-text">
            <strong>Admin Editor</strong>
            <span>Kế Toán Diệu Tâm</span>
          </div>
        </div>
        <nav class="admin-nav" aria-label="Điều hướng admin">
          <?php foreach ($menu as $item): ?>
            <?php
            $isActive = $active === $item['key'];
            $disabled = !empty($item['disabled']);
            ?>
            <a
              class="admin-nav-link <?= $isActive ? 'is-active' : '' ?> <?= $disabled ? 'is-disabled' : '' ?>"
              href="<?= $disabled ? '#' : h($item['href']) ?>"
              <?= $disabled ? 'aria-disabled="true"' : '' ?>
            >
              <i class="<?= h($item['icon']) ?>"></i>
              <span><?= h($item['label']) ?></span>
              <?php if (!empty($item['badge'])): ?>
                <small><?= h((string) $item['badge']) ?></small>
              <?php elseif ($disabled): ?>
                <small>Phase sau</small>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-foot">
          <p>Phiên bản <strong>MVP v1</strong></p>
          <p><?= h($sidebarPhase) ?></p>
        </div>
      </aside>

      <div class="admin-main">
        <header class="admin-topbar">
          <div class="admin-topbar-text">
            <h1><?= h($title) ?></h1>
            <?php if ($description !== ''): ?>
              <p><?= h($description) ?></p>
            <?php endif; ?>
          </div>
          <div class="admin-topbar-actions">
            <div class="admin-user-chip">
              <i class="fa-solid fa-user-shield"></i>
              <div>
                <strong><?= h((string) ($user['display_name'] ?? 'N/A')) ?></strong>
                <span><?= h((string) ($user['role'] ?? '')) ?></span>
              </div>
            </div>
            <a class="admin-logout-btn" href="<?= h(admin_url('logout.php')) ?>">
              <i class="fa-solid fa-arrow-right-from-bracket"></i>
              <span>Đăng xuất</span>
            </a>
          </div>
        </header>

        <main class="admin-content">
          <?php foreach (flash_pull() as $flash): ?>
            <div class="flash flash-<?= h($flash['type']) ?>">
              <?= h($flash['message']) ?>
            </div>
          <?php endforeach; ?>
  <?php
}

/**
 * Render admin page footer and close shell.
 */
function admin_layout_footer(): void
{
  ?>
        </main>
      </div>
    </div>
    <script src="<?= h(admin_url('assets/js/admin.js')) ?>"></script>
  </body>
  </html>
  <?php
}
