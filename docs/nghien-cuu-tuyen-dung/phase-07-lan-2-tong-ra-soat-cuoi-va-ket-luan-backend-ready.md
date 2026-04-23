# Phase 07 (lần rà soát thứ hai) — Tổng rà soát cuối và kết luận backend-ready

## 1) Mục tiêu phase

Thực hiện vòng kiểm tra tổng hợp cuối cùng để trả lời dứt điểm:

1. frontend demo đã đủ ổn để triển khai backend theo đúng demo chưa
2. còn blocker nào buộc phải chỉnh thêm trước khi code backend không

---

## 2) Phạm vi kiểm tra cuối

Đã rà soát toàn bộ nhóm trang liên quan tính năng:

1. 14 trang gốc của feature Tuyển dụng
2. toàn bộ `tuyen-dung/*.html` (trang chi tiết việc làm)
3. toàn bộ `ung-vien/*.html` (hồ sơ ứng viên công khai)
4. script dùng chung `site-shell.js`

---

## 3) Checklist regression đã chạy

## 3.1. Contract kỹ thuật cơ bản

- không còn `href=\"#\"`
- không còn `javascript:void(0)`
- form có `action` + `method`
- controls trong form có `name`
- link local không trỏ file không tồn tại

## 3.2. Contract ngữ cảnh

- recruiter links:
  - `view=...`
  - `job_id=...`
  - `tab=...`
  - `mode=...`
- candidate links:
  - `job_id` / `application_id` theo bước
  - `from=...` tại các điểm chuyển trang quan trọng

## 3.3. Contract trạng thái-hành động

- không còn cặp “Chờ phản hồi” + “Nộp lại hồ sơ”
- `ung-tuyen.html` có mode guard cho:
  - `nop-moi`
  - `xem-ho-so`
  - `nop-lai`
- tin hết hạn không còn mở submit nộp mới

## 3.4. Contract ngôn ngữ UX

- không còn “Ứng tuyển nhanh” trong `tuyen-dung/*.html`
- trang recruiter applications dùng tiêu đề “Đơn ứng tuyển”
- CTA trên hồ sơ công khai ứng viên đã rõ mục đích hơn:
  - “Gửi yêu cầu mở liên hệ”
  - “Xem đơn ứng tuyển theo hồ sơ”

---

## 4) Kết quả tổng rà soát

## 4.1. Các mục pass

1. `href=\"#\"`: pass
2. `javascript:void(0)`: pass
3. local links tồn tại: pass
4. form contract cơ bản: pass
5. recruiter/candidate context params: pass
6. status-action conflict trọng yếu: pass
7. wording chính đã chuẩn hóa: pass

## 4.2. Điểm đã dọn thêm trong phase này

1. chuẩn hóa tĩnh wording trong 9 file `ung-vien/*.html`:
   - “Yêu cầu kết nối ứng viên” -> “Gửi yêu cầu mở liên hệ”
   - “Yêu cầu kết nối” -> “Gửi yêu cầu mở liên hệ”
   - “Mở trang ứng viên tuyển dụng” -> “Xem đơn ứng tuyển theo hồ sơ”

---

## 5) Kết luận backend-ready

**Kết luận hiện tại: Đạt mức backend-ready cho prototype demo.**

Team backend có thể triển khai theo frontend hiện tại mà **không cần sửa lại luồng UI chính**.

Các mảng đã đủ để bám code:

1. route/query context
2. form field contract
3. role-based flow (ứng viên / nhà tuyển dụng)
4. trạng thái nghiệp vụ chính
5. hành vi theo trạng thái nhạy cảm (đặc biệt ở nộp đơn)

---

## 6) Rủi ro còn lại (không blocker frontend)

1. repo chưa có test automation thực (npm test placeholder)
2. một số dữ liệu demo vẫn hardcode (đúng bản chất prototype)
3. cần khóa kỹ bằng backend validation + auth/permission khi triển khai động

---

## 7) Khuyến nghị bàn giao cho backend

1. bám `phase-07` + `phase-08` checklist để chia sprint
2. ưu tiên hoàn thiện Auth + Role guard + Job/Application APIs trước
3. giữ nguyên mapping `job_id` / `application_id` / `profile_id` đã chốt trong frontend

