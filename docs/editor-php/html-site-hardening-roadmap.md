# Roadmap hoàn thiện HTML site trước khi làm editor PHP

## Mục tiêu

Ưu tiên số 1 là làm cho:

- website HTML hiện tại chạy ổn với taxonomy mới
- navigation/filter dễ dùng cho user
- taxonomy đủ logic để làm nền cho editor PHP

**Không** làm editor PHP trước khi các điều trên ổn định.

---

## Giai đoạn 1 — Khoá taxonomy canonical

### Public IA

- `Thư viện`
- `Bản tin`

### Nhánh trong `Thư viện`

- `Hướng dẫn`
- `Biểu mẫu`
- `Công cụ`

### Category tree nội bộ

- root = `Thư viện` / `Bản tin`
- level 2 và level 3 phải rõ vai
- `tags` chỉ là lớp phụ cho search/filter/recommendation

### Điều cần tránh

- không dùng tags để thay category
- không để 1 bài có nhiều category canonical ngang vai
- không tạo UI filter mà không có taxonomy/data hỗ trợ thật

---

## Giai đoạn 2 — Làm cho navigation và filter chạy mượt

### Thư viện

- level 1: `Hướng dẫn / Biểu mẫu / Công cụ`
- level 2: nhóm chính ngoài trang
- level 3: refinement trong `Filters`

### Rule cụ thể

- `Hướng dẫn` → level 3 theo taxonomy scoped
- `Biểu mẫu` → level 3 từ tags hữu ích
- `Công cụ` → level 3 từ `toolLv3`

### Acceptance

- click tag ngoài trang làm list đổi thật
- mở `Filters` thấy đúng level sâu hơn
- không lặp cùng một tầng taxonomy ở cả ngoài trang và trong panel

---

## Giai đoạn 3 — Ổn định article page

- metadata đầu bài đầy đủ
- sidebar không trùng vai với block dưới bài
- `Cùng chủ đề` / `Gợi ý thêm` / `Bản tin mới` / `Mới trong Thư viện` rõ vai
- mobile flow mượt hơn desktop

---

## Giai đoạn 4 — Ổn định table handling

- mobile mặc định: fit-to-viewport cho mọi bảng
- nhóm biểu mẫu hẹp:
  - `preserve-source-width`
  - không bẻ chữ từng ký tự
  - có safe fix chống clip phần cuối

---

## Giai đoạn 5 — Metadata / SEO / artifacts

- `data/articles.json`
- `data/hubs/*.json`
- `data/feeds/*.json`
- `data/article-views/...`
- `robots.txt`
- `sitemap.xml`

### Acceptance

- article page không cần nhúng `content-index.js`
- hub page đọc `data/hubs/*.json`
- build full 2066 bài xong không thiếu assets

---

## Giai đoạn 6 — QA trước editor PHP

Chạy:

```bash
python3 Ketoandieutam.com/tools/site_html_readiness_audit.py
```

### Mục tiêu QA

- đếm article HTML = đếm article metadata
- article-meta có `publishDate` và `authorName`
- không còn internal ref lỗi trong mẫu kiểm
- taxonomy public chạy đúng:
  - `Thư viện`
  - `Bản tin`
- taxonomy nội bộ chạy đúng:
  - `Hướng dẫn`
  - `Biểu mẫu`
  - `Công cụ`

---

## Khi nào mới làm editor PHP?

Chỉ làm khi:

1. HTML site đã ổn định
2. taxonomy không còn thay đổi lớn
3. user-facing navigation đủ mượt
4. QA readiness audit không còn blocker lớn

---

## Gợi ý schema cho editor PHP

Nên đi theo:

- `primary_category_id`
- `secondary_category_ids[]` *(optional)*
- `tags[]`

và map category tree ra menu riêng.

Không nên để editor nhập taxonomy tự do bằng text.
