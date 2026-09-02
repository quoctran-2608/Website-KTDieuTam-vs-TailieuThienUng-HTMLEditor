# Editorial Admin V2 — Kiến trúc

## Tổng quan

```text
/admin/       Legacy Admin (giữ nguyên, không sửa)
/editorial/   Multi-user Editorial Admin V2
```

- **HTML gốc** = live public content (source of truth cho nội dung website).
- **SQLite** (`editorial/storage/editorial.sqlite`) = collaboration/workflow layer.
- Draft/revision chưa publish **không** thay đổi live HTML.
- Publish về sau cập nhật **chính file HTML gốc**.

## Nguyên tắc quan trọng

> **Không** tạo một bộ published HTML riêng bên trong Editorial V2.

> Khi Publish, approved revision phải được áp dụng vào **chính file HTML gốc**
> sau khi kiểm tra optimistic lock (`base_live_hash`) và tạo backup.

## Data model

### editorial_users
Tài khoản biên tập viên. Role: `admin`, `editor`.

### editorial_article_state
Trạng thái collaboration hiện tại của mỗi bài viết.
- `article_id` map trực tiếp về `data/articles.json` → file HTML gốc.
- `status`: available → editing → ready_review → returned → approved → published.
- `base_live_hash`: SHA-256 của file HTML live tại thời điểm bắt đầu biên tập.

### editorial_assignments
Lịch sử gán bài. Mỗi lần gán/trả bài là một record mới (không update mất lịch sử).

### editorial_locks
Editing lock cho phiên làm việc. Lock ≠ Assignment:
- **Assignment**: ai chịu trách nhiệm bài.
- **Lock**: phiên nào đang mở quyền chỉnh sửa.
- Lock hết hạn không tự mất assignment.

### editorial_drafts
Nội dung chưa publish. Key: `(article_id, user_id)`.
Chứa `base_live_hash` để detect stale khi publish.

### editorial_revisions
Lịch sử bất biến. `(article_id, revision_no)` là unique.
Types: `baseline`, `editorial`, `published`, `restore`.

### editorial_activity
Audit log cho mọi thao tác. Không lưu password/secret/token.

## base_live_hash

`base_live_hash` = SHA-256 của file HTML live tại thời điểm bắt đầu editorial session.

Khi Publish:
```text
if base_live_hash != SHA-256(current live HTML):
    BLOCK PUBLISH — không được overwrite
else:
    backup → apply revision → ghi file HTML gốc → ghi hash mới
```

## article_id

`article_id` map trực tiếp về metadata trong `data/articles.json`.
Resolve ra file HTML gốc qua `href` field (ví dụ: `bai-tap-dinh-khoan-ke-toan-co-ban-co-loi-giai.html`).

Không dùng SQLite autoincrement làm article identity.

## Publish workflow (Phase sau)

```text
1. Xác định file HTML gốc
2. Đọc HTML live → tính SHA-256
3. So sánh với base_live_hash
4. Nếu khác → BLOCK
5. Nếu đúng → backup file HTML gốc
6. Lấy approved revision
7. Dùng parser/contract cập nhật vùng editable
8. Ghi chính file HTML gốc
9. Tính hash sau publish
10. Ghi revision published + activity
```

## Phase 2 — Quản lý tài khoản thành viên

### Trang quản lý (`editorial/users.php`)
- Chỉ role `admin` truy cập (server-side `editorial_require_role`).
- Danh sách, tạo, sửa (display_name/role/is_active), đặt lại mật khẩu.
- Không physical delete — chỉ deactivate.

### Auth revalidation mỗi request
- `editorial_is_authenticated()` đọc user từ DB mỗi request.
- Session chỉ giữ `user_id` + session state (`login_at`, `last_seen`).
- `role`, `display_name`, `is_active`, `must_change_password` luôn lấy từ DB.
- Nếu user bị khóa → logout ngay ở request tiếp theo.
- Nếu role đổi → có hiệu lực ngay.

