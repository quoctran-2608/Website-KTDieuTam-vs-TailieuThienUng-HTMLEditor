<?php
declare(strict_types=1);

/**
 * Editorial V2 Phase 6 — Review Service Module.
 *
 * Provides:
 * - Send for review (editor action)
 * - Approve review (admin action)
 * - Return review (admin action)
 * - Review queue query
 * - Reassign article (admin action)
 * - Release assignment (admin action)
 * - Force unlock (admin action)
 * - Draft-revision sync check
 * - Latest return note helper
 */

/**
 * @return array<int,array<string,mixed>>
 */
function editorial_get_verified_revisions_for_review(string $articleId, string $assignmentId, string $where = '', array $params = []): array
{
    $sql = '
        SELECT * FROM editorial_revisions
        WHERE article_id = :article_id
          AND assignment_id = :assignment_id
    ' . $where . '
        ORDER BY revision_no DESC
    ';
    $stmt = editorial_db()->prepare($sql);
    $stmt->execute(array_merge([
        'article_id' => $articleId,
        'assignment_id' => $assignmentId,
    ], $params));

    $verified = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $revision) {
        if (!empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            $verified[] = $revision;
        }
    }
    return $verified;
}

/**
 * Resolve the immutable evidence required for a new review submission.
 *
 * @return array{ok:bool,baseline?:array<string,mixed>,stage1?:array<string,mixed>,stage2?:array<string,mixed>,message:string}
 */
function editorial_resolve_review_submission_stage_bundle(string $articleId, string $assignmentId, string $draftHash): array
{
    $baselines = editorial_get_verified_revisions_for_review(
        $articleId,
        $assignmentId,
        'AND revision_type = \'baseline\''
    );
    if (count($baselines) !== 1) {
        return ['ok' => false, 'message' => 'Không tìm thấy Bản gốc hợp lệ cho phiên biên tập này.'];
    }

    $allStage1 = editorial_get_verified_revisions_for_review(
        $articleId,
        $assignmentId,
        'AND revision_type = \'editorial\' AND milestone_key = \'stage1\''
    );
    if ($allStage1 === []) {
        return ['ok' => false, 'message' => 'Chưa có Chặng 1 để Admin đối chiếu. Hãy hoàn tất Chặng 1 trước khi gửi duyệt.'];
    }

    $allStage2 = editorial_get_verified_revisions_for_review(
        $articleId,
        $assignmentId,
        'AND revision_type = \'editorial\' AND milestone_key = \'stage2\''
    );
    if ($allStage2 === []) {
        return ['ok' => false, 'message' => 'Chưa có Chặng 2 để Admin đối chiếu. Hãy hoàn tất Chặng 2 trước khi gửi duyệt.'];
    }

    $stage2 = null;
    foreach ($allStage2 as $candidate) {
        if (hash_equals((string) ($candidate['content_hash'] ?? ''), $draftHash)) {
            $stage2 = $candidate;
            break;
        }
    }
    if ($stage2 === null) {
        return ['ok' => false, 'message' => 'Nội dung đã thay đổi sau Chặng 2. Hãy hoàn tất Chặng 2 lại trước khi gửi Admin duyệt.'];
    }

    $stage1s = array_values(array_filter(
        $allStage1,
        static fn(array $revision): bool => (int) ($revision['revision_no'] ?? 0) < (int) ($stage2['revision_no'] ?? 0)
    ));
    if ($stage1s === []) {
        return ['ok' => false, 'message' => 'Chưa có Chặng 1 để Admin đối chiếu. Hãy hoàn tất Chặng 1 trước khi gửi duyệt.'];
    }

    return [
        'ok' => true,
        'baseline' => $baselines[0],
        'stage1' => $stage1s[0],
        'stage2' => $stage2,
        'message' => 'Đủ Bản gốc, Chặng 1 và Chặng 2 để gửi duyệt.',
    ];
}

/**
 * Resolve the semantic comparison bundle for an existing review revision.
 * New reviews point directly at Stage2; legacy technical candidates remain
 * readable only when an equivalent verified Stage2 can be proven.
 *
 * @return array{ok:bool,legacy:bool,baseline?:array<string,mixed>,stage1?:array<string,mixed>,stage2?:array<string,mixed>,message:string}
 */
