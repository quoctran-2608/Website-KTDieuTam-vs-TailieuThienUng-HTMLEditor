<?php
declare(strict_types=1);

/**
 * Editorial V2 — Layout.
 *
 * Reuses admin.css from legacy admin for consistent look.
 * Adds editorial.css for V2-specific additions.
 */

/**
 * Render editorial page header and shell opener.
 *
 * @param array{
 *   title?: string,
 *   active?: string,
 *   description?: string,
 *   body_class?: string
 * } $options
 */
function editorial_layout_header(array $options = []): void
{
    $title = $options['title'] ?? 'Editorial Admin';
    $active = $options['active'] ?? '';
    $description = $options['description'] ?? '';
    $bodyClass = trim((string) ($options['body_class'] ?? ''));
    $user = editorial_current_user();

    $isAdmin = ($user !== null && ($user['role'] ?? '') === 'admin');

    $menu = [
        [
            'key' => 'dashboard',
            'label' => 'Tổng quan',
            'href' => editorial_url('dashboard.php'),
            'icon' => 'fa-solid fa-gauge-high',
        ],
        [
            'key' => 'articles',
            'label' => 'Bài viết',
            'href' => editorial_url('articles.php'),
            'icon' => 'fa-solid fa-file-lines',
        ],
        [
            'key' => 'my-work',
            'label' => 'Công việc của tôi',
            'href' => editorial_url('my-work.php'),
            'icon' => 'fa-solid fa-clipboard-list',
        ],
    ];
    if ($isAdmin) {
        $menu[] = [
            'key' => 'members',
            'label' => 'Thành viên',
            'href' => editorial_url('users.php'),
            'icon' => 'fa-solid fa-users',
        ];
    }
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= editorial_h($title) ?> | Editorial Admin</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
        <link rel="stylesheet" href="<?= editorial_h(editorial_admin_asset_url('assets/css/admin.css')) ?>">
        <link rel="stylesheet" href="<?= editorial_h(editorial_url('assets/css/editorial.css')) ?>">
    </head>
    <body class="admin-body admin-page-<?= editorial_h($active) ?><?= $bodyClass !== '' ? (' ' . editorial_h($bodyClass)) : '' ?>">
        <div class="admin-shell">
            <aside class="admin-sidebar">
                <div class="admin-brand">
                    <a href="<?= editorial_h(editorial_url('dashboard.php')) ?>" class="admin-brand-logo" aria-label="Trang chính Editorial">
                        <i class="fa-solid fa-pen-ruler"></i>
                    </a>
                    <div class="admin-brand-text">
                        <strong>Editorial Admin</strong>
                        <span>Kế Toán Diệu Tâm</span>
                    </div>
                </div>
                <nav class="admin-nav" aria-label="Điều hướng Editorial">
                    <?php foreach ($menu as $item): ?>
                        <?php
                        $isActive = $active === $item['key'];
                        $disabled = !empty($item['disabled']);
                        ?>
                        <a
                            class="admin-nav-link <?= $isActive ? 'is-active' : '' ?> <?= $disabled ? 'is-disabled' : '' ?>"
                            href="<?= $disabled ? '#' : editorial_h($item['href']) ?>"
                            <?= $disabled ? 'aria-disabled="true"' : '' ?>
                        >
                            <i class="<?= editorial_h($item['icon']) ?>"></i>
                            <span><?= editorial_h($item['label']) ?></span>
                            <?php if ($disabled): ?>
                                <small>Sắp mở</small>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="admin-sidebar-foot">
                    <p><strong>Kế Toán Diệu Tâm</strong></p>
                    <p>Biên tập nội dung đa người dùng</p>
                </div>
            </aside>

            <div class="admin-main">
                <header class="admin-topbar">
                    <div class="admin-topbar-text">
                        <h1><?= editorial_h($title) ?></h1>
                        <?php if ($description !== ''): ?>
                            <p><?= editorial_h($description) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="admin-topbar-actions">
                        <div class="admin-user-chip">
                            <i class="fa-solid fa-user-pen"></i>
                            <div>
                                <strong><?= editorial_h((string) ($user['display_name'] ?? 'Không rõ')) ?></strong>
                                <span><?= editorial_h((string) ($user['role'] ?? '')) ?></span>
                            </div>
                        </div>
                        <a class="editorial-change-pw-btn" href="<?= editorial_h(editorial_url('change-password.php')) ?>" title="Đổi mật khẩu">
                            <i class="fa-solid fa-key"></i>
                        </a>
                        <a class="admin-logout-btn" href="<?= editorial_h(editorial_url('logout.php')) ?>">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <span>Đăng xuất</span>
                        </a>
                    </div>
                </header>

                <main class="admin-content">
                    <?php foreach (editorial_flash_pull() as $flash): ?>
                        <div class="flash flash-<?= editorial_h($flash['type']) ?>">
                            <?= editorial_h($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>
    <?php
}

/**
 * Render editorial page footer and close shell.
 */
function editorial_layout_footer(): void
{
    ?>
                </main>
            </div>
        </div>
        <script src="<?= editorial_h(editorial_url('assets/js/editorial.js')) ?>"></script>
    </body>
    </html>
    <?php
}
