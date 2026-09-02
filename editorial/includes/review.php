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

function editorial_check_draft_revision_sync(string $articleId, string $userId): array
{
    $draft = editorial_get_draft($articleId, $userId);
    if (!$draft) {
        return ['ok' => false, 'message' => 'Không tìm thấy bản nháp.'];
    }

    try {
        $draftHash = editorial_revision_content_hash($draft['payload']);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => 'Lỗi khi tạo mã băm cho bản nháp.'];
    }

    $state = editorial_get_article_state($articleId);
    if (!$state || empty($state['current_revision_id'])) {
        return ['ok' => false, 'message' => 'Không tìm thấy phiên bản hiện tại.'];
    }

    $revision = editorial_get_revision($state['current_revision_id']);
    if (!$revision) {
        return ['ok' => false, 'message' => 'Không tìm thấy chi tiết phiên bản hiện tại.'];
    }

    if ($draftHash !== $revision['content_hash'] || (int)$draft['version'] !== (int)$revision['source_draft_version']) {
        return ['ok' => false, 'message' => 'Bản nháp đã thay đổi sau phiên bản gần nhất. Hãy tạo phiên bản mới trước khi gửi duyệt.'];
    }

    return ['ok' => true, 'revision' => $revision, 'draft' => $draft];
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

    return editorial_transaction(function() use ($articleId, $userId, $lockToken, $liveHash) {
        $state = editorial_get_article_state($articleId);
        
        if ($state['assigned_user_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không được phân công chỉnh sửa bài viết này.'];
        }
        
        if (!in_array($state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái cho phép gửi duyệt.'];
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

        if (empty($state['current_revision_id'])) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiên bản hiện tại để gửi duyệt.'];
        }

        $revision = editorial_get_revision($state['current_revision_id']);
        if (!$revision || $revision['revision_type'] !== 'editorial' || $revision['assignment_id'] !== $assignment['id']) {
            return ['ok' => false, 'message' => 'Phiên bản không hợp lệ để gửi duyệt.'];
        }

        $snap = editorial_get_verified_revision_snapshot($revision);
        if (!$snap['ok']) {
            return ['ok' => false, 'message' => 'Dữ liệu phiên bản bị lỗi: ' . $snap['message']];
        }

        try {
            $draftHash = editorial_revision_content_hash($draft['payload']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Lỗi khi tạo mã băm cho bản nháp.'];
        }

        if ($draftHash !== $revision['content_hash'] || (int)$draft['version'] !== (int)$revision['source_draft_version']) {
            return ['ok' => false, 'message' => 'Bản nháp đã thay đổi sau phiên bản gần nhất. Hãy tạo phiên bản mới trước khi gửi duyệt.'];
        }

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
        if (!$revision || $revision['revision_type'] !== 'editorial' || $revision['article_id'] !== $articleId) {
            return ['ok' => false, 'message' => 'Phiên bản duyệt không hợp lệ.'];
        }

        $assignment = editorial_get_active_assignment($articleId);
        if (!$assignment || $revision['assignment_id'] !== $assignment['id']) {
            return ['ok' => false, 'message' => 'Phân công không hợp lệ hoặc không khớp với phiên bản.'];
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

        if (!$force) {
            $draft = editorial_get_draft($articleId, $assignment['user_id']);
            if ($draft && !empty($state['current_revision_id'])) {
                try {
                    $draftHash = editorial_revision_content_hash($draft['payload']);
                } catch (RuntimeException $e) {
                    return ['ok' => false, 'message' => 'Lỗi khi tạo mã băm cho bản nháp.'];
                }
                
                $revision = editorial_get_revision($state['current_revision_id']);
                if ($revision && $revision['content_hash'] !== $draftHash) {
                    return ['ok' => false, 'message' => 'Biên tập viên hiện tại có thay đổi chưa lưu thành phiên bản. Cần sử dụng tùy chọn ép buộc (force) để tiếp tục.'];
                }
            }
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

        if (!$force) {
            $draft = editorial_get_draft($articleId, $assignment['user_id']);
            if ($draft && !empty($state['current_revision_id'])) {
                try {
                    $draftHash = editorial_revision_content_hash($draft['payload']);
                } catch (RuntimeException $e) {
                    return ['ok' => false, 'message' => 'Lỗi khi tạo mã băm cho bản nháp.'];
                }
                
                $revision = editorial_get_revision($state['current_revision_id']);
                if ($revision && $revision['content_hash'] !== $draftHash) {
                    return ['ok' => false, 'message' => 'Biên tập viên hiện tại có thay đổi chưa lưu thành phiên bản. Cần sử dụng tùy chọn ép buộc (force) để tiếp tục.'];
                }
            }
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
