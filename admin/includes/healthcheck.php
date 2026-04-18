<?php
declare(strict_types=1);

/**
 * Lightweight CLI healthcheck for admin shell + article list index.
 *
 * Usage:
 *   php admin/includes/healthcheck.php
 */
require_once __DIR__ . '/bootstrap.php';

function health_ok(string $label, bool $ok, string $detail = ''): void
{
  $status = $ok ? 'OK' : 'FAIL';
  echo sprintf("[%s] %s", $status, $label);
  if ($detail !== '') {
    echo ' - ' . $detail;
  }
  echo PHP_EOL;
}

$checks = [];

$checks[] = [
  'label' => 'storage directory exists',
  'ok' => is_dir(ADMIN_STORAGE_PATH),
  'detail' => ADMIN_STORAGE_PATH,
];
$checks[] = [
  'label' => 'admin-data.json exists',
  'ok' => file_exists(ADMIN_DATA_PATH),
  'detail' => ADMIN_DATA_PATH,
];
$checks[] = [
  'label' => 'audit.log exists',
  'ok' => file_exists(ADMIN_LOG_PATH),
  'detail' => ADMIN_LOG_PATH,
];
$checks[] = [
  'label' => 'articles source exists',
  'ok' => file_exists(ADMIN_ARTICLES_SOURCE_PATH),
  'detail' => ADMIN_ARTICLES_SOURCE_PATH,
];

$data = storage_read();
$hasUsers = isset($data['users']) && is_array($data['users']) && count($data['users']) > 0;
$checks[] = [
  'label' => 'seed user exists',
  'ok' => $hasUsers,
  'detail' => $hasUsers ? 'count=' . count($data['users']) : '',
];

$hasAuthFns = function_exists('attempt_login') && function_exists('is_authenticated');
$checks[] = [
  'label' => 'auth helpers loaded',
  'ok' => $hasAuthFns,
];

$sync = sync_articles_index(false);
$checks[] = [
  'label' => 'articles index cache ready',
  'ok' => !empty($sync['synced']),
  'detail' => 'reason=' . (string) ($sync['reason'] ?? 'unknown') . ', count=' . (string) ($sync['count'] ?? 0),
];

$audit = run_parser_audit(false);
$checks[] = [
  'label' => 'parser audit ready',
  'ok' => isset($audit['meta']) && is_array($audit['meta']) && isset($audit['meta']['safe_rate_percent']),
  'detail' => 'safe=' . (string) ($audit['meta']['safe_count'] ?? 0) . '/' . (string) ($audit['meta']['total_count'] ?? 0),
];

$allOk = true;
foreach ($checks as $check) {
  $ok = (bool) $check['ok'];
  if (!$ok) {
    $allOk = false;
  }
  health_ok((string) $check['label'], $ok, (string) ($check['detail'] ?? ''));
}

if (!$allOk) {
  exit(1);
}

echo PHP_EOL;
echo "Phase 3 healthcheck passed." . PHP_EOL;
echo "Default dev login: admin / admin123" . PHP_EOL;
