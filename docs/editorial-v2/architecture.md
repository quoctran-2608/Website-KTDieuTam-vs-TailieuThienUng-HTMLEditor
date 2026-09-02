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

## Roadmap

1. **Foundation** (Phase 1) ✅ — Schema, auth, bootstrap, dashboard shell
2. **Users** (Phase 2) ✅ — Quản lý thành viên, auth revalidation, must_change_password
3. **Assignment** (Phase 3) ✅ — Article catalog, atomic claim, my-work, dashboard metrics
4. **Workspace/Lock/Draft** — TinyMCE editor, heartbeat, auto-save
5. **Revisions/Compare** — Snapshot, diff bản cũ/mới
6. **Review** — Gửi duyệt, trả lại, approve
7. **Safe Publish** — Optimistic lock, backup, ghi HTML gốc
8. **Hardening** — Audit dashboard, bulk ops, performance
