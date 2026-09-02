<?php
/**
 * Workspace / Editing Lock / Draft Service Module
 * Editorial V2 — Phase 4 + Phase 5 corrective fixes
 *
 * Parser functions adapted from admin/includes/article_parser.php.
 * Lock/draft business logic for multi-user editing safety.
 *
 * Phase 5 fixes:
 * - A1: Taxonomy preserved server-side, not from POST
 * - A2: Strict base64 decode
 * - A3: Release lock requires token
 * - A4: Lock validation inside draft save transaction
 * - A5: JSON encode failure handled
 * - A6: Heartbeat rowCount check
 */
declare(strict_types=1);

if (!defined('EDITORIAL_ARTICLE_LOCK_TTL')) {
    define('EDITORIAL_ARTICLE_LOCK_TTL', 900); // 15 minutes
}

// ─── Parser (adapted from admin/includes/article_parser.php) ────

function editorial_extract_prose_region(string $html): array
{
  if (!preg_match('/<div\b[^>]*class=(["\'])(?:(?!\1).)*\barticle-prose\b(?:(?!\1).)*\1[^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE)) {
    return ['ok' => false, 'code' => 'missing_prose_region', 'message' => 'Không tìm thấy khối .article-prose.'];
  }
  $openTag = (string) $match[0][0];
  $openOffset = (int) $match[0][1];
  $openEnd = $openOffset + strlen($openTag);
  $closeOffset = editorial_find_matching_div_close($html, $openOffset);
  if ($closeOffset === null) {
    return ['ok' => false, 'code' => 'unbalanced_prose_div', 'message' => 'Không xác định được thẻ đóng của .article-prose.'];
  }
  $closeTag = '</div>';
  $inner = substr($html, $openEnd, $closeOffset - $openEnd);
  if ($inner === false) $inner = '';
  return ['ok' => true, 'start' => $openOffset, 'open_tag_end' => $openEnd, 'close_tag_start' => $closeOffset, 'end' => $closeOffset + strlen($closeTag), 'open_tag' => $openTag, 'close_tag' => $closeTag, 'inner' => $inner, 'inner_length' => strlen($inner)];
}

function editorial_find_matching_div_close(string $html, int $openOffset): ?int
{
  $tail = substr($html, $openOffset);
  if ($tail === false || $tail === '') return null;
  if (!preg_match_all('/<\/?div\b[^>]*>/i', $tail, $tokens, PREG_OFFSET_CAPTURE)) return null;
  $depth = 0; $started = false;
  foreach ($tokens[0] as $entry) {
    $token = strtolower((string) $entry[0]);
    $offset = $openOffset + (int) $entry[1];
    $isClose = str_starts_with($token, '</div');
    if (!$started) { if ($offset !== $openOffset) continue; $started = true; $depth = 1; continue; }
    if ($isClose) { $depth--; if ($depth === 0) return $offset; continue; }
    $depth++;
  }
  return null;
}

function editorial_extract_meta_region(string $html): array
{
  if (!preg_match('/<script\b[^>]*id=(["\'])article-meta\1[^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE)) {
    return ['ok' => false, 'code' => 'missing_article_meta_script', 'message' => 'Không tìm thấy script#article-meta.'];
  }
  $openTag = (string) $match[0][0];
  $openOffset = (int) $match[0][1];
  $openEnd = $openOffset + strlen($openTag);
  $closeOffset = stripos($html, '</script>', $openEnd);
  if ($closeOffset === false) {
    return ['ok' => false, 'code' => 'missing_article_meta_close', 'message' => 'Không tìm thấy thẻ đóng của script#article-meta.'];
  }
  $inner = substr($html, $openEnd, $closeOffset - $openEnd);
  if ($inner === false) $inner = '';
  return ['ok' => true, 'start' => $openOffset, 'open_tag_end' => $openEnd, 'close_tag_start' => $closeOffset, 'end' => $closeOffset + strlen('</script>'), 'open_tag' => $openTag, 'close_tag' => '</script>', 'inner' => trim($inner), 'inner_length' => strlen(trim($inner))];
}

function editorial_extract_summary_text(string $html): string
{
  if (!preg_match('/<p\b[^>]*class=(["\'])(?:(?!\1).)*\barticle-summary\b(?:(?!\1).)*\1[^>]*>(.*?)<\/p>/is', $html, $match)) return '';
  $inner = trim((string) ($match[2] ?? ''));
  $plain = trim(strip_tags($inner));
  $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim((string) preg_replace('/\s+/', ' ', $plain));
}

/**
 * Parse article HTML from string.
 * A2 fix: accepts HTML string directly so caller controls exact bytes.
 */
function editorial_parse_article_html(string $html, string $path = ''): array
{
  if (trim($html) === '') return ['ok' => false, 'code' => 'empty_html', 'message' => 'Nội dung HTML rỗng.', 'path' => $path];
  $proseRegion = editorial_extract_prose_region($html);
  if (!$proseRegion['ok']) return ['ok' => false, 'code' => $proseRegion['code'], 'message' => $proseRegion['message'], 'path' => $path];
  $metaRegion = editorial_extract_meta_region($html);
  if (!$metaRegion['ok']) return ['ok' => false, 'code' => $metaRegion['code'], 'message' => $metaRegion['message'], 'path' => $path];
  $metaDecoded = json_decode((string) $metaRegion['inner'], true);
  if (!is_array($metaDecoded)) return ['ok' => false, 'code' => 'invalid_article_meta_json', 'message' => 'JSON trong script#article-meta không hợp lệ.', 'path' => $path];
  return ['ok' => true, 'code' => 'ok', 'message' => 'Parse thành công.', 'path' => $path, 'html' => $html, 'prose' => $proseRegion, 'meta' => $metaRegion, 'meta_payload' => $metaDecoded, 'summary_text' => editorial_extract_summary_text($html)];
}

/**
 * Parse article from file path (convenience wrapper).
 * Reads file then delegates to editorial_parse_article_html().
 */
function editorial_parse_article_file(string $path): array
{
  if ($path === '' || !file_exists($path)) return ['ok' => false, 'code' => 'missing_file', 'message' => 'Không tìm thấy file bài viết.', 'path' => $path];
  $html = file_get_contents($path);
  if ($html === false || trim($html) === '') return ['ok' => false, 'code' => 'empty_file', 'message' => 'File rỗng hoặc không đọc được.', 'path' => $path];
  return editorial_parse_article_html($html, $path);
}

// ─── Editing Lock ───────────────────────────────────────────────

/**
 * Acquire or reuse editing lock for article.
 * Uses editorial_transaction() with BEGIN IMMEDIATE.
 */
function editorial_acquire_article_lock(string $articleId, string $userId): array
{
    return editorial_transaction(function () use ($articleId, $userId): array {
        $db = editorial_db();
        $now = date('c');
        $expiresAt = date('c', time() + EDITORIAL_ARTICLE_LOCK_TTL);

        // Check assignment
        $state = editorial_get_article_state($articleId);
        if (!$state) {
            return ['ok' => false, 'message' => 'Không tìm thấy trạng thái bài viết.'];
        }
        if ((string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không được giao xử lý bài viết này.'];
        }
        if (!in_array((string) $state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái cho phép chỉnh sửa.'];
        }

        // Check existing lock
        $stmt = $db->prepare('SELECT * FROM editorial_locks WHERE article_id = :aid');
        $stmt->execute(['aid' => $articleId]);
        $lock = $stmt->fetch();

        if ($lock) {
            $lockExpiry = strtotime((string) $lock['expires_at']);
            if ($lockExpiry !== false && $lockExpiry < time()) {
                $db->prepare('DELETE FROM editorial_locks WHERE article_id = :aid')->execute(['aid' => $articleId]);
                $lock = null;
            } elseif ((string) $lock['user_id'] !== $userId) {
                $otherUser = editorial_find_user_by_id((string) $lock['user_id']);
                $name = $otherUser ? (string) $otherUser['display_name'] : 'người khác';
                return ['ok' => false, 'message' => 'Bài viết đang được chỉnh sửa bởi ' . $name . '.'];
            } else {
                // Same user — reuse, extend TTL
                $db->prepare('UPDATE editorial_locks SET heartbeat_at = :hb, expires_at = :exp WHERE article_id = :aid')
                    ->execute(['hb' => $now, 'exp' => $expiresAt, 'aid' => $articleId]);
                return ['ok' => true, 'lock_token' => (string) $lock['lock_token'], 'expires_at' => $expiresAt, 'message' => 'Tiếp tục phiên chỉnh sửa.'];
            }
        }

        // Create new lock
        $token = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO editorial_locks (article_id, user_id, lock_token, acquired_at, heartbeat_at, expires_at) VALUES (:aid, :uid, :token, :acq, :hb, :exp)')
            ->execute(['aid' => $articleId, 'uid' => $userId, 'token' => $token, 'acq' => $now, 'hb' => $now, 'exp' => $expiresAt]);

        editorial_log_activity('article.lock.acquired', $articleId, $userId);

        return ['ok' => true, 'lock_token' => $token, 'expires_at' => $expiresAt, 'message' => 'Bắt đầu phiên chỉnh sửa.'];
    });
}

/**
 * Get current lock for article.
 */
function editorial_get_article_lock(string $articleId): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_locks WHERE article_id = :aid');
    $stmt->execute(['aid' => $articleId]);
    $lock = $stmt->fetch();
    return $lock ?: null;
}

/**
 * Validate a lock token (for UI pre-checks, NOT final authority for writes).
 */
function editorial_validate_article_lock(string $articleId, string $userId, string $lockToken): array
{
    $lock = editorial_get_article_lock($articleId);
    if (!$lock) {
        return ['ok' => false, 'code' => 'no_lock', 'message' => 'Không có phiên chỉnh sửa nào đang hoạt động.'];
    }
    if ((string) $lock['user_id'] !== $userId) {
        return ['ok' => false, 'code' => 'wrong_user', 'message' => 'Phiên chỉnh sửa thuộc về người dùng khác.'];
    }
    if ((string) $lock['lock_token'] !== $lockToken) {
        return ['ok' => false, 'code' => 'invalid_token', 'message' => 'Token phiên chỉnh sửa không hợp lệ.'];
    }
    $expiry = strtotime((string) $lock['expires_at']);
    if ($expiry !== false && $expiry < time()) {
        return ['ok' => false, 'code' => 'lock_expired', 'message' => 'Phiên chỉnh sửa đã hết hạn. Vui lòng tải lại workspace để tiếp tục.'];
    }
    return ['ok' => true, 'code' => 'ok', 'message' => 'Phiên chỉnh sửa hợp lệ.'];
}

/**
 * Extend lock TTL (heartbeat).
 * FIX A6: check rowCount after UPDATE.
 */
function editorial_heartbeat_article_lock(string $articleId, string $userId, string $lockToken): array
{
    $val = editorial_validate_article_lock($articleId, $userId, $lockToken);
    if (!$val['ok']) return $val;

    $db = editorial_db();
    $now = date('c');
    $expiresAt = date('c', time() + EDITORIAL_ARTICLE_LOCK_TTL);

    $stmt = $db->prepare('UPDATE editorial_locks SET heartbeat_at = :hb, expires_at = :exp WHERE article_id = :aid AND user_id = :uid AND lock_token = :token');
    $stmt->execute(['hb' => $now, 'exp' => $expiresAt, 'aid' => $articleId, 'uid' => $userId, 'token' => $lockToken]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'code' => 'lock_gone', 'message' => 'Phiên chỉnh sửa không còn hợp lệ.'];
    }

    return ['ok' => true, 'expires_at' => $expiresAt];
}

/**
 * Release editing lock. Does NOT release assignment or delete draft.
 * FIX A3: requires lock_token. DELETE must match token to prevent
 * stale tab from deleting a newer tab's lock.
 */
function editorial_release_article_lock(string $articleId, string $userId, string $lockToken): void
{
    $db = editorial_db();
    $stmt = $db->prepare('DELETE FROM editorial_locks WHERE article_id = :aid AND user_id = :uid AND lock_token = :token');
    $stmt->execute(['aid' => $articleId, 'uid' => $userId, 'token' => $lockToken]);

    if ($stmt->rowCount() > 0) {
        editorial_log_activity('article.lock.released', $articleId, $userId);
    }
    // If token didn't match (stale tab), silently ignore — not an error.
}

// ─── Draft ──────────────────────────────────────────────────────

/**
 * Get draft for article + user.
 * Returns row with decoded payload, or null.
 */
function editorial_get_draft(string $articleId, string $userId): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid');
    $stmt->execute(['aid' => $articleId, 'uid' => $userId]);
    $draft = $stmt->fetch();

    if ($draft) {
        $draft['payload'] = json_decode((string) ($draft['payload_json'] ?? '{}'), true) ?: [];
        return $draft;
    }
    return null;
}

/**
 * Save draft with optimistic concurrency (version check).
 *
 * FIX A4: ALL authorization (assignment, status, lock) checked INSIDE transaction.
 * FIX A5: JSON encode failure blocks save.
 *
 * @return array{ok: bool, version?: int, message: string}
 */
function editorial_save_draft(string $articleId, string $userId, array $payload, string $baseLiveHash, int $expectedVersion, string $lockToken): array
{
    // FIX A5: Pre-validate JSON encode
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        return ['ok' => false, 'code' => 'json_error', 'message' => 'Không thể mã hóa dữ liệu bản nháp. Bản nháp chưa được lưu.'];
    }

    // FIX A4: All authorization inside transaction
    return editorial_transaction(function () use ($articleId, $userId, $payloadJson, $baseLiveHash, $expectedVersion, $lockToken): array {
        $db = editorial_db();
        $now = date('c');

        // Step 1-3: Verify assignment + status
        $state = editorial_get_article_state($articleId);
        if (!$state || (string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'code' => 'not_owner', 'message' => 'Bạn không phải người phụ trách bài viết này.'];
        }
        if (!in_array((string) $state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'code' => 'invalid_status', 'message' => 'Bài viết không ở trạng thái cho phép chỉnh sửa.'];
        }

        // Step 4-5: Verify lock inside transaction (authoritative check)
        $lockStmt = $db->prepare('SELECT * FROM editorial_locks WHERE article_id = :aid');
        $lockStmt->execute(['aid' => $articleId]);
        $lock = $lockStmt->fetch();

        if (!$lock) {
            return ['ok' => false, 'code' => 'no_lock', 'message' => 'Không có phiên chỉnh sửa nào đang hoạt động.'];
        }
        if ((string) $lock['user_id'] !== $userId) {
            return ['ok' => false, 'code' => 'wrong_user', 'message' => 'Phiên chỉnh sửa thuộc về người dùng khác.'];
        }
        if ((string) $lock['lock_token'] !== $lockToken) {
            return ['ok' => false, 'code' => 'invalid_token', 'message' => 'Token phiên chỉnh sửa không hợp lệ.'];
        }
        $expiry = strtotime((string) $lock['expires_at']);
        if ($expiry !== false && $expiry < time()) {
            return ['ok' => false, 'code' => 'lock_expired', 'message' => 'Phiên chỉnh sửa đã hết hạn. Vui lòng tải lại workspace để tiếp tục.'];
        }

        // Step 6-7: Version check + save
        if ($expectedVersion === 0) {
            $stmt = $db->prepare('SELECT version FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid');
            $stmt->execute(['aid' => $articleId, 'uid' => $userId]);
            if ($stmt->fetch()) {
                return ['ok' => false, 'code' => 'conflict', 'message' => 'Bản nháp đã được thay đổi ở một phiên/tab khác. Vui lòng tải lại và đối chiếu trước khi lưu tiếp.'];
            }

            $db->prepare('INSERT INTO editorial_drafts (article_id, user_id, payload_json, base_live_hash, updated_at, version) VALUES (:aid, :uid, :payload, :hash, :now, 1)')
                ->execute(['aid' => $articleId, 'uid' => $userId, 'payload' => $payloadJson, 'hash' => $baseLiveHash, 'now' => $now]);
            $newVersion = 1;
        } else {
            $stmt = $db->prepare('UPDATE editorial_drafts SET payload_json = :payload, updated_at = :now, version = version + 1 WHERE article_id = :aid AND user_id = :uid AND version = :ver');
            $stmt->execute(['payload' => $payloadJson, 'now' => $now, 'aid' => $articleId, 'uid' => $userId, 'ver' => $expectedVersion]);

            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'code' => 'conflict', 'message' => 'Bản nháp đã được thay đổi ở một phiên/tab khác. Vui lòng tải lại và đối chiếu trước khi lưu tiếp.'];
            }
            $newVersion = $expectedVersion + 1;
        }

        // Step 8: Activity
        editorial_log_activity('article.draft.saved', $articleId, $userId, json_encode(['draft_version' => $newVersion]));

        return ['ok' => true, 'version' => $newVersion, 'message' => 'Lưu bản nháp thành công (v' . $newVersion . ').'];
    });
}

