<?php
declare(strict_types=1);

/**
 * Editorial V2 — Schema initialization & migration.
 *
 * Idempotent: safe to run multiple times.
 * Uses editorial_schema_meta table to track schema version.
 */

/**
 * Run all pending migrations.
 */
function editorial_run_migrations(): void
{
    $db = editorial_db();

    // Create schema meta table if not exists
    $db->exec('
        CREATE TABLE IF NOT EXISTS editorial_schema_meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ');

    $currentVersion = editorial_schema_version();
    $migrations = editorial_migration_list();

    foreach ($migrations as $version => $sql) {
        if ($version <= $currentVersion) {
            continue;
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            $alreadyApplied = $version === 11
                ? editorial_prepare_handoff_v11_migration($db)
                : false;
            if (!$alreadyApplied) {
                $db->exec($sql);
            }
            $stmt = $db->prepare('
                INSERT INTO editorial_schema_meta (key, value)
                VALUES (:key, :value)
                ON CONFLICT(key) DO UPDATE SET value = :value
            ');
            $stmt->execute(['key' => 'schema_version', 'value' => (string) $version]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable $rollbackError) {
                // Original migration exception remains authoritative.
            }
            throw new EditorialMigrationException(
                $currentVersion,
                $version,
                get_class($e),
                editorial_migration_safe_message($e->getMessage()),
                $e
            );
        }
    }
}

final class EditorialMigrationException extends RuntimeException
{
    public int $currentSchemaVersion;
    public int $targetSchemaVersion;
    public string $causeClass;

    public function __construct(
        int $currentSchemaVersion,
        int $targetSchemaVersion,
        string $causeClass,
        string $safeMessage,
        \Throwable $previous
    ) {
        $this->currentSchemaVersion = $currentSchemaVersion;
        $this->targetSchemaVersion = $targetSchemaVersion;
        $this->causeClass = $causeClass;
        parent::__construct(
            'Editorial migration failed: current_schema=' . $currentSchemaVersion
            . ' target_schema=' . $targetSchemaVersion
            . ' exception=' . $causeClass
            . ' message=' . $safeMessage,
            0,
            $previous
        );
    }
}

function editorial_migration_safe_message(string $message): string
{
    $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';
    $message = preg_replace('/(api[_ -]?key|password|token)\s*=\s*\S+/i', '$1=[redacted]', $message) ?? $message;
    return substr($message, 0, 500);
}

function editorial_migration_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute(['name' => $table]);
    return $stmt->fetchColumn() !== false;
}

/**
 * @return array<string,bool>
 */
function editorial_migration_table_columns(PDO $db, string $table): array
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        throw new RuntimeException('Tên bảng migration không hợp lệ.');
    }
    $rows = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) {
        $columns[(string) ($row['name'] ?? '')] = true;
    }
    return $columns;
}

/**
 * @return array<string,array{unique:bool,columns:array<int,string>}>
 */
function editorial_migration_table_indexes(PDO $db, string $table): array
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        throw new RuntimeException('Tên bảng migration không hợp lệ.');
    }
    $indexes = [];
    foreach ($db->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $columns = [];
        foreach ($db->query('PRAGMA index_info(' . $name . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = (string) ($column['name'] ?? '');
        }
        $indexes[$name] = [
            'unique' => !empty($row['unique']),
            'columns' => $columns,
        ];
    }
    return $indexes;
}

function editorial_migration_columns_match(array $columns, array $required): bool
{
    foreach ($required as $column) {
        if (empty($columns[$column])) {
            return false;
        }
    }
    return true;
}

function editorial_migration_handoff_v11_has_required_columns(PDO $db): bool
{
    $columns = editorial_migration_table_columns($db, 'editorial_handoff_sync');
    $required = [
        'id', 'article_id', 'source_key', 'source_revision_id', 'source_kind',
        'published_revision_id', 'drive_file_id', 'drive_file_url', 'handoff_note',
        'sheet_synced_at', 'synced_by', 'sync_status', 'last_error', 'created_at', 'updated_at',
    ];
    if (!editorial_migration_columns_match($columns, $required)) {
        return false;
    }
    return true;
}

function editorial_migration_handoff_v11_has_unique_source(PDO $db): bool
{
    $indexes = editorial_migration_table_indexes($db, 'editorial_handoff_sync');
    foreach ($indexes as $index) {
        if ($index['unique'] && $index['columns'] === ['article_id', 'source_key']) {
            return true;
        }
    }
    return false;
}

function editorial_migration_ensure_handoff_v11_indexes(PDO $db): void
{
    // The source-key table is already structurally valid. These named
    // non-unique indexes are safe to repair without touching handoff rows.
    $db->exec('DROP INDEX IF EXISTS idx_handoff_sync_article');
    $db->exec('DROP INDEX IF EXISTS idx_handoff_sync_status');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_handoff_sync_article ON editorial_handoff_sync(article_id, source_key)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_handoff_sync_status ON editorial_handoff_sync(sync_status)');
}

