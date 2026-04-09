# Kế hoạch design tính năng Tuyển dụng cho KetoanDieuTam.com

## 1) Mục tiêu

Thiết kế một khu **Tuyển dụng** có thể:

- đăng tin tuyển dụng cho doanh nghiệp
- hiển thị danh sách việc làm
- nhấp vào xem từng tin chi tiết
- giữ được ưu điểm của website hiện tại: **tĩnh, dễ build, dễ SEO, dễ kiểm soát**
- mở đường cho **PHP editor** sau này để thêm / sửa / xoá tin tuyển dụng

---

## 2) Kết luận ngắn gọn

### Ý tưởng của bạn là đúng hướng
Ý tưởng:
- mỗi tin tuyển dụng là một file riêng
- hệ thống tự build thành list
- nhấp vào ra từng bài
- sau này editor PHP quản lý CRUD

=> **Đây là hướng hợp lý cho giai đoạn đầu.**

### Nhưng cần chỉnh 1 điểm quan trọng
**Không nên dùng HTML thuần làm source of truth cho tin tuyển dụng.**

Khuyến nghị tốt hơn:

- **source gốc:** `.md` có front matter metadata
- **output public:** `.html`
- **listing/feed/filter:** build ra `.json`

Nói ngắn gọn:

> **MD là nơi nhập liệu**  
> **HTML là nơi xuất bản**  
> **JSON là nơi phục vụ list/filter/UI**

---

## 3) Phản biện ý tưởng “mỗi tin là 1 file HTML”

## 3.1. Điểm mạnh
Nếu làm mỗi tin là một file riêng thì:
- rất giống tư duy hiện tại của Thư viện / Bản tin
- dễ version bằng Git
- dễ backup
- dễ publish tĩnh
- SEO tốt
- không cần DB ngay

=> đây là lý do **nên giữ tư duy file-based**

## 3.2. Điểm yếu nếu dùng HTML thuần
Nếu mỗi tin tuyển dụng được soạn trực tiếp bằng HTML thì sẽ có 4 vấn đề:

### A. Metadata tuyển dụng rất nhiều trường cấu trúc
Tin tuyển dụng không giống bài viết thường. Nó có các field cố định:
- tên vị trí
- công ty
- địa điểm
- mức lương
- số năm kinh nghiệm
- hình thức làm việc
- hạn nộp hồ sơ
- trạng thái còn tuyển / hết hạn
- featured hay không

Nếu nhập bằng HTML thuần:
- khó validate
- khó filter
- khó sort
- editor PHP sau này sẽ phải parse HTML rất mệt

### B. Danh sách tuyển dụng cần logic dữ liệu mạnh hơn bài viết
Ví dụ:
- ẩn job hết hạn
- nổi bật job tuyển gấp
- filter theo khu vực / level / remote
- sort theo ngày đăng

Nếu chỉ có HTML:
- logic list sẽ mong manh
- dễ sinh lỗi dữ liệu

### C. Tần suất cập nhật tuyển dụng thường cao hơn bài viết thường
Job có đặc điểm:
- đổi trạng thái nhanh
- hết hạn nhanh
- cập nhật lương / địa điểm / số lượng thường xuyên

HTML thuần không phải format tốt để bảo trì nhóm dữ liệu kiểu này.

### D. PHP editor sau này sẽ khó đẹp nếu edit HTML thuần
Nếu editor PHP phải sửa HTML trực tiếp:
- khó UX
- dễ hỏng layout
- khó chống lỗi nhập liệu

---

## 4) Kiến trúc kỹ thuật tôi khuyến nghị

## 4.1. Source of truth

### Khuyến nghị
Mỗi tin tuyển dụng là **1 file Markdown (`.md`)**

Mỗi file gồm:
- **front matter / metadata**
- **body mô tả công việc**

### Vì sao chọn `.md` thay vì `.html`
- dễ nhập liệu hơn
- dễ validate metadata
- dễ cho editor PHP map field
- body công việc vẫn đủ linh hoạt
- sau này có thể render ra HTML chuẩn site

---

