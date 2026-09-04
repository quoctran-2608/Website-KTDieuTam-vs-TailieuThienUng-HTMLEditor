<?php
declare(strict_types=1);

/**
 * Editorial V2 — server-side settings service.
 *
 * Secrets remain in SQLite and are never returned to browser-facing callers.
 */

const EDITORIAL_HANDOFF_SETTING_KEYS = [
    'composio_api_key',
    'composio_connected_account_id',
    'composio_connected_user_id',
    'handoff_drive_folder_id',
    'handoff_spreadsheet_id',
    'handoff_sheet_name',
    'handoff_public_base_url',
    'composio_pinned_toolkit_version',
    'composio_last_verified_at',
    'composio_last_verify_status',
    'composio_last_verify_message',
];

function editorial_setting_get(string $key, ?string $default = null): ?string
{
    $stmt = editorial_db()->prepare('SELECT setting_value FROM editorial_settings WHERE setting_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function editorial_setting_set(string $key, string $value, string $userId, bool $isSecret = false): void
{
    $stmt = editorial_db()->prepare('
        INSERT INTO editorial_settings (setting_key, setting_value, is_secret, updated_by, updated_at)
        VALUES (:key, :value, :is_secret, :updated_by, :updated_at)
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            is_secret = excluded.is_secret,
            updated_by = excluded.updated_by,
            updated_at = excluded.updated_at
    ');
    $stmt->execute([
        'key' => $key,
        'value' => $value,
        'is_secret' => $isSecret ? 1 : 0,
        'updated_by' => $userId,
        'updated_at' => date('c'),
    ]);
}

/**
 * @return array{api_key:string,api_key_configured:bool,connected_account_id:string,connected_user_id:string,drive_folder_id:string,spreadsheet_id:string,sheet_name:string,public_base_url:string,pinned_toolkit_version:string,last_verified_at:string,last_verify_status:string,last_verify_message:string}
 */
function editorial_handoff_settings(bool $includeSecret = false): array
{
    $read = static fn(string $key): string => (string) (editorial_setting_get($key, '') ?? '');
    $apiKey = $read('composio_api_key');

    return [
        'api_key' => $includeSecret ? $apiKey : '',
        'api_key_configured' => $apiKey !== '',
        'connected_account_id' => $read('composio_connected_account_id'),
        'connected_user_id' => $read('composio_connected_user_id'),
        'drive_folder_id' => $read('handoff_drive_folder_id'),
        'spreadsheet_id' => $read('handoff_spreadsheet_id'),
        'sheet_name' => $read('handoff_sheet_name'),
        'public_base_url' => $read('handoff_public_base_url'),
        'pinned_toolkit_version' => $read('composio_pinned_toolkit_version'),
        'last_verified_at' => $read('composio_last_verified_at'),
        'last_verify_status' => $read('composio_last_verify_status'),
        'last_verify_message' => $read('composio_last_verify_message'),
    ];
}

function editorial_handoff_normalize_public_base_url(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['ok' => true, 'value' => ''];
    }
    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        return ['ok' => false, 'message' => 'Public Base URL phải là URL http hoặc https hợp lệ.'];
    }
    $parts = parse_url($value);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return ['ok' => false, 'message' => 'Public Base URL phải dùng http hoặc https.'];
    }
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
        return ['ok' => false, 'message' => 'Public Base URL không được chứa user, query hoặc fragment.'];
    }
    return ['ok' => true, 'value' => rtrim($value, '/') . '/'];
}

function editorial_handoff_settings_is_complete(): bool
{
    $settings = editorial_handoff_settings(true);
    foreach (['api_key', 'connected_account_id', 'drive_folder_id', 'spreadsheet_id', 'sheet_name', 'public_base_url'] as $key) {
        if (trim((string) $settings[$key]) === '') {
            return false;
        }
    }
    return !empty(editorial_handoff_normalize_public_base_url($settings['public_base_url'])['ok']);
}

/**
 * Save connection/destination configuration. A blank API-key input retains
 * an existing server-side key and never exposes it to the caller.
 *
 * @return array{ok:bool,message:string}
 */
