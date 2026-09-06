# Publish, public rebuild và Google Handoff

> **Canonical — current.** Đây là các luồng ghi file/external side effect có
> rủi ro cao. Đọc code trước khi thay đổi:
> `editorial/includes/publish.php`, `public_rebuild.php`, `publication.php`,
> `handoff.php`, `media.php`.

## Safe Publish

### Invariant chính

Safe Publish luôn ghi vào **cùng file HTML bài public gốc**. Nó không tạo một
bản public riêng trong `editorial/` và không render bài public từ SQLite.

Publish lấy content authority từ revision snapshot verified trên server. Nó
không sử dụng browser state chưa lưu.

### Pipeline

`editorial_publish_revision_core()` thực hiện các bước chính:

1. preflight policy/role, article, path, state, revision, snapshot,
   assignment, live hash, lock và backup directory;
2. lặp lại các kiểm tra nhạy cảm trong transaction;
3. đọc live HTML gốc và kiểm tra `base_live_hash`;
4. chuẩn hóa payload verified, render prose/meta/title/summary vào live HTML;
5. validate HTML đã render trước khi ghi;
6. tạo backup live HTML;
7. atomic replace bằng file tạm + rename vào file live gốc;
8. đọc lại, hash và parse/validate file vừa ghi;
9. cập nhật `data/articles.json` với backup và guard hash;
10. tạo published revision snapshot immutable;
11. cập nhật publication facts/workflow theo policy;
12. nếu lỗi sau điểm ghi destructive, thực hiện một lần compensation để phục
    hồi live HTML/catalog khi guard hash cho phép;
13. chạy public rebuild sau transaction thành công.

`publish_backup_path` lưu đường dẫn tương đối trong
`editorial/storage/backups/`.

## Editor Direct và Admin-approved Publish

| Hành vi | Editor Direct Publish | Admin-approved Publish |
|---|---|---|
| Actor | Current Editor owner | Admin active |
| State trước Publish | `editing` hoặc `returned` | `approved` |
| Candidate | Snapshot immutable được chuẩn bị từ saved server-side draft | `approved_revision_id` verified |
| Lock | Cần lock token của Editor | Không được còn lock chỉnh sửa |
| Draft freshness | Draft hash + version phải khớp candidate | Review/approved evidence là authority |
| Workflow sau Publish | Non-terminal: giữ owner/assignment/draft/lock semantics | Terminal: `published`, owner null, active assignment đóng |

Cả hai policy đều ghi publication facts và created published revision. Handoff
chỉ xét published revision hiện tại đã được xác thực, không xét Stage hoặc
approved revision trực tiếp.

## Featured Image và body images

### Featured Image

Featured Image là trường payload riêng:

```text
featured_image
featured_image_alt
featured_image_title
featured_image_caption
featured_image_credit
```

Luồng:

```text
payload verified
→ `image`, `imageAlt`, `imageTitle`, `imageCaption`, `imageCredit` trong
  `script#article-meta`
