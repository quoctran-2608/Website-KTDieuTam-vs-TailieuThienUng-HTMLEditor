<?php
declare(strict_types=1);

/**
 * Ensure draft storage file exists.
 */
function bootstrap_draft_storage(): void
{
  if (!file_exists(ADMIN_DRAFTS_PATH)) {
    $seed = [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'drafts' => [],
    ];
    write_drafts_payload($seed);
  }
}

/**
 * Read all drafts.
 *
 * @return array<string,mixed>
 */
function read_drafts_payload(): array
{
  bootstrap_draft_storage();
  $raw = file_get_contents(ADMIN_DRAFTS_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'drafts' => [],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'drafts' => [],
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['drafts']) || !is_array($decoded['drafts'])) {
    $decoded['drafts'] = [];
  }

  $decoded['meta']['count'] = count($decoded['drafts']);
  if (!isset($decoded['meta']['updated_at'])) {
    $decoded['meta']['updated_at'] = date('c');
  }

  return $decoded;
}

/**
 * Persist all drafts.
 *
 * @param array<string,mixed> $payload
 */
function write_drafts_payload(array $payload): void
{
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = [];
  }
  if (!isset($payload['drafts']) || !is_array($payload['drafts'])) {
    $payload['drafts'] = [];
  }

  if (!isset($payload['meta']['created_at'])) {
    $payload['meta']['created_at'] = date('c');
  }
  $payload['meta']['updated_at'] = date('c');
  $payload['meta']['count'] = count($payload['drafts']);

  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode draft payload.');
  }
  file_put_contents(ADMIN_DRAFTS_PATH, $json . PHP_EOL);
}

/**
 * Get one draft by article id.
 *
 * @return array<string,mixed>|null
 */
function read_article_draft(string $articleId): ?array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return null;
  }
  $payload = read_drafts_payload();
  $row = $payload['drafts'][$articleId] ?? null;
  return is_array($row) ? $row : null;
}

/**
 * Save/update draft for article.
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function save_article_draft(string $articleId, array $data, ?array $actor = null): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    throw new InvalidArgumentException('Article id is required.');
  }

  $payload = read_drafts_payload();
  $existing = $payload['drafts'][$articleId] ?? null;
  $createdAt = is_array($existing) && !empty($existing['created_at'])
    ? (string) $existing['created_at']
    : date('c');

  $row = [
    'article_id' => $articleId,
    'created_at' => $createdAt,
    'updated_at' => date('c'),
    'updated_by' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'data' => $data,
  ];
  $payload['drafts'][$articleId] = $row;
  write_drafts_payload($payload);

  append_audit_log([
    'event' => 'article.draft.saved',
    'article_id' => $articleId,
    'username' => (string) ($row['updated_by']['username'] ?? ''),
    'role' => (string) ($row['updated_by']['role'] ?? ''),
  ]);

  return $row;
}

/**
 * Delete draft by article id.
 */
function delete_article_draft(string $articleId, ?array $actor = null): bool
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return false;
  }
  $payload = read_drafts_payload();
  if (!isset($payload['drafts'][$articleId])) {
    return false;
  }

  unset($payload['drafts'][$articleId]);
  write_drafts_payload($payload);

  append_audit_log([
    'event' => 'article.draft.deleted',
    'article_id' => $articleId,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
  ]);
  return true;
}

