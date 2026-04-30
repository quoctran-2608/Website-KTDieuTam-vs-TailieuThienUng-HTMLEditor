# Taxonomy admin Phase 1 audit

Date: 2026-04-30

## Scope

Audit the current taxonomy/category model before building admin add/edit/delete/rename features.
No taxonomy data or public UI was changed in this phase.

## Current source files

- `data/taxonomy.json` — public/admin category tree, 60,498 bytes.
- `data/editor-taxonomy.json` — editor-facing tree mirror, 60,583 bytes.
- `data/articles.json` — 2,809 article records, 4,963,457 bytes.
- `data/hubs/thu-vien.json` — public Thư viện hub data.
- `data/hubs/ban-tin.json` — public Bản tin hub data.
- `admin/article.php` — article editor reads `data/taxonomy.json` directly.
- `admin/includes/article_publish.php` — publish-time native PHP public rebuild fallback.
- `tools/import_stage1_20.py` / `tools/rebuild_public_from_articles.py` — Python rebuild path.

## Taxonomy shape

Current node count: 304.

| Section | Depth | Meaning | Nodes |
| --- | ---: | --- | ---: |
| `thu-vien` | 1 | section root | 1 |
| `thu-vien` | 2 | library kind | 4 |
| `thu-vien` | 3 | topicLv1 | 16 |
| `thu-vien` | 4 | topicLv2 | 65 |
| `thu-vien` | 5 | hidden/internal topicLv3 | 205 |
| `ban-tin` | 1 | section root | 1 |
| `ban-tin` | 2 | topicLv1 | 4 |
| `ban-tin` | 3 | topicLv2 | 7 |
| `ban-tin` | 4 | hidden/internal topicLv3 | 1 |

Article counts:

- Thư viện: 2,694 articles.
- Bản tin: 115 articles.

Structural audit:

- Duplicate sibling keys: 0.
- Invalid article taxonomy references: 0.
- Label conflicts for same key/section/depth: 0.
- Baseline verifiers passed:
  - `tools/verify_root_url_migration.py`
  - `tools/verify_admin_public_taxonomy.py`
  - `tools/verify_release_hygiene.py`

## Important implementation facts

1. `admin/article.php` reads `data/taxonomy.json` for editor category validation (`read_article_editor_taxonomy_payload`, lines 48-68).
2. The current article editor intentionally exposes only public-visible levels:
   - Thư viện: Cấp 1 = `libraryKind`, Cấp 2 = `topicLv1`, Cấp 3 = `topicLv2`.
   - Bản tin: Cấp 1 = `topicLv1`, Cấp 2 = `topicLv2`.
   - `topicLv3` is preserved only when the visible path does not change (`admin/article.php`, lines 196-214).
3. Current rebuild code derives category trees from `data/articles.json`:
   - Python build: `tools/import_stage1_20.py`, lines 622-657 and 749-823.
   - PHP fallback build: `admin/includes/article_publish.php`, lines 639-700 and 933-1004.
4. Library kind metadata is hard-coded in PHP (`admin/includes/article_publish.php`, lines 516-540) and Python (`LIBRARY_KIND_*` constants in `tools/import_stage1_20.py`).

## Main risk found

The current taxonomy is effectively derived from article assignments. This means a newly added empty category can be lost on the next rebuild unless we introduce a stable editable source of truth or merge manual nodes back into generated artifacts.

Therefore, the admin taxonomy feature must not simply edit `data/taxonomy.json` and stop. It needs a managed taxonomy source and rebuild contract.

## Recommended contract for Phase 2

Create a canonical editable file:

- `data/taxonomy-master.json`

Role:

- Stores admin-managed labels, keys, order, descriptions/icons where applicable, hidden/archived flags, and manual zero-count nodes.
- `data/taxonomy.json`, `data/editor-taxonomy.json`, hub files, `content-index.js`, and article HTML are derived artifacts.

Phase 2 CLI should provide safe operations:

- `verify`
- `usage --path <path>`
- `rename-label --path <path> --label <label>`
- `add-node --parent <path> --key <key> --label <label>`
- `edit-node --path <path> ...`
- `delete-node --path <path> --mode empty|reassign|merge|archive`
- `merge-node --from <path> --to <path>`

Every mutating operation must support:

- `--dry-run` default.
- `--apply` explicit.
- Backup under `.m/taxonomy-admin/<timestamp>/`.
- Manifest with affected article count and file list.
- Validation after apply.

## Operation safety rules

1. Rename label only:
   - Keep key/path stable.
   - Update all matching article label fields.
   - Update article HTML visible labels/meta where needed.
   - Rebuild derived public artifacts.

2. Add node:
   - Permit zero article usage.
   - Persist in `taxonomy-master.json`.
   - Rebuild public/editor taxonomy with `count: 0`.

3. Delete node:
   - Block if usage > 0 unless admin chooses `merge`, `reassign`, or `archive`.
   - Archive should hide from selectors/public tree but preserve legacy article labels until reassigned.

4. Change key or move node:
   - Treat as high-impact.
   - Must update article key fields, labels, card labels when derived, and article HTML.
   - Must preview affected article count.

5. Root sections:
   - `thu-vien` and `ban-tin` are system roots and should not be deleted.

6. Thư viện library kinds:
   - Existing top kinds are system-level because public UI has icons/descriptions.
   - Allow label/description/icon edit later, but do not allow deleting these four in first UI pass.

## Phase 2 deliverable

Build CLI core first:

- `tools/manage_taxonomy.py`
- `tools/verify_taxonomy_master.py`
- Initial `data/taxonomy-master.json` bootstrapped from current `data/taxonomy.json`
- Dry-run and apply support for `verify`, `usage`, `rename-label`, and `add-node`

Do not build the admin UI until CLI operations are replayable and verified.