→ các key cùng tên trong `data/articles.json`
→ public rebuild
→ content index / hub JSON / card hub tĩnh / list-detail public
```

Publish giữ ảnh hiện có nếu payload `featured_image` để trống theo contract
normalization. Với bốn key metadata mới, Publish phân biệt:

- key **vắng mặt** trong snapshot legacy → giữ giá trị `article-meta` live;
- key **có mặt nhưng rỗng** → xóa giá trị live cũ có chủ ý.

Rebuild đồng bộ image và image Alt target vào dynamic artifact, patch ảnh card
hub tĩnh có liên quan, rồi xác minh representation trước khi ghi marker ready.
Hub card chỉ mang `image_alt` và optional `image_title`; Caption/Credit chỉ
hiển thị ở article detail.

### Body images

Body images và metadata của chúng nằm trong `prose_html`, không phải bảng
database:

- `src`, `alt`, `title` ở `img`;
- Caption/Credit plain text trong semantic `figure.article-image` /
  `figcaption` khi Editor dùng control **Thông tin ảnh**.

Upload endpoint chỉ nhận file và trả public path. Nó không nhận Alt, Title,
Caption hoặc Credit. Metadata được bảo vệ bởi luồng Workspace Draft Save hiện
có, không bởi upload transport.

## Media upload

`editorial/upload.php` yêu cầu:

- authentication;
- CSRF hợp lệ;
- article tồn tại;
- current `assigned_user_id` đúng user;
- status `editing` hoặc `returned`;
- lock token hợp lệ và chưa hết hạn.

`editorial/includes/media.php` chỉ nhận:

- JPG (`image/jpeg`);
- PNG (`image/png`);
- GIF (`image/gif`);
- WEBP (`image/webp`);
- dung lượng lớn hơn 0 và không quá **8 MiB**.

File được kiểm tra MIME server-side, đặt tên an toàn/unique và lưu tại:

```text
uploads/articles/YYYY/MM/<filename>
```

Payload lưu site-root-relative public path, không lưu absolute filesystem path.
Snapshot revision chỉ lưu references trong payload, không duplicate binary file.

## Public rebuild

`editorial/includes/public_rebuild.php` rebuild dữ liệu public sau Publish.

### Chọn implementation hiện tại

- Với full rebuild có refresh taxonomy, runner thử **Python** trước; nếu Python
  không thành công thì dùng PHP native fallback.
- Với Publish fast path, runner ưu tiên **PHP native** mà không refresh taxonomy
  vì Publish không sửa taxonomy keys; nếu native fail, runner fallback Python
  full để sửa trạng thái output an toàn.

Không biến mô tả này thành assumption vĩnh viễn: kiểm tra
`editorial_public_rebuild_run()` khi đổi rebuild behavior.

### Artifact hiện tại

Tùy mode, rebuild tạo/cập nhật:

- `content-index.js`;
- `data/hubs/thu-vien.json` và `.js`;
- `data/hubs/ban-tin.json` và `.js`;
- `data/feeds/latest-thu-vien.json`;
- `data/feeds/latest-ban-tin.json`;
- `data/article-views/<article-id>.json` và `.js` cho target fast path, hoặc
  toàn bộ article views ở full mode;
- `data/taxonomy.json`;
- `data/editor-taxonomy.json`;
- `data/menu-config.json`;
- `sitemap.xml`.

Taxonomy artifact refresh sử dụng `data/taxonomy-master.json` khi master hợp lệ.
Editorial Publish không được tự ý thay taxonomy source.

### Target image verification

Sau builder, runner:

1. đồng bộ ảnh/Alt target vào `content-index.js` và hub data;
2. patch `src`, Alt và optional Title của card ảnh trong hub static có bài target;
3. so sánh identity ảnh và Alt ở catalog, content index, hub JSON và static card;
4. trả lỗi rõ ràng nếu representation chưa khớp.

Điều này bảo vệ Featured Image public khỏi thành công một phần. Native và Python
builder đều phải giữ bốn metadata fields trong content index/current article
view; Python fast path không được tự thêm `--include-hub-pages`.

## Public-ready marker

Chỉ rebuild hoàn tất mới ghi marker dưới:

```text
editorial/storage/public-ready/<sha256(article-id)>.json
```

Marker có article ID, `published_revision_id`, `published_live_hash`, thời điểm
và rebuild method. Hệ thống đọc lại marker để xác minh nó khớp publication facts.

Marker không bị xóa chỉ vì ownership đổi. Handoff yêu cầu marker khớp exact
published revision + live hash hiện tại.

## Google Handoff

### Ý nghĩa

Google Handoff là **archive cuối cùng của bản thực sự đã Publish**, không phải
backup draft hay bản Stage2.

Content authority duy nhất là `published_revision_id` đã verified. Handoff
không được lấy content từ:

- Stage1;
- Stage2;
- review revision;
- approved revision;
- browser draft;
- file live fallback không có publication proof.

### Eligibility

`editorial_publication_handoff_status()` yêu cầu:

1. state có published revision;
2. revision tồn tại, thuộc đúng article, type `published`, snapshot verified;
3. `published_live_hash` tồn tại và khớp bytes live HTML hiện tại;
4. public-ready marker khớp exact published revision/hash;
5. nếu current owner vẫn có draft, draft hash phải khớp published revision;
6. config Drive/Sheet đã verified;
7. actor là Admin hoặc current owner/historical contributor hợp lệ theo service.

### Drive

Drive giữ một canonical HTML file cho mỗi Article ID:

- lần đầu tạo file;
- lần sau dùng file canonical hiện có và update đúng file đó;
- nếu update file canonical thất bại, service không tạo file mới để tránh trùng
  archive;
- nội dung file là `prose_html` của published snapshot, không phải toàn bộ page
  shell.

### Sheet

Sheet phải có đúng header:

1. `Article ID`
2. `Tên bài`
3. `URL`
4. `Internal Links`
5. `Hình ảnh`
6. `Category`
7. `Biên tập bởi`
8. `HTML Archive`
9. `Ghi chú`
10. `Published Revision`
11. `Ngày bàn giao`

`Article ID` là business key:

| Số dòng khớp Article ID | Hành vi |
|---:|---|
| 0 | Insert row mới |
| 1 | Update row hiện có |
| 2+ | Chặn; Admin phải xử lý dòng trùng |

Sau upsert, service đọc lại Sheet và xác minh đúng một row, published revision
và URL archive trước khi đánh dấu sync `synced`.

### Images trong Sheet

Trường `Hình ảnh` bao gồm URL ảnh body tìm được trong published `prose_html`,
cộng Featured Image published nếu chưa có trong danh sách.

## Security and configuration

Handoff settings và secret tồn tại server-side trong SQLite. Tài liệu chỉ mô tả
concept/variable name, không ghi API key, token, Drive folder ID, Spreadsheet ID
hay connected account ID thực tế.

Các cấu hình cần được save/verify gồm khái niệm:

- Composio API key;
- connected account;
- Drive folder;
- Spreadsheet;
- Sheet tab;
- public base URL;
- pinned toolkit version;
- verification status.
