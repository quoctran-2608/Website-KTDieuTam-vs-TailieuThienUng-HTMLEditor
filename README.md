# Kế Toán Diệu Tâm — website tĩnh và Editorial V2

> **Tài liệu canonical (current).** Khi mô tả ở đây khác với mã, kiểm tra mã
> hiện tại rồi cập nhật tài liệu cùng thay đổi đó.

## Repository này là gì?

Đây là repository vận hành website Kế Toán Diệu Tâm. Hệ thống gồm:

- website public chủ yếu là **HTML tĩnh**;
- catalog và các artifact dữ liệu public;
- `/admin` PHP đời cũ, vẫn giữ làm fallback/legacy;
- `/editorial` — **Editorial V2**, luồng biên tập đa người dùng hiện tại;
- công cụ Python/PHP phục vụ rebuild, taxonomy và bảo trì dữ liệu.

Nội dung bài public cuối cùng nằm trong **chính file HTML bài viết đang live**.
Editorial V2 không thay website thành ứng dụng render nội dung từ SQLite.

## Các hệ thống hiện tại

| Hệ thống | Vai trò | Điểm vào chính |
|---|---|---|
| **Public site** | Website HTML tĩnh, hub Thư viện/Bản tin, trang bài, JS public và asset. | `/`, `thu-vien.html`, `ban-tin.html`, các file `*.html` |
| **Legacy Admin** | Admin PHP cũ/fallback. Không phải kiến trúc collaborative hiện tại. | `admin/` |
| **Editorial V2** | Luồng nhận bài, draft, revision, review, Publish và bàn giao đa người dùng hiện tại. | `editorial/` |
| **Tools / build pipeline** | Rebuild artifact public, quản lý taxonomy, audit và thao tác bảo trì. | `tools/` |

**Editorial V2 là hệ thống biên tập cộng tác hiện tại.**
`/admin` vẫn tồn tại như fallback/legacy; không được nhầm tài liệu hoặc contract
của `/admin` với Editorial V2.

## Bản đồ repository

| Đường dẫn | Nội dung |
|---|---|
| `/` | Các trang HTML public, hub root, script layout public và cấu hình redirect. |
| `admin/` | Legacy Admin PHP. Xem `admin/README.md` với nhãn legacy. |
| `editorial/` | Editorial V2: trang PHP, service, asset và storage runtime. |
| `editorial/includes/` | Business logic: auth, ownership, draft, revision, review, Publish, rebuild, Handoff. |
| `editorial/storage/` | SQLite, snapshot, backup, marker và lock runtime; phải được bảo vệ khỏi web public. |
| `assets/` | CSS, JS, ảnh và asset public dùng chung. |
| `data/` | Catalog, taxonomy và artifact public JSON/JS. |
| `docs/` | Tài liệu canonical, legacy và archive lịch sử. |
| `tools/` | Tool CLI cho rebuild/taxonomy/audit/bảo trì. |
| `uploads/` | Ảnh upload public. Ảnh Editorial lưu tại `uploads/articles/YYYY/MM/`. |

Repository chứa rất nhiều file HTML bài viết; không cần đọc toàn bộ để định hướng.
Hãy bắt đầu từ tài liệu và module liên quan.

## Tóm tắt nguồn dữ liệu

| Miền dữ liệu | Authority / vai trò hiện tại | Không được hiểu nhầm là |
|---|---|---|
| Nội dung public live | File HTML bài public gốc | Nội dung render từ SQLite |
| Catalog bài | `data/articles.json` | Toàn bộ nội dung prose của bài |
| Workflow Editorial | SQLite trong `editorial/storage/editorial.sqlite` | Kho nội dung public |
| Revision | Snapshot JSON bất biến trong `editorial/storage/revisions/` | File public live |
| Media | File trong `uploads/articles/YYYY/MM/`; payload chỉ lưu path | Binary copy trong snapshot |
| Artifact public | `content-index.js`, `data/hubs/`, `data/article-views/`, feed, taxonomy/menu dẫn xuất, sitemap | Source bài gốc |
| Google Drive + Sheet | Hồ sơ/archive ngoài hệ thống của **published revision** đã xác thực | Source cho Publish hoặc draft |

Xem giải thích đầy đủ tại [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Luồng Editorial chính

```text
Available → Editing → Ready Review → Approved → Published
                  ↘ Returned ────────┘
```

- Chỉ bài ở đúng trạng thái `available` mới được Editor tự nhận.
- Editor Direct Publish là Publish không terminal: owner và workflow active vẫn
  được giữ.
- Admin-approved Publish là terminal: trạng thái chuyển thành `published`.
- Admin có thể release/reassign bài đang `editing` hoặc `returned`, hoặc mở lại
  bài `published` theo luồng có kiểm soát.

## Đọc tiếp

- [AGENTS.md](AGENTS.md) — quick context và bất biến an toàn cho AI/agent.
- [docs/README.md](docs/README.md) — chỉ mục toàn bộ tài liệu.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — kiến trúc repository.
- [docs/EDITORIAL_V2.md](docs/EDITORIAL_V2.md) — reference Editorial V2.
- [docs/PUBLISH_AND_HANDOFF.md](docs/PUBLISH_AND_HANDOFF.md) — Publish, rebuild, media, Drive/Sheet.
- [docs/OPERATIONS.md](docs/OPERATIONS.md) — triển khai, runtime và vận hành.
- [editorial/README.md](editorial/README.md) — entrypoint kỹ thuật tại chỗ.
