# Phase 03 (lần rà soát thứ hai) — Rà soát sâu luồng ứng viên end-to-end

## 1) Mục tiêu phase

Kiểm tra sâu toàn bộ chặng ứng viên:

1. đăng nhập ứng viên
2. vào trang tài khoản ứng viên
3. chỉnh hồ sơ
4. quản lý việc làm đã lưu
5. theo dõi đơn đã nộp
6. mở trang nộp đơn theo job cụ thể

Mục tiêu:

- không đứt ngữ cảnh khi chuyển trang
- trạng thái hiển thị rõ ràng, đúng sắc thái
- CTA ở mỗi bước dẫn đúng bước tiếp theo

---

## 2) Vấn đề phát hiện trước khi sửa

## 2.1. Một số link điều hướng chung chưa đủ ngữ cảnh

Các link “Tuyển dụng”, “Tìm việc”, “Xem đơn…” ở trang ứng viên còn dùng URL quá chung, chưa có anchor/query giúp quay đúng ngữ cảnh thao tác.

## 2.2. Trạng thái “Chờ phản hồi” đang dùng pill quá tiêu cực

Một số chỗ dùng `is-muted` cho “Chờ phản hồi”, dễ khiến người dùng hiểu như trạng thái bất lợi.

## 2.3. Form hồ sơ ứng viên thiếu định danh profile

`candidateProfileForm` chưa gửi `profile_id`, gây thiếu định danh khi backend cập nhật hồ sơ hiện có.

## 2.4. Một số CTA chuyển tiếp thiếu `from` để truy vết luồng

Ví dụ:

- từ `ung-tuyen.html` sang cập nhật hồ sơ
- từ login ứng viên sang các trang đích

---

## 3) Đã sửa trong phase này

## 3.1. `dang-nhap-tuyen-dung.html`

Đã thêm ngữ cảnh chuyển tiếp cho luồng ứng viên:

1. `candidateLoginForm` action:
   - `tai-khoan-ung-vien.html?from=dang-nhap-ung-vien`
2. CTA “Tạo hồ sơ ứng viên”:
   - `ho-so-ung-vien.html?from=dang-nhap-ung-vien`

## 3.2. `tai-khoan-ung-vien.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng” -> `tuyen-dung.html#job-list`
2. CTA tìm việc -> `tuyen-dung.html#job-list`
3. CTA cập nhật hồ sơ -> `ho-so-ung-vien.html?from=tai-khoan-ung-vien`
4. CTA xem toàn bộ đơn -> `don-ung-tuyen.html?view=tat-ca&from=tai-khoan-ung-vien`
5. trạng thái “Chờ phản hồi” đổi pill:
   - `is-muted` -> `is-reviewing`

## 3.3. `ho-so-ung-vien.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng” -> `tuyen-dung.html#job-list`
2. CTA “Về tổng quan ứng viên”:
   - `tai-khoan-ung-vien.html?from=ho-so-ung-vien`
3. CTA “Tìm việc ngay”:
   - `tuyen-dung.html#job-list`
4. thêm hidden field vào `candidateProfileForm`:
   - `profile_id=ho-so-nguyen-minh-anh`

## 3.4. `viec-lam-da-luu.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng” -> `tuyen-dung.html#job-list`
2. CTA “Tìm thêm việc làm” -> `tuyen-dung.html#job-list`
3. CTA “Xem đơn đã ứng tuyển”:
   - `don-ung-tuyen.html?view=tat-ca&from=viec-da-luu`
4. CTA ứng tuyển từ tin còn hạn thêm ngữ cảnh:
   - `ung-tuyen.html?...&from=viec-da-luu`

## 3.5. `don-ung-tuyen.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng” -> `tuyen-dung.html#job-list`
2. CTA “Tiếp tục tìm việc” -> `tuyen-dung.html#job-list`
3. CTA cập nhật hồ sơ:
   - `ho-so-ung-vien.html?from=don-ung-tuyen`
4. trạng thái “Chờ phản hồi” đổi pill:
   - `is-muted` -> `is-reviewing`

## 3.6. `ung-tuyen.html`

Đã chỉnh:

1. wording mô tả “đang chọn” -> “đã chọn” (rõ ngữ cảnh hơn)
2. CTA “Cập nhật hồ sơ trước khi gửi” thêm context:
   - `ho-so-ung-vien.html?from=ung-tuyen&job_id=...`

---

## 4) Lợi ích sau phase

1. đường đi ứng viên liền mạch hơn giữa các trang dashboard
2. backend dễ trace user journey qua `from=...`
3. trạng thái “Chờ phản hồi” hiển thị trung tính và hợp lý hơn
4. form hồ sơ có đủ `profile_id` để update đúng bản ghi

---

## 5) Tiêu chí hoàn thành phase 03 (lần 2)

1. các lối vào chính của ứng viên không còn link “mất ngữ cảnh”
2. trạng thái chính không dùng sai tone hiển thị
3. form hồ sơ có định danh profile rõ ràng
4. CTA của từng trang ứng viên đẩy đúng bước tiếp theo trong hành trình

