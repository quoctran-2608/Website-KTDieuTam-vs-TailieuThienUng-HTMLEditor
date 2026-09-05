<?php
declare(strict_types=1);

require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/revision.php';
require_once __DIR__ . '/public_rebuild.php';

/**
 * Resolve whether the current publication is safe to hand off externally.
 *
 * @return array<string,mixed>
 */
function editorial_publication_handoff_status(string $articleId, ?array $knownState = null): array
{
    $article = editorial_find_article($articleId);
    $state = $knownState ?? editorial_get_article_state($articleId);
    $fail = static function (string $code, string $message): array {
        return ['ok' => false, 'eligible' => false, 'reason_code' => $code, 'message' => $message];
    };
    if ($article === null || $state === null) {
        return $fail('never_published', 'Cần Publish hoàn tất trước khi bàn giao.');
    }
    if (in_array((string) ($state['status'] ?? ''), ['ready_review', 'approved'], true)) {
        return $fail('publish_required', 'Cần Publish hoàn tất trước khi bàn giao.');
    }
    $revisionId = trim((string) ($state['published_revision_id'] ?? ''));
    if ($revisionId === '') {
        return $fail('never_published', 'Cần Publish hoàn tất trước khi bàn giao.');
    }
    $revision = editorial_get_revision($revisionId);
    if ($revision === null
        || (string) ($revision['article_id'] ?? '') !== $articleId
        || (string) ($revision['revision_type'] ?? '') !== 'published') {
        return $fail('invalid_published_revision', 'Cần Publish hoàn tất trước khi bàn giao.');
    }
    $snapshot = editorial_get_verified_revision_snapshot($revision);
    if (empty($snapshot['ok'])) {
        return $fail('invalid_published_revision', 'Không thể xác thực bản Publish để bàn giao.');
    }
    $publishedLiveHash = trim((string) ($state['published_live_hash'] ?? ''));
    if ($publishedLiveHash === '') {
        return $fail('published_live_hash_missing', 'Cần Publish hoàn tất trước khi bàn giao.');
    }
    $path = editorial_resolve_article_path($article);
    $liveHtml = $path !== null ? @file_get_contents($path) : false;
    if ($liveHtml === false || !hash_equals($publishedLiveHash, hash('sha256', $liveHtml))) {
        return $fail('live_hash_mismatch', 'Bản website hiện tại không khớp lần Publish gần nhất.');
    }
    $marker = editorial_public_ready_read_marker($articleId);
    if ($marker === null
        || !hash_equals($revisionId, (string) ($marker['published_revision_id'] ?? ''))
        || !hash_equals($publishedLiveHash, (string) ($marker['published_live_hash'] ?? ''))) {
        return $fail(
            'public_rebuild_pending',
            'Publish chưa hoàn tất dữ liệu public. Cần rebuild thành công trước khi bàn giao.'
        );
    }
    $ownerId = trim((string) ($state['assigned_user_id'] ?? ''));
    if ($ownerId !== '') {
        $draft = editorial_get_draft($articleId, $ownerId);
        if ($draft !== null) {
            try {
                $draftHash = editorial_revision_content_hash((array) ($draft['payload'] ?? []));
            } catch (\Throwable $error) {
                return $fail('draft_changed_after_publish', 'Có thay đổi sau lần Publish gần nhất. Hãy Publish lại trước khi bàn giao.');
            }
            if (!hash_equals((string) ($revision['content_hash'] ?? ''), $draftHash)) {
                return $fail('draft_changed_after_publish', 'Có thay đổi sau lần Publish gần nhất. Hãy Publish lại trước khi bàn giao.');
            }
        }
    }
    return [
        'ok' => true,
        'eligible' => true,
        'reason_code' => 'ready',
        'message' => 'Bản Publish hiện tại đã sẵn sàng để bàn giao Drive + Sheet.',
        'article' => $article,
        'state' => $state,
        'published_revision' => $revision,
        'published_snapshot' => (array) ($snapshot['payload'] ?? []),
        'public_ready_marker' => $marker,
    ];
}
