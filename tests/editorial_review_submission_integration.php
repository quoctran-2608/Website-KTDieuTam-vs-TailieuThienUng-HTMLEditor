<?php
declare(strict_types=1);

$sandboxRoot = rtrim(sys_get_temp_dir(), '/\\')
    . '/kdt-editorial-review-submit-' . bin2hex(random_bytes(6));
if (!mkdir($sandboxRoot, 0775, true) && !is_dir($sandboxRoot)) {
    throw new RuntimeException('Cannot create review submission sandbox.');
}
$articlePath = $sandboxRoot . '/article.html';
file_put_contents($articlePath, '<article>review fixture</article>');

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

const TEST_ARTICLE_ID = 'article-review-note.html';
const TEST_USER_ID = 'user-review-note';
const TEST_ASSIGNMENT_ID = 'assignment-review-note';
const TEST_LOCK_TOKEN = 'lock-review-note';
const TEST_STAGE_HASH = 'stage-hash-review-note';

$testDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$testDb->exec('
    CREATE TABLE editorial_article_state (
        article_id TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        assigned_user_id TEXT,
        base_live_hash TEXT,
        review_revision_id TEXT,
        review_requested_by TEXT,
        review_requested_at TEXT,
        updated_at TEXT
    );
    CREATE TABLE editorial_locks (
        article_id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        lock_token TEXT NOT NULL,
        expires_at TEXT NOT NULL
    );
    CREATE TABLE editorial_revisions (
        id TEXT PRIMARY KEY,
        article_id TEXT NOT NULL,
        assignment_id TEXT NOT NULL,
        revision_no INTEGER NOT NULL,
        revision_type TEXT NOT NULL,
        milestone_key TEXT,
        content_hash TEXT
    );
    CREATE TABLE editorial_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        article_id TEXT,
        actor_user_id TEXT,
        payload_json TEXT,
        created_at TEXT NOT NULL
    );
');

function editorial_db(): PDO
{
    global $testDb;
    return $testDb;
}

