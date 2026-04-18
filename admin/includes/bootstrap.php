<?php
declare(strict_types=1);

if (!defined('ADMIN_BASE_PATH')) {
  define('ADMIN_BASE_PATH', dirname(__DIR__));
}

if (!defined('ADMIN_STORAGE_PATH')) {
  define('ADMIN_STORAGE_PATH', ADMIN_BASE_PATH . '/storage');
}

if (!defined('ADMIN_DATA_PATH')) {
  define('ADMIN_DATA_PATH', ADMIN_STORAGE_PATH . '/admin-data.json');
}

if (!defined('ADMIN_LOG_PATH')) {
  define('ADMIN_LOG_PATH', ADMIN_STORAGE_PATH . '/audit.log');
}

if (!defined('ADMIN_ARTICLES_SOURCE_PATH')) {
  define('ADMIN_ARTICLES_SOURCE_PATH', dirname(ADMIN_BASE_PATH) . '/data/articles.json');
}

if (!defined('ADMIN_ARTICLES_INDEX_PATH')) {
  define('ADMIN_ARTICLES_INDEX_PATH', ADMIN_STORAGE_PATH . '/articles-index.json');
}

if (!defined('ADMIN_SESSION_TTL')) {
  define('ADMIN_SESSION_TTL', 60 * 60 * 8);
}

if (!defined('ADMIN_LOCK_WINDOW')) {
  define('ADMIN_LOCK_WINDOW', 60 * 10);
}

if (!defined('ADMIN_LOCK_ATTEMPTS')) {
  define('ADMIN_LOCK_ATTEMPTS', 5);
}

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/article_index.php';

bootstrap_storage();
bootstrap_session();
ensure_default_admin_user();
sync_articles_index(false);
