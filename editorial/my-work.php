<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$currentUser = editorial_current_user();
$currentUserId = (string) $currentUser['user_id'];

// Get articles assigned to current user
$myStates = editorial_get_user_assignments($currentUserId);

// Enrich with article metadata from catalog
$myArticles = [];
foreach ($myStates as $state) {
    $article = editorial_find_article((string) $state['article_id']);
    if ($article !== null) {
        $myArticles[] = [
            'article' => $article,
            'state' => $state,
        ];
    }
}

// Status grouping for display
$statusGroups = [
    'editing' => ['label' => 'Đang biên tập', 'icon' => 'fa-solid fa-pen', 'items' => []],
    'returned' => ['label' => 'Cần chỉnh lại', 'icon' => 'fa-solid fa-rotate-left', 'items' => []],
    'ready_review' => ['label' => 'Chờ duyệt', 'icon' => 'fa-solid fa-clock', 'items' => []],
    'approved' => ['label' => 'Đã duyệt', 'icon' => 'fa-solid fa-circle-check', 'items' => []],
];

foreach ($myArticles as $entry) {
    $status = (string) $entry['state']['status'];
    if (isset($statusGroups[$status])) {
        $statusGroups[$status]['items'][] = $entry;
    } else {
        // Fallback: put in editing
        $statusGroups['editing']['items'][] = $entry;
    }
}

editorial_layout_header([
    'title' => 'Công việc của tôi',
    'active' => 'my-work',
    'description' => 'Các bài viết bạn đang phụ trách biên tập.',
]);
?>

<section class="admin-grid-cards">
    <article class="metric-card">
        <span class="metric-icon"><i class="fa-solid fa-clipboard-list"></i></span>
        <div class="metric-body">
            <h3><?= editorial_h((string) count($myArticles)) ?></h3>
            <p>Tổng bài đang phụ trách</p>
        </div>
    </article>
    <?php foreach ($statusGroups as $key => $group): ?>
        <?php if (!empty($group['items'])): ?>
            <article class="metric-card">
                <span class="metric-icon"><i class="<?= editorial_h($group['icon']) ?>"></i></span>
                <div class="metric-body">
                    <h3><?= editorial_h((string) count($group['items'])) ?></h3>
                    <p><?= editorial_h($group['label']) ?></p>
                </div>
            </article>
        <?php endif; ?>
    <?php endforeach; ?>
</section>

<?php if (empty($myArticles)): ?>
    <section class="admin-panel">
        <div class="empty-state">
            <i class="fa-regular fa-folder-open"></i>
            <p>Bạn chưa nhận biên tập bài nào.</p>
            <p><a href="<?= editorial_h(editorial_url('articles.php?assignment=available')) ?>">
                <i class="fa-solid fa-arrow-right"></i> Đi đến danh sách bài viết để nhận biên tập
            </a></p>
        </div>
    </section>
<?php else: ?>
    <?php foreach ($statusGroups as $statusKey => $group): ?>
        <?php if (empty($group['items'])) continue; ?>
        <section class="admin-panel">
            <div class="panel-head">
                <h2>
                    <i class="<?= editorial_h($group['icon']) ?>"></i>
                    <?= editorial_h($group['label']) ?>
                    <small style="color:#868e96;font-weight:400;">(<?= count($group['items']) ?>)</small>
                </h2>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Bài viết</th>
                            <th>Mục</th>
                            <th>Nhận lúc</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['items'] as $entry): ?>
                            <?php
                            $article = $entry['article'];
                            $state = $entry['state'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= editorial_h($article['title']) ?></strong>
                                    <br><small style="color:#868e96;">
                                        <a href="<?= editorial_h(editorial_public_article_url($article)) ?>" target="_blank" rel="noopener">
                                            <?= editorial_h($article['id']) ?>
                                            <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.7em;"></i>
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($article['section_label']): ?>
                                        <span class="editorial-badge editorial-badge-section"><?= editorial_h($article['section_label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= editorial_h(editorial_format_datetime((string) ($state['assigned_at'] ?? ''))) ?></td>
                                <td>
                                    <span class="editorial-badge editorial-status-<?= editorial_h(editorial_status_css($statusKey)) ?>">
                                        <?= editorial_h(editorial_status_label($statusKey)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php editorial_layout_footer(); ?>
