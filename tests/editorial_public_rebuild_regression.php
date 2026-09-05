<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/editorial/includes/public_rebuild.php';

$failures = [];
$expectSame = static function (string $label, string $expected, string $actual) use (&$failures): void {
    if (!hash_equals($expected, $actual)) {
        $failures[] = $label . ': expected [' . $expected . '] but got [' . $actual . ']';
    }
};

$siteImage = 'uploads/articles/2026/09/test-image.jpg';
$expectSame(
    'site-root identity must not gain a leading slash',
    $siteImage,
    editorial_public_rebuild_normalize_static_asset($siteImage, '')
);
$expectSame(
    'nested static path must resolve to the same site-root identity',
    $siteImage,
    editorial_public_rebuild_normalize_static_asset(
        '../../../' . $siteImage,
        'thu-vien/trang/2/index.html'
    )
);
$expectSame(
    'same-site absolute URL must use the same site-root identity',
    $siteImage,
    editorial_public_rebuild_normalize_static_asset(
        'https://ketoandieutam.com/' . $siteImage,
        ''
    )
);

foreach ([
    ['page' => 'thu-vien.html', 'root' => ''],
    ['page' => 'thu-vien/trang/2/index.html', 'root' => '../../../'],
] as $case) {
    $articleId = 'probe.html';
    $html = '<body data-root="' . $case['root'] . '">'
        . '<article class="catalog-card">'
        . '<a class="catalog-card__media" href="' . $case['root'] . $articleId . '?from=test">'
        . '<img src="' . $case['root'] . 'old.jpg" alt="old">'
        . '</a></article></body>';
    $patched = editorial_public_rebuild_patch_card_html(
        $html,
        $articleId,
        $siteImage,
        'Ảnh kiểm thử'
    );
    if (empty($patched['ok']) || empty($patched['matched'])) {
        $failures[] = 'card patch failed for ' . $case['page'];
        continue;
    }
    if (preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/i', (string) $patched['html'], $match) !== 1) {
        $failures[] = 'patched image src missing for ' . $case['page'];
        continue;
    }
    $actualIdentity = editorial_public_rebuild_normalize_static_asset(
        html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $case['page']
    );
    $expectSame('patched card identity for ' . $case['page'], $siteImage, $actualIdentity);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "editorial public rebuild regression tests: ok\n";
