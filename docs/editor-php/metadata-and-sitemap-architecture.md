# Kiến trúc metadata và sitemap cho `ketoandieutam.vn`

## Mục tiêu

Trước khi import full hơn 2000 bài, site tĩnh cần có cấu trúc metadata và SEO artifact đủ rõ để:

- quản tên file ↔ tiêu đề ↔ đường dẫn ổn định
- giữ metadata tập trung, không phải đi mò từng file HTML
- hỗ trợ build hub, article recommendation, latest feed
- sinh `sitemap.xml` và `robots.txt`
- cho phép mở rộng full import mà không phụ thuộc vào 1 file JS lớn duy nhất

---

## 1) Tình trạng hiện tại

### Nguồn gốc metadata

Nguồn hiện có:

- `TailieuKeToanThienUng/index.html`

Trong đó có block:

- `catalog-data`

Catalog này đang chứa **2066 bài** với các field gốc như:

- `file`
- `href`
- `title`
- `sort_key`
- `tags`
- `topic_lv1_key / label`
- `topic_lv2_key / label`

### Metadata sau build

Builder hiện enrich thêm và build ra:

- `content-index.js`
- article HTML
- hub HTML

Field build-time hiện có thể gồm:

- `title`
- `canonical`
- `section`
- `libraryKind`
- `publishDate`
- `modifiedDate`
- `author`
- `tags`
- `primarySection`
- `secondarySections`
- `classificationReasons`

---

## 2) Vì sao chưa nên chỉ dựa vào `content-index.js`

`content-index.js` vẫn hữu ích cho sample và runtime hiện tại, nhưng nếu scale đủ 2066 bài thì:

- file sẽ lớn
- parse JS sẽ nặng
- mọi bài đều phải tải cùng 1 index lớn

Nên về dài hạn, `content-index.js` chỉ nên là:

- artifact tiện cho runtime sample
- hoặc file bootstrap mỏng

Chứ **không nên là metadata store duy nhất**.

---

## 3) Cấu trúc metadata chuẩn đề xuất

Builder hiện đã được mở rộng để sinh thêm thư mục:

- `data/`

### Cấu trúc

```text
ketoandieutam.vn/
  data/
    articles.json
    taxonomy.json
    editor-taxonomy.json
    menu-config.json
    hubs/
      thu-vien.json
      ban-tin.json
    feeds/
      latest-thu-vien.json
      latest-ban-tin.json
    article-views/
      thu-vien/<slug>.json
      ban-tin/<slug>.json
```

### Ý nghĩa từng file

#### `data/articles.json`
Metadata canonical cho toàn bộ bài đã build:

- id
- title
- href
- canonical
- image
- tags
- topic lv1/lv2
- primary/secondary section
- library kind
- publish/modified date
- author

#### `data/taxonomy.json`
Tree taxonomy đang dùng ở frontend HTML hiện tại:

- root `Thư viện / Bản tin`
- trạng thái hiện tại sau chuẩn hóa:
  - `Thư viện`: 2041 bài
  - `Bản tin`: 25 bài
- nhánh `Hướng dẫn / Biểu mẫu / Công cụ`
- topic lv1 / lv2
- tool variants cho `Công cụ`

#### `data/editor-taxonomy.json`
Phiên bản taxonomy canonical để editor PHP đọc trực tiếp:

- root category
- children tree
- variant map
- field map giữa dữ liệu hiện tại và schema editor

#### `data/menu-config.json`
Cấu hình menu public tách riêng khỏi taxonomy:

- top menu item
- submenu
- category mapping

#### `data/hubs/<section>.json`
Dữ liệu phục vụ trang hub:

- label section
- pageMap
- taxonomy
- libraryKinds (nếu là `thu-vien`)
- count

#### `data/feeds/latest-<section>.json`
Feed mới nhất theo section:

- title
- href
- canonical
- image
- publishDate
- badge/topic

#### `data/article-views/<section>/<slug>.json`
Dữ liệu recommendation per article:

- prev / next
- related
- latestOther
- newsLatest
- libraryLatest

---

## 4) Chiến lược runtime

### Giai đoạn hiện tại

Runtime vẫn đang dùng:

- `content-index.js`

để tránh phải refactor frontend quá lớn trước khi import full.

### Giai đoạn kế tiếp (khuyến nghị khi import full)

Refactor dần:

- hub page đọc từ `data/hubs/*.json` + `data/feeds/*.json`
- article page đọc từ:
  - `data/articles.json` (hoặc map mỏng)
  - `data/article-views/<section>/<slug>.json`

Mục tiêu:

- giảm payload
- tránh 1 JS index khổng lồ
- dễ cache hơn

---

## 5) Sitemap và robots

Builder hiện nên sinh:

- `robots.txt`
- `sitemap.xml`

### `robots.txt`

Mục tiêu:

- cho phép crawl site public
- khai báo vị trí sitemap

### `sitemap.xml`

Hiện tại quy mô 2066 bài vẫn còn đủ nhỏ để dùng **1 sitemap**.

Nội dung nên gồm:

- `index.html`
- `thu-vien.html`
- `ban-tin.html`
- trang phân trang tĩnh của hub
- mọi article canonical

Không đưa vào sitemap:

- URL query filter (`?kind=`, `?q=`, ...)

### `lastmod`

Ưu tiên:

- `modifiedDate`
- nếu không có thì `publishDate`
- nếu vẫn không có thì ngày build

---

## 6) Vì sao cách này quản nổi 2000+ bài

Vì ta không quản theo “mỗi bài là một HTML rời rạc”, mà quản theo:

1. **source catalog**
2. **build enrichment**
3. **canonical metadata store (`data/articles.json`)**
4. **split artifacts** theo use case
5. **docs + audit**

Tức là:

- thêm bài mới → sửa source → build lại → metadata/update/sitemap tự sinh lại

---

## 7) Quy trình build tối thiểu sau này

1. cập nhật source content
2. chạy audit classification
3. chạy audit mobile
4. build:
   - HTML
   - `content-index.js`
   - `data/`
   - `sitemap.xml`
   - `robots.txt`
5. QA

---

## 8) Trạng thái sau khi triển khai bước B

Khi builder chạy thành công, tối thiểu cần có:

- `content-index.js`
- `data/articles.json`
- `data/hubs/thu-vien.json`
- `data/hubs/ban-tin.json`
- `data/feeds/latest-thu-vien.json`
- `data/feeds/latest-ban-tin.json`
- `data/article-views/...`
- `robots.txt`
- `sitemap.xml`

---

## 9) Kết luận

Với static site, **không cần database vẫn quản nổi 2000+ bài**, nếu:

- có metadata manifest
- có split data artifacts
- có sitemap/robots
- có docs/audit đi kèm

Vấn đề không nằm ở “static hay không”, mà nằm ở:

- có quản theo kiến trúc dữ liệu hay không
