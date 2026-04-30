# Root URL Migration Phase 2 Dry-run

Target: migrate canonical article URLs from `/article/slug.html` to `/slug.html`.

## Tool
- `tools/migrate_article_dir_to_root_urls.py`

Supports:
- dry-run by default
- `--apply --yes`
- rollback from manifest via `--rollback <manifest>`

## Dry-run result
- Fatal: false
- Entries: 2809
- Root HTML files to create: 2809
- Data article rows to update: 2809
- Internal links to rewrite: 6205 links in 1775 files
- Redirect rules to write: 5618
  - `/article/slug.html -> /slug.html`
  - old legacy `/thu-vien|ban-tin/slug.html -> /slug.html`

## Conflict handling
One root conflict was found in Phase 1 and is handled by override:

- `article/lien-he.html` -> `cong-ty-tnhh-tu-van-dao-tao-dieu-tam.html`

This keeps existing root `/lien-he.html` as the contact page.

## Phase 2 status
Dry-run only. No production files moved or rewritten by this phase.

Next phase should apply migration, run full rebuild, archive `/article` and `data/article-views/article`, then verify root URLs.
