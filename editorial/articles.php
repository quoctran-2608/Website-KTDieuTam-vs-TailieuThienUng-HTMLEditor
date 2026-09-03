<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/review.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$currentUserId = (string) $currentUser['user_id'];

// ─── Handle POST: claim article ─────────────────────────────────

if (editorial_is_post()) {
    editorial_enforce_csrf();

    $adminAction = trim((string) ($_POST['_admin_action'] ?? ''));
    $targetArticleId = trim((string) ($_POST['target_article_id'] ?? ''));

    if ($adminAction !== '' && $targetArticleId !== '' && (($currentUser['role'] ?? '') === 'admin')) {
        if ($adminAction === 'force_unlock') {
            $result = editorial_force_unlock($targetArticleId, $currentUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        } elseif ($adminAction === 'release') {
            $result = editorial_release_assignment($targetArticleId, $currentUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        } elseif ($adminAction === 'force_release') {
            $result = editorial_release_assignment($targetArticleId, $currentUserId, true);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        } elseif ($adminAction === 'reassign') {
            $newUserId = trim((string) ($_POST['new_user_id'] ?? ''));
            $result = editorial_reassign_article($targetArticleId, $currentUserId, $newUserId);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        } elseif ($adminAction === 'force_reassign') {
            $newUserId = trim((string) ($_POST['new_user_id'] ?? ''));
            $result = editorial_reassign_article($targetArticleId, $currentUserId, $newUserId, true);
            editorial_flash_set($result['ok'] ? 'success' : 'danger', $result['message']);
        }

        $returnParams = [];
        foreach (['q', 'section', 'library_kind_key', 'topic_lv1_key', 'topic_lv2_key', 'topic_lv3_key', 'assignment', 'page'] as $key) {
            $val = trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
            if ($val !== '') $returnParams[$key] = $val;
        }
        $returnUrl = editorial_url('articles.php');
        if (!empty($returnParams)) $returnUrl .= '?' . http_build_query($returnParams);
        editorial_redirect($returnUrl);
    }

    $claimArticleId = trim((string) ($_POST['claim_article_id'] ?? ''));
    if ($claimArticleId !== '') {
        // 1. Validate article exists in catalog
        $article = editorial_find_article($claimArticleId);
        if ($article === null) {
            editorial_flash_set('danger', 'Không tìm thấy bài viết.');
        } else {
            // 2. Resolve HTML file safely
            $htmlPath = editorial_resolve_article_path($article);
            if ($htmlPath === null) {
                editorial_flash_set('danger', 'Không thể nhận biên tập vì không đọc được file HTML gốc.');
            } else {
                // 3. Compute live hash
                $liveHash = editorial_live_hash($htmlPath);
                if ($liveHash === null) {
                    editorial_flash_set('danger', 'Không thể nhận biên tập vì không đọc được file HTML gốc.');
                } else {
                    // 4. Atomic claim
                    $result = editorial_claim_article($claimArticleId, $currentUserId, $htmlPath, $liveHash);
                    editorial_flash_set($result['ok'] ? 'success' : 'warning', $result['message']);
                }
            }
        }
    }

    // PRG: rebuild current filter URL and redirect
    $returnParams = [];
    foreach (['q', 'section', 'library_kind_key', 'topic_lv1_key', 'topic_lv2_key', 'topic_lv3_key', 'assignment', 'page'] as $key) {
        $val = trim((string) ($_POST[$key] ?? ''));
        if ($val !== '') $returnParams[$key] = $val;
    }
    $returnUrl = editorial_url('articles.php');
    if (!empty($returnParams)) {
        $returnUrl .= '?' . http_build_query($returnParams);
    }
    editorial_redirect($returnUrl);
}

// ─── Filters ────────────────────────────────────────────────────

$filters = editorial_taxonomy_filter_params($_GET);
$q = $filters['q'];
$section = $filters['section'];
$assignment = $filters['assignment'];
$page = max(1, (int) ($_GET['page'] ?? 1));

// Load states for assignment filter — need all assigned article IDs
$db = editorial_db();
$allStates = [];
$stateRows = $db->query('SELECT * FROM editorial_article_state WHERE assigned_user_id IS NOT NULL')->fetchAll();
foreach ($stateRows as $row) {
    $allStates[(string) $row['article_id']] = $row;
}

// Filter and paginate
$result = editorial_filter_articles(array_merge($filters, [
    'page' => $page,
    'per_page' => 30,
]), $allStates, $currentUserId);

$items = $result['items'];
$total = $result['total'];
$totalPages = $result['total_pages'];
$currentPage = $result['page'];

// Batch load states for displayed articles only
$displayedIds = array_map(fn($a) => $a['id'], $items);
$pageStates = editorial_get_states_for_articles($displayedIds);

// Preload owner names
$ownerIds = [];
foreach ($pageStates as $s) {
    if (!empty($s['assigned_user_id'])) $ownerIds[] = (string) $s['assigned_user_id'];
}
$ownerNames = editorial_preload_user_names($ownerIds);

// Sections for filter dropdown
$sections = editorial_article_sections();
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$activeUsers = $isAdmin ? editorial_list_users() : [];
$activeUsers = array_filter($activeUsers, fn($u) => !empty($u['is_active']));

// Build filter query string helper
$filterParams = array_filter($filters, static fn(string $value): bool => $value !== '');
$sidebarTreeHtml = editorial_render_taxonomy_tree($filters, 'articles.php');

// ─── Render ─────────────────────────────────────────────────────

editorial_layout_header([
    'title' => 'Bài viết',
    'active' => 'articles',
    'description' => 'Danh sách bài viết — nhận biên tập bài chưa có người phụ trách.',
    'sidebar_extra_html' => $sidebarTreeHtml,
    'sidebar_note' => 'Cây phân loại chỉ đọc',
]);
?>

<!-- Filters -->
<section class="editorial-filter-bar">
    <form method="get" action="<?= editorial_h(editorial_url('articles.php')) ?>" class="editorial-filter-form">
        <div class="editorial-filter-row">
            <div class="field-input editorial-filter-search">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="q" value="<?= editorial_h($q) ?>" placeholder="Tìm theo tiêu đề, từ khóa…">
            </div>

            <select name="section" class="editorial-filter-select">
                <option value="">Tất cả mục</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= editorial_h($sec['key']) ?>" <?= $section === $sec['key'] ? 'selected' : '' ?>>
                        <?= editorial_h($sec['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="library_kind_key" value="<?= editorial_h($filters['library_kind_key']) ?>">
            <input type="hidden" name="topic_lv1_key" value="<?= editorial_h($filters['topic_lv1_key']) ?>">
            <input type="hidden" name="topic_lv2_key" value="<?= editorial_h($filters['topic_lv2_key']) ?>">
            <input type="hidden" name="topic_lv3_key" value="<?= editorial_h($filters['topic_lv3_key']) ?>">

            <select name="assignment" class="editorial-filter-select">
                <option value="" <?= $assignment === '' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                <option value="available" <?= $assignment === 'available' ? 'selected' : '' ?>>Chưa có người nhận</option>
                <option value="assigned" <?= $assignment === 'assigned' ? 'selected' : '' ?>>Đang được phụ trách</option>
                <option value="mine" <?= $assignment === 'mine' ? 'selected' : '' ?>>Của tôi</option>
            </select>

            <button type="submit" class="editorial-filter-btn"><i class="fa-solid fa-filter"></i> Lọc</button>

            <?php if (!empty($filterParams)): ?>
                <a href="<?= editorial_h(editorial_url('articles.php')) ?>" class="editorial-filter-clear">Xóa bộ lọc</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Results info -->
<p class="editorial-result-count">
    Hiển thị <?= editorial_h((string) count($items)) ?> / <?= editorial_h((string) $total) ?> bài viết
    <?php if ($totalPages > 1): ?>
        — Trang <?= $currentPage ?>/<?= $totalPages ?>
    <?php endif; ?>
</p>

<!-- Article list -->
<section class="admin-panel">
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <i class="fa-regular fa-folder-open"></i>
            <p>Không tìm thấy bài viết phù hợp.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Bài viết</th>
                        <th>Mục</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $article): ?>
                        <?php
                        $aid = $article['id'];
                        $state = $pageStates[$aid] ?? null;
                        $status = $state ? (string) $state['status'] : 'available';
                        $ownerId = $state ? (string) ($state['assigned_user_id'] ?? '') : '';
                        $ownerName = $ownerId !== '' ? ($ownerNames[$ownerId] ?? 'Không rõ') : '';
                        $isMe = ($ownerId === $currentUserId);
                        ?>
                        <tr>
                            <td>
                                <strong><?= editorial_h($article['title']) ?></strong>
                                <br><small style="color:#868e96;">
                                    <a href="<?= editorial_h(editorial_public_article_url($article)) ?>" target="_blank" rel="noopener" title="Xem bài trên website">
                                        <?= editorial_h($article['id']) ?>
                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.7em;"></i>
                                    </a>
                                </small>
                            </td>
                            <td>
                                <?php if ($article['section_label']): ?>
                                    <span class="editorial-badge editorial-badge-section"><?= editorial_h($article['section_label']) ?></span>
                                <?php endif; ?>
                                <?php if ($article['topic_lv1_label']): ?>
                                    <br><small style="color:#868e96;"><?= editorial_h($article['topic_lv1_label']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($status)) ?>">
                                    <?= editorial_h(editorial_status_label($status)) ?>
                                </span>
                                <?php if ($isMe): ?>
                                    <br><small class="editorial-owner-me"><i class="fa-solid fa-user"></i> Bài của tôi</small>
                                <?php elseif ($ownerName !== ''): ?>
                                    <br><small class="editorial-owner-other"><i class="fa-solid fa-user"></i> <?= editorial_h($ownerName) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ownerId === ''): ?>
                                    <form method="post" action="<?= editorial_h(editorial_url('articles.php')) ?>" style="display:inline;">
                                        <?= editorial_csrf_input() ?>
                                        <input type="hidden" name="claim_article_id" value="<?= editorial_h($aid) ?>">
                                        <?php foreach ($filterParams as $k => $v): ?>
                                            <input type="hidden" name="<?= editorial_h($k) ?>" value="<?= editorial_h($v) ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="page" value="<?= $currentPage ?>">
                                        <button type="submit" class="editorial-claim-btn" title="Nhận biên tập bài này">
                                            <i class="fa-solid fa-hand"></i> Nhận biên tập
                                        </button>
                                    </form>
                                <?php elseif ($isMe): ?>
                                    <?php if (in_array($status, ['editing', 'returned'], true)): ?>
                                        <a href="<?= editorial_h(editorial_url('article.php?id=' . urlencode($aid))) ?>" class="editorial-workspace-btn">
                                            <i class="fa-solid fa-pen-to-square"></i> Tiếp tục biên tập
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= editorial_h(editorial_url('my-work.php')) ?>" class="editorial-mywork-link">
                                            <i class="fa-solid fa-clipboard-list"></i> Công việc của tôi
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#868e96;">—</span>
                                <?php endif; ?>
                                <?php if ($isAdmin && $ownerId !== ''): ?>
                                    <div class="editorial-admin-actions" style="margin-top:4px;">
                                        <?php if (in_array($status, ['editing', 'returned'], true)): ?>
                                            <form method="post" style="display:inline;">
                                                <?= editorial_csrf_input() ?>
                                                <input type="hidden" name="_admin_action" value="release">
                                                <input type="hidden" name="target_article_id" value="<?= editorial_h($aid) ?>">
                                                <?php foreach ($filterParams as $k => $v): ?><input type="hidden" name="<?= editorial_h($k) ?>" value="<?= editorial_h($v) ?>"><?php endforeach; ?>
                                                <button type="submit" class="editorial-admin-btn" onclick="return confirm('Giải phóng bài viết này?');" title="Giải phóng">
                                                    <i class="fa-solid fa-unlock"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <nav class="editorial-pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php
            $pageParams = $filterParams;
            $pageParams['page'] = (string) $p;
            $href = editorial_url('articles.php') . '?' . http_build_query($pageParams);
            ?>
            <a href="<?= editorial_h($href) ?>" class="editorial-page-link <?= $p === $currentPage ? 'is-active' : '' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

<?php editorial_layout_footer(); ?>