### must_change_password
- Sau login, nếu `must_change_password = 1`:
  chỉ cho truy cập `change-password.php` và `logout.php`.
- Các trang khác redirect về `change-password.php`.
- Sau đổi mật khẩu thành công: `must_change_password = 0`.

### Bảo vệ admin cuối cùng
- Không cho deactivate nếu chỉ còn 1 active admin.
- Không cho đổi role admin → editor nếu chỉ còn 1 active admin.
- Không cho admin tự khóa chính mình.
- Kiểm tra trong `editorial_transaction()` để đảm bảo atomic.

### Permissions cơ bản
- `admin`: quản lý users, toàn quyền.
- `editor`: chỉ xem dashboard, đổi mật khẩu.
- Server-side enforce qua `editorial_require_role()`.
- Sidebar ẩn mục Thành viên cho editor (nhưng server là lớp bảo vệ thật).

## Phase 3 — Danh sách bài + Nhận biên tập + Công việc của tôi

### Article Catalog (`editorial/includes/article_catalog.php`)
- Đọc `data/articles.json` — canonical source, cached in-memory per request.
- Canonical `article_id` = field `id` trong articles.json (e.g. `bai-tap-dinh-khoan...html`).
- Không import articles vào SQLite. Không tạo `article_state` rows cho bài chưa có collaboration.
- Absent state = `available`, `assigned_user_id = NULL`.
- Filter: text search, section, topic_lv1, assignment status. Pagination 30/page.
- Safe HTML file resolution: strip query, reject `..`, `\0`, scheme, verify `realpath()` inside repo root.

### Atomic Claim (`editorial/includes/assignment.php`)
- `editorial_claim_article()` dùng `editorial_transaction()` → `BEGIN IMMEDIATE`.
- Trong transaction: ensure state row → check current owner → verify no orphaned assignment → insert assignment history → update state → activity log → COMMIT.
- Race condition: `BEGIN IMMEDIATE` serialize writes. Người thứ hai đọc state mới và nhận message tên người đã nhận.
- `base_live_hash = hash_file('sha256', html_path)` — set lúc claim, không tự reset khi refresh.

### DB Invariant (Migration v2)
- `UNIQUE INDEX idx_assignments_active_article ON editorial_assignments(article_id) WHERE released_at IS NULL` — chống 2 active assignment cùng article.
- Indexes trên `article_state(status)`, `article_state(assigned_user_id)`, `assignments(user_id, released_at)`.

### My Work (`editorial/my-work.php`)
- Hiển thị bài đang assigned cho current user (`editorial_article_state.assigned_user_id`).
- Grouped by status: editing → returned → ready_review → approved.
- Enriched với metadata từ article catalog.

### Dashboard Metrics
- Tổng bài viết (từ catalog), Chưa có người nhận, Đang phân công, Công việc của tôi.
- Tính `available = total - assigned` (không tạo state rows cho mọi bài).

### Status Labels (centralized)
```
available     → Chưa có người nhận
editing       → Đang biên tập
ready_review  → Chờ duyệt
returned      → Cần chỉnh lại
approved      → Đã duyệt
published     → Đã xuất bản
```

## Phase 4 — Workspace biên tập + Lock + Draft an toàn

### Assignment vs Lock vs Draft vs Revision
- **Assignment**: ai chịu trách nhiệm bài (persistent, không hết hạn tự động)
- **Editing Lock**: phiên chỉnh sửa đang hoạt động (TTL 15 phút, heartbeat 60s)
- **Draft**: nội dung mutable đang chỉnh (SQLite, optimistic versioning)
- **Revision**: immutable history (Phase 5 ✅)

