# Repository AI Guide

> **Canonical quick context for coding agents.**
> Authority order: **CODE > CANONICAL DOCS > HISTORICAL DOCS**.

## Read first

Read in this order before changing code:

1. `AGENTS.md`
2. `README.md`
3. `docs/ARCHITECTURE.md`
4. `docs/CHANGE_IMPACT_MAP.md` when changing behavior
5. `editorial/README.md` for Editorial V2 work
6. The detailed document and implementation module for the subsystem being changed

For a change in Editorial V2, then read the matching implementation module in
`editorial/includes/`. Do not infer current behavior from old phase documents.

## System authority

- Code is the final authority.
- Canonical docs describe current code and are the next authority.
- `admin/README.md` is legacy/fallback documentation.
- `docs/editor-php/*` is historical design/archive material.

If code and canonical docs disagree:

1. inspect the implementation;
2. do not guess which document is right;
3. change documentation in the same patch as any verified architectural change.

## Non-negotiable invariants

### INV-01 — Public article content

The public article body ultimately lives in the **same original public HTML
article file**. Do not introduce SQLite-backed public article rendering.

### INV-02 — SQLite scope

Editorial SQLite stores workflow/state metadata: users, assignments, locks,
drafts, revisions, activity, settings and handoff state. It is **not** the
public article content store.

### INV-03 — Safe Publish target

Safe Publish writes the same original live HTML article file after validation,
backup and atomic replacement. It does not publish a copied HTML tree.

### INV-04 — One active assignment

An article may have at most one active assignment. Assignment writes must remain
atomic (`BEGIN IMMEDIATE` through `editorial_transaction()`).

### INV-05 — Exact self-claim status

An ordinary self-claim is valid only when state status is exactly `available`.
An empty owner alone never grants claim permission.

### INV-06 — Current ownership authority

`editorial_article_state.assigned_user_id` identifies the current ownership /
assignment authority. It is not by itself sufficient for every write: the
operation also enforces the required workflow status, active-assignment
consistency, role/policy, CSRF and lock/token where applicable. Historical
contributor evidence does not grant edit permission.

### INV-07 — Lock is not assignment

Exiting Workspace releases the temporary lock only. It does not release the
persistent assignment or delete the draft.

### INV-08 — Direct Publish is non-terminal

Editor Direct Publish updates publication facts but retains the active owner,
workflow state, draft and lock semantics required by the direct-publish flow.

### INV-09 — Publication facts survive ownership changes

These fields describe the last live public version and survive claim, release,
reassign and controlled reopen:

- `published_revision_id`
- `published_by`
- `published_at`
- `published_live_hash`
- `publish_backup_path`

Do not confuse these fields with active editorial-cycle pointers.

### INV-10 — Revision immutability

Revision snapshots are immutable JSON evidence. Never modify a snapshot in
place; create a new revision.

### INV-11 — Stage chain

For the current assignment, active milestones are derived from verified revision
chronology:

```text
Baseline → active Stage1 → active Stage2 (optional)
```

The newest verified Stage1 is active. Active Stage2 must be newer than active
Stage1. Recreating Stage1 after Stage2 leaves old Stage2 historical/inactive.

### INV-12 — Compare is read-only

Compare is a GET/read-only view over verified immutable snapshots. It must not
save drafts, create revisions, mutate state or alter live HTML.

### INV-13 — Review evidence

Sending review requires a coherent verified Baseline, Stage1 and Stage2, with
the draft content equal to active Stage2. An optional Editor note is stored in
the revision-scoped `article.review.submitted` activity event, not in the
immutable Stage2 snapshot. Admin review must resolve that note by the exact
`review_revision_id`. Do not weaken or guess this evidence chain.

### INV-14 — Handoff content authority

Google Drive + Sheet Handoff uses only the verified
`published_revision_id`/published snapshot. It must not use Stage1, Stage2,
review, approval, browser state or a live-file fallback as content authority.

### INV-15 — Public-ready requirement

Handoff requires a current `public-ready` marker matching the exact published
revision and published live hash, in addition to publication eligibility.

### INV-16 — Image payload semantics

Body image metadata is semantic HTML inside `prose_html`:

- `img[src|alt|title]`;
- optional `figure.article-image > figcaption`;
- optional plain-text `.article-image-caption` and `.article-image-credit`.

Featured Image payload identity includes:

- `featured_image`;
- `featured_image_alt`;
- `featured_image_title`;
- `featured_image_caption`;
- `featured_image_credit`.

Safe Publish serializes those fields as `image`, `imageAlt`, `imageTitle`,
`imageCaption` and `imageCredit` in live `script#article-meta` and the catalog
record.

These fields naturally participate in draft/revision hashes. Old snapshots may
omit the four optional metadata keys and must remain hash-valid. Image binaries
remain path references and are never copied into revision snapshots.

