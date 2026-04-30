# Root URL Migration Phase 3 Apply Summary

## Result
Canonical article URLs now use root slugs:

- Before: `/article/slug.html`
- After: `/slug.html`

## Counts
- Root article files: 2809
- Data articles: 2809
- Root article-view stores: 5618 files (`.json` + `.js`)
- Redirect rules: 5618
  - `/article/slug.html -> /slug.html`
  - `/thu-vien|ban-tin/slug.html -> /slug.html`
- Old `/article` directory: archived/removed from site tree
- Old `data/article-views/article` directory: archived/removed from site tree

## Conflict override
The existing contact page `/lien-he.html` was preserved.
The article formerly at `/article/lien-he.html` now lives at:

- `/cong-ty-tnhh-tu-van-dao-tao-dieu-tam.html`

## Manifest and rollback
Apply manifest:

- `.m/root-url-migration/20260430T044428Z/manifest.json`
- copy: `docs/root-url-migration-phase3-apply-manifest.json`

Rollback command:

```bash
python3 tools/migrate_article_dir_to_root_urls.py --rollback .m/root-url-migration/20260430T044428Z/manifest.json
```

Note: after rollback, restore archived `article/` and `data/article-views/article` from:

- `.m/root-url-migration/20260430T044428Z/phase3-clean-archive`

## Verification
Latest verifier report:

- `docs/root-url-migration-phase3-final-verify.json`
- Status: OK
- Errors: 0

Post-apply hardening:

- Updated `article-layout.js` hardcoded dense-table preserve IDs from old `thu-vien/...` article IDs to root article IDs.
- Scanned live HTML `href/src/action/data-href` attributes: no stale `/article/*.html`, `/thu-vien/*.html`, or `/ban-tin/*.html` article links found.
- Admin/public rebuild path now resolves articles from root `href` values in `data/articles.json`; `articleHref` and `legacyHref` remain metadata only for redirects/history.
- Added taxonomy verifier: `tools/verify_admin_public_taxonomy.py`.
- Latest taxonomy report: `docs/admin-public-taxonomy-verify.json` (OK for Thư viện visible depth 3 and Bản tin visible depth 2).

Run:

```bash
python3 tools/verify_root_url_migration.py
python3 tools/verify_admin_public_taxonomy.py
```