// ─── Initial payload builder ────────────────────────────────────

/**
 * Build initial draft payload from parsed HTML.
 * FIX A1: includes all taxonomy fields from catalog for preservation.
 */
function editorial_build_initial_payload(array $parsed, array $article, array $articleMeta): array
{
    $tags = $articleMeta['tags'] ?? ($article['tags'] ?? []);
    $tagsText = is_array($tags) ? implode(', ', $tags) : (string) $tags;

    return [
        'title' => (string) ($articleMeta['title'] ?? ($article['title'] ?? '')),
        'excerpt' => (string) ($parsed['summary_text'] ?? ''),
        'prose_html' => (string) ($parsed['prose']['inner'] ?? ''),
        'publish_date' => (string) ($articleMeta['publishDate'] ?? ''),
        'modified_date' => (string) ($articleMeta['modifiedDate'] ?? ''),
        'featured_image' => (string) ($articleMeta['image'] ?? ''),
        'tags' => $tags,
        'tags_text' => $tagsText,
        // Taxonomy (read-only, preserved server-side)
        'section_key' => (string) ($article['section'] ?? ''),
        'section_label' => (string) ($article['section_label'] ?? ''),
        'library_kind_key' => (string) ($article['library_kind_key'] ?? ''),
        'library_kind_label' => (string) ($article['library_kind_label'] ?? ''),
        'topic_lv1_key' => (string) ($article['topic_lv1_key'] ?? ''),
        'topic_lv1_label' => (string) ($article['topic_lv1_label'] ?? ''),
        'topic_lv2_key' => (string) ($article['topic_lv2_key'] ?? ''),
        'topic_lv2_label' => (string) ($article['topic_lv2_label'] ?? ''),
        'topic_lv3_key' => (string) ($article['topic_lv3_key'] ?? ''),
        'topic_lv3_label' => (string) ($article['topic_lv3_label'] ?? ''),
    ];
}

