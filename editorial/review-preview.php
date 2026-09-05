<?php
declare(strict_types=1);

/**
 * Editorial V2 — standalone immutable review/approved preview.
 *
 * The browser supplies only an article ID. Server-side state resolves the
 * authoritative revision, then verifies its snapshot before rendering.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/review.php';

editorial_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method Not Allowed');
}

$currentUser = editorial_current_user();
$currentUserId = (string) ($currentUser['user_id'] ?? '');
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$articleId = trim((string) ($_GET['id'] ?? ''));

$renderError = static function (int $status, string $message): never {
    http_response_code($status);
    ?>
    <!doctype html>
    <html lang="vi">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Không thể xem bản lưu</title></head>
    <body style="font-family:system-ui,sans-serif;max-width:680px;margin:72px auto;padding:24px;color:#172038;">
        <h1>Không thể xem bản lưu</h1>
        <p><?= editorial_h($message) ?></p>
        <p><a href="<?= editorial_h(editorial_url('articles.php')) ?>">Quay lại danh sách bài viết</a></p>
    </body>
    </html>
    <?php
    exit;
};

if ($articleId === '') {
    $renderError(400, 'Thiếu Article ID.');
}

$article = editorial_find_article($articleId);
$state = $article !== null ? editorial_get_article_state($articleId) : null;
if ($article === null || $state === null) {
    $renderError(404, 'Không tìm thấy bài viết hoặc hồ sơ duyệt.');
}

if (!$isAdmin) {
    $isOwner = (string) ($state['assigned_user_id'] ?? '') === $currentUserId;
    $isContributor = false;
    foreach (editorial_get_article_contributors([$articleId])[$articleId] ?? [] as $contributor) {
        if ((string) ($contributor['user_id'] ?? '') === $currentUserId) {
            $isContributor = true;
            break;
        }
    }
    if (!$isOwner && !$isContributor) {
        $renderError(403, 'Bạn không có quyền xem bản lưu của bài viết này.');
    }
}

$readonly = editorial_resolve_review_readonly_revision($articleId, $state);
if (empty($readonly['ok'])) {
    $renderError(409, (string) ($readonly['message'] ?? 'Không thể xác thực bản lưu.'));
}

$preview = editorial_render_review_readonly_preview(
    $article,
    (array) ($readonly['snapshot'] ?? [])
);
if (empty($preview['ok'])) {
    $renderError(409, (string) ($preview['message'] ?? 'Không thể dựng bản lưu để xem.'));
}

$revision = (array) ($readonly['revision'] ?? []);
$label = (string) ($readonly['label'] ?? 'Bản lưu');
$legacy = !empty($readonly['legacy']);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= editorial_h($label) ?> | <?= editorial_h((string) $article['title']) ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; }
        body { background: #edf2f7; color: #172038; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .review-preview { display: grid; grid-template-rows: auto minmax(0, 1fr); width: 100vw; min-height: 100vh; }
        .review-preview-bar { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 10px 16px; background: #172038; color: #dbe7f2; }
        .review-preview-title { min-width: 0; }
        .review-preview-title strong { display: block; overflow: hidden; color: #fff; font-size: .92rem; text-overflow: ellipsis; white-space: nowrap; }
        .review-preview-title span { display: block; margin-top: 2px; font-size: .74rem; }
        .review-preview-meta { display: inline-flex; align-items: center; gap: 8px; flex: 0 0 auto; }
        .review-preview-badge { padding: 4px 8px; border: 1px solid #82d5ad; border-radius: 999px; color: #d7ffe7; font-size: .7rem; font-weight: 800; letter-spacing: .04em; }
        .review-preview-close { color: #dbe7f2; font-size: .74rem; }
        .review-preview-frame { width: 100%; min-height: 0; border: 0; background: #fff; }
        @media (max-width: 640px) {
            .review-preview-bar { align-items: flex-start; flex-direction: column; gap: 7px; }
        }
    </style>
</head>
<body>
    <main class="review-preview">
        <header class="review-preview-bar">
            <div class="review-preview-title">
                <strong><?= editorial_h((string) $article['title']) ?></strong>
                <span><?= editorial_h($label) ?> · Revision #<?= editorial_h((string) ($revision['revision_no'] ?? '')) ?> · Chế độ chỉ xem<?= $legacy ? ' · Phiên duyệt cũ' : '' ?></span>
            </div>
            <div class="review-preview-meta">
                <span class="review-preview-close">Đóng tab để quay lại</span>
                <span class="review-preview-badge">READ-ONLY</span>
            </div>
        </header>
        <iframe class="review-preview-frame" sandbox="" srcdoc="<?= editorial_h((string) $preview['html']) ?>" title="<?= editorial_h($label) ?>"></iframe>
    </main>
</body>
</html>
