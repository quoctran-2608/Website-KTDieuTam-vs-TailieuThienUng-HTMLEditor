# Roadmap triển khai tính năng Tuyển dụng theo phase

## 1) Mục tiêu

Triển khai hoàn chỉnh tính năng **Tuyển dụng** cho KetoanDieuTam.com theo từng phase nhỏ, mỗi phase có:

- phạm vi rõ ràng
- đầu ra cụ thể
- điều kiện hoàn thành
- rủi ro và lưu ý

Mục tiêu cuối:
- có **trang list tuyển dụng**
- có **trang chi tiết từng tin**
- có **teaser tuyển dụng trên homepage**
- có **pipeline dữ liệu ổn định**
- sẵn sàng nối vào **PHP editor** sau này

---

## 2) Quyết định đã chốt trước khi triển khai

### 2.1. Về kiến trúc
- Không dùng HTML thuần làm source gốc cho tin tuyển dụng
- Dùng:
  - **source:** Markdown (`.md`) + metadata
  - **output:** HTML
  - **listing/filter/feed:** JSON

### 2.2. Về vị trí trên site
- Có **trang riêng**: `tuyen-dung.html`
- Có **teaser trên homepage**
- Teaser đặt:
  - **sau `#personas`**
  - **trước `#insights`**

### 2.3. Về menu “Tuyển dụng”
- **Chưa đưa lên top-nav ở phase đầu**
- Chỉ đưa vào top-nav khi đã có đủ dữ liệu thật và flow vận hành ổn

### 2.4. Về dữ liệu nguồn
Bạn đã chốt hướng:
- dùng dữ liệu từ `https://sanketoan.vn/`

### Phản biện cần giữ
Tôi đồng ý dùng **Sàn Kế Toán làm nguồn seed data ban đầu**, nhưng khuyến nghị:
- dùng như **nguồn tham chiếu / nguồn seed**
- không nên mirror hàng loạt nguyên xi theo kiểu “copy toàn bộ 1:1” lên production quá sớm

Lý do:
- rủi ro bản quyền / điều khoản sử dụng
- dữ liệu bên ngoài có thể đổi / hết hạn / sai
- nội dung tuyển dụng cần chuẩn hóa theo schema của site mình

### Cách xử lý thực tế
Ở phase đầu:
- có thể lấy dữ liệu public từ Sanketoan để làm **seed dataset**
- nhưng khi build public site, nên:
  - giữ `sourceSite`
  - giữ `sourceUrl`
  - chuẩn hóa metadata
  - ưu tiên viết lại `summary`
  - gắn trạng thái / hạn nộp rõ ràng

---

## 3) Dữ liệu cần có cho một tin tuyển dụng

## 3.1. Metadata tối thiểu
- `id`
- `slug`
- `title`
- `companyName`
- `location`
- `salaryLabel`
- `experienceLevel`
- `workMode`
- `employmentType`
- `deadline`
- `publishDate`
- `status`
- `featured`
- `urgent`
- `summary`
- `sourceSite`
- `sourceUrl`

## 3.2. Nội dung body
- Mô tả công việc
- Yêu cầu
- Quyền lợi
- Thời gian làm việc
- Địa điểm làm việc
- Cách ứng tuyển

---

## 4) Cấu trúc thư mục đề xuất

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
  feeds/
    tuyen-dung.json