function editorial_handoff_save_settings(array $input, string $userId): array
{
    $current = editorial_handoff_settings(true);
    $apiKeyInput = trim((string) ($input['composio_api_key'] ?? ''));
    if ($apiKeyInput !== '' && (mb_strlen($apiKeyInput) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $apiKeyInput))) {
        return ['ok' => false, 'message' => 'Composio API Key không hợp lệ.'];
    }
    $values = [
        'composio_connected_account_id' => trim((string) ($input['composio_connected_account_id'] ?? '')),
        'handoff_drive_folder_id' => trim((string) ($input['handoff_drive_folder_id'] ?? '')),
        'handoff_spreadsheet_id' => trim((string) ($input['handoff_spreadsheet_id'] ?? '')),
        'handoff_sheet_name' => trim((string) ($input['handoff_sheet_name'] ?? '')),
    ];

    foreach ($values as $key => $value) {
        if (mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return ['ok' => false, 'message' => 'Giá trị cấu hình không hợp lệ: ' . $key . '.'];
        }
    }
    $urlResult = editorial_handoff_normalize_public_base_url((string) ($input['handoff_public_base_url'] ?? ''));
    if (!$urlResult['ok']) {
        return ['ok' => false, 'message' => $urlResult['message']];
    }
    $values['handoff_public_base_url'] = (string) $urlResult['value'];

    $apiKey = $apiKeyInput !== '' ? $apiKeyInput : $current['api_key'];
    $apiKeyChanged = ($apiKeyInput !== '' && !hash_equals($current['api_key'], $apiKeyInput));
    $changed = $apiKeyChanged;
    $comparison = [
        'composio_connected_account_id' => 'connected_account_id',
        'handoff_drive_folder_id' => 'drive_folder_id',
        'handoff_spreadsheet_id' => 'spreadsheet_id',
        'handoff_sheet_name' => 'sheet_name',
        'handoff_public_base_url' => 'public_base_url',
    ];
    foreach ($comparison as $settingKey => $currentKey) {
        if ($values[$settingKey] !== $current[$currentKey]) {
            $changed = true;
        }
    }

    editorial_transaction(function () use ($values, $apiKey, $apiKeyInput, $apiKeyChanged, $current, $changed, $userId): void {
        if ($apiKeyInput !== '') {
            editorial_setting_set('composio_api_key', $apiKey, $userId, true);
        }
        foreach ($values as $key => $value) {
            editorial_setting_set($key, $value, $userId);
        }
        if ($changed) {
            editorial_setting_set('composio_last_verify_status', 'unverified', $userId);
            editorial_setting_set('composio_last_verify_message', 'Cấu hình đã thay đổi và cần kiểm tra lại.', $userId);
            editorial_setting_set('composio_last_verified_at', '', $userId);
            if ($apiKeyChanged || $values['composio_connected_account_id'] !== $current['connected_account_id']) {
                editorial_setting_set('composio_pinned_toolkit_version', '', $userId);
                editorial_setting_set('composio_connected_user_id', '', $userId);
            }
        }
    });

    editorial_log_activity('handoff.settings.saved', null, $userId, json_encode([
        'api_key_replaced' => $apiKeyInput !== '',
        'verification_invalidated' => $changed,
    ]));
    return ['ok' => true, 'message' => 'Đã lưu cấu hình Google Handoff.'];
}

function editorial_handoff_record_verification(array $result, string $userId): void
{
    $ok = !empty($result['ok']);
    $message = trim((string) ($result['message'] ?? ''));
    $version = trim((string) ($result['version'] ?? ''));
    $connectedUserId = trim((string) ($result['user_id'] ?? ''));
    $now = date('c');

    editorial_transaction(function () use ($ok, $message, $version, $connectedUserId, $now, $userId): void {
        editorial_setting_set('composio_last_verified_at', $now, $userId);
        editorial_setting_set('composio_last_verify_status', $ok ? 'verified' : 'failed', $userId);
        editorial_setting_set('composio_last_verify_message', $message, $userId);
        if ($ok && $version !== '') {
            editorial_setting_set('composio_pinned_toolkit_version', $version, $userId);
            if ($connectedUserId !== '') {
                editorial_setting_set('composio_connected_user_id', $connectedUserId, $userId);
            }
        }
    });
}
