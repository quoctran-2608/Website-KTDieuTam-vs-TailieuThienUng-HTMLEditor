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

// Guard: only serve /editorial/ requests
editorial_enforce_request_context();

// Check PDO SQLite availability with friendly error
try {
    editorial_db();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>Lỗi cấu hình</title></head>';
    echo '<body style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h1>⚠️ Lỗi cấu hình hệ thống</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

// Run migrations (idempotent)
editorial_run_migrations();

// Start session
editorial_bootstrap_session();

// Seed admin user if DB is empty
editorial_seed_admin_user();