```

---

## 5) Phase 0 — Chốt scope, schema và guardrails

## Mục tiêu
Khóa toàn bộ quyết định nền tảng trước khi code.

## Việc cần làm
1. Chốt schema metadata cho 1 job
2. Chốt format source:
   - Markdown + front matter
3. Chốt folder:
   - `content/tuyen-dung/`
4. Chốt output:
   - `tuyen-dung.html`
   - `tuyen-dung/<slug>.html`
   - `data/jobs.json`
5. Chốt rule status:
   - `active`
   - `expired`
   - `closed`
6. Chốt rule source:
   - seed data từ sanketoan.vn
   - có lưu `sourceUrl`

## Deliverable
- Tài liệu schema cuối
- Mẫu 1 file `.md` chuẩn

## Điều kiện hoàn thành
- Không còn tranh luận về format dữ liệu
- Team có thể bắt đầu tạo job file đầu tiên

---

## 6) Phase 1 — Seed data & chuẩn hóa dữ liệu tuyển dụng

## Mục tiêu
Tạo bộ dữ liệu mẫu ban đầu đủ để build giao diện thật.

## Việc cần làm
1. Chọn số lượng seed:
   - đề xuất **15–30 tin**
2. Lấy dữ liệu public từ Sanketoan:
   - title
   - company
   - địa điểm
   - lương
   - ngày đăng
   - hạn nộp hồ sơ
   - mô tả / yêu cầu / phúc lợi nếu có
3. Chuẩn hóa sang format `.md`
4. Bổ sung metadata còn thiếu
5. Gắn `sourceSite: sanketoan.vn`
6. Gắn `sourceUrl`
7. Gắn `status`

## Phản biện quan trọng
Không nên lấy 200–300 tin ngay.

### Vì sao
- nhiều tin sẽ hết hạn nhanh
- công việc chuẩn hóa rất nặng
- khó QA

### Khuyến nghị
Chỉ seed trước **15–30 tin active và đủ đẹp dữ liệu**.

## Deliverable
- thư mục `content/tuyen-dung/` có dữ liệu seed chuẩn hóa

## Điều kiện hoàn thành
- có ít nhất 15 tin hợp lệ
- metadata không thiếu field bắt buộc
- mỗi tin có slug sạch

---

## 7) Phase 2 — Build pipeline cho Tuyển dụng

## Mục tiêu
Tạo pipeline build tự động giống tinh thần Thư viện / Bản tin, nhưng riêng schema job.

## Việc cần làm
1. Viết builder cho jobs
2. Parse toàn bộ file `.md`
3. Build:
   - `data/jobs.json`
   - `tuyen-dung.html`
   - từng detail page
4. Build các group list cơ bản:
   - mới nhất
   - nổi bật
   - tuyển gấp
   - còn hạn
5. Tự động loại / gắn cờ:
   - job hết hạn
   - job thiếu dữ liệu

## Phản biện
Đừng gắn tuyển dụng vào taxonomy engine hiện tại của Thư viện/Bản tin.

### Chỉ nên tái sử dụng
- triết lý build static
- runtime JSON
- shell/header/footer
- pattern list/detail

### Không nên tái sử dụng nguyên xi
- Lv1/Lv2/Lv3 taxonomy
- libraryKind
- article topic model

## Deliverable
- script build jobs
- `data/jobs.json`
- list page hoạt động được
- detail page hoạt động được

## Điều kiện hoàn thành
- thêm 1 file `.md` mới có thể build ra trang detail mới
- `jobs.json` đúng schema

---

## 8) Phase 3 — UI/UX cho trang Tuyển dụng

## Mục tiêu
Làm ra giao diện thật cho người dùng.

## Việc cần làm
1. Thiết kế `tuyen-dung.html`
2. Có các khối:
   - hero
   - filter nhanh
   - danh sách job
   - CTA cho nhà tuyển dụng
3. Thiết kế card job
4. Thiết kế detail page
5. Responsive mobile tốt

## Vị trí trên homepage
Thêm teaser vào `index.html`:
- sau `#personas`
- trước `#insights`

## Nội dung teaser
- title
- 3 job mới nhất
- CTA:
  - Xem tất cả việc làm
  - Đăng nhu cầu tuyển dụng

## Deliverable
- `tuyen-dung.html`
- UI detail page
- block teaser ở homepage

## Điều kiện hoàn thành
- từ homepage có thể nhấp vào list
- từ list có thể nhấp vào detail
- UX desktop/mobile ổn

---

## 9) Phase 4 — Logic vận hành & moderation

## Mục tiêu
Đảm bảo dữ liệu tuyển dụng không thành “rác”.

## Việc cần làm
1. Thêm rule kiểm tra:
   - deadline bắt buộc
   - sourceUrl bắt buộc nếu là tin copy seed
   - status hợp lệ
2. Tạo audit script:
   - thiếu field
   - job expired
   - slug trùng
