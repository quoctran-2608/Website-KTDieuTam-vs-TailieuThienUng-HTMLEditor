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
            $db->exec($sql);
            $stmt = $db->prepare('
                INSERT INTO editorial_schema_meta (key, value)
                VALUES (:key, :value)
                ON CONFLICT(key) DO UPDATE SET value = :value
            ');
            $stmt->execute(['key' => 'schema_version', 'value' => (string) $version]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw new RuntimeException(
                "Editorial migration v{$version} thất bại: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
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
    ];
}