/**
 * Build full draft payload merging editable POST data with canonical taxonomy.
 * A7 fix: article catalog is authority for taxonomy. Draft fallback only if catalog empty.
 */
function editorial_merge_draft_payload(array $editablePost, array $article, ?array $existingDraftPayload = null): array
{
    // Editable fields from POST
    $payload = [
        'title' => (string) ($editablePost['title'] ?? ''),
        'excerpt' => (string) ($editablePost['excerpt'] ?? ''),
        'prose_html' => (string) ($editablePost['prose_html'] ?? ''),
        'publish_date' => (string) ($editablePost['publish_date'] ?? ''),
        'modified_date' => (string) ($editablePost['modified_date'] ?? ''),
        'featured_image' => (string) ($editablePost['featured_image'] ?? ''),
        'tags_text' => (string) ($editablePost['tags_text'] ?? ''),
    ];

    // A7: Taxonomy — catalog is canonical authority, draft is fallback only if catalog empty
    $taxSource = $existingDraftPayload ?? [];
    $taxFields = [
        ['section_key', 'section'],
        ['section_label', 'section_label'],
        ['library_kind_key', 'library_kind_key'],
        ['library_kind_label', 'library_kind_label'],
        ['topic_lv1_key', 'topic_lv1_key'],
        ['topic_lv1_label', 'topic_lv1_label'],
        ['topic_lv2_key', 'topic_lv2_key'],
        ['topic_lv2_label', 'topic_lv2_label'],
        ['topic_lv3_key', 'topic_lv3_key'],
        ['topic_lv3_label', 'topic_lv3_label'],
    ];
    foreach ($taxFields as [$payloadKey, $catalogKey]) {
        $catalogVal = (string) ($article[$catalogKey] ?? '');
        $draftVal = (string) ($taxSource[$payloadKey] ?? '');
        $payload[$payloadKey] = $catalogVal !== '' ? $catalogVal : $draftVal;
    }

    return $payload;
}