function editorial_transaction(callable $callback): mixed
{
    $db = editorial_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $callback();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function editorial_find_article(string $articleId): ?array
{
    return $articleId === TEST_ARTICLE_ID ? ['id' => TEST_ARTICLE_ID] : null;
}

function editorial_resolve_article_path(array $article): ?string
{
    global $articlePath;
    return $articlePath;
}

function editorial_live_hash(string $path): ?string
{
    $hash = hash_file('sha256', $path);
    return $hash === false ? null : $hash;
}

function editorial_get_article_state(string $articleId): ?array
{
    $stmt = editorial_db()->prepare('SELECT * FROM editorial_article_state WHERE article_id = :article_id');
    $stmt->execute(['article_id' => $articleId]);
    return $stmt->fetch() ?: null;
}

function editorial_can_transition(string $from, string $to): bool
{
    return in_array($from, ['editing', 'returned'], true) && $to === 'ready_review';
}

function editorial_get_active_assignment(string $articleId): ?array
{
    return $articleId === TEST_ARTICLE_ID
        ? ['id' => TEST_ASSIGNMENT_ID, 'article_id' => TEST_ARTICLE_ID, 'user_id' => TEST_USER_ID]
        : null;
}

function editorial_get_draft(string $articleId, string $userId): ?array
{
    return $articleId === TEST_ARTICLE_ID && $userId === TEST_USER_ID
        ? ['payload' => ['title' => 'Draft review fixture']]
        : null;
}

function editorial_revision_content_hash(array $payload): string
{
    return TEST_STAGE_HASH;
}

function editorial_get_active_stage_bundle(string $articleId, string $assignmentId): array
{
    return [
        'stage1' => [
            'id' => 'stage1-review-note',
            'article_id' => TEST_ARTICLE_ID,
            'assignment_id' => TEST_ASSIGNMENT_ID,
            'revision_no' => 2,
            'revision_type' => 'editorial',
            'milestone_key' => 'stage1',
            'content_hash' => 'stage1-hash',
        ],
        'stage2' => [
            'id' => 'stage2-review-note',
            'article_id' => TEST_ARTICLE_ID,
            'assignment_id' => TEST_ASSIGNMENT_ID,
            'revision_no' => 3,
            'revision_type' => 'editorial',
            'milestone_key' => 'stage2',
            'content_hash' => TEST_STAGE_HASH,
        ],
    ];
}

function editorial_get_verified_revision_snapshot(array $revision): array
{
    return ['ok' => true, 'payload' => ['title' => 'Verified review fixture']];
}

require_once dirname(__DIR__) . '/editorial/includes/review.php';

$liveHash = editorial_live_hash($articlePath);
$insertState = $testDb->prepare('
    INSERT INTO editorial_article_state
        (article_id, status, assigned_user_id, base_live_hash, updated_at)
    VALUES
        (:article_id, :status, :assigned_user_id, :base_live_hash, :updated_at)
');
$insertState->execute([
    'article_id' => TEST_ARTICLE_ID,
    'status' => 'editing',
    'assigned_user_id' => TEST_USER_ID,
    'base_live_hash' => $liveHash,
    'updated_at' => '2026-09-06T00:00:00+00:00',
]);
$testDb->prepare('
    INSERT INTO editorial_locks (article_id, user_id, lock_token, expires_at)
    VALUES (:article_id, :user_id, :lock_token, :expires_at)
')->execute([
    'article_id' => TEST_ARTICLE_ID,
    'user_id' => TEST_USER_ID,
    'lock_token' => TEST_LOCK_TOKEN,
    'expires_at' => date('c', time() + 3600),
]);
$testDb->prepare('
    INSERT INTO editorial_revisions
        (id, article_id, assignment_id, revision_no, revision_type, milestone_key, content_hash)
    VALUES
        (:id, :article_id, :assignment_id, :revision_no, :revision_type, :milestone_key, :content_hash)
')->execute([
    'id' => 'baseline-review-note',
    'article_id' => TEST_ARTICLE_ID,
    'assignment_id' => TEST_ASSIGNMENT_ID,
    'revision_no' => 1,
    'revision_type' => 'baseline',
    'milestone_key' => null,
    'content_hash' => 'baseline-hash',
]);

$result = editorial_send_for_review(
    TEST_ARTICLE_ID,
    TEST_USER_ID,
    TEST_LOCK_TOKEN,
    "  Đã rà soát số liệu.\nNhờ Admin kiểm tra mục 3.  "
);
$failures = [];
if (empty($result['ok'])) {
    $failures[] = 'review submission failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
}

$state = editorial_get_article_state(TEST_ARTICLE_ID);
if (($state['status'] ?? '') !== 'ready_review'
    || ($state['review_revision_id'] ?? '') !== 'stage2-review-note'
    || ($state['review_requested_by'] ?? '') !== TEST_USER_ID) {
    $failures[] = 'review state was not updated to the exact Stage2 submission';
}
$lockCount = (int) $testDb->query('SELECT COUNT(*) FROM editorial_locks')->fetchColumn();
if ($lockCount !== 0) {
    $failures[] = 'workspace lock was not released after review submission';
}
$activity = $testDb->query("
    SELECT * FROM editorial_activity
    WHERE event_type = 'article.review.submitted'
    ORDER BY id DESC LIMIT 1
")->fetch();
$payload = json_decode((string) ($activity['payload_json'] ?? ''), true);
if (($payload['revision_id'] ?? '') !== 'stage2-review-note'
    || ($payload['stage1_revision_id'] ?? '') !== 'stage1-review-note'
    || ($payload['assignment_id'] ?? '') !== TEST_ASSIGNMENT_ID
    || ($payload['note'] ?? '') !== "Đã rà soát số liệu.\nNhờ Admin kiểm tra mục 3.") {
    $failures[] = 'review activity did not preserve revision evidence and normalized note';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "editorial review submission integration: ok\n";
