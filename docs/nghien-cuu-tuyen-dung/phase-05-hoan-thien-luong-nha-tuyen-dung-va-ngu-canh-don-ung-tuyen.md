# Phase 05 — Hoàn thiện luồng nhà tuyển dụng và ngữ cảnh đơn ứng tuyển

## 1) Mục tiêu phase

Làm rõ toàn bộ trải nghiệm nhà tuyển dụng theo hướng:

1. quản lý theo **tin tuyển dụng**
2. theo dõi theo **đơn ứng tuyển**
3. mọi thao tác đều có định danh rõ (`job_id`, `application_id`, `candidate_id`)

Mục tiêu là khi làm backend, đội triển khai có thể map thẳng từ giao diện demo sang database và API, không phải sửa lại luồng.

---

## 2) Vấn đề trước khi chỉnh

Trước phase 05, luồng nhà tuyển dụng còn các điểm gây mơ hồ:

1. nhiều chỗ dùng cụm “hồ sơ quan tâm”, chưa tách rõ với “đơn ứng tuyển”
2. trang ứng viên của nhà tuyển dụng thiếu cột mã đơn và ngữ cảnh `job_id` trên từng dòng
3. trạng thái đơn phía bảng nhà tuyển dụng dùng key tạm (`new`, `reviewing`, …), chưa khớp bộ trạng thái nghiệp vụ đã chốt ở phase 01
4. một số liên kết điều hướng nội bộ chưa gắn đủ tham số ngữ cảnh

Hệ quả:

- backend dễ map sai cấp dữ liệu (ứng viên vs đơn)
- khó triển khai lịch sử thao tác theo từng đơn
- tăng rủi ro phải đổi UI khi dựng API thật

---

## 3) Những gì đã chỉnh trong phase 05

## 3.1. `nha-tuyen-dung.html`

Đã cập nhật:

1. câu chữ tổng quan từ “hồ sơ quan tâm” sang “đơn ứng tuyển”
2. CTA chính sang luồng xem đơn mới:
   - `ung-vien-tuyen-dung.html?view=theo-tin&status=moi_nop`
3. khối “Tin tuyển dụng theo trạng thái” gắn link ngữ cảnh:
   - chi tiết tin: `job_id=...`
   - xem đơn theo tin: `job_id=...&view=theo-tin`
   - gia hạn tin hết hạn: `action=gia-han&job_id=...`
4. đồng bộ ngôn ngữ “đơn ứng tuyển” tại khối theo dõi gần đây và việc cần xử lý

## 3.2. `quan-ly-tin-tuyen-dung.html`

Đã cập nhật:

1. đồng bộ trạng thái thực:
   - nháp
   - đang tuyển
   - đã hết hạn
   - đã đóng
2. bổ sung hàng tin “đã đóng” để rõ kịch bản hậu tuyển
3. mỗi dòng có hành động theo ngữ cảnh:
   - xem chi tiết tin
   - xem đơn theo tin
   - gia hạn
   - đóng tin
   - đăng lại

## 3.3. `chi-tiet-tin-tuyen-dung.html`

Đã cập nhật:

1. breadcrumb quay lại quản lý tin có `tab=tat-ca` + `job_id`
2. CTA xem đơn gắn `job_id` + `view=theo-tin`
3. thêm dữ liệu định danh ngay trong nội dung:
   - mã tin
   - trạng thái
4. thêm khối “Đơn ứng tuyển gần nhất” với các lối vào theo trạng thái:
   - `status=moi_nop`
   - `status=dang_xem`
   - `status=da_lien_he`
5. bổ sung thao tác trung gian `action=tam-dung` trước khi đóng hẳn

## 3.4. `dang-tin-viec-lam.html`

Đã cập nhật:

1. sidebar điều hướng có ngữ cảnh rõ:
   - `mode=tao-moi`
   - `tab=tat-ca`
   - `view=theo-tin`
2. form có thêm hidden field phục vụ backend:
   - `job_id`
   - `formMode`
3. danh sách trạng thái form thêm `tam_dung`
4. `action` của form trỏ về danh sách quản lý có `tab=tat-ca`

## 3.5. `ung-vien-tuyen-dung.html`

Đây là phần chỉnh lớn nhất:

1. đổi cách gọi sang “đơn ứng tuyển” cho nhất quán toàn trang
2. thêm bộ lọc theo tin:
   - `job_id`
3. bảng dữ liệu đổi từ “ứng viên thuần” sang “đơn theo ứng viên”:
   - thêm cột `Mã đơn`
   - thêm cột `Tin tuyển dụng`
4. mỗi dòng có đủ ngữ cảnh:
   - `data-application-id`
   - `data-job-id`
   - link mở hồ sơ có `application_id` + `job_id`
5. chuẩn hóa trạng thái theo nghiệp vụ phase 01:
   - `moi_nop`
   - `dang_xem`
   - `can_bo_sung`
   - `da_lien_he`
   - `moi_phong_van`
   - `tu_choi`
   - `trung_tuyen`
6. script lọc/sắp xếp/nhật ký đã map sang key mới
7. nhật ký thao tác ghi rõ cấp đơn:
   - “Đã cập nhật trạng thái đơn `<application_id>` …”

---

## 4) Hợp đồng dữ liệu backend sau phase 05

## 4.1. Đầu vào query cho trang đơn ứng tuyển (nhà tuyển dụng)

- `view` (`theo-tin`, `tat-ca`)
- `job_id`
- `status`
- `q`
- `role`
- `experience`
- `sort`

## 4.2. Định danh bắt buộc cho thao tác trạng thái đơn

Khi nhà tuyển dụng đổi trạng thái, backend cần đủ:

1. `application_id`
2. `job_id`
3. `candidate_id`
4. `trang_thai_don` (theo enum phase 01)

## 4.3. Quy tắc thao tác theo trạng thái tin

1. `dang_tuyen`: cho phép nhận đơn mới và cập nhật trạng thái đơn
2. `het_han`: không nhận đơn mới, vẫn cho xử lý đơn đã có
3. `tam_dung`: tạm ngưng nhận đơn mới, giữ lịch sử đơn
4. `da_dong`: khóa nhận đơn mới, chỉ còn tra cứu và ghi chú nội bộ

---

## 5) Những điểm đã khóa để backend không phải sửa lại frontend

1. phân tách rõ:
   - ứng viên (thực thể người)
   - đơn ứng tuyển (thực thể giao dịch)
2. mỗi thao tác quan trọng có định danh đi kèm
3. trạng thái đơn đồng bộ với tài liệu nghiệp vụ gốc phase 01
4. câu chữ giao diện thuần Việt, không dùng từ kỹ thuật nội bộ gây nhiễu

---

## 6) Tiêu chí hoàn thành phase 05

1. luồng nhà tuyển dụng bám cấu trúc dữ liệu thật
2. trang quản lý tin và trang đơn ứng tuyển liên thông theo `job_id`
3. trạng thái đơn và trạng thái tin không mâu thuẫn
4. backend có thể code theo demo hiện tại mà không phải đổi layout hay đổi câu chữ trọng yếu
