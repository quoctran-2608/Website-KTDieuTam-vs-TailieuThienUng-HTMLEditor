# Spec form và workflow cho editor PHP

## Mục tiêu

Tạo spec đủ rõ để dev PHP có thể xây:

- form thêm bài
- form sửa bài
- CRUD category / tag
- menu manager
- preview metadata / URL / breadcrumb

mà không phải đoán lại logic taxonomy.

---

## 1) Khối dữ liệu chính

### 1.1 Bài viết

Một bài viết nên có tối thiểu các field:

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `id` | string | Có | khóa nội bộ / slug canonical |
| `title` | string | Có | tiêu đề bài |
| `slug` | string | Có | duy nhất |
| `section` | enum | Có | `thu-vien` / `ban-tin` |
| `library_kind` | enum/null | Có điều kiện | chỉ có nếu `section=thu-vien` (`huong-dan / bieu-mau / cong-cu / van-ban`) |
| `domain` | string | Có | map từ `topicLv1Key` |
| `subdomain` | string | Có | map từ `topicLv2Key` |
| `variant` | string/null | Có điều kiện | hiện dùng cho `cong-cu` |
| `summary` | text | Có | mô tả ngắn / excerpt |
| `content_html` | longtext | Có | nội dung bài |
| `publish_date` | date | Có | ngày đăng |
| `modified_date` | date/null | Không | ngày cập nhật |
| `author_name` | string | Có | mặc định `Kế Toán Diệu Tâm` |
| `author_type` | enum | Có | `Organization` / `Person` |
| `canonical_url` | string | Có | URL thật của bài |
| `feature_image` | string/null | Không | đường dẫn ảnh đại diện |
| `status` | enum | Có | `draft / published / archived` |
| `source_file` | string/null | Không | file nguồn legacy |
| `legacy_primary_section` | string/null | Không | audit / trace |

### 1.1.1 SEO fields nên có ngay từ đầu

Để editor PHP có thể thay thế vai trò plugin SEO kiểu Rank Math về sau, nên có thêm:

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `seo_title` | string/null | Không | nếu trống, fallback từ `title` |
| `seo_description` | text/null | Không | nếu trống, fallback từ `summary/excerpt` |
| `focus_keyword_primary` | string/null | Không | từ khóa SEO chính |
| `focus_keywords_secondary` | json/array | Không | danh sách từ khóa SEO phụ |
| `robots_index` | tinyint | Có | `1=index`, `0=noindex` |
| `robots_follow` | tinyint | Có | `1=follow`, `0=nofollow` |
| `canonical_url` | string | Có | URL canonical thật |
| `og_title` | string/null | Không | Open Graph title |
| `og_description` | text/null | Không | Open Graph description |
| `og_image` | string/null | Không | Open Graph image |
| `og_image_alt` | string/null | Không | mô tả ảnh OG |
| `twitter_title` | string/null | Không | Twitter/X title |
| `twitter_description` | text/null | Không | Twitter/X description |
| `twitter_image` | string/null | Không | Twitter/X image |
| `schema_type` | string/null | Không | ví dụ `Article`, `NewsArticle`, `HowTo`, `FAQPage` |
| `schema_payload` | json/null | Không | JSON-LD override nếu cần |
| `breadcrumb_title` | string/null | Không | nếu muốn breadcrumb khác title |
| `faq_items` | json/array | Không | dùng khi bài có FAQ |
| `last_reviewed_date` | date/null | Không | ngày rà soát nội dung |

### Rule SEO gợi ý

- nếu `section = ban-tin`:
  - ưu tiên `schema_type = NewsArticle`
  - dùng cho tin thương hiệu / đào tạo / ưu đãi / địa điểm
- nếu `library_kind = huong-dan`:
  - ưu tiên `schema_type = Article` hoặc `HowTo` khi thật sự là hướng dẫn từng bước
- nếu `faq_items` có dữ liệu:
  - có thể sinh thêm JSON-LD `FAQPage`

---

---

### 1.2 Category tree

Nên có bảng `categories` thay vì hardcode trong PHP.

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `id` | string/int | Có | khóa category |
| `parent_id` | string/int/null | Không | tree category |
| `key` | string | Có | key ổn định |
| `label` | string | Có | nhãn hiển thị |
| `type` | enum | Có | `section / library_kind / domain / subdomain / variant` |
| `is_active` | tinyint | Có | bật/tắt category |
| `sort_order` | int | Có | thứ tự hiển thị |

### Rule

- mỗi bài có **1 primary category leaf**
- có thể có `secondary_category_ids[]` nếu cần cho collection/related
- nhưng URL canonical phải bám `primary category`

---

### 1.3 Tags

Nên tách thành:

- `tags`
- `article_tags`

| Field | Type | Required |
|---|---|---:|
| `tag_id` | int | Có |
| `name` | string | Có |
| `slug` | string | Có |
| `is_active` | tinyint | Có |

`article_tags`

| Field | Type |
|---|---|
| `article_id` | string/int |
| `tag_id` | int |

### Rule tags

- 3–7 tags/bài
- tag không được thay category
- tránh tag quá chung:
  - `Biểu mẫu`
  - `Mẫu biểu`
  - `Thủ tục`
  - `Công cụ`
  - `Phần mềm`

---

## 2) Logic phụ thuộc field trong form

### Bước 1 — chọn `section`

- `thu-vien`
- `ban-tin`

### Nếu `section = thu-vien`

Hiện field:
- `library_kind`

Giá trị:
- `huong-dan`
- `bieu-mau`
- `cong-cu`

### Bước tiếp theo

