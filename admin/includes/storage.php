<?php
declare(strict_types=1);

/**
 * Bootstrap storage files for admin panel.
 */
function bootstrap_storage(): void
{
  if (!is_dir(ADMIN_STORAGE_PATH)) {
    mkdir(ADMIN_STORAGE_PATH, 0775, true);
  }

  if (!file_exists(ADMIN_DATA_PATH)) {
    $seed = [
      'users' => [],
      'login_attempts' => [],
      'audit_logs' => [],
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
      ],
    ];
    storage_write($seed);
  }

  if (!file_exists(ADMIN_LOG_PATH)) {
    file_put_contents(ADMIN_LOG_PATH, '');
  }
}

/**
 * Read admin storage payload.
 *
 * @return array<string,mixed>
 */
function storage_read(): array
{
  $raw = file_get_contents(ADMIN_DATA_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'users' => [],
      'login_attempts' => [],
      'audit_logs' => [],
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
      ],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'users' => [],
      'login_attempts' => [],
      'audit_logs' => [],
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
      ],
    ];
  }

  if (!isset($decoded['users']) || !is_array($decoded['users'])) {
    $decoded['users'] = [];
  }
  if (!isset($decoded['login_attempts']) || !is_array($decoded['login_attempts'])) {
    $decoded['login_attempts'] = [];
  }
  if (!isset($decoded['audit_logs']) || !is_array($decoded['audit_logs'])) {
    $decoded['audit_logs'] = [];
  }
  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['meta']['created_at'])) {
    $decoded['meta']['created_at'] = date('c');
  }
  $decoded['meta']['updated_at'] = date('c');

  return $decoded;
}

/**
 * Persist admin storage payload.
 *
 * @param array<string,mixed> $data
 */
function storage_write(array $data): void
{
  $data['meta']['updated_at'] = date('c');
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode admin storage data.');
  }
  file_put_contents(ADMIN_DATA_PATH, $json . PHP_EOL);
}

/**
 * Append audit log to flat log file and storage snapshot.
 *
 * @param array<string,mixed> $record
 */
function append_audit_log(array $record): void
{
  $record['timestamp'] = $record['timestamp'] ?? date('c');
  $record['ip'] = $record['ip'] ?? client_ip();
  $record['user_agent'] = $record['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($line !== false) {
    file_put_contents(ADMIN_LOG_PATH, $line . PHP_EOL, FILE_APPEND);
  }

  $data = storage_read();
  $data['audit_logs'][] = $record;
  if (count($data['audit_logs']) > 500) {
    $data['audit_logs'] = array_slice($data['audit_logs'], -500);
  }
  storage_write($data);
}

