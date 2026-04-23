# Phase 04 — Hoàn thiện luồng ứng viên và trạng thái thực tế

## 1) Mục tiêu phase

Làm rõ trải nghiệm ứng viên theo trạng thái thực của tin tuyển dụng, tránh thao tác sai ngữ cảnh.

Trọng tâm phase này:

1. xử lý đúng khi tin đã hết hạn
2. bỏ liên kết giả `#` trong luồng ứng viên
3. làm rõ câu chữ trạng thái để người dùng dễ hiểu

---

## 2) Vấn đề trước khi chỉnh

Trong luồng ứng viên còn các bất cập:

1. tin đã hết hạn nhưng vẫn hiện nút “Ứng tuyển ngay”
2. một số nút thao tác vẫn là `href="#"`
3. trang ứng tuyển đang thể hiện như tin còn mở dù hạn đã qua

Hệ quả:

- người dùng dễ hiểu sai trạng thái
- thao tác không nhất quán với dữ liệu thật
- backend khó xử lý vì hành vi phía giao diện mâu thuẫn

---

## 3) Các trang đã chỉnh

## 3.1. `viec-lam-da-luu.html`

Đã cập nhật:

- đánh dấu rõ tin đã hết hạn bằng nhãn trạng thái
- đổi hành động của tin hết hạn:
  - từ “Ứng tuyển ngay”
  - thành “Xem tin tương tự”
- thay `href="#"` của nút “Bỏ lưu” bằng liên kết có ngữ cảnh:
  - `action=bo-luu`
  - `job_id=...`

## 3.2. `don-ung-tuyen.html`

Đã cập nhật:

- với đơn gắn tin đã hết hạn, trạng thái hiển thị:
  - `Tin đã hết hạn`
- hành động tương ứng:
  - “Xem hồ sơ đã nộp”
  - không khuyến khích nộp lại vào tin hết hạn

## 3.3. `ung-tuyen.html`

Đã cập nhật:

- nhãn đầu trang từ “Ứng tuyển nhanh” sang “Đơn ứng tuyển”
- thẻ thông tin trạng thái tin:
  - `Đã hết hạn · Bạn chỉ có thể xem lại hồ sơ đã nộp`

Mục tiêu là tránh khiến người dùng hiểu rằng còn có thể nộp đơn mới vào tin hết hạn.

## 3.4. `ho-so-ung-vien.html`

Đã bỏ liên kết giả:

- “Tải CV mới” không còn `href="#"`
- chuyển thành liên kết có ngữ cảnh:
  - `mode=tai-cv-moi`
  - `profile_id=...`

## 3.5. `tai-khoan-ung-vien.html`

Đã làm rõ trạng thái ở các khối:

- việc đã lưu có tin hết hạn
- đơn đã nộp gắn tin hết hạn
- việc gợi ý có tin hết hạn

---

## 4) Quy tắc trạng thái ứng viên đã chốt

## 4.1. Với tin còn hạn (`dang_tuyen`)

- hiển thị nút “Ứng tuyển ngay”
- cho phép tạo đơn mới

## 4.2. Với tin hết hạn (`het_han`)

- không hiển thị hành động nộp mới
- thay bằng:
  - xem tin tương tự
  - xem hồ sơ đã nộp (nếu đã từng ứng tuyển)

## 4.3. Với tin đã đóng (`da_dong`)

- hành vi tương tự `het_han`
- ưu tiên dẫn sang gợi ý việc làm còn mở

---

## 5) Hợp đồng backend liên quan phase 04

Pages Functions / Workers khi trả dữ liệu job cho frontend cần luôn kèm:

- `job_status`
- `deadline`
- `is_applicable` (có cho nộp mới hay không)

Frontend map quy tắc:

- `is_applicable = true` → nút nộp mới
- `is_applicable = false` → nút xem tin tương tự / xem hồ sơ cũ

---

## 6) Tiêu chí hoàn thành phase 04

1. không còn nút nộp mới cho tin đã hết hạn
2. không còn `href="#"` trong luồng ứng viên chính
3. trạng thái hiển thị thống nhất giữa trang tổng quan, việc đã lưu, đơn đã nộp, trang đơn ứng tuyển
4. ngôn ngữ hiển thị rõ ràng, thuần Việt, không mơ hồ

