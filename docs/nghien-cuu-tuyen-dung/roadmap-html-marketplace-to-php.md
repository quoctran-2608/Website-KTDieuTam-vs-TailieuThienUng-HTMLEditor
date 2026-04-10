# Roadmap HTML marketplace Tuyển dụng → PHP

## 1) Mục tiêu đã chốt lại

Mục tiêu không còn là một góc tuyển dụng đơn giản.

Mục tiêu mới là:

1. Làm **bản HTML hoàn chỉnh** cho khu Tuyển dụng theo chuẩn một marketplace tuyển dụng:
   - có lối vào cho **ứng viên**
   - có lối vào cho **nhà tuyển dụng**
   - có đủ các màn hình quan trọng để user nhìn vào thấy sản phẩm đã hoàn chỉnh
2. Sau khi UX/UI và flow đã chốt, mới **nâng cấp sang PHP** để chạy chức năng thật:
   - đăng nhập
   - tạo hồ sơ
   - lưu việc làm
   - ứng tuyển
   - đăng tin
   - quản lý tin
   - quản lý ứng viên

Nói ngắn gọn:

> HTML trước để chốt sản phẩm  
> PHP sau để biến sản phẩm thành hệ thống chạy thật

---

## 2) Tiêu chuẩn tham chiếu

Chuẩn tham chiếu là các nhóm tính năng mà người dùng cảm nhận được trên Sanketoan:

- trang list việc làm hoàn chỉnh
- trang chi tiết việc làm
- lối vào cho ứng viên
- lối vào cho nhà tuyển dụng
- đăng nhập / tạo tài khoản
- lưu việc làm
- ứng tuyển
- dashboard ứng viên
- dashboard nhà tuyển dụng
- đăng tin và quản lý tin
- khu quản lý ứng viên

Lưu ý:

- Bản HTML ở giai đoạn này **không cần backend thật**
- Nhưng giao diện, hành trình và cấu trúc màn hình phải đủ hoàn chỉnh để sau này PHP nối vào không phải thiết kế lại từ đầu

---

## 3) Ba phase lớn

## Phase A — HTML UX-complete

### Mục tiêu
Làm bộ giao diện HTML hoàn chỉnh cho toàn bộ hành trình người dùng.

### Kết quả cần có
- list page đẹp và dễ dùng
- detail page đẹp và rõ CTA
- auth page
- ứng viên dashboard
- nhà tuyển dụng dashboard
- màn đăng tin
- màn ứng tuyển
- màn việc làm đã lưu / đã nộp
- màn quản lý tin / quản lý ứng viên

### Kết quả của phase A
Người dùng nhìn vào sẽ thấy khu Tuyển dụng là một sản phẩm hoàn chỉnh, không còn cảm giác “chỉ là vài trang nội dung”.

---

## Phase B — Contract & data model

### Mục tiêu
Chốt toàn bộ cấu trúc dữ liệu để PHP hóa không bị làm lại.

### Cần chốt
- user
- candidate profile
- employer account
- job post
- saved jobs
- application
- application status
- employer inbox / candidate review
- permission model
- log / moderation / audit

---

## Phase C — PHP implementation

### Mục tiêu
Biến toàn bộ HTML flow đã chốt thành hệ thống có chức năng thật.

### Cần làm
- auth
- session
- CRUD hồ sơ ứng viên
- CRUD tin tuyển dụng
- ứng tuyển thật
- lưu việc làm thật
- dashboard thật
- moderation / admin

---

## 4) Các phase nhỏ để triển khai lần lượt

## A1 — Dựng khung marketplace HTML

### Deliverable
- roadmap mới
- lối vào nhanh trên `tuyen-dung.html`
- `dang-nhap-tuyen-dung.html`
- `tai-khoan-ung-vien.html`
- `nha-tuyen-dung.html`

### Mục tiêu
Cho user nhìn thấy ngay sản phẩm có 2 vai rõ ràng:
- ứng viên
- nhà tuyển dụng

---

## A2 — Ứng viên flow HTML

### Deliverable
- trang hồ sơ ứng viên
- trang việc làm đã lưu
- trang việc làm đã ứng tuyển
- trang ứng tuyển
- empty state / success state / status state

---

## A3 — Nhà tuyển dụng flow HTML

### Deliverable
- `dang-tin-viec-lam.html`
- `quan-ly-tin-tuyen-dung.html`
- `chi-tiet-tin-tuyen-dung.html`
- `ung-vien-tuyen-dung.html`
- trạng thái nháp / đang tuyển / sắp hết hạn / đã đóng

---

## A4 — Parity polish với Sanketoan

### Deliverable
- phân trang HTML
- bộ lọc nâng cao
- CTA lưu việc làm
- CTA ứng tuyển nhanh
- khối việc liên quan
- tinh chỉnh mobile và desktop

---

## B1 — Data contract cho PHP

### Deliverable
- schema user / employer / candidate / application / saved jobs
- state machine cho application
- contract cho auth
- contract cho recruiter posting flow

---

## C1 — PHP auth & account
## C2 — PHP ứng viên
## C3 — PHP nhà tuyển dụng
## C4 — PHP moderation / admin

---

## 5) Kết luận triển khai

Từ thời điểm này, khu Tuyển dụng sẽ được làm theo nguyên tắc:

- **không thêm nhỏ lẻ**
- **không vá rời rạc**
- đi theo roadmap từ HTML hoàn chỉnh → PHP hóa

Phase hiện tại đang triển khai là:

> **A4 — Parity polish với Sanketoan**
