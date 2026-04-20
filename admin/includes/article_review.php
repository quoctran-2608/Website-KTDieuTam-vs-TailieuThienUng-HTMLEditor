<?php
declare(strict_types=1);

/**
 * Ensure review status storage file exists.
 */
function bootstrap_review_status_storage(): void
{
  if (!file_exists(ADMIN_REVIEW_STATUS_PATH)) {
    $seed = [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'edited_count' => 0,
      ],
      'items' => [],
    ];
    write_review_status_payload($seed);
  }
}

/**
 * Read review status payload.
 *
 * @return array<string,mixed>
 */
function read_review_status_payload(): array
{
  bootstrap_review_status_storage();
  $raw = file_get_contents(ADMIN_REVIEW_STATUS_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'edited_count' => 0,
      ],
      'items' => [],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'edited_count' => 0,
      ],
      'items' => [],
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['items']) || !is_array($decoded['items'])) {
    $decoded['items'] = [];
  }

  $normalizedItems = [];
  foreach ($decoded['items'] as $key => $row) {
    if (!is_array($row)) {
      continue;
    }
    $articleId = trim((string) ($row['article_id'] ?? $key));
    if ($articleId === '') {
      continue;
    }
    $status = normalize_article_review_status((string) ($row['status'] ?? 'unreviewed'));

    $editedAt = trim((string) ($row['edited_at'] ?? ''));
    $updatedAt = trim((string) ($row['updated_at'] ?? ''));
    if ($updatedAt === '') {
      $updatedAt = date('c');
    }
    if (($status === 'edited' || $status === 'draft_saved') && $editedAt === '') {
      $editedAt = $updatedAt;
    }

    $normalizedItems[$articleId] = [
      'article_id' => $articleId,
      'status' => $status,
      'edited_at' => $editedAt,
      'edited_by' => [
        'user_id' => (string) (($row['edited_by']['user_id'] ?? '') ?: ''),
        'username' => (string) (($row['edited_by']['username'] ?? '') ?: ''),
        'display_name' => (string) (($row['edited_by']['display_name'] ?? '') ?: ''),
        'role' => (string) (($row['edited_by']['role'] ?? '') ?: ''),
      ],
      'updated_at' => $updatedAt,
      'source' => (string) ($row['source'] ?? ''),
    ];
  }
  $decoded['items'] = $normalizedItems;

  if (!isset($decoded['meta']['created_at'])) {
    $decoded['meta']['created_at'] = date('c');
  }
  if (!isset($decoded['meta']['updated_at'])) {
    $decoded['meta']['updated_at'] = date('c');
  }
  $decoded['meta']['edited_count'] = count(array_filter($normalizedItems, static function (array $row): bool {
    return (string) ($row['status'] ?? '') === 'edited';
  }));
  $decoded['meta']['draft_saved_count'] = count(array_filter($normalizedItems, static function (array $row): bool {
    return (string) ($row['status'] ?? '') === 'draft_saved';
  }));

  return $decoded;
}

/**
 * Persist review status payload.
 *
 * @param array<string,mixed> $payload
 */
