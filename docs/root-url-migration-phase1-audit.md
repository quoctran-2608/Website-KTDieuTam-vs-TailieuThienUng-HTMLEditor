# Root URL Migration Phase 1 Audit

Target: migrate canonical article URLs from `/article/slug.html` to `/slug.html`.

## Result
- Articles total: 2809
- Current `/article/*.html` entries: 2809
- Missing `/article` files: 0
- Duplicate article slugs: 0
- Existing direct article files in `/thu-vien` + `/ban-tin`: 0
- Current redirect rules old section URL -> `/article`: 2809 in `_redirects` and 2809 in `.htaccess`

## Blocker
There is 1 root path conflict:

- Existing root page: `/lien-he.html` (contact page)
- Article currently at: `/article/lien-he.html`
- Legacy URL: `/ban-tin/lien-he.html`

We must not overwrite the contact page.

Recommended article target slug:

- `/cong-ty-tnhh-tu-van-dao-tao-dieu-tam.html`

This candidate has no root conflict and no article slug conflict.

## Rewrite scope if applied
- `/article/*.html` -> root `/*.html`: 2809 files
- Internal article links to rewrite: 6205 links in 1775 files
- `data/articles.json`, `content-index.js`, `data/hubs/*`, `data/article-views/*`, `sitemap.xml` must be rebuilt/updated
- Redirects should become:
  - old `/thu-vien|ban-tin/slug.html` -> root `/*.html`
  - old `/article/slug.html` -> root `/*.html`

## Phase 1 status
Audit only. No production files moved or rewritten for root URLs.