function editorial_resolve_review_stage_bundle(string $articleId, array $reviewRevision): array
{
    if ((string) ($reviewRevision['article_id'] ?? '') !== $articleId
        || (string) ($reviewRevision['revision_type'] ?? '') !== 'editorial'
        || empty(editorial_get_verified_revision_snapshot($reviewRevision)['ok'])) {
        return ['ok' => false, 'legacy' => false, 'message' => 'Phiên bản gửi duyệt không hợp lệ hoặc snapshot chưa được xác thực.'];
    }

    $assignmentId = trim((string) ($reviewRevision['assignment_id'] ?? ''));
    if ($assignmentId === '') {
        return ['ok' => false, 'legacy' => false, 'message' => 'Phiên bản gửi duyệt thiếu thông tin phân công.'];
    }

    $baselines = editorial_get_verified_revisions_for_review(
        $articleId,
        $assignmentId,
        'AND revision_type = \'baseline\''
    );
    if (count($baselines) !== 1) {
        return ['ok' => false, 'legacy' => false, 'message' => 'Không có Bản gốc hợp lệ, duy nhất cho phiên duyệt này.'];
    }

    $milestone = (string) ($reviewRevision['milestone_key'] ?? '');
    $legacy = $milestone !== 'stage2';
    $stage2 = null;
    if ($milestone === 'stage2') {
        $stage2 = $reviewRevision;
    } elseif ($milestone === '') {
        $stage2Candidates = editorial_get_verified_revisions_for_review(
            $articleId,
            $assignmentId,
            'AND revision_type = \'editorial\' AND milestone_key = \'stage2\' AND content_hash = :content_hash',
            ['content_hash' => (string) ($reviewRevision['content_hash'] ?? '')]
        );
        $stage2 = $stage2Candidates[0] ?? null;
    }

    if ($stage2 === null) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'message' => $legacy
                ? 'Phiên duyệt này được tạo theo luồng cũ và chưa có đủ Chặng 1/Chặng 2 để đối chiếu.'
                : 'Không tìm thấy Chặng 2 hợp lệ cho phiên duyệt này.',
        ];
    }

    $stage1s = editorial_get_verified_revisions_for_review(
        $articleId,
        $assignmentId,
        'AND revision_type = \'editorial\' AND milestone_key = \'stage1\' AND revision_no < :stage2_no',
        ['stage2_no' => (int) $stage2['revision_no']]
    );
    if ($stage1s === []) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'message' => $legacy
                ? 'Phiên duyệt này được tạo theo luồng cũ và chưa có đủ Chặng 1/Chặng 2 để đối chiếu.'
                : 'Không tìm thấy Chặng 1 hợp lệ trước Chặng 2 cho phiên duyệt này.',
        ];
    }

    return [
        'ok' => true,
        'legacy' => $legacy,
        'baseline' => $baselines[0],
        'stage1' => $stage1s[0],
        'stage2' => $stage2,
        'message' => 'Đủ dữ liệu so sánh biên tập.',
    ];
}

