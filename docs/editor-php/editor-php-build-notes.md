# Ghi chú build và tích hợp editor PHP

## Mục tiêu

Tài liệu này mô tả cách:

1. dùng taxonomy/schema hiện tại để làm editor PHP  
2. import dữ liệu vào database  
3. export ngược ra static HTML site

---

## 1) Trạng thái hiện tại

Website HTML hiện đã có:

- `data/articles.json`
- `data/taxonomy.json`
- `data/editor-taxonomy.json`
- `data/menu-config.json`
- `data/hubs/*.json`
- `data/feeds/*.json`
- `data/article-views/*.json`
- `robots.txt`
- `sitemap.xml`

Đây là bộ artifact để:

- đọc taxonomy
- đọc menu
- đối chiếu metadata
- làm spec cho editor PHP

---

## 2) Source of truth khi có editor PHP

Khi chuyển sang editor PHP, **database phải là source of truth**.

Static site chỉ là artifact export từ database.

### Nguyên tắc

- Editor PHP ghi vào DB
- Build script đọc từ DB / export JSON từ DB
- sau đó sinh lại:
  - HTML
  - `data/*.json`
  - `robots.txt`
  - `sitemap.xml`

Không chỉnh tay trực tiếp các file HTML đã build.

### Diễn giải rất ngắn

- **User cuối** chỉ xem HTML tĩnh
- **Editor** dùng PHP admin để thêm / sửa / xóa bài
- PHP không nên sửa trực tiếp từng file HTML
- DB mới là nguồn dữ liệu gốc
- static HTML là output build từ DB

---

## 3) Mapping dữ liệu

### Taxonomy

Nguồn dùng cho editor:
- `data/editor-taxonomy.json`

Nguồn dùng cho frontend/static:
- `data/taxonomy.json`

### Menu

Nguồn dùng cho menu manager:
- `data/menu-config.json`

### Metadata bài

Nguồn đối chiếu khi import dữ liệu hiện tại:
- `data/articles.json`

---

## 4) Các bước làm editor PHP

### Bước 1 — tạo database schema

Dùng spec tại:
- `editor-php-form-spec.md`
- `editor-php-taxonomy-schema.md`

### Bước 2 — import seed taxonomy

Import trước:
- root categories
- library kinds
- domain / subdomain
- tool variants
- menu config

### Bước 3 — import articles

Map từ `data/articles.json` vào bảng:
- `articles`
- `article_categories`
- `article_tags`

### Bước 4 — làm editor form

Form phải bám taxonomy canonical:
- `section`
- `library_kind`
- `domain`
- `subdomain`
- `variant`
- `tags`
- `seo_title`
- `seo_description`
- `focus_keyword_primary`
- `focus_keywords_secondary`
- `robots_index/follow`
- `canonical_url`
- `og_*`
- `twitter_*`
- `schema_type`
- `schema_payload`
- `faq_items`

### Bước 5 — làm export pipeline

Editor PHP không render site trực tiếp.  
Nó chỉ:
- sửa DB
- kích hoạt job export

Job export sẽ:
- build HTML
- build `data/*.json`
- build `sitemap.xml`
- build `robots.txt`

---

## 5) Build/export flow đề xuất

### Option A — PHP gọi script build trực tiếp

Editor PHP:
1. user publish bài
2. PHP gọi script export
3. script export regenerate artifact

### Option B — queue/background job

Editor PHP:
1. lưu thay đổi vào DB
2. đẩy 1 job vào queue
3. worker chạy build
4. khi xong, ghi log / trạng thái build

### Khuyến nghị

Nếu scale hơn 2000 bài:
- **Option B tốt hơn**

---

## 6) Cần export những gì

Mỗi lần publish batch hoặc publish bài mới, nên regenerate:

- page article bị đổi
- hub liên quan
- `data/articles.json`
- `data/hubs/*.json`
- `data/feeds/*.json`
- `data/article-views/*.json`
- `sitemap.xml`
- `robots.txt`

---

## 7) Điều không nên làm

- không để editor PHP viết thẳng vào file HTML live từng bài
- không dùng tags để thay category
- không để menu hardcode lệch với `menu-config.json`
- không để article publish xong mà quên regenerate `data/` và `sitemap.xml`

---

## 8) Checklist trước khi bắt đầu code editor

- [ ] taxonomy canonical đã chốt
- [ ] menu public đã chốt
- [ ] `data/editor-taxonomy.json` đúng
- [ ] `data/menu-config.json` đúng
- [ ] schema form đã chốt
- [ ] rule tags đã chốt
- [ ] export pipeline từ DB → static đã thống nhất

---

## 9) Checklist sau khi làm xong editor

- [ ] tạo được bài mới
- [ ] sửa được taxonomy của bài
- [ ] gắn/xóa tag được
- [ ] preview URL/breadcrumb đúng
- [ ] publish xong regenerate artifact thành công
- [ ] `site_html_readiness_audit.py` không phát sinh blocker lớn

---

## 10) Kết luận

Muốn editor PHP vận hành bền:

- DB phải là source of truth
- taxonomy phải là controlled vocabulary
- menu phải map từ config
- static HTML chỉ là output build

Đây là cách ít rủi ro nhất để quản hơn 2000 bài về lâu dài.