### Lock TTL
- `EDITORIAL_ARTICLE_LOCK_TTL = 900` (15 phút)
- Browser heartbeat mỗi 60 giây via `lock-heartbeat.php`
- Lock hết hạn → KHÔNG mất assignment, KHÔNG xóa draft
- Lock reuse: cùng user mở lại workspace → reuse lock hiện tại, extend TTL

### Draft Versioning (Migration v3)
- `editorial_drafts.version INTEGER NOT NULL DEFAULT 0`
- Insert: version = 1, UPDATE: `version = version + 1 WHERE version = expectedVersion`
- `rowCount = 0` → conflict: "Bản nháp đã thay đổi ở phiên/tab khác"
- Chống same-user multi-tab overwrite

### Parser (adapted from admin)
- `editorial_parse_article_file()` — copy stateless functions từ `admin/includes/article_parser.php`
- Functions: `editorial_extract_prose_region`, `editorial_find_matching_div_close`, `editorial_extract_meta_region`, `editorial_extract_summary_text`
- Prefix `editorial_*` tránh collision

### TinyMCE
- CDN: `cdn.jsdelivr.net/npm/tinymce@7`
- Giữ nguyên: plugins, toolbar, content_css (4 EDS CSS files), body_class, content_style
- Image upload: disabled trong V2 (throw error message), ảnh có sẵn vẫn hiển thị
- Base64 encode prose_html trước submit (WAF workaround)

### Live Hash Conflict Warning
- So sánh `current_live_hash` vs `article_state.base_live_hash` khi mở workspace
- Khác → hiển thị warning, KHÔNG reset base_hash, KHÔNG merge
- Phase 7 sẽ xử lý conflict resolution

### HTML live không thay đổi
- Phase 4 chỉ ĐỌC HTML gốc
- Draft lưu vào SQLite `editorial_drafts`
- Không `file_put_contents()` vào HTML bài viết

## Phase 4 Corrective Fixes (applied in Phase 5)

### A1. Taxonomy Preservation
- Save Draft chỉ lấy editable fields (title, excerpt, prose_html, publish_date, modified_date, featured_image, tags_text) từ POST.
- Taxonomy fields (section_key/label, library_kind_key/label, topic_lv1–3 key/label) **KHÔNG** lấy từ POST.
- `editorial_merge_draft_payload()` lấy taxonomy từ existing draft payload hoặc article catalog (server-side).
- `editorial_build_initial_payload()` bao gồm tất cả taxonomy fields khi tạo payload ban đầu.

### A2. Strict Base64 Decode
- `base64_decode($value, true)` — nếu trả `false`, block save ngay.
- Message: "Dữ liệu nội dung gửi lên không hợp lệ. Bản nháp chưa được lưu."
- Không ghi draft rỗng do decode lỗi.

### A3. Release Lock Requires Token
- `editorial_release_article_lock(articleId, userId, lockToken)` — DELETE yêu cầu match cả 3 điều kiện.
- Stale tab (token cũ) KHÔNG xóa được lock mới của tab khác.
- Nếu token không match: silently ignore, không log `article.lock.released`.
- `exit_workspace` gửi `lock_token` hiện tại.

### A4. Lock Validation Inside Draft Save Transaction
- `editorial_save_draft()` dùng `BEGIN IMMEDIATE` transaction.
- Bên trong transaction: (1) verify assignment+status, (2) verify lock user/token/expiry, (3) version check, (4) save.
- TOCTOU window được loại bỏ.

### A5. JSON Encode Failure
- `json_encode($payload)` trả `false` → block save, không bind `false` vào SQLite.
- Message: "Không thể mã hóa dữ liệu bản nháp. Bản nháp chưa được lưu."

### A6. Heartbeat Safety
- `editorial_heartbeat_article_lock()` UPDATE match cả article_id + user_id + lock_token.
- `rowCount() === 0` → response `{ok: false}`, không trả `{ok: true}` nếu lock biến mất.

## Phase 5 — Revision History & Compare

