# Editorial V2

> **Current collaborative editorial system.**
> Đọc [`../AGENTS.md`](../AGENTS.md) trước khi sửa module.

## Thư mục này là gì?

`editorial/` là ứng dụng PHP cho luồng biên tập đa người dùng hiện tại:
ownership, Workspace, draft, revision, review, Safe Publish, public rebuild và
Google Drive + Sheet Handoff. Nó không phải public content renderer; website
public vẫn là HTML tĩnh tại repository root.

## Entry pages

| Trang | Vai trò |
|---|---|
| `index.php` | Redirect vào Dashboard. |
| `login.php`, `logout.php`, `change-password.php` | Authentication và password flow. |
| `dashboard.php` | Tổng quan Editorial. |
| `articles.php` | Danh sách, self-claim và Admin assignment/reopen actions. |
| `my-work.php` | Công việc của current user. |
| `article.php` | Workspace bài owned: draft, revision/stage/review/direct publish actions. |
| `revisions.php` | Danh sách revision của bài. |
| `compare.php` | Compare snapshot read-only. |
| `review.php` | Admin review queue/cockpit: revision-scoped Editor note, two baseline comparisons, approve/return and secondary assignment controls. |
| `publish.php` | Admin-approved Publish. |
| `resume-editing.php` | Same-owner approved resume. |
| `upload.php` | Endpoint upload ảnh có CSRF/ownership/lock checks. |
| `lock-heartbeat.php` | Endpoint gia hạn workspace lock. |
| `handoff.php` | POST Google Drive + Sheet handoff. |
| `google-handoff-settings.php` | Admin settings/verification cho external handoff. |
| `integrity.php` | Admin read-only integrity scan và action recovery được code cho phép. |
| `users.php` | Admin user management. |

## `includes/` module map

| Module | Tóm tắt |
|---|---|
| `bootstrap.php` | Khởi tạo constants, module nền, DB/schema/session. |
| `auth.php` | Session, roles, CSRF, user management, activity log. |
| `article_catalog.php` | Catalog `data/articles.json`, taxonomy read, safe path/hash. |
| `assignment.php` | Ownership/state/self-claim. |
| `workspace.php` | Parse/preview, lock, draft và payload. |
| `revision.php` | Snapshot immutable, revision, Baseline, Stage1/Stage2. |
| `review.php` | Review, approval, return, reassign, release, reopen. |
| `publish.php` | Safe Publish/backup/atomic replace/compensation. |
| `publication.php` | Publication eligibility cho Handoff. |
| `public_rebuild.php` | Artifact rebuild, image verification, public-ready marker. |
| `handoff.php` | Drive/Sheet archive state machine. |
| `media.php` | Image storage policy. |
| `settings.php`, `composio.php` | Server-side Handoff config và external client. |
| `integrity.php` | Scanner read-only toàn vẹn. |
| `database.php`, `migrations.php` | SQLite connection/transaction/schema. |
| `helpers.php`, `layout.php` | Request/UI helpers và shell. |

## `storage/`

`editorial/storage/` là runtime data, không phải public content:

- `editorial.sqlite` và WAL/SHM;
- `revisions/` — snapshot JSON immutable;
- `backups/` — Publish backups;
- `public-ready/` — marker revision/hash đã rebuild;
- runtime Handoff locks.

Directory có deny rule trong `.htaccess`; deployment phải duy trì bảo vệ tương
đương. Runtime storage bị `.gitignore` loại trừ.

## `assets/`

`editorial/assets/` chứa style/script đặc thù Editorial. Layout cũng tham chiếu
legacy `admin.css` để giữ nhất quán hiển thị, nhưng Editorial business logic
không phụ thuộc runtime vào `/admin`.

## Uploads

Ảnh upload **không** nằm trong `editorial/`. Chúng nằm tại site root:

```text
uploads/articles/YYYY/MM/
```

Payload và revision lưu public path tương đối site root.

## Read next

- [`../README.md`](../README.md)
- [`../AGENTS.md`](../AGENTS.md)
- [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md)
- [`../docs/CHANGE_IMPACT_MAP.md`](../docs/CHANGE_IMPACT_MAP.md)
- [`../docs/EDITORIAL_V2.md`](../docs/EDITORIAL_V2.md)
- [`../docs/PUBLISH_AND_HANDOFF.md`](../docs/PUBLISH_AND_HANDOFF.md)
- [`../docs/OPERATIONS.md`](../docs/OPERATIONS.md)
