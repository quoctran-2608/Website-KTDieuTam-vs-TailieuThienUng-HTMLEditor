# Kế hoạch import full 2066 bài vào `Ketoandieutam.com`

## Mục tiêu

Import toàn bộ **2066 bài** theo lô nhỏ, có kiểm soát, để:

- không làm vỡ IA `Thư viện | Bản tin`
- không kéo theo regression mobile/table hàng loạt
- có thời gian QA sau mỗi đợt

---

## 1) Snapshot hiện tại

### Theo taxonomy public

- **Thư viện:** 1823 bài
- **Bản tin:** 243 bài

### Theo kind trong Thư viện

- **Hướng dẫn:** 1057 bài
- **Biểu mẫu:** 493 bài
- **Công cụ:** 273 bài

### Theo mobile risk

#### Thư viện
- `critical`: 266
- `high`: 803
- `medium`: 659
- `low`: 95

#### Bản tin
- `critical`: 45
- `high`: 69
- `medium`: 113
- `low`: 16

---

## 2) Nguyên tắc chia batch

Không chia batch ngẫu nhiên theo alphabet.

Chia batch theo:

1. **risk**
2. **section**
3. **library kind**
4. **khả năng QA**

### Lý do

- bài `critical/high` cần được nhìn lại sớm
- `Biểu mẫu` và `Công cụ` thường dễ phát sinh lỗi layout hơn
- `Bản tin` có tính SEO/freshness cao hơn nên cần vào sớm

---

## 3) Thứ tự batch khuyến nghị

## Batch 0 — nền tảng

### Mục tiêu

Chốt hạ tầng trước khi import lớn:

- build ổn
- `data/`
- `sitemap.xml`
- `robots.txt`
- docs/audit hiện hành

### Trạng thái

Đã có.

---

## Batch 1 — Bản tin critical + high

### Scope

- khoảng **114 bài**
  - `critical` 45
  - `high` 69

### Lý do vào trước

- số lượng vừa phải
- dễ thấy hiệu quả SEO
- phát hiện sớm lỗi ở văn bản / bài cập nhật

### QA bắt buộc

- canonical
- publishDate
- card `Bản tin`
- recommendation cuối bài
- latest feed `data/feeds/latest-ban-tin.json`

---

## Batch 2 — Thư viện / Biểu mẫu critical + high

### Scope

Ưu tiên toàn bộ:

- `Biểu mẫu`
- risk `critical/high`

### Lý do

Đây là nhóm dễ lỗi nhất về:

- bảng
- form
- width cột
- thumbnail fallback

### QA bắt buộc

- bảng biểu mẫu hẹp
- preserve-source-width
- fit-to-viewport
- alt image
- `libraryKind = bieu-mau`

---

## Batch 3 — Thư viện / Công cụ critical + high

### Scope

Toàn bộ:

- `Công cụ`
- risk `critical/high`

### Lý do

Nhóm này có:

- nhiều ảnh màn hình
- nhiều hướng dẫn HTKK / MISA / FAST / Excel
- nguy cơ lỗi asset path và thumbnail

### QA bắt buộc

- link ảnh
- ảnh fallback
- heading / list
- `libraryKind = cong-cu`

---

## Batch 4 — Thư viện / Hướng dẫn critical + high

### Scope

Nhóm:

- `Hướng dẫn`
- risk `critical/high`

### Vì sao để sau Batch 2–3

Số lượng lớn nhất, nhưng layout thường bền hơn nhóm biểu mẫu.

---

## Batch 5 — Bản tin medium + low

### Scope

- `medium` 113
- `low` 16

### Mục tiêu

Hoàn tất toàn bộ `Bản tin` trước, để:

- menu `Bản tin`
- latest feed
- collection sau này

đã có data đủ dày.

---

## Batch 6 — Thư viện medium

### Scope

Toàn bộ `medium` của Thư viện:

- khoảng **659 bài**

### Cách chia nhỏ

Tách tiếp thành:

- 6A: Hướng dẫn medium
- 6B: Biểu mẫu medium
- 6C: Công cụ medium

---

## Batch 7 — Thư viện low

### Scope

- khoảng **95 bài**

### Mục tiêu

Dọn nốt các bài ít rủi ro, chủ yếu để hoàn tất số lượng.

---

## 4) Kích thước mỗi lô nên thế nào?

### Khuyến nghị

- **Batch QA nặng:** 40–80 bài
- **Batch QA vừa:** 80–150 bài
- **Batch low-risk:** 150–250 bài

### Không nên

- nhập 400–500 bài/lần ngay từ đầu

Vì nếu build hoặc heuristic sai:

- rollback rất mệt
- khó truy vết lỗi

---

## 5) Quy trình cho mỗi batch

1. chọn tập file theo plan
2. build batch
3. chạy:
   - audit classification
   - audit mobile
4. QA spot-check tối thiểu:
   - 5 bài đầu
   - 5 bài giữa
   - 5 bài cuối
   - tất cả bài `critical` mới import
5. kiểm:
   - article page
   - hub page
   - thumbnail
   - table
   - latest feed
   - sitemap
6. chốt merge batch

---

## 6) Tiêu chí go / no-go

### GO nếu

- không có lỗi build
- không có lỗi path asset
- không có bài vỡ layout nghiêm trọng
- `data/`, `sitemap.xml`, `robots.txt` regenerate đúng
- risk sample pass

### NO-GO nếu

- sai taxonomy hàng loạt
- bảng biểu mẫu vỡ
- article recommendation sai
- số lượng missing asset tăng bất thường

---

## 7) Ưu tiên QA bằng mắt

Sau mỗi batch, QA bằng mắt trước ở:

1. bài có bảng
2. bài có nhiều ảnh
3. bài có publishDate suy luận
4. bài có fallback thumbnail
5. bài `Bản tin` mới

---

## 8) Kết luận vận hành

Thứ tự import khuyến nghị:

1. `Bản tin` high-risk
2. `Biểu mẫu` high-risk
3. `Công cụ` high-risk
4. `Hướng dẫn` high-risk
5. phần còn lại theo medium → low

Đây là cách:

- ít rủi ro nhất
- nhìn thấy lỗi sớm nhất
- và phù hợp nhất với static site đang dùng pipeline build + audit hiện tại.