### Revision vs Draft
- **Draft** = mutable working copy, save nhiều lần, optimistic version locking.
- **Revision** = immutable snapshot, lịch sử chính thức, không sửa sau khi tạo.
- Không tạo revision mỗi lần Save Draft.

### Snapshot Storage
- Path: `editorial/storage/revisions/<2-char-prefix>/<sha256-article-id>/rev_<id>.json`
- SHA-256 sharding của article_id cho filesystem path.
- Server tự tạo path, không nhận từ request.
- `.gitignore` đã exclude `editorial/storage/revisions/`.
- `.htaccess` deny web access (nằm dưới `editorial/storage/`).
- Snapshot chỉ đọc qua authenticated PHP (`editorial_read_revision_snapshot()`).

### Snapshot Format
```json
{
  "schema_version": 1,
  "article_id": "...",
  "payload": {
    "title": "...",
    "excerpt": "...",
    "prose_html": "...",
    "publish_date": "...",
    "modified_date": "...",
    "featured_image": "...",
    "tags_text": "...",
    "section_key": "...",
    "section_label": "...",
    "library_kind_key": "...",
    "library_kind_label": "...",
    "topic_lv1_key": "...",
    "topic_lv1_label": "...",
    "topic_lv2_key": "...",
    "topic_lv2_label": "...",
    "topic_lv3_key": "...",
    "topic_lv3_label": "..."
  }
}
```

