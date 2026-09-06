# Vận hành và triển khai

> **Canonical — current.** Đây là hướng dẫn runtime/maintenance, không phải
> nhật ký phát triển.

## Runtime assumptions

Editorial V2 là PHP chạy cạnh website file-based/static:

- mã dùng PHP 8+ syntax và cần extension `pdo_sqlite`;
- SQLite chạy với foreign keys, busy timeout và WAL mode;
- web process phải đọc được public HTML, `data/articles.json` và asset cần thiết;
- các luồng ghi cần filesystem permissions phù hợp;
- Python chỉ cần khi đường fallback/full rebuild gọi
  `tools/rebuild_public_from_articles.py`; native rebuild vẫn tồn tại;
- external Google Handoff cần cURL/HTTPS theo Composio client và cấu hình đã
  verified.

Không giả định Docker, CI hay một web server cụ thể vì repository không định
nghĩa chúng như contract runtime.

## Writable locations

Deployment cần xét quyền ghi cho các đường dẫn thực sự được service dùng:

| Đường dẫn | Lý do ghi |
|---|---|
| `editorial/storage/` | SQLite, WAL/SHM, snapshots, activity-adjacent runtime state, settings. |
| `editorial/storage/revisions/` | Snapshot revision immutable mới. |
| `editorial/storage/backups/` | Backup Publish và catalog backup. |
| `editorial/storage/public-ready/` | Marker rebuild thành công. |
| `editorial/storage/handoff-locks/` | Per-article lock khi gọi Drive/Sheet. |
| `uploads/articles/YYYY/MM/` | Image upload. |
| Original live article HTML files | Safe Publish atomic replace. |
| `data/articles.json` | Catalog update trong Publish. |
| Derived public outputs | Rebuild: content index, hubs, feeds, views, taxonomy/menu và sitemap. |

Quyền ghi nên giới hạn vào đúng account web/worker cần thiết. Không cấp quyền ghi
rộng hơn chỉ để khắc phục lỗi tạm thời.

## Protected storage

`editorial/storage/` chứa SQLite, draft/revision evidence, backup, marker và
trạng thái runtime. Repository có `editorial/storage/.htaccess` với deny rule.

Khi triển khai trên web server khác Apache hoặc config override `.htaccess`,
phải triển khai một rule tương đương để không expose directory này qua HTTP.
Không commit SQLite runtime, WAL/SHM, revision, backup hoặc marker mới vào Git.

## Deployment model

1. Deploy website public HTML, assets, `data/`, `editorial/` và tool theo phiên
   bản đã review.
2. Giữ `editorial/storage/` writable và protected.
3. Bảo đảm file bài public và `data/articles.json` writable nếu Safe Publish
   được bật.
4. Đảm bảo `uploads/` writable nếu Image Upload được bật.
5. Để bootstrap/migrations idempotent chạy theo code hiện tại; không tự sửa
   schema thủ công.
6. Không trộn runtime DB/storage từ môi trường khác nếu chưa có quy trình
   backup/restore được review.

## Configuration

### Editorial runtime

Các path cơ sở do bootstrap xác định:

- `EDITORIAL_BASE_PATH`
- `EDITORIAL_STORAGE_PATH`
- `EDITORIAL_DB_PATH`
- `EDITORIAL_ARTICLES_SOURCE`

Thông thường chúng trỏ vào `editorial/`, `editorial/storage/`,
`editorial/storage/editorial.sqlite` và `data/articles.json`.

### Google Handoff

Admin-only page `editorial/google-handoff-settings.php` quản lý khái niệm:

- Composio API key;
- connected account ID;
- Drive folder ID;
- Spreadsheet ID;
- Sheet name;
- public base URL;
- pinned toolkit version;
- last verification metadata.

Không đưa giá trị thật của API key, token, password, folder ID, spreadsheet ID
hoặc private account ID vào source, docs, screenshot, log hay commit.

Thay đổi cấu hình làm verification status thành chưa verified; cần verify lại
trước khi Handoff sẵn sàng.

## Runtime smoke tests

Sau deploy, thực hiện manual test trên môi trường phù hợp với quyền thật:

1. Đăng nhập bằng Admin và Editor.
2. Claim một bài `available`; xác nhận không claim được bài có owner, review,
   approved hoặc published.
3. Mở Workspace, lock/heartbeat và Save Draft.
4. Tạo Baseline, Stage1, Stage2; mở Compare và xác nhận chỉ đọc.
5. Gửi review, Admin return/approve; xác nhận chỉ same owner resume approved.
6. Test Admin reassign/release và kiểm tra owner/lock/draft safety.
7. Test Published → Mở lại & giao và Published → Mở lại cho nhóm trên bài an
   toàn; kiểm tra publication facts còn nguyên.
8. Test Editor Direct Publish và Admin-approved Publish trên bài test.
9. Kiểm tra Featured Image trên HTML live, `data/articles.json`, hub/list/detail
   public và marker ready.
10. Kiểm tra public rebuild retry nếu cần.
11. Test Google Handoff: Drive canonical file và Sheet Article ID upsert.

Không dùng bài production nhạy cảm cho lần test đầu của một deployment mới.

## Troubleshooting

| Hiện tượng | Điểm kiểm tra trước |
|---|---|
| Trang báo migration error | Bootstrap/migration error code, PDO SQLite availability, storage write permission. |
| Không nhận/lưu bài | `assigned_user_id`, status, active assignment, lock token/expiry; xem `assignment.php` và `workspace.php`. |
| Live hash mismatch | File HTML live đã đổi ngoài Editorial hoặc baseline/publication hash không còn khớp. Không bỏ qua hash guard. |
| Publish thất bại | Preflight result, backup directory, render validation, atomic replace, compensation activity. |
| Rebuild không hoàn tất | Result code từ `public_rebuild.php`; kiểm tra artifact write permission, Python/exec availability chỉ khi fallback cần nó, target image verification. |
| Handoff bị khóa | Publication eligibility: published revision, live hash, public-ready marker, draft freshness, actor quyền và config verification. |
| Handoff Sheet lỗi | Header 11 cột, Article ID duplicate (2+ row bị block), schema/tool verification và post-write verification. |
| Handoff config chưa sẵn sàng | Settings đã đổi hoặc verification/pinned toolkit/account/destination/base URL chưa hợp lệ. |
| Integrity warning | `editorial/integrity.php` là scanner read-only; đọc issue code trước khi sửa dữ liệu. |

## Logs và trace

Editorial activity log nằm trong SQLite và ghi event cho auth, assignment,
revision, review, Publish, rebuild retry, configuration và Handoff. Nội dung
log external được lọc để tránh secret. Khi debug, ưu tiên event/code/metadata
an toàn thay vì in key hoặc token.

## Maintenance rules

- Backup trước khi thay đổi runtime storage hoặc file public hàng loạt.
- Không delete revision snapshot/history để “dọn lỗi”.
- Không clear `published_*` chỉ để mở assignment mới.
- Không sửa derived artifact bằng tay nếu rebuild là đường contract.
- Khi architecture thay đổi, cập nhật canonical docs cùng code.
