<?php
declare(strict_types=1);

/**
 * Editorial V2 — Admin-only Google Handoff configuration.
 *
 * Phase 12A only stores config and performs explicit read-only verification.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/composio.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_role(['admin']);

$currentUser = editorial_current_user();
$adminUserId = (string) ($currentUser['user_id'] ?? '');

if (editorial_is_post()) {
    editorial_enforce_csrf();
    $intent = trim((string) ($_POST['_intent'] ?? ''));

    if ($intent === 'save_handoff_settings') {
        $result = editorial_handoff_save_settings($_POST, $adminUserId);
        editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        editorial_redirect(editorial_url('google-handoff-settings.php'));
    }

    if ($intent === 'verify_handoff_connection') {
        $result = editorial_verify_google_handoff();
        editorial_handoff_record_verification($result, $adminUserId);

        $safePayload = [
            'toolkit' => EDITORIAL_GOOGLE_SUPER_TOOLKIT,
            'version' => (string) ($result['version'] ?? ''),
            'connected_user_id' => (string) ($result['user_id'] ?? ''),
            'connected_account_id' => (string) (editorial_setting_get('composio_connected_account_id', '') ?? ''),
            'drive_folder_id' => (string) (editorial_setting_get('handoff_drive_folder_id', '') ?? ''),
            'spreadsheet_id' => (string) (editorial_setting_get('handoff_spreadsheet_id', '') ?? ''),
            'sheet_name' => (string) (editorial_setting_get('handoff_sheet_name', '') ?? ''),
        ];
        editorial_log_activity(
            !empty($result['ok']) ? 'handoff.settings.verified' : 'handoff.settings.verify_failed',
            null,
            $adminUserId,
            json_encode($safePayload)
        );
        editorial_flash_set(
            !empty($result['ok']) ? 'success' : 'danger',
            (string) ($result['message'] ?? 'Không thể kiểm tra kết nối.')
        );
        editorial_redirect(editorial_url('google-handoff-settings.php'));
    }

    editorial_redirect(editorial_url('google-handoff-settings.php'));
}

$settings = editorial_handoff_settings();
$isComplete = editorial_handoff_settings_is_complete();
$verifyStatus = (string) $settings['last_verify_status'];
$statusLabel = match ($verifyStatus) {
    'verified' => 'THÀNH CÔNG',
    'failed' => 'LỖI',
    'unverified' => 'CHƯA KIỂM TRA',
    default => ($isComplete ? 'CHƯA KIỂM TRA' : 'CHƯA CẤU HÌNH'),
};
$statusClass = match ($verifyStatus) {
    'verified' => 'is-verified',
    'failed' => 'is-failed',
    default => ($isComplete ? 'is-unverified' : 'is-incomplete'),
};
$expectedHeaders = [
    'Article ID',
    'Tên bài',
    'URL',
    'Internal Links',
    'Hình ảnh',
    'Category',
    'Biên tập bởi',
    'HTML Archive',
    'Ghi chú',
    'Published Revision',
    'Ngày bàn giao',
];

editorial_layout_header([
    'title' => 'Google Handoff',
    'active' => 'handoff-settings',
    'description' => 'Kết nối Editorial với Google Drive và Google Sheets qua Composio.',
]);
?>

<section class="editorial-handoff-status <?= editorial_h($statusClass) ?>">
    <div>
        <p class="editorial-handoff-kicker"><?= editorial_h($statusLabel) ?></p>
        <strong>
            <?php if ($verifyStatus === 'verified'): ?>
                Kết nối Google Handoff đã được xác minh.
            <?php elseif ($verifyStatus === 'failed'): ?>
                Lần kiểm tra gần nhất chưa thành công.
            <?php elseif ($isComplete): ?>
                Cấu hình đã lưu nhưng chưa xác minh.
            <?php else: ?>
                Chưa đủ thông tin để kiểm tra kết nối.
            <?php endif; ?>
        </strong>
        <?php if ($settings['last_verify_message'] !== ''): ?>
            <p><?= editorial_h($settings['last_verify_message']) ?></p>
        <?php endif; ?>
    </div>
    <div class="editorial-handoff-status-meta">
        <?php if ($settings['pinned_toolkit_version'] !== ''): ?>
            <span><i class="fa-solid fa-thumbtack"></i> Pinned: <?= editorial_h($settings['pinned_toolkit_version']) ?></span>
        <?php endif; ?>
        <?php if ($settings['connected_user_id'] !== ''): ?>
            <span><i class="fa-solid fa-user-link"></i> Composio User: <?= editorial_h($settings['connected_user_id']) ?></span>
        <?php endif; ?>
        <?php if ($settings['last_verified_at'] !== ''): ?>
            <span><i class="fa-solid fa-clock"></i> <?= editorial_h(editorial_format_datetime($settings['last_verified_at'])) ?></span>
        <?php endif; ?>
    </div>
</section>

<form method="post" class="editorial-handoff-form" novalidate>
    <?= editorial_csrf_input() ?>
    <input type="hidden" name="_intent" value="save_handoff_settings">

    <section class="admin-panel">
        <div class="panel-head">
            <h2><i class="fa-solid fa-plug-circle-bolt"></i> Kết nối Composio</h2>
            <p>Composio chỉ được gọi từ server khi Admin bấm kiểm tra kết nối.</p>
        </div>

        <div class="editorial-handoff-grid">
            <label class="form-field">
                <span>Composio API Key</span>
                <div class="field-input">
                    <i class="fa-solid fa-key"></i>
                    <input type="password" name="composio_api_key" value="" autocomplete="new-password" placeholder="Nhập key mới để thay thế">
                </div>
                <small><?= $settings['api_key_configured'] ? 'Đã lưu API Key. Để trống để giữ key hiện tại.' : 'Lấy trong Composio Project. Khóa chỉ được dùng phía server.' ?></small>
            </label>

            <label class="form-field">
                <span>Connected Account ID</span>
                <div class="field-input">
                    <i class="fa-solid fa-link"></i>
                    <input class="editorial-mono-input" type="text" name="composio_connected_account_id" value="<?= editorial_h($settings['connected_account_id']) ?>" autocomplete="off" placeholder="ca_...">
                </div>
                <small>ID tài khoản Google Super đang ACTIVE trong Composio.</small>
            </label>
        </div>
    </section>

    <section class="admin-panel">
        <div class="panel-head">
            <h2><i class="fa-brands fa-google-drive"></i> Đích lưu Google</h2>
            <p>Admin tạo trước Drive Folder, Spreadsheet và Sheet/Tab. Phase này không ghi dữ liệu ra Google.</p>
        </div>

        <div class="editorial-handoff-grid">
            <label class="form-field">
                <span>Drive Folder ID</span>
                <div class="field-input">
                    <i class="fa-solid fa-folder"></i>
                    <input class="editorial-mono-input" type="text" name="handoff_drive_folder_id" value="<?= editorial_h($settings['drive_folder_id']) ?>" autocomplete="off" placeholder="ID từ URL Google Drive Folder">
                </div>
                <small>Thư mục Google Drive dùng để lưu các file HTML archive.</small>
            </label>

            <label class="form-field">
                <span>Spreadsheet ID</span>
                <div class="field-input">
                    <i class="fa-solid fa-table"></i>
                    <input class="editorial-mono-input" type="text" name="handoff_spreadsheet_id" value="<?= editorial_h($settings['spreadsheet_id']) ?>" autocomplete="off" placeholder="ID từ URL Google Sheets">
                </div>
                <small>File Google Sheet theo dõi bài đã hoàn tất.</small>
            </label>

            <label class="form-field">
                <span>Sheet Name</span>
                <div class="field-input">
                    <i class="fa-solid fa-table-columns"></i>
                    <input type="text" name="handoff_sheet_name" value="<?= editorial_h($settings['sheet_name']) ?>" autocomplete="off" placeholder="Ví dụ: Bài viết">
                </div>
                <small>Tên tab hiển thị ở cuối Spreadsheet.</small>
            </label>

            <label class="form-field">
                <span>Public Base URL</span>
                <div class="field-input">
                    <i class="fa-solid fa-globe"></i>
                    <input class="editorial-mono-input" type="url" name="handoff_public_base_url" value="<?= editorial_h($settings['public_base_url']) ?>" autocomplete="url" placeholder="https://ketoandieutam.com/">
                </div>
                <small>Domain public dùng để tạo URL bài và normalize link/ảnh.</small>
            </label>
        </div>
    </section>

    <div class="editorial-handoff-actions">
        <button type="submit" class="btn-auth-submit">
            <i class="fa-solid fa-floppy-disk"></i> Lưu cấu hình
        </button>
    </div>
</form>

<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-shield-check"></i> Kiểm tra kết nối</h2>
        <p>Chỉ dùng cấu hình đã lưu. Quy trình chỉ đọc metadata Drive/Sheets, không tạo file hoặc ghi Sheet.</p>
    </div>
    <form method="post" class="editorial-handoff-verify-form">
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="_intent" value="verify_handoff_connection">
        <button type="submit" class="editorial-btn editorial-btn-primary" <?= $isComplete ? '' : 'disabled title="Hãy lưu đủ cấu hình trước khi kiểm tra."' ?>>
            <i class="fa-solid fa-plug-circle-check"></i> Kiểm tra kết nối
        </button>
        <?php if (!$isComplete): ?>
            <small>Nhập và lưu đủ API Key, Connected Account, Drive Folder, Spreadsheet, Sheet Name và Public Base URL trước.</small>
        <?php endif; ?>
    </form>
</section>

<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-list-check"></i> Cột dự kiến cho Phase 12B</h2>
        <p>Phase 12A chỉ hướng dẫn. Hệ thống chưa tạo header, upload HTML hoặc cập nhật Spreadsheet.</p>
    </div>
    <div class="editorial-handoff-header-list">
        <?php foreach ($expectedHeaders as $header): ?>
            <span><?= editorial_h($header) ?></span>
        <?php endforeach; ?>
    </div>
</section>

<?php editorial_layout_footer(); ?>
