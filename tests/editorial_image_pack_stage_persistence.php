<?php
declare(strict_types=1);

const TEST_IMAGE_PACK_ARTICLE = 'image-pack-stage-' . __LINE__ . '.html';
const TEST_IMAGE_PACK_USER = 'editor-image-pack';
const TEST_IMAGE_PACK_ASSIGNMENT = 'assignment-image-pack';
const TEST_IMAGE_PACK_LOCK = 'lock-image-pack';

$testDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$testDb->exec('
    CREATE TABLE editorial_article_state (
        article_id TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        assigned_user_id TEXT,
        current_revision_id TEXT,
        updated_at TEXT
    );
    CREATE TABLE editorial_assignments (
        id TEXT PRIMARY KEY,
        article_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        assigned_at TEXT NOT NULL,
        released_at TEXT,
        release_reason TEXT,
        created_by TEXT,
        created_at TEXT NOT NULL,
        first_saved_at TEXT,
        last_saved_at TEXT
    );
    CREATE TABLE editorial_locks (
        article_id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        lock_token TEXT NOT NULL,
        acquired_at TEXT NOT NULL,
        heartbeat_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    );
    CREATE TABLE editorial_drafts (
        article_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        base_live_hash TEXT,
        updated_at TEXT NOT NULL,
        version INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (article_id, user_id)
    );
    CREATE TABLE editorial_revisions (
        id TEXT PRIMARY KEY,
        article_id TEXT NOT NULL,
        revision_no INTEGER NOT NULL,
        revision_type TEXT NOT NULL,
        snapshot_path TEXT,
        content_hash TEXT,
        base_revision_id TEXT,
        created_by TEXT NOT NULL,
        created_at TEXT NOT NULL,
        note TEXT,
        assignment_id TEXT,
        source_draft_version INTEGER,
        milestone_key TEXT,
        UNIQUE (article_id, revision_no)
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

function editorial_get_article_state(string $articleId): ?array
{
    $stmt = editorial_db()->prepare(
        'SELECT * FROM editorial_article_state WHERE article_id = :article_id'
    );
    $stmt->execute(['article_id' => $articleId]);
    return $stmt->fetch() ?: null;
}

function editorial_log_activity(
    string $eventType,
    ?string $articleId = null,
    ?string $actorUserId = null,
    ?string $payloadJson = null
): void {
    editorial_db()->prepare('
        INSERT INTO editorial_activity
            (event_type, article_id, actor_user_id, payload_json, created_at)
        VALUES
            (:event_type, :article_id, :actor_user_id, :payload_json, :created_at)
    ')->execute([
        'event_type' => $eventType,
        'article_id' => $articleId,
        'actor_user_id' => $actorUserId,
        'payload_json' => $payloadJson,
        'created_at' => date('c'),
    ]);
}

function editorial_generate_id(string $prefix = 'ed'): string
{
    static $counter = 0;
    $counter++;
    return $prefix . '-image-pack-' . $counter;
}

function editorial_find_user_by_id(string $userId): ?array
{
    return $userId === TEST_IMAGE_PACK_USER
        ? ['id' => $userId, 'display_name' => 'Image Pack Editor']
        : null;
}

require_once dirname(__DIR__) . '/editorial/includes/workspace.php';
require_once dirname(__DIR__) . '/editorial/includes/revision.php';

$snapshotHash = hash('sha256', TEST_IMAGE_PACK_ARTICLE);
$snapshotDir = dirname(__DIR__) . '/editorial/storage/revisions/'
    . substr($snapshotHash, 0, 2) . '/' . $snapshotHash;
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
register_shutdown_function(static function () use ($snapshotDir, $removeTree): void {
    $removeTree($snapshotDir);
    $shard = dirname($snapshotDir);
    if (is_dir($shard) && (scandir($shard) ?: []) === ['.', '..']) {
        @rmdir($shard);
    }
});

$now = date('c');
$testDb->prepare('
    INSERT INTO editorial_article_state
        (article_id, status, assigned_user_id, current_revision_id, updated_at)
    VALUES
        (:article_id, :status, :assigned_user_id, NULL, :updated_at)
')->execute([
    'article_id' => TEST_IMAGE_PACK_ARTICLE,
    'status' => 'editing',
    'assigned_user_id' => TEST_IMAGE_PACK_USER,
    'updated_at' => $now,
]);
$testDb->prepare('
    INSERT INTO editorial_assignments
        (id, article_id, user_id, assigned_at, released_at, created_by, created_at)
    VALUES
        (:id, :article_id, :user_id, :assigned_at, NULL, :created_by, :created_at)
')->execute([
    'id' => TEST_IMAGE_PACK_ASSIGNMENT,
    'article_id' => TEST_IMAGE_PACK_ARTICLE,
    'user_id' => TEST_IMAGE_PACK_USER,
    'assigned_at' => $now,
    'created_by' => TEST_IMAGE_PACK_USER,
    'created_at' => $now,
]);
$testDb->prepare('
    INSERT INTO editorial_locks
        (article_id, user_id, lock_token, acquired_at, heartbeat_at, expires_at)
    VALUES
        (:article_id, :user_id, :lock_token, :acquired_at, :heartbeat_at, :expires_at)
')->execute([
    'article_id' => TEST_IMAGE_PACK_ARTICLE,
    'user_id' => TEST_IMAGE_PACK_USER,
    'lock_token' => TEST_IMAGE_PACK_LOCK,
    'acquired_at' => $now,
    'heartbeat_at' => $now,
    'expires_at' => date('c', time() + 3600),
]);

