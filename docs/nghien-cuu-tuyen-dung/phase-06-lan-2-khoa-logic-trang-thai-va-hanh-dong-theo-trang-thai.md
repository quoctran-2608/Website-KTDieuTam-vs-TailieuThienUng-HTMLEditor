# Phase 06 (lần rà soát thứ hai) — Khóa logic trạng thái và hành động theo trạng thái

## 1) Mục tiêu phase

Khóa mâu thuẫn giữa:

1. trạng thái hiển thị của tin/đơn
2. hành động người dùng được phép thực hiện

Mục tiêu:

- không hiển thị CTA trái trạng thái
- tránh dẫn user vào thao tác backend chắc chắn phải từ chối
- giữ trải nghiệm rõ ràng theo từng `mode`

---

## 2) Vấn đề phát hiện trước khi sửa

## 2.1. `ung-tuyen.html` mâu thuẫn trạng thái và nút submit

Trang đang hiển thị:

- “Đã hết hạn · Bạn chỉ có thể xem lại hồ sơ đã nộp”

nhưng vẫn cho bấm:

- “Gửi hồ sơ”

=> mâu thuẫn rõ giữa UI và rule nghiệp vụ.

## 2.2. `don-ung-tuyen.html` hành động chưa khớp trạng thái “Chờ phản hồi”

Một dòng có:

- trạng thái: “Chờ phản hồi”
- hành động: “Nộp lại hồ sơ”

Trong khi với trạng thái đang chờ phản hồi, hành động hợp lý hơn là:

- xem lại hồ sơ đã nộp

---

## 3) Đã sửa trong phase này

## 3.1. Nâng cấp `ung-tuyen.html` theo mode thực tế

Đã thêm script `initApplicationMode()` để điều khiển UI theo query:

- `mode=nop-moi`
- `mode=xem-ho-so`
- `mode=nop-lai`

và theo trạng thái tin (dựa trên `job_id` mẫu trong demo).

### a) Bổ sung id/hook cho phần hiển thị

Thêm các id để script cập nhật đúng vùng:

- `applicationJobTitleStat`
- `applicationJobLocationStat`
- `applicationJobSalaryStat`
- `applicationJobStatusStat`
- `applicationModeNote`
- `applicationSubmitBtn`
- `applicationJobIdInput`
- `applicationIdInput`
- `applicationBackToJobLink`
- `applicationViewApplicationsLink`

### b) Quy tắc hành vi đã áp dụng

1. `mode=xem-ho-so`
   - khóa toàn bộ form (read-only / disabled)
   - disable submit
   - nút đổi text: “Bạn đang xem hồ sơ đã nộp”
2. `mode=nop-lai`
   - nếu tin hết hạn:
     - khóa form
     - disable submit
     - text: “Tin đã hết hạn, chưa thể nộp lại”
   - nếu tin còn tuyển:
     - mở form
     - submit text: “Nộp lại hồ sơ”
3. `mode=nop-moi`
   - nếu tin hết hạn:
     - khóa form
     - disable submit
     - text: “Tin đã hết hạn, chưa thể gửi mới”
   - nếu tin còn tuyển:
     - mở form bình thường
     - submit text: “Gửi hồ sơ”

### c) Đồng bộ lại link theo ngữ cảnh hiện tại

Script cũng đồng bộ:

1. link quay lại tin tuyển dụng theo `job_id`
2. link “Xem đơn đã ứng tuyển” theo `job_id`, `application_id` nếu có
3. hidden input `job_id` và `application_id` theo query thực tế

## 3.2. Sửa hành động sai ở `don-ung-tuyen.html`

Dòng “Chờ phản hồi” đã đổi:

- từ: “Nộp lại hồ sơ” (`mode=nop-lai`)
- thành: “Xem hồ sơ đã nộp” (`mode=xem-ho-so`)

---

## 4) Lợi ích sau phase

1. tránh mâu thuẫn UI-state ở trang nộp đơn
2. giảm thao tác vô ích của user khi tin đã hết hạn
3. chuẩn bị tốt cho backend rule:
   - `het_han` / `da_dong` không nhận nộp mới
4. hành vi theo `mode` rõ ràng, phù hợp dữ liệu động sau này

---

## 5) Tiêu chí hoàn thành phase 06 (lần 2)

1. trạng thái “hết hạn” không còn đi kèm submit mới
2. `mode=xem-ho-so` thật sự ở chế độ chỉ xem
3. `mode=nop-lai` chỉ khả dụng khi tin còn nhận hồ sơ
4. bảng đơn đã nộp không gợi hành động trái trạng thái hiện tại

