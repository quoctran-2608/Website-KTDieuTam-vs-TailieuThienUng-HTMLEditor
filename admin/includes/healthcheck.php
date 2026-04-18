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

$checks[] = [
  'label' => 'draft storage exists',
  'ok' => file_exists(ADMIN_DRAFTS_PATH),
  'detail' => ADMIN_DRAFTS_PATH,
];

$draftPayload = read_drafts_payload();
$checks[] = [
  'label' => 'draft payload readable',
  'ok' => isset($draftPayload['drafts']) && is_array($draftPayload['drafts']),
  'detail' => 'count=' . (string) count(is_array($draftPayload['drafts'] ?? null) ? $draftPayload['drafts'] : []),
];

$checks[] = [
  'label' => 'backup directory exists',
  'ok' => is_dir(ADMIN_BACKUPS_DIR),
  'detail' => ADMIN_BACKUPS_DIR,
];

$checks[] = [
  'label' => 'publish history exists',
  'ok' => file_exists(ADMIN_PUBLISH_HISTORY_PATH),
  'detail' => ADMIN_PUBLISH_HISTORY_PATH,
];

$publishHistory = read_publish_history();
$checks[] = [
  'label' => 'publish history readable',
  'ok' => isset($publishHistory['records']) && is_array($publishHistory['records']),
  'detail' => 'count=' . (string) count(is_array($publishHistory['records'] ?? null) ? $publishHistory['records'] : []),
];

$sample = null;
if (isset($publishHistory['records']) && is_array($publishHistory['records']) && !empty($publishHistory['records'])) {
  $records = array_values(array_filter($publishHistory['records'], static fn($row): bool => is_array($row)));
  $sample = $records ? $records[count($records) - 1] : null;
}
$traceOk = true;
if (is_array($sample)) {
  $event = (string) ($sample['event'] ?? '');
  if ($event === 'publish') {
    $traceOk = (isset($sample['hash_before']) && isset($sample['hash_after'])) || isset($sample['backup_path']);
  } elseif ($event === 'rollback') {
    $traceOk = isset($sample['restored_hash']) || isset($sample['restored_from']);
  }
}
$checks[] = [
  'label' => 'publish record has trace hash',
  'ok' => $traceOk,
  'detail' => is_array($sample) ? ('event=' . (string) ($sample['event'] ?? '')) : 'no-record-yet',
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
echo "Phase 6 healthcheck passed." . PHP_EOL;
echo "Default dev login: admin / admin123" . PHP_EOL;
