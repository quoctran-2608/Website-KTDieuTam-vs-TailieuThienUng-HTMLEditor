<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/editorial/includes/workspace.php';
require_once dirname(__DIR__) . '/editorial/includes/publish.php';

$articleId = 'cac-doi-tuong-chiu-thue-tieu-thu-dac-biet.html';
$articlePath = dirname(__DIR__) . '/' . $articleId;
$catalogPath = dirname(__DIR__) . '/data/articles.json';
$liveHtml = file_get_contents($articlePath);
$catalog = json_decode((string) file_get_contents($catalogPath), true);
if ($liveHtml === false || !is_array($catalog)) {
    fwrite(STDERR, "Cannot load Featured Publish fixtures.\n");
    exit(1);
}
$article = null;
foreach ($catalog as $item) {
    if (($item['id'] ?? '') === $articleId) {
        $article = $item;
        break;
    }
}
if (!is_array($article)) {
    fwrite(STDERR, "Featured Publish fixture article missing.\n");
    exit(1);
}

$assetDir = dirname(__DIR__) . '/uploads/articles/.image-pack-test-' . bin2hex(random_bytes(5));
if (!mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
    throw new RuntimeException('Cannot create temporary upload fixture.');
}
$assetPath = $assetDir . '/featured.webp';
file_put_contents($assetPath, 'image-pack-featured-fixture');
$publicPath = substr($assetPath, strlen(dirname(__DIR__)) + 1);
$publicPath = str_replace('\\', '/', $publicPath);
register_shutdown_function(static function () use ($assetPath, $assetDir): void {
    @unlink($assetPath);
    @rmdir($assetDir);
});

$parsed = editorial_parse_article_html($liveHtml, $articlePath);
$payload = editorial_build_initial_payload($parsed, $article, $parsed['meta_payload'] ?? []);
$payload['featured_image'] = $publicPath;
$payload['featured_image_alt'] = 'Featured Image Pack alt';
$payload['featured_image_title'] = 'Featured Image Pack title';
$payload['featured_image_caption'] = 'Featured Image Pack caption';
$payload['featured_image_credit'] = 'Nguồn: Featured Image Pack credit';

$normalized = editorial_normalize_publish_payload(
    $payload,
    $parsed['meta_payload'] ?? [],
    $article
);
$rendered = editorial_render_approved_html($liveHtml, $article, $normalized, false);
$validation = !empty($rendered['ok'])
    ? editorial_validate_rendered_html((string) $rendered['html'], $normalized)
    : ['ok' => false, 'message' => $rendered['message'] ?? 'render failed'];
$renderedParsed = !empty($rendered['ok'])
    ? editorial_parse_article_html((string) $rendered['html'], '')
    : [];
$catalogResult = editorial_apply_normalized_article_to_catalog(
    [$article],
    $articleId,
    $normalized
);
$catalogArticle = $catalogResult['catalog'][0] ?? [];
$rebuildArticle = editorial_public_rebuild_article_map($catalogArticle);

$failures = [];
if (($payload['featured_image'] ?? '') !== $publicPath) {
    $failures[] = 'Draft Featured path changed before Publish normalization.';
}
if (($normalized['image'] ?? '') !== $publicPath) {
    $failures[] = 'Normalized Publish image did not preserve Image Pack public_path.';
}
if (empty($rendered['ok']) || empty($validation['ok'])) {
    $failures[] = 'Featured managed render/validation failed: '
        . json_encode([$rendered, $validation], JSON_UNESCAPED_UNICODE);
}
if (($renderedParsed['meta_payload']['image'] ?? '') !== $publicPath) {
    $failures[] = 'Rendered article-meta.image did not preserve Image Pack public_path.';
}
if (($catalogArticle['image'] ?? '') !== $publicPath) {
    $failures[] = 'Catalog image did not preserve Image Pack public_path.';
}
if (($rebuildArticle['image'] ?? '') !== $publicPath) {
    $failures[] = 'Rebuild/index image did not preserve Image Pack public_path.';
}
$managedPattern = '#<figure\b[^>]*data-editorial-featured=(["\'])1\1[^>]*>.*?</figure>#is';
preg_match_all($managedPattern, (string) ($rendered['html'] ?? ''), $managedMatches);
$managed = (string) ($managedMatches[0][0] ?? '');
if (count($managedMatches[0]) !== 1
    || !str_contains($managed, 'src="' . $publicPath . '"')
    || !str_contains($managed, 'alt="Featured Image Pack alt"')
    || !str_contains($managed, 'title="Featured Image Pack title"')
    || !str_contains($managed, 'Featured Image Pack caption')
    || !str_contains($managed, 'Nguồn: Featured Image Pack credit')) {
    $failures[] = 'Managed public Featured markup did not preserve path/metadata.';
}
$managedOffset = strpos((string) ($rendered['html'] ?? ''), 'data-editorial-featured="1"');
$topNavOffset = strpos((string) ($rendered['html'] ?? ''), 'id="articleTopNav"');
if ($managedOffset === false || $topNavOffset === false || $managedOffset >= $topNavOffset) {
    $failures[] = 'Managed Featured markup is not before #articleTopNav.';
}

$missingAsset = editorial_publish_validate_featured_image_asset(
    'uploads/articles/.image-pack-test-missing/featured.webp'
);
if (!empty($missingAsset['ok'])) {
    $failures[] = 'Missing local Featured asset did not block Publish.';
}
$externalAsset = editorial_publish_validate_featured_image_asset(
    'https://cdn.example.com/uploads/articles/featured.webp'
);
if (empty($externalAsset['ok'])) {
    $failures[] = 'Existing external Featured URL support regressed.';
}
$traversalAsset = editorial_publish_validate_featured_image_asset(
    '../uploads/articles/featured.webp'
);
if (!empty($traversalAsset['ok'])) {
    $failures[] = 'Traversal Featured path was not blocked.';
}

$clearPayload = $payload;
$clearPayload['featured_image'] = '';
$cleared = editorial_normalize_publish_payload(
    $clearPayload,
    array_merge($parsed['meta_payload'] ?? [], ['image' => $publicPath]),
    array_merge($article, ['image' => $publicPath])
);
$clearedRender = editorial_render_approved_html(
    (string) ($rendered['html'] ?? $liveHtml),
    $article,
    $cleared,
    false
);
if (($cleared['image'] ?? 'not-cleared') !== ''
    || empty($clearedRender['ok'])
    || preg_match($managedPattern, (string) ($clearedRender['html'] ?? '')) === 1) {
    $failures[] = 'Explicit Featured clear did not remove the managed block.';
}

$layoutSource = file_get_contents(dirname(__DIR__) . '/article-layout.js');
if ($layoutSource === false
    || !str_contains($layoutSource, 'querySelector(\'figure[data-editorial-featured="1"]\')')
    || !str_contains($layoutSource, "console.error('Featured Image failed to load:', image.src)")
    || str_contains($layoutSource, "var existing = document.querySelector('.article-featured-media');")) {
    $failures[] = 'Public runtime does not hydrate the managed Featured block safely.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'editorial image pack featured publish: ok'
    . ' public_path=' . $publicPath
    . ' article_meta=' . ($renderedParsed['meta_payload']['image'] ?? '')
    . ' catalog=' . ($catalogArticle['image'] ?? '')
    . ' rebuild=' . ($rebuildArticle['image'] ?? '')
    . PHP_EOL;