### Canonical Content Hash
- `editorial_revision_content_hash()` = SHA-256 of canonical JSON.
- `editorial_stable_sort_keys()` → recursive stable key ordering.
- `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Cùng payload → cùng hash. Không hash metadata (created_at, revision_id).

### Migration V4
- `ALTER TABLE editorial_revisions ADD COLUMN assignment_id TEXT`
- `ALTER TABLE editorial_revisions ADD COLUMN source_draft_version INTEGER`
- Index: `idx_revisions_assignment ON editorial_revisions(assignment_id)`

### Active Assignment Helper
- `editorial_get_active_assignment(articleId)` — query `editorial_assignments WHERE released_at IS NULL`.
- Return null nếu 0 hoặc >1 active rows.
- Log diagnostic `article.assignment.conflict_detected` nếu >1.

### Baseline Revision
- Type: `revision_type = 'baseline'` — đại diện live content trước editorial.
- Lazy-create khi workspace mở và assignment chưa có baseline.
- Điều kiện: `current_live_hash === base_live_hash`.
- Nếu hash khác → skip, log `article.baseline.skipped_conflict`.
- Không bulk tạo baseline cho 2.000 bài, chỉ lazy khi workspace mở.

### Editorial Revision
- Action: "Tạo phiên bản" trong workspace (không gọi Publish).
- Snapshot từ **draft đã lưu trong SQLite**, KHÔNG từ POST data.
- Flow: POST `create_revision` → verify owner/lock/status/draft_version → load draft từ DB → snapshot → insert revision.
- Duplicate prevention: compare `content_hash` với latest editorial revision.
- Note: optional, max 500 ký tự, escaped khi render.

### Revision Numbering
- Mỗi article: 1, 2, 3, ...
- `MAX(revision_no) + 1` bên trong `BEGIN IMMEDIATE`.
- Schema UNIQUE `(article_id, revision_no)`.

### Atomic Snapshot Write
1. Generate random revision id.
2. Canonicalize snapshot.
3. Write temp file cùng filesystem.
4. Atomic rename → final path.
5. DB transaction insert revision metadata.
6. Nếu DB fail → best-effort unlink snapshot.
7. Nếu final path đã tồn tại → idempotent return.

### Snapshot Path Validation
- Read snapshot: resolve dưới `EDITORIAL_STORAGE_PATH/revisions`.
- `realpath()` containment check.
- Nếu snapshot missing/corrupt → UI báo "Snapshot không khả dụng", không fatal.

### Revision Immutability
- Không có `UPDATE editorial_revisions SET snapshot...`.
- Không có action "Edit revision".
- Restore về sau tạo revision mới, không sửa cũ.

### Article State Update
- Sau tạo editorial revision: set `editorial_article_state.current_revision_id` = latest.
- Status giữ editing/returned trừ khi editor gửi duyệt hoặc admin approve/return.

### Revision History UI (`editorial/revisions.php`)
- Authorization: Admin xem mọi article; Editor chỉ xem nếu là current owner.
- Hiển thị: Revision #, Loại, Người tạo, Thời gian, Draft version, Ghi chú, Hash ngắn, Actions.
- Loại: baseline → Bản gốc, editorial → Bản biên tập, published → Đã xuất bản, restore → Khôi phục.
- CTA: "So sánh với bản trước" link tới compare.php.

### Workspace Revision Panel
- Trong article.php: panel "Lịch sử phiên bản" hiển thị 5 revision gần nhất.
- Link "Xem toàn bộ lịch sử" tới revisions.php.

### Compare UI (`editorial/compare.php`)
- Query: `?id=<article_id>&from=<revision_id>&to=<revision_id>`.
- Server verify: cả hai revision thuộc article_id, user có quyền, snapshot tồn tại.
- Side-by-side revision headers: BẢN TRƯỚC / BẢN SAU.
- Metadata compare: field-level, chỉ highlight thay đổi.
- Prose diff: normalize thành text (strip_tags), line-based diff.
- HTML nguồn: escaped, side-by-side (collapsed mặc định).
- **Phase 6**: Snapshot verification dùng `editorial_get_verified_revision_snapshot()`.

### Diff Algorithm
- LCS line-based diff cho content nhỏ.
- Performance guard: `m + n > maxTokens/10` → fallback del-all/add-all.
- Không dùng unbounded O(n*m) trên article lớn.

### Activity Events
- `article.revision.baseline_created` — revision_id, revision_no, revision_type, content_hash.
- `article.revision.created` — revision_id, revision_no, revision_type, source_draft_version, content_hash.
- `article.review.submitted` — revision_id, revision_no, assignment_id.
- `article.review.approved` — revision_id, revision_no, editor_user_id.
- `article.review.returned` — revision_id, note.
- `article.assignment.reassigned` — old_user_id, new_user_id, old_assignment_id, new_assignment_id.
- `article.assignment.released` — old_user_id, assignment_id.
- `article.lock.force_released` — previous_lock_user_id.
- Không log full prose, full snapshot JSON, hoặc lock token.

### Dashboard
- "Revision & so sánh" → Đã sẵn sàng.
- "Review & duyệt bài" → Đã sẵn sàng.
- Admin dashboard hiển thị đếm "Chờ duyệt" và "Đã duyệt chờ Publish".

---

## Phase 6 — Review & Duyệt bài

### Migration v5
- `editorial_article_state` thêm columns: `review_revision_id`, `review_requested_by`, `review_requested_at`, `approved_revision_id`, `approved_by`, `approved_at`.
- Partial index trên `status IN ('ready_review', 'approved')`.

### Corrective Fixes (A1–A7)
- **A1**: `editorial_create_editorial_revision()` fail-closed nếu assignment null hoặc không khớp user.
- **A2**: Parser refactored thành `editorial_parse_article_html()` nhận string. Baseline đọc file 1 lần duy nhất, hash cùng bytes đó, parse cùng bytes đó.
- **A3**: `editorial_create_baseline_revision()` tự verify active assignment bên trong, không tin caller.
- **A4**: `editorial_get_verified_revision_snapshot()` — verify path, JSON, schema_version, article_id match, payload structure, recomputed content_hash.
- **A5**: `editorial_revision_content_hash()` throw RuntimeException thay vì hash '{}' khi json_encode fail.
- **A6**: `editorial_write_revision_snapshot()` verify `$written === strlen($json)`, không chỉ `!== false`.
- **A7**: `editorial_merge_draft_payload()` ưu tiên article catalog cho taxonomy, draft chỉ fallback khi catalog empty.

### Status Transitions
```text
editorial_can_transition(from, to):
  available → editing
  editing → ready_review, available, editing
  returned → ready_review, available, editing
  ready_review → returned, approved
  approved → (terminal trước publish)
  published → (terminal)