## 4.2. Output public

Từ các file `.md`, hệ thống build ra:

1. **Trang list tổng**
   - `tuyen-dung.html`

2. **Trang chi tiết từng job**
   - `tuyen-dung/<slug>.html`

3. **Dữ liệu JSON**
   - `data/jobs.json`
   - có thể thêm:
     - `data/feeds/tuyen-dung.json`
     - `data/hubs/tuyen-dung.json`

---

## 4.3. Cấu trúc thư mục đề xuất

### Option khuyến nghị
```text
content/
  tuyen-dung/
    ke-toan-tong-hop-cong-ty-a.md
    ke-toan-noi-bo-cong-ty-b.md

tuyen-dung/
  ke-toan-tong-hop-cong-ty-a.html
  ke-toan-noi-bo-cong-ty-b.html

data/
  jobs.json
```

### Nếu muốn giống site hiện tại hơn
Bạn cũng có thể dùng:
```text
tuyen-dung-src/
  *.md

tuyen-dung/
  *.html
```

Tôi nghiêng về cách này hơn:
- không lẫn source với output
- dễ build
- editor sau này cũng rõ

---

## 5) Schema dữ liệu tối thiểu cho 1 job

## 5.1. Front matter gợi ý

```yaml
id: job/ke-toan-tong-hop-cong-ty-a
slug: ke-toan-tong-hop-cong-ty-a
title: Tuyển Kế toán tổng hợp
companyName: Công ty ABC
companySlug: cong-ty-abc
location: TP.HCM
workMode: onsite
employmentType: full-time
salaryMin: 12000000
salaryMax: 18000000
salaryLabel: 12 - 18 triệu
experienceLevel: 2-nam
deadline: 2026-05-30
publishDate: 2026-04-10
status: active
featured: true
urgent: false
contactName: Phòng nhân sự
contactPhone: 09xx xxx xxx
contactEmail: hr@abc.com
applyUrl: https://...
summary: Công việc phù hợp với ứng viên đã có kinh nghiệm lập báo cáo thuế và BCTC.
tags:
  - kế toán tổng hợp
  - tuyển dụng
  - tp hcm
```

## 5.2. Body markdown
Phần body có thể chia theo block:
- Mô tả công việc
- Yêu cầu
- Quyền lợi
- Thời gian làm việc
- Hồ sơ ứng tuyển

---

## 6) Vì sao không nên dùng taxonomy giống hệt Thư viện / Bản tin

## 6.1. Điểm giống
Tuyển dụng cũng cần:
- list page
- detail page
- metadata JSON
- runtime render
- feed mới nhất

## 6.2. Điểm khác
Tuyển dụng không phải content taxonomy kiểu:
- Lv1 / Lv2 / Lv3
- libraryKind
- topicLabel

Thay vào đó nó cần field nghiệp vụ riêng:
- vị trí
- địa điểm
- level
- lương
- loại công việc
- hạn tuyển
- trạng thái

### Kết luận
**Nên tái sử dụng kiến trúc build/list/detail của Thư viện / Bản tin, nhưng không bê nguyên taxonomy engine của chúng sang Tuyển dụng.**

---

## 7) Đề xuất trang và vị trí trong website

## 7.1. Trang chính
### Trang nên tạo
- `tuyen-dung.html`

### Vai trò
- landing tổng
- listing job
- CTA cho nhà tuyển dụng

## 7.2. Trang chủ
### Có nên đưa lên `index.html`?
**Có**, nhưng chỉ ở dạng teaser.

### Vị trí nên đặt
**Sau `#personas`, trước `#insights`**

### Nội dung teaser nên có
- heading
- mô tả ngắn
- 3 job mới nhất
- CTA:
  - Xem tất cả việc làm
  - Đăng nhu cầu tuyển dụng

## 7.3. Menu “Tuyển dụng”

### MVP
**Chưa cần thêm ngay**

### Giai đoạn 2
Khi có đủ dữ liệu thật thì thêm:
- menu `Tuyển Dụng`
- đặt sau `Đào Tạo`

---