function editorial_send_for_review(string $articleId, string $userId, string $lockToken): array
{
    $article = editorial_find_article($articleId);
    if (!$article) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết trong danh mục.'];
    }

    $htmlPath = editorial_resolve_article_path($article);
    if (!$htmlPath || !file_exists($htmlPath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy file HTML của bài viết.'];
    }

    $liveHash = editorial_live_hash($htmlPath);
    if (!$liveHash) {
        return ['ok' => false, 'message' => 'Không thể đọc nội dung file HTML gốc.'];
    }

    $state = editorial_get_article_state($articleId);
    if (!$state) {
        return ['ok' => false, 'message' => 'Không tìm thấy trạng thái bài viết.'];
    }

    if ($liveHash !== $state['base_live_hash']) {
        return ['ok' => false, 'message' => 'Cảnh báo: File HTML gốc đã bị thay đổi bên ngoài hệ thống trong quá trình chỉnh sửa.'];
    }

    return editorial_transaction(function() use ($articleId, $userId, $lockToken, $htmlPath) {
        $state = editorial_get_article_state($articleId);
        
        if ($state['assigned_user_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không được phân công chỉnh sửa bài viết này.'];
        }
        
        if (!in_array($state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái cho phép gửi duyệt.'];
        }
        $currentLiveHash = editorial_live_hash($htmlPath);
        if ($currentLiveHash === null || $currentLiveHash !== (string) ($state['base_live_hash'] ?? '')) {
            return ['ok' => false, 'message' => 'File HTML gốc đã thay đổi trong khi chuẩn bị gửi duyệt.'];
        }

        if (!editorial_can_transition($state['status'], 'ready_review')) {
            return ['ok' => false, 'message' => 'Trạng thái chuyển đổi không hợp lệ.'];
        }

        $assignment = editorial_get_active_assignment($articleId);
        if (!$assignment || $assignment['user_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Phân công không hợp lệ hoặc không khớp.'];
        }

        $db = editorial_db();
        $stmtLock = $db->prepare("SELECT * FROM editorial_locks WHERE article_id = :aid");
        $stmtLock->execute([':aid' => $articleId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

        if (!$lockRow || $lockRow['user_id'] !== $userId || $lockRow['lock_token'] !== $lockToken || strtotime($lockRow['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'Khóa chỉnh sửa không hợp lệ hoặc đã hết hạn.'];
        }

        $draft = editorial_get_draft($articleId, $userId);
        if (!$draft) {
            return ['ok' => false, 'message' => 'Không tìm thấy bản nháp.'];
        }

        try {
            $draftHash = editorial_revision_content_hash($draft['payload']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Lỗi khi tạo mã băm cho bản nháp.'];
        }

        $bundle = editorial_resolve_review_submission_stage_bundle(
            $articleId,
            (string) $assignment['id'],
            $draftHash
        );
        if (!$bundle['ok']) {
            return $bundle;
        }
        $revision = $bundle['stage2'];

        $now = date('c');

        $stmtUpdState = $db->prepare("
            UPDATE editorial_article_state
            SET status = 'ready_review',
                review_revision_id = :rev_id,
                review_requested_by = :req_by,
                review_requested_at = :req_at,
                approved_revision_id = NULL,
                approved_by = NULL,
                approved_at = NULL,
                updated_at = :upd
            WHERE article_id = :aid
        ");
        $stmtUpdState->execute([
            ':rev_id' => $revision['id'],
            ':req_by' => $userId,
            ':req_at' => $now,
            ':upd'    => $now,
            ':aid'    => $articleId
        ]);

        $stmtDelLock = $db->prepare("DELETE FROM editorial_locks WHERE article_id = :aid AND user_id = :uid AND lock_token = :token");
        $stmtDelLock->execute([
            ':aid'   => $articleId,
            ':uid'   => $userId,
            ':token' => $lockToken
        ]);

        editorial_log_activity('article.review.submitted', $articleId, $userId, json_encode([
            'revision_id' => $revision['id'],
            'revision_no' => $revision['revision_no'],
            'stage1_revision_id' => $bundle['stage1']['id'],
            'stage1_revision_no' => $bundle['stage1']['revision_no'],
            'assignment_id' => $assignment['id']
        ]));

        return ['ok' => true, 'message' => 'Đã gửi duyệt thành công.'];
    });
}

function editorial_approve_review(string $articleId, string $adminUserId): array
{
    $article = editorial_find_article($articleId);
    if (!$article) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết trong danh mục.'];
    }

    $htmlPath = editorial_resolve_article_path($article);
    if (!$htmlPath || !file_exists($htmlPath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy file HTML của bài viết.'];
    }

    $liveHash = editorial_live_hash($htmlPath);
    if (!$liveHash) {
        return ['ok' => false, 'message' => 'Không thể đọc nội dung file HTML gốc.'];
    }

    $state = editorial_get_article_state($articleId);
    if (!$state) {
        return ['ok' => false, 'message' => 'Không tìm thấy trạng thái bài viết.'];
    }

    if ($liveHash !== $state['base_live_hash']) {
        return ['ok' => false, 'message' => 'Lỗi: File HTML gốc đã bị thay đổi bên ngoài hệ thống. Không thể phê duyệt.'];
    }

    return editorial_transaction(function() use ($articleId, $adminUserId) {
        $state = editorial_get_article_state($articleId);

        if ($state['status'] !== 'ready_review') {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái chờ duyệt.'];
        }

        if (empty($state['review_revision_id'])) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiên bản chờ duyệt.'];
        }

        $revision = editorial_get_revision($state['review_revision_id']);
        if (!$revision || $revision['revision_type'] !== 'editorial'
            || $revision['article_id'] !== $articleId) {
            return ['ok' => false, 'message' => 'Phiên bản duyệt không hợp lệ.'];
        }

        $assignmentStmt = editorial_db()->prepare('
            SELECT * FROM editorial_assignments
            WHERE id = :assignment_id
              AND article_id = :article_id
        ');
        $assignmentStmt->execute([
            'assignment_id' => (string) ($revision['assignment_id'] ?? ''),
            'article_id' => $articleId,
        ]);
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            return ['ok' => false, 'message' => 'Phân công gắn với phiên bản duyệt không hợp lệ.'];
        }

        $snap = editorial_get_verified_revision_snapshot($revision);
        if (!$snap['ok']) {
            return ['ok' => false, 'message' => 'Dữ liệu phiên bản bị lỗi: ' . $snap['message']];
        }

        $now = date('c');
        $db = editorial_db();

        $stmtUpd = $db->prepare("
            UPDATE editorial_article_state
            SET status = 'approved',
                approved_revision_id = :rev_id,
                approved_by = :app_by,
                approved_at = :app_at,
                updated_at = :upd
            WHERE article_id = :aid
        ");
        $stmtUpd->execute([
            ':rev_id' => $state['review_revision_id'],
            ':app_by' => $adminUserId,
            ':app_at' => $now,
            ':upd'    => $now,
            ':aid'    => $articleId
        ]);

        editorial_log_activity('article.review.approved', $articleId, $adminUserId, json_encode([
            'revision_id' => $revision['id'],
            'revision_no' => $revision['revision_no'],
            'editor_user_id' => $assignment['user_id']
        ]));

        return ['ok' => true, 'message' => 'Phê duyệt thành công.'];
    });
}

function editorial_return_review(string $articleId, string $adminUserId, string $note): array
{
    $note = trim($note);
    if ($note === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập lý do trả lại.'];
    }
    if (mb_strlen($note) > 2000) {
        return ['ok' => false, 'message' => 'Lý do trả lại không được vượt quá 2000 ký tự.'];
    }

    return editorial_transaction(function() use ($articleId, $adminUserId, $note) {
        $state = editorial_get_article_state($articleId);
        
        if (!$state || $state['status'] !== 'ready_review') {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái chờ duyệt.'];
        }

        if (!editorial_can_transition($state['status'], 'returned')) {
            return ['ok' => false, 'message' => 'Trạng thái chuyển đổi không hợp lệ.'];
        }

        $now = date('c');
        $db = editorial_db();

        $stmtUpd = $db->prepare("
            UPDATE editorial_article_state
            SET status = 'returned',
                approved_revision_id = NULL,
                approved_by = NULL,
                approved_at = NULL,
                updated_at = :upd
            WHERE article_id = :aid
        ");
        $stmtUpd->execute([
            ':upd' => $now,
            ':aid' => $articleId
        ]);

        editorial_log_activity('article.review.returned', $articleId, $adminUserId, json_encode([
            'revision_id' => $state['review_revision_id'],
            'note' => $note
        ]));

        return ['ok' => true, 'message' => 'Đã trả lại bài viết cho biên tập viên.'];
    });
}

function editorial_review_queue(array $params = []): array
{
    $db = editorial_db();
    $sql = "SELECT * FROM editorial_article_state WHERE status = 'ready_review' ORDER BY review_requested_at ASC";
    
    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    $userIds = [];
    
    foreach ($rows as $row) {
        $userIds[] = $row['assigned_user_id'];
        $userIds[] = $row['review_requested_by'];
    }
    
    $userIds = array_unique(array_filter($userIds));
    $userNames = editorial_preload_user_names($userIds);
    
    $searchQ = isset($params['q']) ? mb_strtolower(trim($params['q'])) : '';

    foreach ($rows as $row) {
        $article = editorial_find_article($row['article_id']);
        if (!$article) continue;
        
        $title = $article['title'] ?? 'N/A';
        
        if ($searchQ !== '') {
            $titleLower = mb_strtolower($title);
            if (mb_strpos($titleLower, $searchQ) === false && mb_strpos(mb_strtolower($row['article_id']), $searchQ) === false) {
                continue;
            }
        }
        
        $hasLiveConflict = true;
        $htmlPath = editorial_resolve_article_path($article);
        if ($htmlPath && file_exists($htmlPath)) {
            $liveHash = editorial_live_hash($htmlPath);
            if ($liveHash === $row['base_live_hash']) {
                $hasLiveConflict = false;
            }
        }
        
        $revisionNo = null;
        if (!empty($row['review_revision_id'])) {
            $rev = editorial_get_revision($row['review_revision_id']);
            if ($rev) {
                $revisionNo = $rev['revision_no'];
            }
        }
        
        $results[] = [
            'article_id' => $row['article_id'],
            'title' => $title,
            'owner_name' => $userNames[$row['assigned_user_id']] ?? 'Unknown',
            'revision_no' => $revisionNo,
            'requested_at' => $row['review_requested_at'],
            'requester_name' => $userNames[$row['review_requested_by']] ?? 'Unknown',
            'has_live_conflict' => $hasLiveConflict
        ];
    }
    
    return $results;
}

/**
 * Check if draft handoff is safe for normal (non-force) reassign/release.
 * A1 fix: fail-closed when draft exists but no valid matching revision.
 *
 * @return array{safe: bool, has_draft: bool, draft_version: ?int, draft_hash: ?string, message: string}
 */
function editorial_check_draft_handoff_safety(string $articleId, string $ownerUserId): array
{
    $draft = editorial_get_draft($articleId, $ownerUserId);
    
    // Case 1: No draft — safe
    if (!$draft) {
        return ['safe' => true, 'has_draft' => false, 'draft_version' => null, 'draft_hash' => null, 'message' => ''];
    }
    
    // Draft exists — must verify it’s fully preserved in an immutable revision
    $draftVersion = (int) ($draft['version'] ?? 0);
    
    try {
        $draftHash = editorial_revision_content_hash($draft['payload']);
    } catch (RuntimeException $e) {
        // Can't compute hash — fail-closed
        return ['safe' => false, 'has_draft' => true, 'draft_version' => $draftVersion, 'draft_hash' => null,
            'message' => 'Bản nháp hiện tại chưa được bảo toàn đầy đủ trong một phiên bản. Hãy tạo phiên bản trước khi thay đổi phân công.'];
    }
    
    $assignment = editorial_get_active_assignment($articleId);
    if (!$assignment || (string) ($assignment['user_id'] ?? '') !== $ownerUserId) {
        return ['safe' => false, 'has_draft' => true, 'draft_version' => $draftVersion, 'draft_hash' => $draftHash,
            'message' => 'Bản nháp hiện tại chưa được bảo toàn đầy đủ trong một phiên bản. Hãy tạo phiên bản trước khi thay đổi phân công.'];
    }

    $stmt = editorial_db()->prepare('
        SELECT * FROM editorial_revisions
        WHERE article_id = :article_id
          AND assignment_id = :assignment_id
          AND revision_type = \'editorial\'
          AND content_hash = :content_hash
        ORDER BY revision_no DESC
    ');
    $stmt->execute([
        'article_id' => $articleId,
        'assignment_id' => (string) $assignment['id'],
        'content_hash' => $draftHash,
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $revision) {
        if (!empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            return ['safe' => true, 'has_draft' => true, 'draft_version' => $draftVersion, 'draft_hash' => $draftHash, 'message' => ''];
        }
    }
    return ['safe' => false, 'has_draft' => true, 'draft_version' => $draftVersion, 'draft_hash' => $draftHash,
        'message' => 'Bản nháp hiện tại chưa được bảo toàn đầy đủ trong một phiên bản. Hãy tạo phiên bản trước khi thay đổi phân công.'];
}

function editorial_reassign_article(string $articleId, string $adminUserId, string $newUserId, bool $force = false): array
{
    $newUser = editorial_find_user_by_id($newUserId);
    if (!$newUser || empty($newUser['is_active']) || !in_array($newUser['role'], ['admin', 'editor'], true)) {
        return ['ok' => false, 'message' => 'Người dùng được phân công không hợp lệ hoặc không có quyền.'];
    }

    $article = editorial_find_article($articleId);
    if (!$article) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết.'];
    }

    $htmlPath = editorial_resolve_article_path($article);
    if (!$htmlPath || !file_exists($htmlPath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy file HTML.'];
    }

    $liveHash = editorial_live_hash($htmlPath);
    if (!$liveHash) {
        return ['ok' => false, 'message' => 'Không thể tạo mã băm file HTML.'];
    }

    return editorial_transaction(function() use ($articleId, $adminUserId, $newUserId, $force, $liveHash) {
        $state = editorial_get_article_state($articleId);
        
        if (!$state || !in_array($state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Chỉ có thể phân công lại khi bài viết đang chỉnh sửa hoặc bị trả lại.'];
        }

        $assignment = editorial_get_active_assignment($articleId);
        if (!$assignment) {
            return ['ok' => false, 'message' => 'Không tìm thấy phân công hiện tại.'];
        }

        if ($assignment['user_id'] === $newUserId) {
            return ['ok' => false, 'message' => 'Người dùng này đã được phân công cho bài viết này.'];
        }

        // A1: Central draft handoff safety check
        $oldOwnerUserId = (string) $assignment['user_id'];
        $handoff = editorial_check_draft_handoff_safety($articleId, $oldOwnerUserId);
        
        if (!$force && !$handoff['safe']) {
            return ['ok' => false, 'message' => $handoff['message']];
        }

        $db = editorial_db();
        $now = date('c');

        $stmtCloseAssign = $db->prepare("
            UPDATE editorial_assignments
            SET released_at = :rel_at, release_reason = 'reassigned'
            WHERE id = :id
        ");
        $stmtCloseAssign->execute([
            ':rel_at' => $now,
            ':id' => $assignment['id']
        ]);

        $db->prepare("DELETE FROM editorial_locks WHERE article_id = :aid")->execute([':aid' => $articleId]);

        $assignId = editorial_generate_id('asg');
        $stmtNewAssign = $db->prepare("
            INSERT INTO editorial_assignments (id, article_id, user_id, assigned_at)
            VALUES (:id, :aid, :uid, :assigned_at)
        ");
        $stmtNewAssign->execute([
            ':id' => $assignId,
            ':aid' => $articleId,
            ':uid' => $newUserId,
            ':assigned_at' => $now
        ]);

        $stmtUpdState = $db->prepare("
            UPDATE editorial_article_state
            SET assigned_user_id = :uid,
                assigned_at = :assigned_at,
                status = 'editing',
                base_live_hash = :hash,
                current_revision_id = NULL,
                review_revision_id = NULL,
                review_requested_by = NULL,
                review_requested_at = NULL,
                approved_revision_id = NULL,
                approved_by = NULL,
                approved_at = NULL,
                updated_at = :upd
            WHERE article_id = :aid
        ");
        $stmtUpdState->execute([
            ':uid' => $newUserId,
            ':assigned_at' => $now,
            ':hash' => $liveHash,
            ':upd' => $now,
            ':aid' => $articleId
        ]);

        // A2+A3+A4: Draft cleanup
        if ($handoff['has_draft']) {
            if ($force && !$handoff['safe']) {
                // A4: Force discard — audit before delete
                editorial_log_activity('article.draft.force_discarded', $articleId, $adminUserId, json_encode([
                    'forced' => true,
                    'discarded_draft' => true,
                    'discarded_draft_version' => $handoff['draft_version'],
                    'discarded_draft_hash' => $handoff['draft_hash'],
                ]));
            }
            // A2+A3: Delete old owner's draft (safe if preserved in revision, or force-discarded)
            $db->prepare('DELETE FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid')
               ->execute([':aid' => $articleId, ':uid' => $oldOwnerUserId]);
        }

        editorial_log_activity('article.assignment.reassigned', $articleId, $adminUserId, json_encode([
            'old_user_id' => $assignment['user_id'],
            'new_user_id' => $newUserId,
            'old_assignment_id' => $assignment['id'],
            'new_assignment_id' => $assignId
        ]));

        return ['ok' => true, 'message' => 'Phân công lại thành công.'];
    });
}

function editorial_release_assignment(string $articleId, string $adminUserId, bool $force = false): array
{
    return editorial_transaction(function() use ($articleId, $adminUserId, $force) {
        $state = editorial_get_article_state($articleId);
        
        if (!$state || !in_array($state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Chỉ có thể thu hồi khi bài viết đang được chỉnh sửa hoặc bị trả lại.'];
        }

        $assignment = editorial_get_active_assignment($articleId);
        if (!$assignment) {
            return ['ok' => false, 'message' => 'Không tìm thấy phân công hiện tại.'];
        }

        // A1: Central draft handoff safety check
        $oldOwnerUserId = (string) $assignment['user_id'];
        $handoff = editorial_check_draft_handoff_safety($articleId, $oldOwnerUserId);
        
        if (!$force && !$handoff['safe']) {
            return ['ok' => false, 'message' => $handoff['message']];
        }

        $db = editorial_db();
        $now = date('c');

        $stmtCloseAssign = $db->prepare("
            UPDATE editorial_assignments
            SET released_at = :rel_at, release_reason = 'admin_release'
            WHERE id = :id
        ");
        $stmtCloseAssign->execute([
            ':rel_at' => $now,
            ':id' => $assignment['id']
        ]);

        $db->prepare("DELETE FROM editorial_locks WHERE article_id = :aid")->execute([':aid' => $articleId]);

        $stmtUpdState = $db->prepare("
            UPDATE editorial_article_state
            SET status = 'available',
                assigned_user_id = NULL,
                assigned_at = NULL,
                base_live_hash = NULL,
                current_revision_id = NULL,
                review_revision_id = NULL,
                review_requested_by = NULL,
                review_requested_at = NULL,
                approved_revision_id = NULL,
                approved_by = NULL,
                approved_at = NULL,
                updated_at = :upd
            WHERE article_id = :aid
        ");
        $stmtUpdState->execute([
            ':upd' => $now,
            ':aid' => $articleId
        ]);

        // A2+A3+A4: Draft cleanup
        if ($handoff['has_draft']) {
            if ($force && !$handoff['safe']) {
                editorial_log_activity('article.draft.force_discarded', $articleId, $adminUserId, json_encode([
                    'forced' => true,
                    'discarded_draft' => true,
                    'discarded_draft_version' => $handoff['draft_version'],
                    'discarded_draft_hash' => $handoff['draft_hash'],
                ]));
            }
            $db->prepare('DELETE FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid')
               ->execute([':aid' => $articleId, ':uid' => $oldOwnerUserId]);
        }

        editorial_log_activity('article.assignment.released', $articleId, $adminUserId, json_encode([
            'old_user_id' => $assignment['user_id'],
            'assignment_id' => $assignment['id']
        ]));

        return ['ok' => true, 'message' => 'Thu hồi phân công thành công.'];
    });
}

function editorial_force_unlock(string $articleId, string $adminUserId): array
{
    $db = editorial_db();
    
    $stmtLock = $db->prepare("SELECT * FROM editorial_locks WHERE article_id = :aid");
    $stmtLock->execute([':aid' => $articleId]);
    $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

    if (!$lockRow) {
        return ['ok' => true, 'message' => 'Bài viết hiện không bị khóa.'];
    }

    $stmtDel = $db->prepare("DELETE FROM editorial_locks WHERE article_id = :aid");
    $stmtDel->execute([':aid' => $articleId]);

    editorial_log_activity('article.lock.force_released', $articleId, $adminUserId, json_encode([
        'previous_lock_user_id' => $lockRow['user_id']
    ]));

    return ['ok' => true, 'message' => 'Đã mở khóa phiên chỉnh sửa thành công.'];
}

function editorial_get_latest_return_note(string $articleId): ?string
{
    $db = editorial_db();
    $stmt = $db->prepare("
        SELECT payload_json 
        FROM editorial_activity 
        WHERE article_id = :aid AND event_type = 'article.review.returned' 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':aid' => $articleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row || empty($row['payload_json'])) {
        return null;
    }
    
    $payload = json_decode($row['payload_json'], true);
    if (is_array($payload) && isset($payload['note'])) {
        return (string)$payload['note'];
    }
    
    return null;
}