/**
 * Normalize clearly recoverable v11 starting states before raw migration SQL.
 *
 * @return bool true when v11 schema is already complete and only bookkeeping is needed
 */
function editorial_prepare_handoff_v11_migration(PDO $db): bool
{
    $mainExists = editorial_migration_table_exists($db, 'editorial_handoff_sync');
    $legacyExists = editorial_migration_table_exists($db, 'editorial_handoff_sync_v10');

    if ($mainExists && $legacyExists) {
        $mainColumns = implode(',', array_keys(editorial_migration_table_columns($db, 'editorial_handoff_sync')));
        $legacyColumns = implode(',', array_keys(editorial_migration_table_columns($db, 'editorial_handoff_sync_v10')));
        throw new RuntimeException(
            'Migration v11 ambiguous handoff tables: editorial_handoff_sync=[' . $mainColumns
            . ']; editorial_handoff_sync_v10=[' . $legacyColumns . '].'
        );
    }

    $v10Columns = [
        'id', 'article_id', 'published_revision_id', 'drive_file_id', 'drive_file_url',
        'handoff_note', 'sheet_synced_at', 'synced_by', 'sync_status',
        'last_error', 'created_at', 'updated_at',
    ];

    if (!$mainExists && $legacyExists) {
        $legacyColumns = editorial_migration_table_columns($db, 'editorial_handoff_sync_v10');
        if (!editorial_migration_columns_match($legacyColumns, $v10Columns)
            || !empty($legacyColumns['source_key'])) {
            throw new RuntimeException('Migration v11 legacy handoff table có schema không nhận diện được.');
        }
        $db->exec('ALTER TABLE editorial_handoff_sync_v10 RENAME TO editorial_handoff_sync');
        return false;
    }

    if (!$mainExists) {
        throw new RuntimeException('Migration v11 không tìm thấy editorial_handoff_sync v10 để nâng cấp.');
    }

    $columns = editorial_migration_table_columns($db, 'editorial_handoff_sync');
    if (!empty($columns['source_key'])) {
        if (!editorial_migration_handoff_v11_has_required_columns($db)
            || !editorial_migration_handoff_v11_has_unique_source($db)) {
            throw new RuntimeException('Migration v11 phát hiện bảng source-key dở dang hoặc thiếu unique source identity.');
        }
        editorial_migration_ensure_handoff_v11_indexes($db);
        return true;
    }

    if (!editorial_migration_columns_match($columns, $v10Columns)) {
        throw new RuntimeException('Migration v11 editorial_handoff_sync không có schema v10 nhận diện được.');
    }
    return false;
}

/**
 * Get current schema version.
 */
function editorial_schema_version(): int
{
    $db = editorial_db();
    try {
        $stmt = $db->prepare('SELECT value FROM editorial_schema_meta WHERE key = :key');
        $stmt->execute(['key' => 'schema_version']);
        $row = $stmt->fetch();
        return $row ? (int) $row['value'] : 0;
    } catch (\PDOException $e) {
        return 0;
    }
}

/**
 * Migration list: version => SQL.
 *
 * @return array<int, string>
 */
