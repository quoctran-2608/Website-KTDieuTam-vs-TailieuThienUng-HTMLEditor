<?php
declare(strict_types=1);

/**
 * Editorial V2 — Database layer (PDO SQLite).
 *
 * Provides a single PDO connection to editorial.sqlite with:
 * - foreign_keys ON
 * - busy_timeout 5000ms
 * - WAL journal mode
 * - Transaction helpers for atomic operations (needed for Phase 3+ assignment claims)
 */

if (!defined('EDITORIAL_STORAGE_PATH')) {
    define('EDITORIAL_STORAGE_PATH', dirname(__DIR__) . '/storage');
}

if (!defined('EDITORIAL_DB_PATH')) {
    define('EDITORIAL_DB_PATH', EDITORIAL_STORAGE_PATH . '/editorial.sqlite');
}

/**
 * Get or create the singleton PDO connection.
 *
 * @return PDO
 * @throws RuntimeException if PDO SQLite is not available
 */
function editorial_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException(
            'Editorial V2 yêu cầu PHP PDO SQLite. '
            . 'Vui lòng bật extension pdo_sqlite trong php.ini.'
        );
    }

    if (!is_dir(EDITORIAL_STORAGE_PATH)) {
        mkdir(EDITORIAL_STORAGE_PATH, 0775, true);
    }

    $pdo = new PDO('sqlite:' . EDITORIAL_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

/**
 * Run a callback inside a transaction.
 *
 * Uses BEGIN IMMEDIATE for write transactions to support
 * atomic claim operations in Phase 3+.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function editorial_transaction(callable $callback): mixed
{
    $db = editorial_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $callback();
        $db->exec('COMMIT');
        return $result;
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}