## 8) Thiết kế UI gợi ý

## 8.1. Trang list `tuyen-dung.html`

### Hero
- Tiêu đề: Tuyển dụng kế toán & cơ hội nghề nghiệp
- Mô tả ngắn
- CTA kép:
  - Tìm việc
  - Đăng tuyển

### Bộ lọc nhanh
- Vị trí
- Địa điểm
- Hình thức làm việc
- Kinh nghiệm
- Mức lương

### Danh sách job
Card nên có:
- tiêu đề
- công ty
- địa điểm
- lương
- badge: tuyển gấp / nổi bật
- ngày đăng / hạn nộp

## 8.2. Trang detail `tuyen-dung/<slug>.html`
- tiêu đề job
- công ty
- bảng tóm tắt nhanh
- mô tả công việc
- yêu cầu
- quyền lợi
- CTA ứng tuyển
- CTA liên hệ Zalo / hotline

## 8.3. Khối cho doanh nghiệp
Đặt ở cuối list page:
- cần tuyển kế toán?
- để lại nhu cầu
- nút Zalo / form

---

## 9) Build pipeline đề xuất

## 9.1. Build input
- đọc tất cả file `.md` trong thư mục nguồn tuyển dụng

## 9.2. Build output
- sinh `data/jobs.json`
- sinh `tuyen-dung.html`
- sinh từng file detail HTML

## 9.3. Runtime/UI
- list page có thể đọc từ JSON build sẵn
- detail page render giống bài viết thường

---

## 10) Gắn với editor PHP sau này

## 10.1. Giai đoạn trước editor
Admin thêm job bằng:
- tạo file `.md`
- chạy build

## 10.2. Khi có editor PHP
Editor không nên sửa HTML trực tiếp.

Editor nên:
- edit metadata field riêng
- edit body markdown
- save lại file `.md`
- trigger build

### Đây là điểm rất quan trọng
Nếu editor PHP edit HTML trực tiếp:
- khó validate
- dễ hỏng layout
- khó maintain

Nếu editor PHP edit `.md + metadata`:
- UX sạch hơn
- code backend rõ hơn
- dễ thêm validation:
  - deadline bắt buộc
  - lương là số
  - status hợp lệ
  - slug không trùng

---

## 11) Rủi ro & phản biện thêm

## 11.1. Rủi ro nếu làm quá sớm như một job board lớn
- ít dữ liệu thật
- trang rỗng
- phải moderation nhiều
- dễ lệch trọng tâm website

### Cách xử lý
- bắt đầu bằng MVP
- dùng teaser + landing page
- chỉ thêm top-nav khi đủ dữ liệu

## 11.2. Rủi ro nếu không có trạng thái job
Nếu không có:
- `active`
- `closed`
- `expired`

thì site rất dễ hiển thị job cũ.

=> trạng thái là field **bắt buộc**

## 11.3. Rủi ro nếu không có ngày hết hạn
Job sẽ tồn đọng, làm giảm chất lượng site.

=> cần:
- `deadline`
- rule auto ẩn / đánh dấu hết hạn

---

## 12) Đề xuất quyết định kỹ thuật

### Tôi khuyến nghị chốt như sau

1. **Trang riêng:** `tuyen-dung.html`
2. **Teaser homepage:** có, đặt sau `#personas`
3. **Menu top-nav:** chưa thêm ở MVP
4. **Source dữ liệu tuyển dụng:** **`.md` + metadata**
5. **Output public:** HTML + JSON
6. **Editor PHP sau này:** edit metadata + markdown, không edit raw HTML

---

## 13) Kết luận cuối

### Ý tưởng của bạn
**Đúng hướng.**

### Điều tôi phản biện
**Đừng lấy HTML thuần làm dữ liệu gốc cho tuyển dụng.**

### Phương án tốt nhất
- lưu **job source bằng Markdown**
- build ra:
  - list page
  - detail page
  - jobs JSON

Đây là phương án:
- hợp với kiến trúc hiện tại
- dễ mở rộng
- dễ nối sang editor PHP
- ít technical debt nhất trong dài hạn