function write_review_status_payload(array $payload): void
{
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = [];
  }
  if (!isset($payload['items']) || !is_array($payload['items'])) {
    $payload['items'] = [];
  }

  $normalizedItems = [];
  foreach ($payload['items'] as $key => $row) {
    if (!is_array($row)) {
      continue;
    }
    $articleId = trim((string) ($row['article_id'] ?? $key));
    if ($articleId === '') {
      continue;
    }
    $status = normalize_article_review_status((string) ($row['status'] ?? 'unreviewed'));
    $editedAt = trim((string) ($row['edited_at'] ?? ''));
    $updatedAt = trim((string) ($row['updated_at'] ?? ''));
    if ($updatedAt === '') {
      $updatedAt = date('c');
    }
    if (($status === 'edited' || $status === 'draft_saved') && $editedAt === '') {
      $editedAt = $updatedAt;
    }

    $normalizedItems[$articleId] = [
      'article_id' => $articleId,
      'status' => $status,
      'edited_at' => $editedAt,
      'edited_by' => [
        'user_id' => (string) (($row['edited_by']['user_id'] ?? '') ?: ''),
        'username' => (string) (($row['edited_by']['username'] ?? '') ?: ''),
        'display_name' => (string) (($row['edited_by']['display_name'] ?? '') ?: ''),
        'role' => (string) (($row['edited_by']['role'] ?? '') ?: ''),
      ],
      'updated_at' => $updatedAt,
      'source' => (string) ($row['source'] ?? ''),
    ];
  }

  if (!isset($payload['meta']['created_at'])) {
    $payload['meta']['created_at'] = date('c');
  }
  $payload['meta']['updated_at'] = date('c');
  $payload['meta']['edited_count'] = count(array_filter($normalizedItems, static function (array $row): bool {
    return (string) ($row['status'] ?? '') === 'edited';
  }));
  $payload['meta']['draft_saved_count'] = count(array_filter($normalizedItems, static function (array $row): bool {
    return (string) ($row['status'] ?? '') === 'draft_saved';
  }));
  $payload['items'] = $normalizedItems;

  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode review status payload.');
  }
  file_put_contents(ADMIN_REVIEW_STATUS_PATH, $json . PHP_EOL);
}

/**
 * Get review status map by article id.
 *
 * @return array<string,array<string,mixed>>
 */
function review_status_map(): array
{
  $payload = read_review_status_payload();
  $rows = $payload['items'] ?? [];
  return is_array($rows) ? $rows : [];
}

/**
 * Read review status row for one article.
 *
 * @return array<string,mixed>|null
 */
function read_article_review_status(string $articleId): ?array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return null;
  }
  $rows = review_status_map();
  $row = $rows[$articleId] ?? null;
  return is_array($row) ? $row : null;
}

/**
 * Mark one article as edited by current operator.
 *
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function mark_article_reviewed(string $articleId, ?array $actor = null, string $source = 'save_draft'): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    throw new InvalidArgumentException('Article id is required for review status.');
  }

  $status = review_status_from_source($source);
  $payload = read_review_status_payload();
  $now = date('c');
  $row = [
    'article_id' => $articleId,
    'status' => $status,
    'edited_at' => $now,
    'edited_by' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'updated_at' => $now,
    'source' => $source,
  ];
  $payload['items'][$articleId] = $row;
  write_review_status_payload($payload);

  append_audit_log([
    'event' => 'article.review.marked',
    'article_id' => $articleId,
    'status' => $status,
    'source' => $source,
    'username' => (string) ($row['edited_by']['username'] ?? ''),
    'role' => (string) ($row['edited_by']['role'] ?? ''),
  ]);

  return $row;
}

/**
 * Normalize status from raw value into canonical enum.
 */
function normalize_article_review_status(string $status): string
{
  $status = trim(strtolower($status));
  if ($status === 'edited' || $status === 'draft_saved' || $status === 'unreviewed') {
    return $status;
  }
  return 'unreviewed';
}

/**
 * Map action source => review status.
 */
function review_status_from_source(string $source): string
{
  $source = trim(strtolower($source));
  if ($source === 'publish_now') {
    return 'edited';
  }
  if ($source === 'save_draft' || $source === 'preview_only') {
    return 'draft_saved';
  }
  return 'edited';
}

/**
 * Mark one article back to unreviewed.
 *
 * @param array<string,mixed>|null $actor
 */
function mark_article_unreviewed(string $articleId, ?array $actor = null, string $reason = 'manual_reset'): bool
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return false;
  }

  $payload = read_review_status_payload();
  if (!isset($payload['items'][$articleId])) {
    return false;
  }

  unset($payload['items'][$articleId]);
  write_review_status_payload($payload);

  append_audit_log([
    'event' => 'article.review.marked_unreviewed',
    'article_id' => $articleId,
    'reason' => $reason,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
  ]);
  return true;
}

/**
 * Purge review status row without audit log (used in destructive full-delete flow).
 */
function purge_article_review_status_silent(string $articleId): bool
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return false;
  }

  $payload = read_review_status_payload();
  if (!isset($payload['items'][$articleId])) {
    return false;
  }

  unset($payload['items'][$articleId]);
  write_review_status_payload($payload);
  return true;
}
