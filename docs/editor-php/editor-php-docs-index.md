# Bộ tài liệu chuẩn để build editor PHP

## Mục tiêu

Tập hợp toàn bộ tài liệu cần thiết để bắt đầu code editor PHP mà không phải tra ngược lại nhiều cuộc trao đổi.

---

## 1) Tài liệu phải đọc theo thứ tự

### 1. `html-site-hardening-roadmap.md`

Trả lời:
- HTML site hiện tại cần ổn định những gì trước khi làm editor
- readiness gate là gì

### 2. `site-html-readiness-audit.md`

Trả lời:
- build HTML hiện tại đã ổn tới đâu
- còn blocker lớn hay không

### 3. `content-classification-policy.md`

Trả lời:
- taxonomy public hiện tại là gì
- `Thư viện / Bản tin`
- `Hướng dẫn / Biểu mẫu / Công cụ`

### 4. `editor-php-taxonomy-schema.md`

Trả lời:
- category tree canonical nên là gì
- tags đóng vai trò gì
- `toolLv3` dùng khi nào

### 5. `editor-php-form-spec.md`

Trả lời:
- form editor nên có field gì
- field nào bắt buộc
- dependency giữa các field
- SEO fields kiểu Rank Math nên đặt ở đâu

### 6. `metadata-and-sitemap-architecture.md`

Trả lời:
- artifact nào builder đang sinh
- vì sao có `data/articles.json`, `taxonomy.json`, `sitemap.xml`, `robots.txt`

### 7. `editor-php-build-notes.md`

Trả lời:
- user xem gì
- editor PHP chỉnh gì
- DB và static build liên hệ thế nào
- export pipeline phải chạy ra sao

---

## 2) Artifact JSON quan trọng

### `data/articles.json`
- metadata canonical của toàn bộ bài

### `data/taxonomy.json`
- taxonomy tree cho frontend hiện tại

### `data/editor-taxonomy.json`
- taxonomy tree machine-readable cho editor PHP

### `data/menu-config.json`
- menu public map từ taxonomy

### `data/hubs/*.json`
- dữ liệu hub page

### `data/article-views/*.json`
- prev/next/related/latest của từng bài

---

## 3) Những thứ đã chốt

- user cuối xem **HTML tĩnh**
- editor dùng **PHP admin**
- DB là **source of truth**
- static HTML chỉ là **artifact build**
- taxonomy chính đi theo **category tree**
- tags chỉ là lớp phụ
- menu map riêng từ config

---

## 4) Những thứ phải giữ nguyên khi bắt đầu code

- public menu:
  - `Thư viện`
  - `Bản tin`
- nhánh `Thư viện`:
  - `Hướng dẫn`
  - `Biểu mẫu`
  - `Công cụ`

Không đổi taxonomy này nếu chưa có quyết định mới.

---

## 5) Checklist để bắt đầu code editor PHP

- [ ] Đọc xong 7 file docs ở mục 1
- [ ] Hiểu rõ `data/editor-taxonomy.json`
- [ ] Hiểu rõ `data/menu-config.json`
- [ ] Chốt DB schema theo `editor-php-form-spec.md`
- [ ] Chốt publish workflow theo `editor-php-build-notes.md`

---

## 6) Kết luận

Nếu bắt đầu làm editor PHP, đây là bộ tài liệu tối thiểu cần bám.

Nếu có thay đổi taxonomy hoặc export pipeline, phải cập nhật lại file index này trước.