$oldInline = 'assets/images/legacy-inline.jpg';
$newInline = 'uploads/articles/2026/09/image-pack-inline.webp';
$newFeatured = 'uploads/articles/2026/09/image-pack-featured.webp';
$payload = [
    'title' => 'Image Pack persistence fixture',
    'excerpt' => 'Fixture',
    'prose_html' => '<p>Text trước ảnh.</p>'
        . '<figure class="article-image" data-editorial-image-meta="1">'
        . '<img src="' . $newInline . '" alt="Alt mới" title="Title mới">'
        . '<figcaption><span class="article-image-caption">Caption mới</span>'
        . '<span class="article-image-credit">Nguồn: Credit mới</span></figcaption>'
        . '</figure><p>Text sau ảnh.</p>',
    'publish_date' => '2026-09-08',
    'modified_date' => '',
    'tags_text' => 'Image Pack',
    'featured_image' => $newFeatured,
    'featured_image_alt' => 'Featured alt',
    'featured_image_title' => 'Featured title',
    'featured_image_caption' => 'Featured caption',
    'featured_image_credit' => 'Featured credit',
    'legacy_inline_source' => $oldInline,
];

$save = editorial_save_draft(
    TEST_IMAGE_PACK_ARTICLE,
    TEST_IMAGE_PACK_USER,
    $payload,
    'base-live-hash',
    0,
    TEST_IMAGE_PACK_LOCK
);
$failures = [];
if (empty($save['ok']) || (int) ($save['version'] ?? 0) !== 1) {
    $failures[] = 'Draft save failed: ' . json_encode($save, JSON_UNESCAPED_UNICODE);
}

$reloaded = editorial_get_draft(TEST_IMAGE_PACK_ARTICLE, TEST_IMAGE_PACK_USER);
if (($reloaded['payload']['featured_image'] ?? '') !== $newFeatured
    || !str_contains((string) ($reloaded['payload']['prose_html'] ?? ''), 'src="' . $newInline . '"')
    || str_contains((string) ($reloaded['payload']['prose_html'] ?? ''), 'src="' . $oldInline . '"')) {
    $failures[] = 'Reloaded Draft did not preserve Image Pack paths.';
}

$stage1 = editorial_create_stage_milestone_revision(
    TEST_IMAGE_PACK_ARTICLE,
    TEST_IMAGE_PACK_USER,
    TEST_IMAGE_PACK_LOCK,
    1,
    'stage1'
);
$stage2 = editorial_create_stage_milestone_revision(
    TEST_IMAGE_PACK_ARTICLE,
    TEST_IMAGE_PACK_USER,
    TEST_IMAGE_PACK_LOCK,
    1,
    'stage2'
);
foreach (['stage1' => $stage1, 'stage2' => $stage2] as $label => $result) {
    if (empty($result['ok'])) {
        $failures[] = $label . ' creation failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
        continue;
    }
    $revision = editorial_get_revision((string) $result['revision_id']);
    $snapshot = $revision ? editorial_get_verified_revision_snapshot($revision) : ['ok' => false];
    $snapshotPayload = $snapshot['payload'] ?? [];
    if (empty($snapshot['ok'])
        || ($snapshotPayload['featured_image'] ?? '') !== $newFeatured
        || !str_contains((string) ($snapshotPayload['prose_html'] ?? ''), 'src="' . $newInline . '"')
        || str_contains((string) ($snapshotPayload['prose_html'] ?? ''), 'src="' . $oldInline . '"')) {
        $failures[] = $label . ' snapshot did not preserve Image Pack paths.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'editorial image pack stage persistence: ok'
    . ' inline=' . $newInline
    . ' featured=' . $newFeatured
    . ' stage1_snapshot=' . ($stage1['revision_id'] ?? '')
    . ' stage2_snapshot=' . ($stage2['revision_id'] ?? '')
    . PHP_EOL;
