# Chuẩn kiến trúc bài viết cho 2 menu Thư viện / Bản tin

## 1) Mục tiêu

Tài liệu này là checklist chuẩn để:

- thêm bài viết HTML mới mà **không phá kiến trúc hiện tại**
- giữ cho bài viết **dễ bảo trì**
- tránh việc phải sửa hàng loạt file khi đổi giao diện chung
- chuẩn bị sẵn nền tảng để tối ưu SEO/GEO/AI Search sau này

Nguyên tắc cốt lõi:

> **Bài viết chỉ chứa nội dung lõi của chính nó.**  
> Các phần giao diện dùng chung phải lấy từ template/script ngoài.

---

## 2) Quy ước đường dẫn trong tài liệu này

- Mọi path bên dưới được tính từ **root của thư mục `Ketoandieutam.com/`**
- Vì file này nằm trong `docs/`, khi mở trực tiếp từ đây thì:
  - `site-shell.js` tương ứng `../site-shell.js`
  - `content-index.js` tương ứng `../content-index.js`
  - `article-layout.js` tương ứng `../article-layout.js`
  - `assets/...` tương ứng `../assets/...`

---

## 3) Kiến trúc hiện tại cần giữ

### A. Phần phải giữ **static trong từng file bài viết**

Đây là **content lõi** của bài, phải có sẵn trong HTML:

- `title`
- `meta description`
- `canonical`
- `h1`
- breadcrumb text của bài
- topic/tag chính hiển thị đầu bài
- summary / đoạn mô tả ngắn
- toàn bộ thân bài (`article-prose`)
- `article-meta` JSON nhỏ của chính bài đó

### B. Phần phải lấy từ **template/script ngoài**

Các phần này **không được hardcode riêng cho từng bài**:

- header / menu / footer / contact bar  
  → render từ `site-shell.js`
- sidebar bài viết
- điều hướng bài trước / bài sau
- nút quay lại danh sách
- block “Đọc tiếp cùng chuyên đề”
- block “Mới từ chuyên mục khác”
- mobile article nav

Các phần trên render từ:

- `content-index.js` → runtime index hiện tại cho article chrome
- `data/articles.json` → metadata canonical đã build
- `data/article-views/...` → recommendation / prev-next theo bài
- `article-layout.js` → layout/article chrome dùng chung
- `assets/css/content-hub.css` → style dùng chung
- `assets/images/site/` → ảnh dùng chung của site
- `assets/images/content/` → ảnh bài viết được copy từ nguồn

---

## 4) File nào đang giữ vai trò gì

### `site-shell.js`
Render:
- logo
- header
- menu
- footer
- contact bar
- back-to-top

### `content-index.js`
Là **runtime index hiện tại** cho article chrome.  
Chứa metadata toàn bộ bài viết đã build:

- section
- title
- excerpt
- topic
- tags
- image
- author
- publish/modified date
- prev / next
- related
- latest other sections

### `data/`
Là lớp metadata tĩnh phục vụ scale lớn hơn:

- `data/articles.json` → metadata bài viết canonical
- `data/hubs/*.json` → taxonomy/hub metadata
- `data/feeds/*.json` → feed mới nhất
- `data/article-views/...` → recommendation theo bài

### `article-layout.js`
Đọc:
- `article-meta` trong file bài
- `content-index.js`

Rồi render:
- sidebar
- điều hướng trên / dưới bài
- recommendations
- mobile nav

### `docs/`
Chứa tài liệu nội bộ của site:

- `docs/article-template-standard.md`
- `docs/sample-migration-thu-vien-ban-tin.md`
- `docs/sample-migration-thu-vien-ban-tin.json`

> Tài liệu này chỉ liệt kê các path nằm **bên trong `Ketoandieutam.com/`**.

---

## 5) Cấu trúc tối thiểu của một file bài viết mới

Một bài viết HTML mới cần có các host/placeholder sau:

```html
<body class="article-page" data-root="../" data-nav="thu-vien">
  <div id="siteHeader"></div>

  <!-- hero + breadcrumb + title + summary + body là static của bài -->

  <div id="articleTopNav" class="article-top-nav"></div>
  <aside id="articleSidebar" class="article-side"></aside>
  <div id="articleBottomNav" class="article-bottom-nav"></div>
  <div id="articleRecommendations" class="article-recommendations"></div>

  <div id="siteFooter"></div>

  <script id="article-meta" type="application/json">...</script>
  <script src="../site-shell.js"></script>
  <script src="../content-index.js"></script>
  <script src="../article-layout.js"></script>
</body>
```

---

## 6) `article-meta` tối thiểu phải có

Ví dụ:

```json
{
  "id": "thu-vien/ten-bai-viet.html",
  "sectionKey": "thu-vien",
  "title": "Tiêu đề bài viết",
  "topicLabel": "Tên chuyên đề con",
  "tags": ["thuế gtgt", "hóa đơn"],
  "publishDate": "2025-05-17",
  "modifiedDate": "2025-05-20",
  "authorName": "Kế Toán Diệu Tâm",
  "authorType": "Organization"
}
```

### Trường bắt buộc hiện tại

- `id`
- `sectionKey`
- `title`
- `topicLabel`

### Trường nên có ngay khi bắt đầu thêm bài mới

- `tags`
- `publishDate`
- `modifiedDate`
- `authorName`
- `authorType`

---

## 7) Metadata chuẩn nên có cho mọi bài mới

