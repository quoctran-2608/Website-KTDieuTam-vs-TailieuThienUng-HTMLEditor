<?php
declare(strict_types=1);

/**
 * Editorial V2 — Bootstrap.
 *
 * Single entry point to initialize the editorial system.
 * Does NOT load or modify legacy /admin/ code.
 */

if (!defined('EDITORIAL_BASE_PATH')) {
    define('EDITORIAL_BASE_PATH', dirname(__DIR__));
}

if (!defined('EDITORIAL_STORAGE_PATH')) {
    define('EDITORIAL_STORAGE_PATH', EDITORIAL_BASE_PATH . '/storage');
}

if (!defined('EDITORIAL_DB_PATH')) {
    define('EDITORIAL_DB_PATH', EDITORIAL_STORAGE_PATH . '/editorial.sqlite');
}

if (!defined('EDITORIAL_ARTICLES_SOURCE')) {
    define('EDITORIAL_ARTICLES_SOURCE', dirname(EDITORIAL_BASE_PATH) . '/data/articles.json');
}

// Load modules
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/article_catalog.php';
require_once __DIR__ . '/assignment.php';
require_once __DIR__ . '/settings.php';

// Guard: only serve /editorial/ requests
editorial_enforce_request_context();

// Check PDO SQLite availability with friendly error
try {
    editorial_db();
} catch (\Throwable $e) {
    error_log('Editorial database bootstrap failed: exception=' . get_class($e));
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>Lỗi cấu hình</title></head>';
    echo '<body style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h1>⚠️ Lỗi cấu hình hệ thống</h1>';
    echo '<p>Không thể khởi tạo dữ liệu Editorial. Vui lòng liên hệ quản trị viên.</p>';
    echo '</body></html>';
    exit;
}

// Run migrations (idempotent). A failed production upgrade must never become
// an unexplained blank HTTP 500 before users reach the login page.
try {
    editorial_run_migrations();
} catch (\Throwable $e) {
    $currentSchema = 0;
    if ($e instanceof EditorialMigrationException) {
        $currentSchema = $e->currentSchemaVersion;
    } else {
        try {
            $currentSchema = editorial_schema_version();
        } catch (\Throwable $schemaError) {
            $currentSchema = 0;
        }
    }
    $targetSchema = $e instanceof EditorialMigrationException
        ? $e->targetSchemaVersion
        : 0;
    $causeClass = $e instanceof EditorialMigrationException
        ? $e->causeClass
        : get_class($e);
    $safeMessage = editorial_migration_safe_message($e->getMessage());
    error_log(
        'Editorial migration failed: current_schema=' . $currentSchema
        . ' target_schema=' . $targetSchema
        . ' exception=' . $causeClass
        . ' message=' . $safeMessage
    );
    http_response_code(500);
    $errorCode = 'EDITORIAL_MIGRATION_FAILED_V' . ($targetSchema > 0 ? $targetSchema : 'UNKNOWN');
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>Editorial đang bảo trì</title></head>';
    echo '<body style="font-family:Arial,sans-serif;max-width:680px;margin:72px auto;padding:24px;color:#243447;">';
    echo '<h1>Không thể nâng cấp dữ liệu Editorial.</h1>';
    echo '<p>Hệ thống Editorial đang dừng an toàn để bảo vệ dữ liệu. Vui lòng báo quản trị viên với mã lỗi bên dưới.</p>';
    echo '<p><strong>Mã lỗi:</strong> ' . htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><strong>Schema hiện tại:</strong> ' . (int) $currentSchema
        . ' &nbsp; <strong>Migration cần chạy:</strong> ' . ($targetSchema > 0 ? (int) $targetSchema : 'không xác định') . '</p>';
    echo '</body></html>';
    exit;
}

// Start session
editorial_bootstrap_session();

// Seed admin user if DB is empty
editorial_seed_admin_user();
