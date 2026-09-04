<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$currentUserId = (string) $currentUser['user_id'];
$isAdmin = (($currentUser['role'] ?? '') === 'admin');

$articleId = trim((string) ($_GET['id'] ?? ''));
$fromId = trim((string) ($_GET['from'] ?? ''));
$toId = trim((string) ($_GET['to'] ?? ''));
$autoCompare = ((string) ($_GET['auto_compare'] ?? '') === '1');

// ─── Validate params ─────────────────────────────────────────────

if ($articleId === '' || $fromId === '' || $toId === '') {
    editorial_flash_set('warning', 'Thiếu tham số so sánh.');
    editorial_redirect(editorial_url('my-work.php'));
}

$article = editorial_find_article($articleId);
if ($article === null) {
    editorial_flash_set('danger', 'Không tìm thấy bài viết.');
    editorial_redirect(editorial_url('my-work.php'));
}

// ─── Authorization ───────────────────────────────────────────────

if (!$isAdmin) {
    $state = editorial_get_article_state($articleId);
    if ($state === null || (string) ($state['assigned_user_id'] ?? '') !== $currentUserId) {
        editorial_flash_set('warning', 'Bạn không có quyền xem so sánh phiên bản của bài viết này.');
        editorial_redirect(editorial_url('articles.php'));
    }
}

// ─── Load revisions ─────────────────────────────────────────────

$fromRev = editorial_get_revision($fromId);
$toRev = editorial_get_revision($toId);

if (!$fromRev || !$toRev) {
    editorial_flash_set('warning', 'Không tìm thấy phiên bản để so sánh.');
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

// Verify both belong to this article
if ((string) $fromRev['article_id'] !== $articleId || (string) $toRev['article_id'] !== $articleId) {
    editorial_flash_set('warning', 'Phiên bản không thuộc bài viết này.');
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

// ─── Load snapshots ──────────────────────────────────────────────

// A4: Use verified snapshot helper
$fromVerified = editorial_get_verified_revision_snapshot($fromRev);
$toVerified = editorial_get_verified_revision_snapshot($toRev);

if (!$fromVerified['ok'] || !$toVerified['ok']) {
    $failMsg = !$fromVerified['ok'] ? $fromVerified['message'] : $toVerified['message'];
    editorial_flash_set('danger', $failMsg);
    editorial_redirect(editorial_url('revisions.php?id=' . urlencode($articleId)));
}

$fromPayload = $fromVerified['payload'];
$toPayload = $toVerified['payload'];

// Compare is opened only after Workspace saved the current draft and stage.
// Derive sync metadata from server-side rows/snapshots; never trust query input
// for lock state, payload, or version.
$openerSync = null;
if ($autoCompare
    && in_array((string) ($toRev['milestone_key'] ?? ''), ['stage1', 'stage2'], true)) {
    $draft = editorial_get_draft($articleId, $currentUserId);
    if ($draft !== null) {
        try {
            $draftHash = editorial_revision_content_hash($draft['payload']);
        } catch (RuntimeException $e) {
            $draftHash = '';
        }
        if ($draftHash !== '' && hash_equals($draftHash, (string) ($toRev['content_hash'] ?? ''))) {
            $openerSync = [
                'articleId' => $articleId,
                'draftVersion' => (int) ($draft['version'] ?? 0),
                'draftHash' => $draftHash,
                'stage' => (string) $toRev['milestone_key'],
                'revisionId' => (string) $toRev['id'],
                'revisionNo' => (int) ($toRev['revision_no'] ?? 0),
            ];
        }
    }
}

// Live HTML is presentation context only; compared prose remains immutable snapshots.
$articlePath = editorial_resolve_article_path($article);
$liveArticleHtml = $articlePath ? file_get_contents($articlePath) : false;
if ($liveArticleHtml === false) {
    $liveArticleHtml = '';
}
$siteBaseUrl = editorial_site_url('');
$fromPreview = editorial_build_public_article_preview_document(
    $liveArticleHtml,
    (string) ($fromPayload['prose_html'] ?? ''),
    $siteBaseUrl
);
$toPreview = editorial_build_public_article_preview_document(
    $liveArticleHtml,
    (string) ($toPayload['prose_html'] ?? ''),
    $siteBaseUrl
);

// ─── Standalone visual comparison ─────────────────────────────────
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>So sánh phiên bản | <?= editorial_h($article['title']) ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { background: #edf2f7; color: #172038; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .compare-standalone { display: grid; grid-template-rows: auto minmax(0, 1fr); width: 100vw; height: 100vh; }
        .compare-standalone-note { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 42px; padding: 8px 14px; background: #172038; color: #dbe7f2; font-size: 0.78rem; }
        .compare-standalone-note strong { color: #fff; }
        .compare-standalone-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); min-height: 0; }
        .compare-pane { display: grid; grid-template-rows: auto minmax(0, 1fr); min-width: 0; min-height: 0; background: #fff; border-right: 1px solid #cbd5e1; }
        .compare-pane:last-child { border-right: 0; }
        .compare-pane header { position: sticky; top: 0; z-index: 1; padding: 11px 14px; border-bottom: 1px solid #dbe4ee; background: #f8fbff; }
        .compare-pane h1 { margin: 0; color: #1e3a5f; font-size: 0.92rem; line-height: 1.25; }
        .compare-pane p { margin: 3px 0 0; color: #64748b; font-size: 0.75rem; }
        .compare-pane iframe { display: block; width: 100%; height: 100%; min-height: 0; border: 0; background: #fff; }
        @media (max-width: 820px) {
            html, body { overflow: auto; }
            .compare-standalone { height: auto; min-height: 100vh; }
            .compare-standalone-grid { grid-template-columns: 1fr; }
            .compare-pane { min-height: 72vh; border-right: 0; border-bottom: 1px solid #cbd5e1; }
            .compare-pane:last-child { border-bottom: 0; }
        }
    </style>
</head>
<body>
    <main class="compare-standalone">
        <div class="compare-standalone-note">
            <span><strong>So sánh trực quan</strong> · Đóng tab để quay lại Workspace.</span>
            <span><?= editorial_h($article['title']) ?></span>
        </div>
        <div class="compare-standalone-grid">
            <section class="compare-pane">
                <header>
                    <h1><?= editorial_h(editorial_revision_label($fromRev)) ?></h1>
                    <p>Revision #<?= editorial_h((string) $fromRev['revision_no']) ?></p>
                </header>
                <iframe sandbox="" srcdoc="<?= editorial_h($fromPreview) ?>" title="<?= editorial_h(editorial_revision_label($fromRev)) ?>"></iframe>
            </section>
            <section class="compare-pane">
                <header>
                    <h1><?= editorial_h(editorial_revision_label($toRev)) ?></h1>
                    <p>Revision #<?= editorial_h((string) $toRev['revision_no']) ?></p>
                </header>
                <iframe sandbox="" srcdoc="<?= editorial_h($toPreview) ?>" title="<?= editorial_h(editorial_revision_label($toRev)) ?>"></iframe>
            </section>
        </div>
    </main>
    <?php if ($openerSync !== null): ?>
        <script>
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage(
                    <?= json_encode(
                        ['type' => 'editorial-stage-saved'] + $openerSync,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ) ?>,
                    window.location.origin
                );
            }
        </script>
    <?php endif; ?>
</body>
</html>