Hiện tại hệ thống đã sẵn chỗ cho các field này. Khi thêm bài mới, nên điền đầy đủ:

### Nhóm nội dung cơ bản
- `title`
- `meta description`
- `h1`
- `summary`
- `canonical`
- `sectionKey`
- `topic_lv1`
- `topic_lv2`
- `tags`

### Nhóm thời gian
- `publishDate` → ngày xuất bản gốc
- `modifiedDate` → ngày cập nhật gần nhất

> Dùng format chuẩn: `YYYY-MM-DD`  
> Nếu có giờ: `YYYY-MM-DDTHH:mm:ss+07:00`

### Nhóm tác giả / chịu trách nhiệm nội dung
- `authorName`
- `authorType` (`Person` hoặc `Organization`)

### Nhóm nên chuẩn bị cho SEO/GEO/AI Search về sau
- `reviewerName`
- `editorName`
- `sourceName`
- `sourceUrl`
- `legalReference`
- `ogImage`
- `ogImageAlt`
- `faqItems`
- `lastReviewedDate`

---

## 8) Quy tắc khi thêm bài viết mới

### Bắt buộc
1. Chọn đúng mục:
   - `thu-vien`
   - `ban-tin`
2. Nếu là `thu-vien`, gắn thêm loại nội dung nội bộ phù hợp:
   - `huong-dan`
   - `bieu-mau`
   - `cong-cu`
3. Đặt slug ngắn, sạch, ổn định
4. Không hardcode header / footer / sidebar riêng trong file bài
5. Không nhúng riêng block related/latest theo kiểu copy-paste thủ công
6. Bổ sung metadata vào nguồn build để `content-index.js` tự sinh lại

### Không nên
- tự tạo nav bài trước/sau bằng tay
- tự sửa sidebar từng bài
- tự thêm CTA khác biệt riêng từng bài nếu không có chủ đích rõ ràng

---

## 9) Quy trình chuẩn khi thêm một bài mới

### Nếu thêm bài từ nguồn nội bộ
1. Đặt file HTML nguồn vào đúng nguồn dữ liệu
2. Gán đúng section/topic/tag
3. Bổ sung:
   - `publishDate`
   - `modifiedDate`
   - `authorName`
4. Chạy lại quy trình build nội bộ
5. Kiểm tra output:
   - file bài
   - `content-index.js`
   - hub page của mục tương ứng

### Nếu tự tạo bài HTML thủ công
1. Tạo file bài theo template article hiện tại
2. Điền đủ head + content lõi
3. Nhúng `article-meta`
4. Đăng ký bài đó vào nguồn metadata nội bộ
5. Build lại để:
   - prev/next
   - related
   - latest other sections
   - content index
   được cập nhật đồng bộ

---

## 10) Checklist QA trước khi publish

### Kiến trúc
- [ ] Không có header/menu/footer hardcode riêng trong bài
- [ ] Có `site-shell.js`
- [ ] Có `content-index.js`
- [ ] Có `data/articles.json`
- [ ] Có `data/article-views/...`
- [ ] Có `article-layout.js`
- [ ] Có `article-meta`

### Nội dung
- [ ] Có đúng 1 thẻ `h1`
- [ ] `title` và `h1` khớp logic
- [ ] `meta description` đủ rõ, không bị trùng lặp vô nghĩa
- [ ] Có summary đầu bài
- [ ] Breadcrumb đúng mục

### Metadata
- [ ] `canonical` đúng
- [ ] `publishDate` có giá trị nếu là bài mới
- [ ] `modifiedDate` có giá trị nếu đã chỉnh sửa
- [ ] `authorName` có giá trị
- [ ] `tags` có ý nghĩa, không nhồi từ khóa
- [ ] `robots.txt` đã có
- [ ] `sitemap.xml` đã regenerate

### Kỹ thuật
- [ ] Ảnh có `alt`
- [ ] Link điều hướng bài trước/sau hoạt động
- [ ] Nút quay lại danh sách hoạt động
- [ ] Block “Đọc tiếp cùng chuyên đề” hoạt động
- [ ] Block “Mới từ chuyên mục khác” hoạt động

---

## 11) Khuyến nghị vận hành lâu dài

### Nên làm
- giữ một **nguồn metadata trung tâm**
- runtime hiện tại có thể đọc từ `content-index.js`
- nhưng build chuẩn phải sinh thêm `data/` để scale lớn
- mọi thay đổi UI chung chỉ sửa trong:
  - `site-shell.js`
  - `article-layout.js`
  - `assets/css/content-hub.css`

### Không nên
- sửa thủ công 1 bài rồi quên không đồng bộ index
- để bài mới thiếu `publishDate` / `modifiedDate`
- thêm HTML mới mà không rebuild `content-index.js`
- thêm batch mới mà không regenerate `data/`, `sitemap.xml`, `robots.txt`

---

## 12) Kết luận

Chuẩn hiện tại tốt nhất là:

- **content lõi** của bài → giữ static trong file bài
- **chrome quanh bài** → lấy từ template/script ngoài
- **dữ liệu điều hướng / related / latest** → runtime có thể lấy từ `content-index.js`, nhưng về dài hạn nên bám `data/`

Đây là cách bền nhất để:

- dễ sửa giao diện hàng loạt
- dễ thêm bài mới
- tránh copy-paste sai
- chuẩn bị sẵn cho tối ưu SEO/GEO/AI Search sau này
