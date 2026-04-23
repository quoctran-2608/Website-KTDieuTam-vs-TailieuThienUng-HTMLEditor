# Phase 02 — Chuẩn hóa định tuyến và ngữ cảnh thao tác

## 1) Mục tiêu phase

Loại bỏ các liên kết chung chung, đảm bảo mọi thao tác quan trọng đều mang theo ngữ cảnh dữ liệu để backend map trực tiếp.

Ngữ cảnh chuẩn dùng trong giao diện demo:

- `job_id`
- `application_id`
- `profile_id`
- `mode`
- `action`
- `tab`
- `view`

---

## 2) Vấn đề trước khi chỉnh

Trước phase 02, nhiều nút đang đi về trang đích chung mà không có ngữ cảnh:

1. từ bảng quản lý tin → chi tiết tin không có `job_id`
2. từ đơn đã ứng tuyển → trang ứng tuyển không có `application_id`
3. từ việc làm đã lưu → ứng tuyển không có `job_id`
4. từ chi tiết tin → sửa nội dung/đóng tin không có `job_id`

Hệ quả:

- backend không biết thao tác đang áp dụng lên bản ghi nào
- phải đoán từ session hoặc từ trang trước
- tăng rủi ro sai dữ liệu

---

## 3) Quy tắc định tuyến đã chốt

## 3.1. Quy tắc chung

Mọi thao tác thay đổi dữ liệu đều phải có ít nhất một định danh:

- thao tác với tin tuyển dụng: bắt buộc có `job_id`
- thao tác với đơn ứng tuyển: bắt buộc có `application_id`

## 3.2. Quy tắc theo nhóm thao tác

1. **Sửa/đăng/đóng tin**
   - cần `job_id`
   - có thể thêm `mode` hoặc `action`

2. **Nộp lại hồ sơ / xem hồ sơ đã gửi**
   - cần `application_id`
   - cần `job_id` để đối soát

3. **Dùng hồ sơ để ứng tuyển**
   - cần `profile_id`
   - nên có `mode=chon-viec-de-ung-tuyen`

4. **Mở tab trong trang tổng hợp**
   - dùng `tab` hoặc `view`

---

## 4) Danh sách trang đã chỉnh trong phase 02

## 4.1. `quan-ly-tin-tuyen-dung.html`

Đã bổ sung `job_id` cho các liên kết:

- Xem chi tiết
- Sửa tin
- Gia hạn

## 4.2. `chi-tiet-tin-tuyen-dung.html`

Đã bổ sung ngữ cảnh:

- Chỉnh sửa tin → `job_id` + `mode=sua`
- Xem ứng viên phù hợp → `job_id`
- Đóng tin → `job_id` + `action=dong-tin`

## 4.3. `dang-tin-viec-lam.html`

Đã chuẩn hóa luồng:

- Lưu nháp → `job_id` + `action=luu-nhap`
- Đăng tin → `job_id` + `action=dang-tin`
- Xem danh sách tin → `tab=tat-ca`

## 4.4. `nha-tuyen-dung.html`

Đã bổ sung tham số điều hướng:

- `mode=tao-moi`
- `view=ho-so-phu-hop`
- `view=tat-ca`
- `tab=tat-ca`
- `tab=can-xu-ly`

## 4.5. `don-ung-tuyen.html`

Đã bổ sung định danh cho từng dòng:

- `application_id`
- `job_id`
- `mode` theo hành động:
  - `cap-nhat-ho-so`
  - `xem-ho-so`
  - `nop-lai`

## 4.6. `viec-lam-da-luu.html`

Mỗi nút “Ứng tuyển ngay” đã có:

- `job_id`
- `mode=nop-moi`

## 4.7. `ho-so-ung-vien.html`

Nút “Dùng hồ sơ để ứng tuyển” đã có:

- `profile_id`
- `mode=chon-viec-de-ung-tuyen`

## 4.8. `ung-tuyen.html`

Liên kết quay lại tin tuyển dụng đã bổ sung:

- `job_id`
- `from=ung-tuyen`

---

## 5) Hợp đồng frontend → backend sau phase 02

## 5.1. Pages Functions / Workers

Các endpoint có thể đọc trực tiếp tham số định tuyến để:

1. lấy đúng bản ghi
2. kiểm tra quyền
3. ghi log thao tác chính xác

## 5.2. Supabase

Map trực tiếp:

- `job_id` → bảng tin tuyển dụng
- `application_id` → bảng đơn ứng tuyển
- `profile_id` → bảng hồ sơ ứng viên

## 5.3. R2

Không đổi trong phase này.  
Phase sau sẽ gắn `application_id` và `profile_id` vào luồng tải tệp CV.

---

## 6) Tiêu chí hoàn thành phase 02

1. không còn liên kết thao tác chính đi tới trang đích chung chung
2. mọi thao tác chính có định danh rõ
3. route có thể suy ra bản ghi dữ liệu cần xử lý
4. backend có thể map API theo ngữ cảnh từ URL mà không cần đoán

