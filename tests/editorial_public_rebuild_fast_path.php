<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__);
$sandboxRoot = rtrim(sys_get_temp_dir(), '/\\')
    . '/kdt-editorial-rebuild-' . bin2hex(random_bytes(6));

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

$copyFile = static function (string $relative) use ($sourceRoot, $sandboxRoot): void {
    $source = $sourceRoot . '/' . $relative;
    $target = $sandboxRoot . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Cannot create sandbox directory for ' . $relative);
    }
    if (!copy($source, $target)) {
        throw new RuntimeException('Cannot copy sandbox fixture ' . $relative);
    }
};

foreach ([
    'data/articles.json',
    'content-index.js',
    'data/hubs/thu-vien.json',
    'data/hubs/ban-tin.json',
    'thu-vien/trang/2/index.html',
] as $fixture) {
    $copyFile($fixture);
}
mkdir($sandboxRoot . '/editorial/storage', 0775, true);

const TEST_ARTICLE_ID = 'bai-tap-ke-toan-thue-xuat-nhap-khau-co-loi-giai.html';
const TEST_REVISION_ID = 'rev-fast-path-test';
const TEST_LIVE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

define('EDITORIAL_BASE_PATH', $sandboxRoot . '/editorial');
define('EDITORIAL_STORAGE_PATH', $sandboxRoot . '/editorial/storage');
define('EDITORIAL_ARTICLES_SOURCE', $sandboxRoot . '/data/articles.json');

function editorial_get_article_state(string $articleId): ?array
{
    if ($articleId !== TEST_ARTICLE_ID) {
        return null;
    }
    return [
        'published_revision_id' => TEST_REVISION_ID,
        'published_live_hash' => TEST_LIVE_HASH,
    ];
}

require_once $sourceRoot . '/editorial/includes/public_rebuild.php';

$hubPath = $sandboxRoot . '/data/hubs/thu-vien.json';
$hubBefore = editorial_public_rebuild_read_json($hubPath);
$taxonomyBefore = [
    'libraryKinds' => $hubBefore['libraryKinds'] ?? null,
    'taxonomy' => $hubBefore['taxonomy'] ?? null,
    'taxonomyByKind' => $hubBefore['taxonomyByKind'] ?? null,
];
$result = editorial_public_rebuild_run(TEST_ARTICLE_ID, false);
$failures = [];
if (empty($result['ok'])) {
    $failures[] = 'fast rebuild failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
}
if (($result['rebuild_method'] ?? '') !== 'native') {
    $failures[] = 'publish fast path did not use native rebuild first';
}
if (($result['python_attempt']['code'] ?? '') !== 'not_attempted') {
    $failures[] = 'Python was called before the native publish fast path';
}
if (($result['summary']['taxonomy_refreshed'] ?? null) !== false) {
    $failures[] = 'publish fast path unexpectedly refreshed taxonomy';
}
$hubAfter = editorial_public_rebuild_read_json($hubPath);
$taxonomyAfter = [
    'libraryKinds' => $hubAfter['libraryKinds'] ?? null,
    'taxonomy' => $hubAfter['taxonomy'] ?? null,
    'taxonomyByKind' => $hubAfter['taxonomyByKind'] ?? null,
];
if ($taxonomyAfter !== $taxonomyBefore) {
    $failures[] = 'publish fast path changed canonical hub taxonomy';
}
if (($result['target_image_verification']['static_pages'][0] ?? '')
    !== 'thu-vien/trang/2/index.html') {
    $failures[] = 'nested static card was not verified';
}
$marker = editorial_public_ready_read_marker(TEST_ARTICLE_ID);
if ($marker === null
    || ($marker['published_revision_id'] ?? '') !== TEST_REVISION_ID
    || ($marker['published_live_hash'] ?? '') !== TEST_LIVE_HASH) {
    $failures[] = 'public-ready marker was not written and verified';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "editorial publish fast path integration: ok\n";