function editorial_migration_list(): array
{
    return [
        1 => '
            CREATE TABLE IF NOT EXISTS editorial_users (
                id                   TEXT PRIMARY KEY,
                username             TEXT NOT NULL UNIQUE,
                display_name         TEXT NOT NULL DEFAULT \'\',
                password_hash        TEXT NOT NULL,
                role                 TEXT NOT NULL DEFAULT \'editor\',
                is_active            INTEGER NOT NULL DEFAULT 1,
                must_change_password INTEGER NOT NULL DEFAULT 0,
                created_at           TEXT NOT NULL,
                updated_at           TEXT NOT NULL,
                last_login_at        TEXT
            );

            CREATE TABLE IF NOT EXISTS editorial_article_state (
                article_id          TEXT PRIMARY KEY,
                status              TEXT NOT NULL DEFAULT \'available\',
                assigned_user_id    TEXT,
                assigned_at         TEXT,
                base_live_hash      TEXT,
                current_revision_id TEXT,
                updated_at          TEXT NOT NULL,
                FOREIGN KEY (assigned_user_id) REFERENCES editorial_users(id)
            );

            CREATE TABLE IF NOT EXISTS editorial_assignments (
                id              TEXT PRIMARY KEY,
                article_id      TEXT NOT NULL,
                user_id         TEXT NOT NULL,
                assigned_at     TEXT NOT NULL,
                released_at     TEXT,
                release_reason  TEXT,
                created_by      TEXT,
                created_at      TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES editorial_users(id)
            );

            CREATE TABLE IF NOT EXISTS editorial_locks (
                article_id   TEXT PRIMARY KEY,
                user_id      TEXT NOT NULL,
                lock_token   TEXT NOT NULL,
                acquired_at  TEXT NOT NULL,
                heartbeat_at TEXT NOT NULL,
                expires_at   TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES editorial_users(id)
            );

            CREATE TABLE IF NOT EXISTS editorial_drafts (
                article_id     TEXT NOT NULL,
                user_id        TEXT NOT NULL,
                payload_json   TEXT NOT NULL DEFAULT \'{}\',
                base_live_hash TEXT,
                updated_at     TEXT NOT NULL,
                PRIMARY KEY (article_id, user_id),
                FOREIGN KEY (user_id) REFERENCES editorial_users(id)
            );

            CREATE TABLE IF NOT EXISTS editorial_revisions (
                id               TEXT PRIMARY KEY,
                article_id       TEXT NOT NULL,
                revision_no      INTEGER NOT NULL,
                revision_type    TEXT NOT NULL DEFAULT \'editorial\',
                snapshot_path    TEXT,
                content_hash     TEXT,
                base_revision_id TEXT,
                created_by       TEXT NOT NULL,
                created_at       TEXT NOT NULL,
                note             TEXT,
                UNIQUE (article_id, revision_no),
                FOREIGN KEY (created_by) REFERENCES editorial_users(id)
            );

            CREATE TABLE IF NOT EXISTS editorial_activity (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type     TEXT NOT NULL,
                article_id     TEXT,
                actor_user_id  TEXT,
                payload_json   TEXT,
                created_at     TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_activity_created
                ON editorial_activity(created_at);
            CREATE INDEX IF NOT EXISTS idx_activity_article
                ON editorial_activity(article_id);
            CREATE INDEX IF NOT EXISTS idx_assignments_article
                ON editorial_assignments(article_id);
            CREATE INDEX IF NOT EXISTS idx_revisions_article
                ON editorial_revisions(article_id);
        ',

        2 => '
            -- Phase 3: Assignment invariant — at most one active assignment per article
            CREATE UNIQUE INDEX IF NOT EXISTS idx_assignments_active_article
                ON editorial_assignments(article_id)
                WHERE released_at IS NULL;

            -- Useful indexes for article state queries
            CREATE INDEX IF NOT EXISTS idx_article_state_status
                ON editorial_article_state(status);
            CREATE INDEX IF NOT EXISTS idx_article_state_assigned
                ON editorial_article_state(assigned_user_id);
            CREATE INDEX IF NOT EXISTS idx_assignments_user_active
                ON editorial_assignments(user_id, released_at);
        ',

        3 => '
            -- Phase 4: Draft optimistic concurrency versioning
            ALTER TABLE editorial_drafts ADD COLUMN version INTEGER NOT NULL DEFAULT 0;

            -- Index for lock expiry queries
            CREATE INDEX IF NOT EXISTS idx_locks_expires
                ON editorial_locks(expires_at);
        ',

        4 => '
            -- Phase 5: Revision traceability
            ALTER TABLE editorial_revisions ADD COLUMN assignment_id TEXT;
            ALTER TABLE editorial_revisions ADD COLUMN source_draft_version INTEGER;

            -- Index for assignment-based revision queries
            CREATE INDEX IF NOT EXISTS idx_revisions_assignment
                ON editorial_revisions(assignment_id);
        ',
        5 => '
            -- Phase 6: Review/approval workflow columns
            ALTER TABLE editorial_article_state ADD COLUMN review_revision_id TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN review_requested_by TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN review_requested_at TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN approved_revision_id TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN approved_by TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN approved_at TEXT;

            CREATE INDEX IF NOT EXISTS idx_article_state_review
                ON editorial_article_state(status) WHERE status IN (\'ready_review\', \'approved\');
        ',
        6 => '
            -- Phase 7: Publish columns
            ALTER TABLE editorial_article_state ADD COLUMN published_revision_id TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN published_by TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN published_at TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN published_live_hash TEXT;
            ALTER TABLE editorial_article_state ADD COLUMN publish_backup_path TEXT;
        ',
        7 => '
            -- Phase 9A: semantic milestones keep workflow revision_type backward-compatible.
            ALTER TABLE editorial_revisions ADD COLUMN milestone_key TEXT;
            CREATE INDEX IF NOT EXISTS idx_revisions_assignment_milestone
                ON editorial_revisions(assignment_id, milestone_key, revision_no DESC);
        ',
        8 => '
            -- Phase 11B: permanent evidence that an assignment actually edited.
            ALTER TABLE editorial_assignments ADD COLUMN first_saved_at TEXT;
            ALTER TABLE editorial_assignments ADD COLUMN last_saved_at TEXT;

            -- Backfill only when existing drafts or draft-derived revisions
            -- provide evidence. A claim by itself is never a contributor mark.
            UPDATE editorial_assignments
            SET
                first_saved_at = COALESCE(
                    first_saved_at,
                    (
                        SELECT MIN(r.created_at)
                        FROM editorial_revisions r
                        WHERE r.assignment_id = editorial_assignments.id
                          AND r.source_draft_version IS NOT NULL
                          AND r.source_draft_version > 0
                    ),
                    (
                        SELECT d.updated_at
                        FROM editorial_drafts d
                        WHERE editorial_assignments.released_at IS NULL
                          AND d.article_id = editorial_assignments.article_id
                          AND d.user_id = editorial_assignments.user_id
                    )
                ),
                last_saved_at = COALESCE(
                    (
                        SELECT d.updated_at
                        FROM editorial_drafts d
                        WHERE editorial_assignments.released_at IS NULL
                          AND d.article_id = editorial_assignments.article_id
                          AND d.user_id = editorial_assignments.user_id
                    ),
                    (
                        SELECT MAX(r.created_at)
                        FROM editorial_revisions r
                        WHERE r.assignment_id = editorial_assignments.id
                          AND r.source_draft_version IS NOT NULL
                          AND r.source_draft_version > 0
                    ),
                    last_saved_at
                )
            WHERE first_saved_at IS NULL OR last_saved_at IS NULL;
        ',
        9 => '
            -- Phase 12A: server-side Google Handoff configuration.
            CREATE TABLE IF NOT EXISTS editorial_settings (
                setting_key   TEXT PRIMARY KEY,
                setting_value TEXT,
                is_secret     INTEGER NOT NULL DEFAULT 0,
                updated_by    TEXT,
                updated_at    TEXT NOT NULL
            );
        ',
        10 => '
            -- Phase 12B: idempotent Google Drive/Sheet handoff state.
            CREATE TABLE IF NOT EXISTS editorial_handoff_sync (
                id                    TEXT PRIMARY KEY,
                article_id            TEXT NOT NULL,
                published_revision_id TEXT NOT NULL,
                drive_file_id         TEXT,
                drive_file_url        TEXT,
                handoff_note          TEXT,
                sheet_synced_at       TEXT,
                synced_by             TEXT,
                sync_status           TEXT NOT NULL DEFAULT \'pending\',
                last_error            TEXT,
                created_at            TEXT NOT NULL,
                updated_at            TEXT NOT NULL,
                UNIQUE(article_id, published_revision_id)
            );
            CREATE INDEX IF NOT EXISTS idx_handoff_sync_article
                ON editorial_handoff_sync(article_id, published_revision_id);
            CREATE INDEX IF NOT EXISTS idx_handoff_sync_status
                ON editorial_handoff_sync(sync_status);
        ',
        11 => '
            -- Phase 12C: general handoff source identity, independent from Publish.
            DROP INDEX IF EXISTS idx_handoff_sync_article;
            DROP INDEX IF EXISTS idx_handoff_sync_status;
            ALTER TABLE editorial_handoff_sync RENAME TO editorial_handoff_sync_v10;

            CREATE TABLE editorial_handoff_sync (
                id                    TEXT PRIMARY KEY,
                article_id            TEXT NOT NULL,
                source_key            TEXT NOT NULL,
                source_revision_id    TEXT,
                source_kind           TEXT NOT NULL,
                published_revision_id TEXT,
                drive_file_id         TEXT,
                drive_file_url        TEXT,
                handoff_note          TEXT,
                sheet_synced_at       TEXT,
                synced_by             TEXT,
                sync_status           TEXT NOT NULL DEFAULT \'pending\',
                last_error            TEXT,
                created_at            TEXT NOT NULL,
                updated_at            TEXT NOT NULL,
                UNIQUE(article_id, source_key)
            );

            INSERT INTO editorial_handoff_sync (
                id, article_id, source_key, source_revision_id, source_kind,
                published_revision_id, drive_file_id, drive_file_url,
                handoff_note, sheet_synced_at, synced_by, sync_status,
                last_error, created_at, updated_at
            )
            SELECT
                id,
                article_id,
                \'revision:\' || published_revision_id,
                published_revision_id,
                \'published\',
                published_revision_id,
                drive_file_id,
                drive_file_url,
                handoff_note,
                sheet_synced_at,
                synced_by,
                sync_status,
                last_error,
                created_at,
                updated_at
            FROM editorial_handoff_sync_v10;

            DROP TABLE editorial_handoff_sync_v10;

            CREATE INDEX idx_handoff_sync_article
                ON editorial_handoff_sync(article_id, source_key);
            CREATE INDEX idx_handoff_sync_status
                ON editorial_handoff_sync(sync_status);
        ',
    ];
}
