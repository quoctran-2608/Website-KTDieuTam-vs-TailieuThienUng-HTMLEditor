# Article URL Migration Phase 7 Final Cleanup

## Clean state
- Direct article HTML files under `/thu-vien/*.html` and `/ban-tin/*.html`: 0
- Canonical article files under `/article/*.html`: 2809
- New article view stores under `data/article-views/article`: 5618 files (json + js)
- Legacy article-view dirs removed from site tree: `data/article-views/thu-vien`, `data/article-views/ban-tin`

## Legacy URL handling
Old article URLs are no longer kept as HTML stubs. They are handled by generated redirect rules:
- `_redirects`: 2809 rules
- `.htaccess`: 2809 rules

Archive of removed legacy stubs/views:
- `.m/article-url-migration/20260430T033534Z/phase7-clean-archive`

## Verification
Latest verifier report:
- `docs/article-url-migration-phase7-final-clean-verify.json`
- Status: OK
- Errors: 0

Run:
```bash
python3 tools/verify_article_url_migration.py
```
