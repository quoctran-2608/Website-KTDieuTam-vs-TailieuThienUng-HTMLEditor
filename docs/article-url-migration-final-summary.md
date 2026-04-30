# Article URL Migration Final Summary

## Result
- Canonical article URLs migrated from `/thu-vien/*.html` and `/ban-tin/*.html` to `/article/*.html`.
- Section/category is now metadata-driven, not folder-driven.
- Old direct article URLs remain as static redirect stubs with `noindex,follow`.

## Counts
- Articles in `data/articles.json`: 2809
- New `/article/*.html` files: 2809
- Legacy redirect stubs checked: 2809
- New `data/article-views/article/*.json`: 2809
- Sitemap canonical article URLs: 2809
- Legacy direct article URLs in sitemap: 0
- Old hub pagination URLs in sitemap: 233 (expected: `/thu-vien/trang/*`, `/ban-tin/trang/*`)

## Verification
Run:

```bash
python3 tools/verify_article_url_migration.py
```

Latest report:
- `docs/article-url-migration-phase6-final-verify.json`
- Status: OK, errors: 0

## Rollback
Migration manifest:
- `.m/article-url-migration/20260430T033534Z/manifest.json`
- Copy: `docs/article-url-migration-phase3-apply-manifest.json`

Rollback command:

```bash
python3 tools/migrate_articles_to_article_dir.py --rollback .m/article-url-migration/20260430T033534Z/manifest.json
```

After rollback, rebuild public data if needed.

## Notes
- `data/article-views/thu-vien` and `data/article-views/ban-tin` are legacy tracked files and were intentionally not deleted in this migration.
- Use `docs/article-url-migration-phase5-redirect-map.json` if server-level 301 redirects are added later.
