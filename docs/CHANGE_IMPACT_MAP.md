# Change Impact Map — AI / Developer Guide

> **Canonical — current.** Tài liệu này trả lời: **“Trước khi đổi X, cần mở
> file nào và có thể ảnh hưởng tới đâu?”** Nó không thay thế implementation.
>
> Authority order: **CURRENT CODE → CANONICAL DOCS → HISTORICAL DOCS**. Nếu
> code khác tài liệu, inspect code trước rồi cập nhật canonical docs cùng thay đổi.

## Cách dùng

1. Xác định hành vi người dùng muốn đổi, không bắt đầu từ tên file.
2. Tìm task trong bảng Feature → File.
3. Mở **Start here** trước, rồi đọc các file ở **Also inspect** theo blast
   radius.
4. Đọc invariant được nêu và xác định source/state/evidence liên quan.
5. Chọn file set nhỏ nhất. UI không phải authority.

Tài liệu chi tiết:

- [Architecture](ARCHITECTURE.md)
- [Editorial V2](EDITORIAL_V2.md)
- [Publish / Rebuild / Handoff](PUBLISH_AND_HANDOFF.md)
- [Operations](OPERATIONS.md)
- [AI invariants](../AGENTS.md)

## Mức rủi ro thay đổi

| Risk | Khi nào dùng | Ý nghĩa |
|---|---|---|
| **LOW** | Presentation-only, không authority server, không persistence/side effect. | Blast radius nhỏ; vẫn kiểm tra UI không vô tình trở thành “security”. |
| **MEDIUM** | Read logic, workflow behavior, persistence không destructive hoặc state presentation. | Có thể ảnh hưởng user flow/state; phải xem cả service authority và route. |
| **HIGH** | Authorization, assignment, concurrency, revision evidence, Publish, filesystem write, rebuild, Google side effect hoặc schema. | Có thể mất dữ liệu, tạo inconsistency hoặc thay nội dung public; đọc full safety contract trước. |

Risk là mức **blast radius kiến trúc**, không phải số dòng code hay độ khó cảm
nhận.

## Quy tắc nền: UI không bao giờ là authority

Ẩn nút **Nhận biên tập** không bảo vệ Claim. Authority là backend:
`editorial_claim_article()` trong
`editorial/includes/assignment.php`.

Tương tự:

- disable/ẩn Publish không thay role, state, assignment, live hash hay lock
  checks trong `editorial/includes/publish.php`;
- disable/ẩn Handoff không thay publication eligibility trong
  `editorial/includes/publication.php` hoặc Handoff service;
- ẩn Reassign không thay Admin verification trong
  `editorial/includes/review.php`.

Khi thay đổi hành vi, luôn xác định server-side authority trước, rồi mới sửa
route/UI/CSS.

## Feature → File master map