3. Tạo dashboard / report nội bộ
4. Rule cho job hết hạn:
   - ẩn khỏi danh sách chính
   - hoặc chuyển nhãn “Hết hạn”

## Deliverable
- script QA riêng cho tuyển dụng
- báo cáo kiểm tra dữ liệu

## Điều kiện hoàn thành
- có thể chạy audit và biết job nào lỗi
- không còn job lỗi hiển thị public

---

## 10) Phase 5 — SEO, feed, sitemap, internal linking

## Mục tiêu
Để khu Tuyển dụng có traffic thật và không bị cô lập.

## Việc cần làm
1. Sinh meta title / description chuẩn cho job
2. Gắn structured data phù hợp nếu cần
3. Đưa job pages vào sitemap
4. Tạo feed JSON / latest jobs
5. Liên kết nội bộ:
   - homepage teaser
   - trang Đào tạo
   - trang Giới thiệu / Giải pháp nếu phù hợp

## Deliverable
- jobs trong sitemap
- feed latest jobs
- internal linking rõ

## Điều kiện hoàn thành
- crawler có thể discover toàn bộ khu tuyển dụng

---

## 11) Phase 6 — Gắn với editor PHP

## Mục tiêu
Chuẩn bị để sau này có thể thêm / sửa / xoá job bằng giao diện admin.

## Việc cần làm
1. PHP editor đọc schema tuyển dụng
2. Form field map vào metadata
3. Body editor cho markdown
4. Save lại file `.md`
5. Trigger build

## Phản biện quan trọng
PHP editor **không nên edit HTML raw**.

Nó nên edit:
- metadata field
- markdown body

## Deliverable
- contract cho editor PHP
- mapping field rõ ràng

## Điều kiện hoàn thành
- editor PHP có thể CRUD job mà không phá layout

---

## 12) Phase 7 — Hardening & rollout chính thức

## Mục tiêu
Chốt hệ thống trước khi đẩy mạnh vận hành.

## Việc cần làm
1. QA toàn bộ route
2. QA mobile
3. kiểm tra performance list page
4. kiểm tra job expired flow
5. kiểm tra seed data
6. quyết định có đưa menu top-nav hay chưa

## Điều kiện để thêm menu “Tuyển dụng”
- có tối thiểu 10–20 tin active
- page đủ dày nội dung
- moderation ổn

## Deliverable
- phiên bản production-ready

---

## 13) Thứ tự triển khai khuyến nghị

### Thứ tự tốt nhất
1. Phase 0 — chốt schema
2. Phase 1 — seed data
3. Phase 2 — build pipeline
4. Phase 3 — UI list/detail + teaser
5. Phase 4 — moderation & QA
6. Phase 5 — SEO/feed/sitemap
7. Phase 6 — editor PHP integration
8. Phase 7 — hardening / production rollout

---

## 14) Cần làm gì tiếp ngay bây giờ

### Bước tiếp theo khuyến nghị
**Bắt đầu Phase 0 ngay**

Tức là làm 3 việc đầu tiên:

1. chốt schema metadata cuối cùng cho 1 job  
2. tạo 1 file mẫu `.md` chuẩn  
3. tạo thư mục source tuyển dụng trong repo  

### Sau đó
chuyển ngay sang **Phase 1**:
- seed 15–30 tin tuyển dụng từ Sanketoan
- chuẩn hóa thành file `.md`

---

## 15) Kết luận cuối

### Hướng đúng
- làm tuyển dụng theo kiến trúc file-based
- nhưng source gốc là **Markdown + metadata**

### Hướng triển khai tốt nhất
- chia nhỏ theo phase
- seed data trước
- rồi build pipeline
- rồi mới làm UI thật
- cuối cùng mới gắn editor PHP

### Điểm cần giữ kỷ luật
- không “copy full raw hàng loạt rồi public ngay”
- phải có bước chuẩn hóa dữ liệu
- phải có trạng thái job và hạn nộp

Như vậy tính năng Tuyển dụng sẽ đi đúng hướng, không phá site hiện tại, và đủ nền tảng để phát triển lâu dài.
