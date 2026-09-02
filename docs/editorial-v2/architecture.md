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

## Roadmap

1. **Foundation** (Phase 1) ✅ — Schema, auth, bootstrap, dashboard shell
2. **Users** — Quản lý thành viên, thêm/sửa/deactivate
3. **Assignment** — Nhận bài, atomic claim, assignment history
4. **Workspace/Lock/Draft** — TinyMCE editor, heartbeat, auto-save
5. **Revisions/Compare** — Snapshot, diff bản cũ/mới
6. **Review** — Gửi duyệt, trả lại, approve
7. **Safe Publish** — Optimistic lock, backup, ghi HTML gốc
8. **Hardening** — Audit dashboard, bulk ops, performance