### INV-17 — Media path contract

New Editorial uploads are stored as site-root-relative paths:

```text
uploads/articles/YYYY/MM/<filename>
```

Do not store media as filesystem absolute paths or move them into
`editorial/storage/`.

`KTDT_IMAGE_PACK` version 1 is an import-only clipboard contract. Its required
shape remains `protocol`, `version`, `article.title`, `featured` and
`inline_images`; inline replacement authority is exact canonical `old_src`
matching against the current TinyMCE DOM. The server may download and persist
validated assets, but only the browser mutates current Draft fields. Image Pack
import must never auto-Save, Stage, Review, Publish or Handoff. Public content
changes still belong to Safe Publish.

When Image Pack replaces an inline image, update it through TinyMCE DOM APIs and
keep `src` and `data-mce-src` synchronized. Save authority is
`tinymce.get('proseEditor').getContent()`; the textarea is only a synchronized
fallback. Publish renders one managed static Featured block marked
`data-editorial-featured="1"` before `#articleTopNav`; public JavaScript hydrates
that block rather than creating a duplicate. A local `uploads/articles/...`
Featured path must exist and remain inside the upload root before Publish.

`article.title` mismatch is warning-only; `article.slug` is not used for Image
Pack identity or eligibility. Missing/duplicate exact `old_src` remains a hard
block. Caption/Credit is best-effort: when legacy HTML cannot safely host a
managed figure, import still replaces the image plus Alt/Title and reports that
Caption/Credit was skipped.

### INV-18 — Taxonomy boundary

Editorial V2 preserves catalog taxonomy and uses public taxonomy artifacts
read-only. Do not turn Editorial into a free-form taxonomy editor.

### INV-19 — Legacy boundary

Editorial V2 must not acquire runtime dependencies on `/admin`. Shared visual
CSS is not a business-logic dependency.

### INV-20 — Static public-site boundary

Do not replace the static public site with dynamic SQLite rendering. Rebuild
updates derived artifacts; it does not change this architecture.

## Sensitive modules: inspect before changing

| Concern | First implementation file |
|---|---|
| Authentication / CSRF / roles | `editorial/includes/auth.php` |
| Catalog, paths and public taxonomy reading | `editorial/includes/article_catalog.php` |
| Claim, ownership and status transition table | `editorial/includes/assignment.php` |
| Lock, draft and workspace payload | `editorial/includes/workspace.php` |
| Snapshots, revisions and stages | `editorial/includes/revision.php` |
| Review, release, reassignment and reopen | `editorial/includes/review.php` |
| Safe Publish | `editorial/includes/publish.php` |
| Publication eligibility | `editorial/includes/publication.php` |
| Public rebuild and ready marker | `editorial/includes/public_rebuild.php` |
| Google handoff | `editorial/includes/handoff.php` |
| Media upload / Image Pack transfer | `editorial/includes/media.php`, `editorial/upload.php` and `editorial/image-pack-import.php` |
| Schema | `editorial/includes/migrations.php` |
| Integrity scanner | `editorial/includes/integrity.php` |

## Change rules

1. Define the source of truth before editing.
2. Preserve server-side authorization; UI visibility is never authority.
3. Keep destructive writes atomic and retain live-hash checks.
4. Do not clear publication facts merely because an editorial cycle changes.
5. Do not create a new Handoff source type without reviewing
   `publication.php` and `handoff.php`.
6. Do not alter generated artifacts by hand when the relevant rebuild path is
   the intended mechanism.
7. Do not add secrets, tokens, passwords, API keys, private account IDs or
   sensitive runtime values to code, docs, logs or commits.
8. Avoid changes to `/admin` unless the task explicitly targets legacy Admin.

## Documentation maintenance rule

Update canonical documentation in the **same change** whenever code changes:

- source-of-truth boundaries;
- ownership or state transitions;
- revision or stage semantics;
- Safe Publish;
- public rebuild or public-ready marker;
- Google Handoff;
- media paths/policy;
- taxonomy authority.

Canonical documents are:

- `README.md`
- `docs/README.md`
- `docs/ARCHITECTURE.md`
- `docs/CHANGE_IMPACT_MAP.md`
- `docs/EDITORIAL_V2.md`
- `docs/PUBLISH_AND_HANDOFF.md`
- `docs/OPERATIONS.md`
- `editorial/README.md`

## Historical-doc warning

`docs/editor-php/*` contains historical editor/design material. It is not
canonical for current Editorial V2 unless a current canonical document links to
a specific file for historical context. In particular, do not reuse its older
claims that database is the public content source of truth or that static HTML
is merely a build artifact.

## Before handoff

Before concluding a code change:

1. inspect `git diff` and `git diff --check`;
2. verify only intended paths changed;
3. run only checks permitted by the current task;
4. state tests not run and why;
5. report risks that require a production/manual check.
