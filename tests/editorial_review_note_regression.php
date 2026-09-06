<?php
declare(strict_types=1);

$sandboxRoot = rtrim(sys_get_temp_dir(), '/\\')
    . '/kdt-editorial-review-note-' . bin2hex(random_bytes(6));
$storagePath = $sandboxRoot . '/storage';
if (!mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
    throw new RuntimeException('Cannot create review-note test sandbox.');
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($sandboxRoot, $removeTree): void {
    $removeTree($sandboxRoot);
});

define('EDITORIAL_STORAGE_PATH', $storagePath);
define('EDITORIAL_DB_PATH', $storagePath . '/editorial.sqlite');

require_once dirname(__DIR__) . '/editorial/includes/database.php';
require_once dirname(__DIR__) . '/editorial/includes/review.php';

$db = editorial_db();
$db->exec('
    CREATE TABLE editorial_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        article_id TEXT,
        actor_user_id TEXT,
        payload_json TEXT,
        created_at TEXT NOT NULL
    )
');

$insert = $db->prepare('
    INSERT INTO editorial_activity
        (event_type, article_id, actor_user_id, payload_json, created_at)
    VALUES
        (:event_type, :article_id, :actor_user_id, :payload_json, :created_at)
');
$events = [
    ['article.review.submitted', 'article-a.html', 'user-a', ['revision_id' => 'rev-old', 'note' => 'Ghi chú cũ'], '2026-09-06T08:00:00+00:00'],
    ['article.review.submitted', 'article-a.html', 'user-a', ['revision_id' => 'rev-current', 'note' => 'Ghi chú cùng revision nhưng đã cũ'], '2026-09-06T08:30:00+00:00'],
    ['article.review.submitted', 'article-a.html', 'user-a', ['revision_id' => 'rev-current', 'note' => '  Kiểm tra kỹ bảng số liệu.  '], '2026-09-06T09:00:00+00:00'],
    ['article.review.submitted', 'article-b.html', 'user-b', ['revision_id' => 'rev-other', 'note' => 'Bài khác'], '2026-09-06T10:00:00+00:00'],
];
foreach ($events as [$eventType, $articleId, $actorId, $payload, $createdAt]) {
    $insert->execute([
        'event_type' => $eventType,
        'article_id' => $articleId,
        'actor_user_id' => $actorId,
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $createdAt,
    ]);
}

$failures = [];
$normalized = editorial_normalize_review_submission_note("  Dòng một\nDòng hai  ");
if (empty($normalized['ok']) || ($normalized['note'] ?? '') !== "Dòng một\nDòng hai") {
    $failures[] = 'review note normalization failed';
}
$tooLong = editorial_normalize_review_submission_note(str_repeat('a', 2001));
if (!empty($tooLong['ok'])) {
    $failures[] = 'review note length limit was not enforced';
}

$contexts = editorial_get_review_submission_contexts(['rev-current', 'rev-other', 'missing']);
if (($contexts['rev-current']['note'] ?? '') !== 'Kiểm tra kỹ bảng số liệu.') {
    $failures[] = 'current review note did not match its revision';
}
if (($contexts['rev-current']['actor_user_id'] ?? '') !== 'user-a') {
    $failures[] = 'review requester context was not preserved';
}
if (($contexts['rev-other']['article_id'] ?? '') !== 'article-b.html') {
    $failures[] = 'batched review context lookup returned wrong article';
}
if (isset($contexts['rev-old']) || isset($contexts['missing'])) {
    $failures[] = 'context lookup returned an unrequested or missing revision';
}

$current = editorial_get_review_submission_context('article-a.html', 'rev-current');
if (($current['note'] ?? '') !== 'Kiểm tra kỹ bảng số liệu.') {
    $failures[] = 'single review context lookup failed';
}
if (editorial_get_review_submission_context('wrong-article.html', 'rev-current') !== null) {
    $failures[] = 'single review context lookup ignored article identity';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "editorial review note regression tests: ok\n";