Hiện field:
- `domain`

Giá trị domain phải lấy theo:
- `data/editor-taxonomy.json`

### Sau khi chọn `domain`

Hiện field:
- `subdomain`

### Nếu `library_kind = cong-cu`

Hiện thêm field:
- `variant`

Nguồn dữ liệu:
- `toolVariants` trong `data/editor-taxonomy.json`

### Nếu `library_kind = bieu-mau`

Không hiện `variant` riêng.  
Level 3 sẽ đi qua `tags`.

### Nếu `section = ban-tin`

Không hiện `library_kind`.  
Chỉ cần:
- `domain` thuộc một trong các nhóm:
  - `gioi-thieu-thuong-hieu`
  - `khoa-hoc-dao-tao`
  - `uu-dai-thong-bao`
  - `co-so-dia-diem`
- `subdomain`

---

## 3) Gợi ý UI form

### Khối A — Thông tin cơ bản

- Tiêu đề
- Slug
- Tóm tắt
- Ảnh đại diện

### Khối B — Phân loại

- Section
- Library kind *(nếu có)*
- Domain
- Subdomain
- Variant *(nếu có)*

### Khối C — Metadata

- Publish date
- Modified date
- Author
- Canonical

### Khối D — SEO

- SEO title
- SEO description
- Focus keyword chính
- Focus keyword phụ
- Robots index/follow
- OG title / description / image
- Twitter title / description / image
- Schema type
- FAQ items

### Khối E — Tags

- auto-suggest tag
- hiển thị tag được gợi ý từ title/content
- editor thêm/bớt thủ công

### Khối F — Preview

- preview URL
- preview breadcrumb
- preview badge card
- preview SEO snippet
- preview JSON-LD/schema
- preview “Bài sẽ nằm ở đâu trong menu”

---

## 4) Validation bắt buộc

### Tiêu đề / slug

- `title` không rỗng
- `slug` không trùng
- `slug` chỉ chứa ký tự URL-safe

### Taxonomy

- `section` luôn bắt buộc
- nếu `section=thu-vien` thì `library_kind` bắt buộc
- `domain` bắt buộc
- `subdomain` bắt buộc
- nếu `library_kind=cong-cu` thì `variant` bắt buộc

### Tags

- tối thiểu 3 tag
- tối đa 7 tag
- không duplicate

### Metadata

- `publish_date` bắt buộc
- `author_name` bắt buộc
- `canonical_url` phải khớp slug thật

### SEO

- nếu `seo_title` trống → fallback `title`
- nếu `seo_description` trống → fallback `summary`
- `focus_keyword_primary` không nên trùng hoàn toàn với slug của quá nhiều bài khác
- `schema_payload` nếu có phải là JSON hợp lệ
- `robots_index = 0` thì phải cân nhắc loại khỏi sitemap

---

## 5) Workflow tạo/sửa bài

### Tạo bài mới

1. nhập title
2. hệ thống gợi ý slug
3. chọn `section`
4. chọn taxonomy theo thứ tự phụ thuộc
5. nhập summary / content
6. gợi ý tags
7. preview
8. lưu draft hoặc publish

### Sửa bài

1. khóa field slug nếu đã publish *(hoặc chỉ cho đổi có cảnh báo)*
2. đổi taxonomy phải update:
   - breadcrumb
   - URL preview
   - menu context
3. lưu `modified_date`

### Xóa bài

- soft delete tốt hơn hard delete
- status → `archived`

---

## 6) Menu manager

Menu không nên hardcode trong PHP.

Nên đọc từ:
- `data/menu-config.json`

### Menu manager nên cho phép

- bật/tắt item menu
- đổi label menu
- đổi sort order
- map category → menu item
- chọn có submenu hay không

### Nhưng:

- category tree vẫn là chuẩn chính
- menu chỉ là lớp trình bày

---

## 7) Gợi ý DB schema tối thiểu

### `articles`
- id
- title
- slug
- section
- library_kind
- domain
- subdomain
- variant
- summary
- content_html
- publish_date
- modified_date
- author_name
- author_type
- canonical_url
- feature_image
- status
- source_file

### `categories`
- id
- parent_id
- key
- label
- type
- is_active
- sort_order

### `article_categories`
- article_id
- category_id
- is_primary

### `tags`
- id
- name
- slug
- is_active

### `article_tags`
- article_id
- tag_id

### `menu_items`
- id
- key
- label
- href
- category_key/null
- parent_id/null
- sort_order
- is_active

---

## 8) Source of truth

### Frontend hiện tại
- `data/articles.json`
- `data/taxonomy.json`
- `data/editor-taxonomy.json`
- `data/menu-config.json`

### Khi có editor PHP

Source of truth sẽ chuyển sang:
- database

và builder/static export chỉ là artifact từ DB.

### Diễn giải vận hành

- **User cuối** chỉ xem HTML tĩnh
- **Editor** thao tác trong PHP admin
- PHP admin ghi vào DB
- job build/export sinh lại HTML + JSON + sitemap + robots

---

## 9) Mốc chuyển giao sang editor

Chỉ nên bắt đầu code editor khi:

- taxonomy đã ổn định
- HTML site chạy mượt
- filters hoạt động đúng
- article metadata đầy đủ
- readiness audit không còn blocker lớn

---

## 10) Kết luận

Form editor tốt nhất là:

- ít field
- field hiện theo ngữ cảnh
- taxonomy canonical rõ
- tags chỉ là phụ
- menu map riêng

Đây là cách dễ dùng nhất cho editor và bền nhất cho hệ thống 2000+ bài.
