'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
  path.join(__dirname, '..', 'editorial', 'article.php'),
  'utf8'
);

function extractFunction(name) {
  const marker = `function ${name}(`;
  const start = source.indexOf(marker);
  if (start < 0) throw new Error(`Missing ${name}`);
  const open = source.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (quote) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === quote) quote = null;
      continue;
    }
    if (char === '"' || char === "'" || char === '`') {
      quote = char;
      continue;
    }
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, index + 1);
    }
  }
  throw new Error(`Unclosed ${name}`);
}

function image(attributes) {
  return {
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(attributes, name)
        ? attributes[name]
        : null;
    }
  };
}

const sandbox = {
  URL,
  Set,
  String,
  decodeURIComponent,
  canonicalArticleOrigin: 'https://ketoandieutam.vn',
  siteBaseUrl: 'https://mettasingingbowl.com/ktdieutam/',
  liveProseImages: [
    {
      src: 'assets/images/content/pic/Service/images/Hoa-don-ban-hang.png',
      id: 'Hoá đơn đã lập khi bán hàng',
      alt: 'Hoá đơn đã lập khi bán hàng',
      title: 'Hoá đơn đã lập khi bán hàng'
    },
    {
      src: 'assets/images/content/pic/Service/images/Hoa-don-giam-gia-hang-ban(2).png',
      id: 'Hoá đơn giảm giá hàng bán',
      alt: 'Hoá đơn giảm giá hàng bán',
      title: 'Hoá đơn giảm giá hàng bán'
    }
  ]
};
vm.createContext(sandbox);
[
  'safeDecodePathname',
  'canonicalImageSrc',
  'imageSourceIdentities',
  'exactLiveAnchorCandidates'
].forEach((name) => vm.runInContext(extractFunction(name), sandbox));

const oldA = 'assets/images/content/pic/Service/images/Hoa-don-ban-hang.png';
const oldB = 'assets/images/content/pic/Service/images/Hoa-don-giam-gia-hang-ban(2).png';
const idA = sandbox.canonicalImageSrc(oldA);
const idB = sandbox.canonicalImageSrc(oldB);

const srcImage = image({ src: oldA });
const mceImage = image({
  src: 'https://mettasingingbowl.com/ktdieutam/uploads/articles/2026/09/a.webp',
  'data-mce-src': oldA
});
const productionImage = image({
  src: 'https://ketoandieutam.vn/assets/images/content/pic/Service/images/Hoa-don-giam-gia-hang-ban(2).png'
});
const markerImage = image({
  src: 'uploads/articles/2026/09/prior-import.webp',
  'data-editorial-original-src': oldA
});

if (!sandbox.imageSourceIdentities(srcImage).has(idA)) {
  throw new Error('Exact src identity did not match.');
}
if (!sandbox.imageSourceIdentities(mceImage).has(idA)) {
  throw new Error('data-mce-src identity did not match.');
}
if (!sandbox.imageSourceIdentities(productionImage).has(idB)) {
  throw new Error('Canonical production origin did not alias to staging site identity.');
}
if (!sandbox.imageSourceIdentities(markerImage).has(idA)) {
  throw new Error('Original source marker did not match a re-import package.');
}

const anchorTarget = image({
  id: 'Hoá đơn đã lập khi bán hàng',
  src: 'uploads/articles/2026/09/draft-changed.webp'
});
const anchorMatches = sandbox.exactLiveAnchorCandidates(
  sandbox.liveProseImages[0],
  [anchorTarget]
);
if (anchorMatches.length !== 1 || anchorMatches[0] !== anchorTarget) {
  throw new Error('Exact unique live id anchor did not resolve Draft image.');
}
const ambiguousAnchor = sandbox.exactLiveAnchorCandidates(
  sandbox.liveProseImages[0],
  [anchorTarget, image({ id: 'Hoá đơn đã lập khi bán hàng', src: 'other.webp' })]
);
if (ambiguousAnchor.length !== 2) {
  throw new Error('Ambiguous live anchor fixture did not remain ambiguous.');
}
const duplicateLiveSandbox = {
  ...sandbox,
  liveProseImages: [
    sandbox.liveProseImages[0],
    { ...sandbox.liveProseImages[0], src: 'assets/images/duplicate-live.png' }
  ]
};
vm.createContext(duplicateLiveSandbox);
[
  'safeDecodePathname',
  'canonicalImageSrc',
  'imageSourceIdentities',
  'exactLiveAnchorCandidates'
].forEach((name) => vm.runInContext(extractFunction(name), duplicateLiveSandbox));
if (duplicateLiveSandbox.exactLiveAnchorCandidates(
  duplicateLiveSandbox.liveProseImages[0],
  [anchorTarget]
).length !== 0) {
  throw new Error('Duplicate live id must not become a stable anchor.');
}

if (source.includes('normalizeCompareSlug')
  || !source.includes('closeAfterSuccess = true')
  || !source.includes('if (closeAfterSuccess)')) {
  throw new Error('Slug-free identity or auto-close success contract is missing.');
}

console.log(
  'editorial image pack identity matching: ok'
  + ' tiers=src,data-mce-src,canonical-origin,original-marker,live-id-anchor'
  + ' production_old_src=2'
);
