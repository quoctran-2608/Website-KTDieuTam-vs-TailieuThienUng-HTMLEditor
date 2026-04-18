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

if (!defined('ADMIN_PARSER_AUDIT_PATH')) {
  define('ADMIN_PARSER_AUDIT_PATH', ADMIN_STORAGE_PATH . '/parser-audit.json');
}

if (!defined('ADMIN_DRAFTS_PATH')) {
  define('ADMIN_DRAFTS_PATH', ADMIN_STORAGE_PATH . '/article-drafts.json');
}

if (!defined('ADMIN_BACKUPS_DIR')) {
  define('ADMIN_BACKUPS_DIR', ADMIN_STORAGE_PATH . '/backups');
}

if (!defined('ADMIN_PUBLISH_HISTORY_PATH')) {
  define('ADMIN_PUBLISH_HISTORY_PATH', ADMIN_STORAGE_PATH . '/publish-history.json');
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
require_once __DIR__ . '/article_parser.php';
require_once __DIR__ . '/article_draft.php';
require_once __DIR__ . '/article_publish.php';

bootstrap_storage();
bootstrap_session();
ensure_default_admin_user();
bootstrap_draft_storage();
bootstrap_publish_storage();
sync_articles_index(false);