| Feature / task | Risk | Start here | Also inspect | Critical invariants / data | Likely docs to update |
|---|---|---|---|---|---|
| Login, session timeout, login lock | HIGH | `editorial/includes/auth.php` | `editorial/login.php`, `bootstrap.php` | Session revalidation; active user; secure cookie; activity log. | `EDITORIAL_V2.md`, `OPERATIONS.md`, `AGENTS.md` |
| Role authorization / CSRF | HIGH | `editorial/includes/auth.php` | Target route (`editorial/*.php`) | UI is not authority; role + CSRF remain server-side. | `EDITORIAL_V2.md`, `CHANGE_IMPACT_MAP.md` |
| Password change/reset | HIGH | `editorial/includes/auth.php` | `change-password.php`, `users.php` | Password policy; must-change flow; do not expose credential values. | `OPERATIONS.md` if runtime policy changes |
| User activation/deactivation | HIGH | `editorial/includes/auth.php` | `users.php`, `assignment.php`, `integrity.php` | Cannot remove last active Admin; inactive users may still appear in workflow state. | `EDITORIAL_V2.md`, `OPERATIONS.md` |
| Article lookup / metadata | MEDIUM | `editorial/includes/article_catalog.php` | `data/articles.json`, `articles.php`, `article.php` | Catalog is metadata authority; not public prose authority. | `ARCHITECTURE.md`, `EDITORIAL_V2.md` |
| Safe article path / live hash | HIGH | `editorial/includes/article_catalog.php` | `workspace.php`, `publish.php`, `integrity.php` | Path containment; live HTML is original public file; hashes are representation-specific. | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md` |
| Public taxonomy read/filter | MEDIUM | `editorial/includes/article_catalog.php` | `data/taxonomy.json`, `public_rebuild.php`, taxonomy tools | Editorial taxonomy is read-only/preserved; distinguish master/source from derived artifact. | `ARCHITECTURE.md`, `EDITORIAL_V2.md` |
| Self Claim | HIGH | `editorial/includes/assignment.php` | `articles.php`, `workspace.php`, `revision.php`, `integrity.php` | Exact `available`; one active assignment; live baseline; atomic transaction. | `AGENTS.md`, `EDITORIAL_V2.md`, this map |
| Current owner / assignment history | HIGH | `editorial/includes/assignment.php` | `workspace.php`, `review.php`, `articles.php` | `assigned_user_id` is ownership pointer; active assignment consistency; contributor is not owner. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Contributor history display/permission | MEDIUM | `editorial/includes/assignment.php` | `handoff.php`, `articles.php` | Successful saved work creates contributor evidence; never grant edit permission from it. | `EDITORIAL_V2.md`, this map |
| Admin Release | HIGH | `editorial/includes/review.php` | `assignment.php`, `workspace.php`, `articles.php`, `integrity.php` | Admin-only; draft handoff safety; release lock not assignment semantics; preserve publication facts. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Admin Reassign | HIGH | `editorial/includes/review.php` | `workspace.php`, `articles.php`, `revision.php`, `integrity.php` | Admin-only; active assignment; draft safety; new owner baseline; no published-fact reset. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Published → Open & assign | HIGH | `editorial/includes/review.php` | `assignment.php`, `articles.php`, `publication.php` | Admin-only; live hash revalidation; no active assignment; preserve `published_*`. | `AGENTS.md`, `EDITORIAL_V2.md`, `PUBLISH_AND_HANDOFF.md` |
| Published → Open for team | HIGH | `editorial/includes/review.php` | `assignment.php`, `articles.php`, `integrity.php` | Admin-only; status becomes `available`; future self-claim still exact available only. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Workspace lock / heartbeat | HIGH | `editorial/includes/workspace.php` | `article.php`, `lock-heartbeat.php`, `upload.php` | Current owner + allowed status + token + expiry; lock is temporary. | `EDITORIAL_V2.md`, `OPERATIONS.md` |
| Draft save / optimistic concurrency | HIGH | `editorial/includes/workspace.php` | `article.php`, `revision.php`, `database.php` | Owner, state, active assignment, lock, draft version and expected hash; no silent overwrite. | `EDITORIAL_V2.md`, this map |
| Content payload fields | MEDIUM | `editorial/includes/workspace.php` | `article.php`, `publish.php`, `revision.php` | `title`, `excerpt`, `prose_html`, dates, tags, `featured_image`, `featured_image_alt`, `featured_image_title`, `featured_image_caption`, `featured_image_credit`; taxonomy preserved from catalog. | `EDITORIAL_V2.md`, `PUBLISH_AND_HANDOFF.md` |
| Baseline / editorial revision | HIGH | `editorial/includes/revision.php` | `workspace.php`, `assignment.php`, `revisions.php` | Snapshot immutable; `content_hash`; `assignment_id`; `source_draft_version`. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Stage1 / Stage2 | HIGH | `editorial/includes/revision.php` | `article.php`, `compare.php`, `review.php` | Newest verified Stage1; Stage2 newer than active Stage1; Stage1 recreation deactivates old Stage2 chain. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Compare UI / snapshot preview | MEDIUM | `editorial/compare.php` | `revision.php`, `workspace.php` | Read-only GET; snapshot authority; live HTML only presentation context. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Send Review | HIGH | `editorial/includes/review.php` | `revision.php`, `workspace.php`, `article.php`, `editorial_activity` | Verified Baseline + Stage1 + Stage2; draft equals active Stage2; current owner/lock; optional note stored in revision-scoped submission event. | `AGENTS.md`, `EDITORIAL_V2.md` |
| Return / Approve review | HIGH | `editorial/includes/review.php` | `editorial/review.php`, `compare.php`, `assignment.php`, `publish.php` | Admin-only; `review_revision_id` is dossier authority; resolve matching submission note; keep both baseline comparisons visible. | `EDITORIAL_V2.md`, this map |
| Same-owner resume after approval | HIGH | `editorial/includes/assignment.php` | `resume-editing.php`, `review.php`, `workspace.php` | Same current owner + active assignment + `approved → editing`. | `EDITORIAL_V2.md` |
| Editor Direct Publish | HIGH | `editorial/includes/publish.php` | `revision.php`, `workspace.php`, `article.php`, `public_rebuild.php` | Saved server-side immutable candidate; draft freshness; owner/lock; non-terminal workflow. | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md`, `EDITORIAL_V2.md` |
| Admin-approved Publish | HIGH | `editorial/includes/publish.php` | `review.php`, `revision.php`, `editorial/publish.php`, `public_rebuild.php` | Approved revision; live hash; backup; atomic replace; compensation; terminal state. | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| Catalog update during Publish | HIGH | `editorial/includes/publish.php` | `article_catalog.php`, `data/articles.json`, `public_rebuild.php` | Backup + hash guard; catalog is metadata source, not prose source. | `ARCHITECTURE.md`, `PUBLISH_AND_HANDOFF.md` |
| Body-image upload | HIGH | `editorial/upload.php` | `media.php`, `workspace.php`, `article.php` | Editor owner; active assignment; CSRF; lock; MIME/size; path contract. | `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| KTDT Image Pack import | HIGH | `editorial/image-pack-import.php` | `article.php`, `media.php`, TinyMCE current DOM | Contract v1 fixed; exact canonical `old_src`; SSRF guard; all assets validated before Draft apply; no auto workflow. | `EDITORIAL_V2.md`, `PUBLISH_AND_HANDOFF.md`, this map |
| Featured Image upload/display | HIGH | `article.php` | `workspace.php`, `media.php`, `upload.php`, `publish.php`, `public_rebuild.php`, `article-layout.js`, `content-hub.js`, public CSS | Five payload fields; absent legacy key differs from explicit blank; never infer from first body image; propagation spans live/catalog/rebuild/presentation. | `PUBLISH_AND_HANDOFF.md`, `ARCHITECTURE.md`, this map |
| Fast / full public rebuild | HIGH | `editorial/includes/public_rebuild.php` | `tools/rebuild_public_from_articles.py`, `publish.php`, `integrity.php` | Current native/Python selection depends on `refreshTaxonomy`; derived artifacts are not manual source. | `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md`, `ARCHITECTURE.md` |
| Hub/static-card image sync | HIGH | `editorial/includes/public_rebuild.php` | `content-index.js`, `data/hubs/*`, static hub HTML, `content-hub.js` | Target image identity must agree across catalog/index/hub/static card before ready marker. | `PUBLISH_AND_HANDOFF.md`, this map |
| Taxonomy refresh | HIGH | `editorial/includes/public_rebuild.php` | `data/taxonomy-master.json`, `tools/manage_taxonomy.py`, `article_catalog.php` | Identify taxonomy master/source vs derived JSON/JS/menu/hub output first. | `ARCHITECTURE.md`, `EDITORIAL_V2.md`, `PUBLISH_AND_HANDOFF.md` |
| Public-ready marker | HIGH | `editorial/includes/public_rebuild.php` | `publication.php`, `handoff.php`, `editorial/storage/public-ready/` | Exact article + `published_revision_id` + `published_live_hash`. | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| Handoff eligibility | HIGH | `editorial/includes/publication.php` | `handoff.php`, `workspace.php`, `revision.php`, `public_rebuild.php` | Published snapshot only; live hash; ready marker; draft freshness. | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md` |
| Drive canonical archive | HIGH | `editorial/includes/handoff.php` | `settings.php`, `composio.php`, `editorial/handoff.php` | `editorial_handoff_article()` serializes the operation; one canonical file per Article ID; do not create duplicate fallback when edit fails. | `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| Sheet Article ID upsert | HIGH | `editorial/includes/handoff.php` | `settings.php`, `composio.php` | Exact header; Article ID business key; 0/1/2+ match contract; post-write readback. | `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| Handoff config / verification | HIGH | `editorial/includes/settings.php` | `composio.php`, `google-handoff-settings.php`, `handoff.php` | Secrets server-side; verified config + pinned toolkit; no secret in logs/docs. | `OPERATIONS.md`, `PUBLISH_AND_HANDOFF.md` |
| Integrity scan | MEDIUM | `editorial/includes/integrity.php` | `editorial/integrity.php`, implicated service | Scanner is diagnostic/read-only; do not turn warnings into destructive auto-fix casually. | `OPERATIONS.md`, this map |
| Retry public rebuild | HIGH | `editorial/includes/publish.php` | `public_rebuild.php`, `editorial/publish.php`, `editorial/integrity.php` | Published revision/snapshot/live hash must verify; retry must not rewrite live HTML or workflow. | `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md` |
| Expired lock cleanup | MEDIUM | `editorial/includes/integrity.php` | `editorial/integrity.php`, `workspace.php` | Delete only expired locks; never conflate cleanup with release assignment. | `OPERATIONS.md`, `EDITORIAL_V2.md` |
| SQLite schema / migration | HIGH | `editorial/includes/migrations.php` | `database.php`, all queries touching table, `bootstrap.php` | Prove need; idempotency; preserve runtime data; partial migration recovery; unique indexes. | `EDITORIAL_V2.md`, `OPERATIONS.md`, `ARCHITECTURE.md` |
| Legacy `/admin` change | MEDIUM–HIGH | `admin/` target module | `admin/README.md`, legacy docs | `/admin` is fallback/legacy; do not introduce Editorial runtime dependency. | Legacy README; canonical docs only if boundary changes |
| Public article detail presentation | LOW–MEDIUM | `article-layout.js` | Article HTML metadata, `data/article-views/*`, public CSS | Distinguish content authority from presentation and derived data. | `ARCHITECTURE.md` only if architecture changes |
| Public hub/card presentation | LOW–MEDIUM | `content-hub.js`, `assets/css/content-hub.css` | `data/hubs/*`, static hub HTML, `public_rebuild.php` | Do not solve stale derived data with a CSS-only or manual-artifact workaround. | `PUBLISH_AND_HANDOFF.md` if rebuild contract changes |

## Ownership and edit authority

| Term | Current meaning | Not equivalent to |
|---|---|---|
| **Current owner / assignee** | `editorial_article_state.assigned_user_id`. Identifies who owns the active editorial cycle. | A complete authorization decision for every operation. |
| **Active assignment** | Exactly one `editorial_assignments` row with `released_at IS NULL`. | Contributor history or lock. |
| **Historical contributor** | An assignment with successful saved work evidence (`first_saved_at`). | Current editor permission. |
| **Workspace lock** | Temporary `article_id` + user + token + TTL session. | Persistent assignment. |

`assigned_user_id` is current **ownership/assignment authority**. An edit/write
operation also needs the relevant combination of:

- allowed workflow status;
- active-assignment consistency;
- user role/policy;
- CSRF when the route is POST;
- valid lock token/expiry where the operation requires a Workspace lock;
- revision/hash proof where the operation acts on an immutable candidate or live
  file.

## State pointer cheat sheet

`editorial_article_state` uses workflow pointers and publication facts for
different purposes. Do not treat them as interchangeable.

| Field | Meaning | Lifecycle | Survives new assignment / release / reassign / reopen? |
|---|---|---|---|
| `status` | Current workflow state. | Changes through workflow transition. | No; set for the new/current cycle. |
| `assigned_user_id` | Current owner pointer. | Set on assign, cleared on release/terminal Publish. | No. |
| `assigned_at` | Time current owner was assigned. | Current cycle metadata. | No. |
| `base_live_hash` | Live HTML baseline used by current cycle. | Set on claim/reassign/reopen; advances after Publish. | No. |
| `current_revision_id` | Current editorial workflow pointer. Not necessarily live content. | Advances with editorial revisions; terminal Publish points to published revision. | No. |
| `review_revision_id` | Immutable revision currently under review. | Set on send review; cleared after terminal/next cycle transitions. | No. |
| `review_requested_by` | User who requested current review. | Review metadata. | No. |
| `review_requested_at` | Time current review was requested. | Review metadata. | No. |
| `approved_revision_id` | Immutable approved checkpoint. | Set on approval; may remain historical while later work resumes. | Workflow-dependent; reset for a new cycle. |
| `approved_by` | Admin who approved checkpoint. | Approval metadata. | Workflow-dependent. |
| `approved_at` | Approval timestamp. | Approval metadata. | Workflow-dependent. |
| `published_revision_id` | Immutable revision actually published; Handoff content authority. | Last-public-version fact. | **Yes.** |
| `published_by` | Actor who made last Publish. | Last-public-version fact. | **Yes.** |
| `published_at` | Time of last Publish. | Last-public-version fact. | **Yes.** |
| `published_live_hash` | Exact bytes hash of live article after last Publish. | Last-public-version fact. | **Yes.** |
| `publish_backup_path` | Relative backup path for last Publish. | Last-public-version fact. | **Yes.** |

Workflow pointers can reset when a new editorial cycle begins. Publication facts
describe what is currently live and survive Claim, Release, Reassign and
controlled Reopen.

## Revision pointer semantics

| Pointer | Meaning | Do not confuse with |
|---|---|---|
| `current_revision_id` | Current workflow revision pointer. | Guaranteed live public content. |
| `review_revision_id` | Immutable revision being reviewed. | Latest arbitrary revision. |
| `approved_revision_id` | Approved checkpoint. | Automatically published content. |
| `published_revision_id` | Immutable revision actually published and external Handoff authority. | Stage1, Stage2, review, browser draft or live fallback. |

## Hash cheat sheet

| Hash / identity | What it identifies | Primary use |
|---|---|---|
| `base_live_hash` | Live article HTML baseline for active editorial cycle. | Detect external live drift before workflow/Publish actions. |
| Revision `content_hash` | Canonically normalized revision payload. | Snapshot integrity, draft freshness and candidate identity. |
| Draft hash | Canonical draft payload hash computed at write/check time. | Optimistic concurrency and fresh candidate/review evidence. |
| `published_live_hash` | Exact bytes of live public HTML after Publish. | Publication integrity, rebuild retry and Handoff eligibility. |
| Public-ready marker | Article ID + `published_revision_id` + `published_live_hash`. | Proves derived public rebuild completed for exact publication. |

Never compare hashes of different representations as if they were equal:

```text
live HTML byte hash != payload content_hash
```

## Concurrency map

| Flow | Existing mechanism | Before adding new synchronization |
|---|---|---|
| Claim | `editorial_transaction()` / SQLite `BEGIN IMMEDIATE` + unique active-assignment index + conditional state update. | Read `assignment.php` and `migrations.php`. |
| Draft save | Draft version + expected draft hash + conditional update/recovery. | Read `workspace.php`; do not add blind overwrite. |
| Workspace | Lock token + expiry + heartbeat. | Read `workspace.php` and endpoint `lock-heartbeat.php`. |
| Publish | Base/live hash + policy revalidation inside transaction + atomic file operations + compensation. | Read entire `publish.php` safety pipeline. |
| Handoff | Per-article filesystem lock + Sheet Article ID preflight + canonical Drive file selection. | Read `handoff.php`; do not introduce duplicate archive behavior. |

## Task recipes

### UI-only change

For labels, spacing, card layout or badge presentation:

1. verify server authority and persistence are unchanged;
2. locate page/template plus CSS/JS;
3. avoid changing service modules because a button is visible there;
4. verify hidden/disabled UI is not being used as security;
5. do not add a migration for UI state when existing state suffices.

Usually start at a route page or public asset, not `assignment.php`,
`revision.php` or `migrations.php`.

### Claim / ownership

Read:

1. `editorial/includes/assignment.php`
2. `editorial/includes/review.php`
3. `editorial/articles.php`
4. `editorial/includes/workspace.php`
5. `editorial/includes/revision.php`
6. `editorial/includes/integrity.php`

Preserve: exact available self-claim; one active assignment; owner is not
contributor; Admin-only release/reassign/reopen; publication facts survive;
lock is not assignment.

### Draft / lock

Read `workspace.php`, `article.php`, `database.php`, then `revision.php` if
draft output creates a candidate. Preserve owner + editing/returned status +
active assignment + valid lock + version/hash concurrency. Never silently
overwrite a newer draft.

### Stage / revision

Read `revision.php`, `article.php`, `compare.php`, `review.php`.

Preserve immutable snapshots, current-assignment scope, active Stage1/Stage2
chronology, Stage1 reset semantics, Compare read-only behavior and Stage2
freshness for review.

### Review

Read `editorial/includes/review.php`, `editorial/review.php`,
`revision.php`, `assignment.php`.

Preserve verified Baseline/Stage1/Stage2, draft equals active Stage2,
`review_revision_id` authority, approved checkpoint and same-owner resume.
Submission note belongs to the `article.review.submitted` event for that exact
revision, not to Stage2 content. Do not casually reassign `ready_review`, guess
the latest note by article alone, or hide the two baseline comparison actions.

### Publish

**HIGH RISK.** Read before editing:

1. `AGENTS.md`
2. `PUBLISH_AND_HANDOFF.md`
3. `editorial/includes/publish.php`
4. `revision.php`, `workspace.php`, `article_catalog.php`
5. `public_rebuild.php`

Check actor/policy, state, assignment, candidate revision, verified snapshot,
live hash, lock, backup, renderer, validation, atomic replace, catalog write,
compensation, published revision and rebuild.

Do **not** create a shortcut Publish path without reviewing the entire existing
safety pipeline.

### Featured Image

Read `article.php`, `workspace.php`, `media.php`, `upload.php`, `publish.php`,
`public_rebuild.php`, `article-layout.js`, `content-hub.js` and
`assets/css/content-hub.css`.

Trace:

```text
featured_image payload
→ published snapshot
→ live `article-meta` image/imageAlt/imageTitle/imageCaption/imageCredit
→ data/articles.json fields
→ rebuild
→ public detail and hub card presentation
```

Do not infer Featured Image from the first body image.

For old snapshots, missing optional `featured_image_alt`,
`featured_image_title`, `featured_image_caption` or `featured_image_credit`
means preserve the corresponding live metadata at Publish; a present empty key is
an explicit clear. Do not add a snapshot migration for these optional fields.

### Body image

Read `media.php`, `upload.php`, `article.php`, `workspace.php`.

Body image references and Alt/Title/Caption/Credit metadata live in
`prose_html`; their physical file path follows `uploads/articles/YYYY/MM/`.
Uploading a file does not itself save a draft, create a stage or Publish it.
Use TinyMCE `imagemeta` for explicit metadata edits; it must resolve the
currently selected image/figure, never a global first image.

### KTDT Image Pack import

Read `article.php`, `image-pack-import.php` and `media.php`.

Preserve:

- exact `KTDT_IMAGE_PACK` version 1 fields; no article ID, hash, fingerprint or
  placement requirement;
- canonical current-site pathname matching while retaining external
  `origin + pathname`;
- 0/2+ matches and duplicate package `old_src` as blocking conflicts;
- Caption/Credit compatibility preflight before server transfer;
- auth, CSRF, current owner, active assignment and valid lock at the endpoint;
- HTTP(S)-only server download with private/reserved IP rejection, no redirect,
  8 MiB per image and `finfo` MIME authority;
- all downloads validated before permanent persistence and all browser mappings
  re-checked before any Draft mutation;
- `writeInlineImageMetadata()` reuse and one final `markDraftDirty()`;
- TinyMCE DOM source update must keep `src` and `data-mce-src` aligned, and Save
  must encode `getContent()` rather than trust textarea state alone;
- no whole-prose replacement and no automatic Save/Stage/Review/Publish.

Do not log signed source URLs or package JSON. A browser-side race after server
success may leave orphan files; do not add cleanup architecture casually.

For Featured persistence, trace Draft/Stage/normalized payload through
`article-meta`, catalog and rebuild, then verify the managed static block
`data-editorial-featured="1"` before `#articleTopNav`. Public JS must hydrate
that block. Never allow a local `uploads/articles/...` path to Publish when the
file is missing, unreadable or outside upload root.

### Public rebuild

Read `editorial/includes/public_rebuild.php` and
`tools/rebuild_public_from_articles.py`.

Determine before changing:

- fast vs full rebuild;
- `refreshTaxonomy` behavior;
- native vs Python selection/order;
- target image sync and static hub card patching;
- derived artifact set;
- public-ready marker identity.

Never rely on old behavior descriptions; inspect `editorial_public_rebuild_run()`.

### Google Handoff

Read `publication.php` first—`editorial_publication_handoff_status()` is the
publication eligibility authority—then `handoff.php`, `settings.php` and
`composio.php`. Inspect `migrations.php` only if a schema change is proposed.

Preserve published revision-only authority, live hash, ready marker, draft
freshness, canonical Drive file, Sheet Article ID key, duplicate-row block,
verified external config and secret redaction.

### Taxonomy

Editorial V2 is **not** a taxonomy editor. Read `article_catalog.php`,
`public_rebuild.php`, `data/taxonomy-master.json` and taxonomy tools.

Before changing taxonomy, classify the object as:

1. source/master;
2. catalog classification;
3. derived output.

Never hand-edit a derived taxonomy artifact and call it the source.

### Migration

**HIGH RISK.** Before adding one:

1. prove current schema cannot support the task;
2. inspect migration versions and all affected queries;
3. preserve idempotency and existing runtime data;
4. consider partially applied migration recovery;
5. update operations/canonical docs;
6. do not create a migration solely for UI state if a current field suffices.

### Public HTML presentation

For “article image missing” or “hub card stale” requests, distinguish:

```text
content authority ≠ public presentation ≠ derived data
```

Potential files include `article-layout.js`, `content-hub.js`,
`assets/css/content-hub.css`, static hub HTML, `data/hubs/*`,
`content-index.js` and `public_rebuild.php`. Do not change payload/revision
semantics unless source data authority is actually wrong.

### Integrity / recovery

Read `editorial/includes/integrity.php`, `editorial/integrity.php` and the
service implicated by the issue. The scanner is primarily diagnostic; do not
convert warnings into automatic destructive repair without a separate design.

## Impact matrix

| Change area | Public HTML | SQLite | Immutable revision evidence | Derived public artifacts | External Google | Migration likely |
|---|---:|---:|---:|---:|---:|---:|
| Auth | No | Yes | No | No | No | Rare |
| Claim | No | Yes | Indirect | No | No | Rare |
| Draft | No | Yes | Yes | No | No | Rare |
| Stage | No | Yes | Yes | No | No | Rare |
| Compare | No | Read-only | Reads | No | No | No |
| Review | No | Yes | Reads/pointers | No | No | Rare |
| Publish | **Yes** | Yes | Yes | **Yes** | No | Rare |
| Media upload | Files only | Activity | Reference only | After Publish/rebuild | No | No |
| Featured Image | **Yes after Publish** | Draft/revision | Yes | **Yes** | Metadata later | No |
| Rebuild | Static hub card may change | Marker | Reads published facts | **Yes** | No | No |
| Handoff | No | Sync/settings | Reads published snapshot | No | **Yes** | Rare |
| Taxonomy | Hub output may change | No | No | **Yes** | No | Possible |
| Integrity | No by scan | Reads; limited maintenance actions | Reads | Retry may write artifacts | No | No |

## Do-not-touch map

Use this to avoid scope expansion:

| If changing… | Normally do **not** touch… |
|---|---|
| Claim rule | `publish.php`, `handoff.php`, `public_rebuild.php` unless the task proves an interaction. |
| CSS/layout | `migrations.php`, `revision.php`, `assignment.php`. |
| Drive/Sheet metadata | Safe Publish renderer or article HTML replacement contract. |
| Featured Image public card | Stage/Review design unless payload authority is actually wrong. |
| Compare presentation | Draft-save/revision creation behavior. |
| Rebuild output formatting | Ownership or review state machine. |
| Taxonomy output | Editorial payload taxonomy semantics unless source/classification needs change. |

Start at authority, inspect blast radius, then stop at the smallest correct file
set.

## Generated / derived artifact warning

These important files are normally generated or synchronized through the
rebuild path:

- `content-index.js`;
- `data/hubs/*`;
- `data/feeds/*`;
- `data/article-views/*`;
- `data/taxonomy.json`;
- `data/editor-taxonomy.json`;
- `data/menu-config.json`;
- `sitemap.xml`.

Static hub HTML can also be patched/synchronized by current rebuild code.
Normally change the builder/rebuild contract rather than hand-editing generated
output as the architectural solution.

## Source vs derived vs runtime

| Class | Actual project artifacts |
|---|---|
| **Source / authority** | Original live article HTML for public body; `data/articles.json` for catalog metadata; `data/taxonomy-master.json` for taxonomy rebuild when valid. |
| **Runtime state** | `editorial/storage/editorial.sqlite`, locks, drafts, settings, handoff sync. |
| **Immutable evidence** | `editorial/storage/revisions/*.json`, published revision row/content hash. |
| **Derived output** | Content index, hubs, feeds, article views, taxonomy/editor taxonomy/menu output, sitemap, synchronized static hub cards. |
| **External archive** | Google Drive canonical HTML archive and Google Sheet row for verified published revision. |

## Common mistakes to avoid

1. Reading historical docs as current architecture.
2. Treating SQLite as public article content source.
3. Using contributor history as edit authorization.
4. Treating lock release as assignment release.
5. Mutating an old revision snapshot.
6. Making Compare save state.
7. Using Stage2 directly for Google Handoff.
8. Clearing `published_*` when changing owner.
9. Publishing unsaved browser state.
10. Creating a new public HTML file instead of updating original live article.
11. Changing body-image semantics to fix Featured Image presentation.
12. Editing generated JSON manually without reviewing rebuild source.
13. Treating UI visibility as authorization.
14. Adding a migration before proving it is necessary.
15. Introducing an Editorial V2 runtime dependency on legacy `/admin`.
16. Treating an Image Pack as prose authority or matching images by basename.

## Documentation update matrix

| If code changes… | Update canonical docs… |
|---|---|
| Ownership / state | `AGENTS.md`, `EDITORIAL_V2.md`, `CHANGE_IMPACT_MAP.md` |
| Publish | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md`, `CHANGE_IMPACT_MAP.md` |
| Rebuild | `ARCHITECTURE.md`, `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md`, `CHANGE_IMPACT_MAP.md` |
| Handoff | `AGENTS.md`, `PUBLISH_AND_HANDOFF.md`, `OPERATIONS.md`, `CHANGE_IMPACT_MAP.md` |
| Media | `PUBLISH_AND_HANDOFF.md`, `CHANGE_IMPACT_MAP.md` |
| Source of truth | `README.md`, `AGENTS.md`, `ARCHITECTURE.md`, `CHANGE_IMPACT_MAP.md` |
| Migration / runtime | `EDITORIAL_V2.md`, `OPERATIONS.md`, `CHANGE_IMPACT_MAP.md` |

## AI pre-change checklist

- [ ] Identify exact user-facing behavior.
- [ ] Identify server-side authority.
- [ ] Identify source of truth.
- [ ] Identify owner/state/revision concepts involved.
- [ ] Identify immutable evidence involved.
- [ ] Determine filesystem and external side effects.
- [ ] Determine existing concurrency mechanism.
- [ ] Read relevant invariants.
- [ ] Inspect implementation, not only docs.
- [ ] Define the smallest file set.
- [ ] Avoid migration unless proven necessary.
- [ ] Define static acceptance cases.
- [ ] Plan canonical documentation updates if invariant changes.

## AI post-change checklist

- [ ] Diff contains only expected files.
- [ ] No unrelated architecture expansion.
- [ ] Authorization remains server-side.
- [ ] Source-of-truth boundary unchanged unless intentionally redesigned.
- [ ] Immutable snapshots remain immutable.
- [ ] Publication facts were not accidentally cleared.
- [ ] Compare remains read-only if touched.
- [ ] Safe Publish still targets same live file if touched.
- [ ] Handoff remains published-only if touched.
- [ ] No secret/log leakage.
- [ ] Canonical docs changed when an invariant changed.
- [ ] Runtime tests are not claimed unless actually run.