```

### Review Service Module (`editorial/includes/review.php`)

#### Gửi duyệt — `editorial_send_for_review()`
- Editor action, cần lock token hợp lệ.
- Pre-checks: article catalog, HTML file, live hash ≠ base_live_hash → BLOCK.
- Inside transaction: verify owner, status, active assignment, lock, draft-revision sync.
- Update state: status='ready_review', set review fields, clear approval fields.
- Release editing lock.
- Log `article.review.submitted`.

#### Phê duyệt — `editorial_approve_review()`
- Admin action, verify live hash conflict.
- Inside transaction: status='ready_review', verify revision, assignment, verified snapshot.
- Update state: status='approved', set approval fields.
- Log `article.review.approved`.

#### Trả lại — `editorial_return_review()`
- Admin action, mandatory note (1–2000 chars).
- Inside transaction: status='ready_review', transition to 'returned'.
- Clear approval fields, keep review_revision_id for trace.
- Log `article.review.returned` with note.

#### Phân công lại — `editorial_reassign_article()`
- Admin action, target user must be active admin/editor.
- Draft handoff safety: nếu draft khác latest revision, cần force=true.
- Close old assignment (release_reason='reassigned'), delete lock.
- Create new assignment, reset state (editing, clear review/approval).
- Log `article.assignment.reassigned`.

#### Thu hồi — `editorial_release_assignment()`
- Admin action, same draft safety as reassign.
- Close assignment (release_reason='admin_release'), delete lock.
- Reset state to available, clear all fields.
- Log `article.assignment.released`.

#### Mở khóa — `editorial_force_unlock()`
- Admin action, chỉ xóa lock row.
- Không thay đổi assignment, status, draft, revision.
- Log `article.lock.force_released`.

### Review Queue UI (`editorial/review.php`)
- Admin-only page.
- **Queue mode**: hiển thị articles status='ready_review', chỉ ra live conflict, revision #, requester.
- **Detail mode**: hiển thị metadata, content preview (sandboxed iframe), hash, compare link.
- Admin actions: Approve, Return (with note), Force Unlock, Reassign, Release.
- Draft handoff safety: nếu release/reassign fail do unsaved changes, hiển thị force button.

### Workspace Updates (`editorial/article.php`)
- Nút "Gửi duyệt" hiển thị khi có draft version > 0 và có revision.
- Confirm dialog cảnh báo editor sẽ không thể chỉnh sửa cho đến khi reviewer phản hồi.
- Baseline creation gọi `editorial_create_baseline_revision(articleId, userId)` (không truyền assignmentId).

### My Work Updates (`editorial/my-work.php`)
- Hiển thị return note cho articles status='returned'.
- Dùng `editorial_get_latest_return_note()` từ activity log.

### Article List Updates (`editorial/articles.php`)
- Admin controls: Release button cho articles status editing/returned.
- POST handlers cho: force_unlock, release, force_release, reassign, force_reassign.

### Sidebar
- Thêm "Duyệt bài" (fa-clipboard-check) cho admin.

### Không sửa
- `/admin/` — chỉ đọc/tham khảo.
- Không publish revision (trước Phase 7).

## Phase 6 Corrective Fixes

### A1: Draft handoff fail-closed
`editorial_check_draft_handoff_safety()` — central helper kiểm tra draft có được bảo toàn trong immutable revision trước khi cho phép normal reassign/release. Fail-closed khi: draft tồn tại nhưng chưa có revision, revision không phải editorial type, không thuộc active assignment, snapshot không valid, content hash không khớp, hoặc source_draft_version không khớp.

### A2: Stale draft cleanup
Reassign/release (cả normal và force) xóa draft của old owner sau khi close assignment.

### A3: Normal handoff preserves data
Normal handoff chỉ delete draft khi đã verified là draft == latest immutable editorial revision (via A1 check).

### A4: Force handoff audit
Force reassign/release log `article.draft.force_discarded` với draft_version và draft_hash trước khi xóa.

### A5: Verified snapshot truthy fix
`editorial/review.php` sử dụng `!empty($snapshot['ok'])` thay vì `if ($snapshot)` để tránh false positive khi `['ok'=>false]` vẫn truthy.

### A6: Return note from activity
Sử dụng `editorial_get_latest_return_note()` từ activity log thay vì query `revision_type='returned'` (loại revision này không tồn tại).

---

## Phase 7 — Safe Publish

### Migration v6
- `editorial_article_state` thêm columns: `published_revision_id`, `published_by`, `published_at`, `published_live_hash`, `publish_backup_path`.

### Publish Service Module (`editorial/includes/publish.php`)

#### Tags Parser — `editorial_parse_tags_text()`
Split comma, trim, remove empty, dedupe case-insensitive (preserve first spelling), preserve order.

#### Preflight — `editorial_publish_preflight()`
Checks (ordered):
1. Admin actor re-verified (active, role=admin)
2. Article in catalog, file exists, path inside repo root (realpath containment)
3. Status = approved, approved_revision_id exists
4. Revision type = editorial, article_id matches
5. Verified snapshot (path, JSON, schema, article_id, content_hash)
6. Exactly one active assignment matching revision
7. current_revision_id == approved_revision_id
8. base_live_hash exists
9. Live HTML read once, hash matches base_live_hash (CRITICAL optimistic lock)
10. No active editing lock

#### Pure Render — `editorial_render_approved_html()`
- Nhận live HTML bytes + article + approved payload → trả new HTML bytes
- Replace `.article-prose` inner HTML bằng offset-based substr (không regex greedy)
- Update `script#article-meta` JSON: chỉ editable keys (title, publishDate, modifiedDate, tags, excerpt, image)
- Update `<title>`, `meta description`, `.article-summary`
- Cache busting trên JS files (article-layout.js, article-views/*.js)
- **KHÔNG touch taxonomy** (section, topic, library, breadcrumb, kicker, body data-nav)

#### Editable Field Contract
```text
title, excerpt, prose_html, publish_date, modified_date, featured_image, tags_text
```
Không thay: article_id, href, canonical, taxonomy fields.

#### Pre-write Validation — `editorial_validate_rendered_html()`
Parse rendered HTML, verify prose region, meta region, meta JSON valid, title not empty.

#### Backup — `editorial_create_publish_backup()`
- Storage: `editorial/storage/backups/<2-char>/<article-hash>/<timestamp>-<random>.html`
- Exact bytes của live HTML trước mutation
- Atomic temp+rename, verify byte count, verify hash

#### Atomic Replace — `editorial_atomic_replace_file()`
- Temp file SAME directory as target (same filesystem for rename atomicity)
- Write, verify byte count
- **Final optimistic lock**: immediately before rename, re-read target, re-hash, verify
- Rename temp → target

#### Compensation — `editorial_restore_backup()`
- Best-effort restore backup to target
- Atomic temp+rename, verify hash
- Log `article.publish.compensation_failed` nếu restore thất bại

#### Catalog Sync — `editorial_update_article_source()`
- Update `data/articles.json`: chỉ editable fields (title, excerpt, publishDate, modifiedDate, tags, image)
- Preserve tất cả field khác (id, href, canonical, section, taxonomy, classification, author, etc.)
- Race protection: re-hash source trước rename, abort nếu đã thay đổi
- Atomic temp+rename

#### Public Rebuild — `editorial_public_rebuild_after_publish()`
- Best-effort gọi `tools/rebuild_public_from_articles.py`
- Non-fatal: core publish đã thành công tại thời điểm này

#### Core Publish — `editorial_publish_approved_revision()`
Flow inside `editorial_transaction(BEGIN IMMEDIATE)`:
1. Re-verify all preflight inside transaction
2. Re-read live HTML, verify hash (double-check optimistic lock)
3. Render new HTML
4. Pre-write validation
5. Create backup + verify
6. **Point of no return** — atomic replace live file
7. Post-write verification (re-read, re-hash, re-parse)
8. Update data/articles.json (with compensation on failure)
9. Create published revision (type=published, same payload as approved)
10. Verify published revision snapshot
11. Close assignment (release_reason=published)
12. Delete lock (defensive)
13. Delete old draft
14. Update article state (status=published, base_live_hash=new hash)
15. Log `article.publish.succeeded`

Compensation: sau point-of-return, mọi failure → restore backup + rollback DB.

After transaction commit: caller gọi `editorial_public_rebuild_after_publish()` (best-effort).

### Publish Page (`editorial/publish.php`)
- Admin-only, POST + CSRF
- Preflight display: bảng kiểm tra status, revision, snapshot, assignment, live hash, lock
- Confirmation: checkbox + confirm dialog
- POST handler gọi `editorial_publish_approved_revision()` rồi `editorial_public_rebuild_after_publish()`
- Rebuild warning: "Bài đã được Publish nhưng đồng bộ dữ liệu public phụ trợ chưa hoàn tất."

### Status Transitions (Updated)
```text
published → editing (chỉ qua fresh claim/new work cycle)
```

### Claim Published Article
- `editorial_claim_article()` cho phép status published (assigned_user_id=NULL sau publish)
- Reset tất cả work-cycle fields: review_*, approved_*, published_*
- Historical revisions/activity giữ nguyên

### Review Page Updates
- Approved articles: "Chuẩn bị Publish" CTA link tới publish.php

### Dashboard Updates
- "Safe Publish" → "Đã sẵn sàng"
- Metric card "Đã xuất bản" (count published articles)

### Activity Events
- `article.publish.succeeded` — approved_revision_id, published_revision_id, assignment_id, hash_before, hash_after, backup_path, bytes_written
- `article.publish.failed` — stage, reason, hashes nếu có
- `article.publish.public_rebuild_failed` — message
- `article.publish.compensation_failed` — reason, hashes, backup_path
- `article.draft.force_discarded` — forced, discarded_draft_version, discarded_draft_hash

### Backup ≠ Revision
- Backup = exact previous public HTML bytes = operational recovery
- Revision = semantic editorial content snapshot = history/workflow

### Không sửa
- `/admin/` — chỉ đọc/tham khảo
- Không user-facing rollback/restore (Phase 8)
- Không refactor legacy publish
- Không taxonomy mutation

## Roadmap

1. **Foundation** (Phase 1) ✅ — Schema, auth, bootstrap, dashboard shell
2. **Users** (Phase 2) ✅ — Quản lý thành viên, auth revalidation, must_change_password
3. **Assignment** (Phase 3) ✅ — Article catalog, atomic claim, my-work, dashboard metrics
4. **Workspace/Lock/Draft** (Phase 4) ✅ — TinyMCE editor, lock, heartbeat, draft versioning
5. **Revisions/Compare** (Phase 5) ✅ — Baseline, editorial revision, immutable snapshot, content hash, compare, diff
6. **Review** (Phase 6) ✅ — Gửi duyệt, trả lại, approve, reassign, release, force unlock, revision hardening
7. **Safe Publish** (Phase 7) ✅ — Optimistic lock, backup, pure render, atomic replace, post-write verify, compensation, catalog sync, published revision
8. **Hardening** — Audit dashboard, bulk ops, performance, rollback/restore





