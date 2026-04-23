# Phase 06 — Rà soát tổng thể và khóa hợp đồng triển khai backend

## 1) Mục tiêu phase

Chốt lần cuối toàn bộ tính năng Tuyển dụng để đảm bảo:

1. frontend demo đã đủ rõ để code backend trực tiếp
2. không còn điểm mơ hồ khi map API, database và authentication
3. hạn chế tối đa việc phải sửa giao diện sau khi dựng dữ liệu thật

---

## 2) Kết quả rà soát tổng thể

Phạm vi đã rà:

- 14 trang chính của tính năng Tuyển dụng
- toàn bộ trang chi tiết việc làm trong `tuyen-dung/*.html`
- các trang hồ sơ ứng viên công khai trong `ung-vien/*.html`
- script dùng chung `site-shell.js`

Kết quả chính:

1. không còn `href="#"` trong các luồng chính
2. form nghiệp vụ chính đã có `action`, `method`, trường có `name`
3. luồng nhà tuyển dụng đã tách rõ:
   - ứng viên (thực thể người)
   - đơn ứng tuyển (thực thể giao dịch)
4. trạng thái đơn ở trang nhà tuyển dụng đã dùng bộ key nghiệp vụ chuẩn

---

## 3) Điểm chốt bổ sung trong phase 06

## 3.1. Khóa ngữ cảnh cho toàn bộ trang chi tiết việc làm

Trước phase 06, các trang chi tiết việc làm vẫn có liên kết tĩnh:

- `../ung-tuyen.html`
- `../viec-lam-da-luu.html`

Điểm yếu là thiếu `job_id`, khiến backend khó xác định bản ghi thao tác.

Đã xử lý bằng script dùng chung trong `site-shell.js`:

1. tự lấy `job_id` từ URL trang chi tiết (`tuyen-dung/<slug>.html`)
2. tự gắn query cho các nút “Ứng tuyển nhanh / Ứng tuyển ngay”:
   - `job_id`
   - `mode=nop-moi`
   - `from=chi-tiet-tin`
3. tự gắn query cho nút “Lưu việc làm”:
   - `action=luu-viec`
   - `job_id`
   - `from=chi-tiet-tin`

Nhờ vậy không cần sửa thủ công hàng loạt file chi tiết việc làm.

## 3.2. Xử lý nhất quán khi tin đã hết hạn trên trang chi tiết

Khi trang chi tiết có nhãn `Hết hạn`:

1. nút nộp đơn được đổi thành “Xem tin tương tự”
2. chuyển hướng về danh sách việc làm (`tuyen-dung.html#job-list`)
3. thêm thông báo ngắn, thuần Việt:
   - tin đã hết hạn nhận hồ sơ
   - gợi ý xem tin còn đang tuyển

Điểm này giúp thống nhất với phase 04 (không khuyến khích nộp mới vào tin hết hạn).

---

## 4) Checklist triển khai backend theo frontend hiện tại

## 4.1. Authentication & role guard

Vai trò:

- `ung_vien`
- `nha_tuyen_dung`
- `quan_tri`

Guard route đã có tài liệu từ phase 01:

- trang ứng viên chỉ cho `ung_vien`
- trang nhà tuyển dụng chỉ cho `nha_tuyen_dung`
- sai vai trò thì chuyển hướng về trang đúng vai trò

## 4.2. API query contract (đọc dữ liệu)

Query phổ biến đã khóa:

- `job_id`
- `application_id`
- `profile_id`
- `view`
- `tab`
- `mode`
- `action`
- các filter: `q`, `location`, `role`, `employmentType`, `workMode`, `experience`, `status`, `sort`

## 4.3. Write contract (thao tác thay đổi)

1. Nộp đơn ứng tuyển:
   - cần `job_id`, `ung_vien_id`, dữ liệu form
2. Lưu việc làm:
   - cần `job_id`, `ung_vien_id`
3. Cập nhật trạng thái đơn (nhà tuyển dụng):
   - cần `application_id`, `job_id`, `candidate_id`, `trang_thai_don`
4. Sửa/đóng/gia hạn/tạm dừng tin:
   - cần `job_id`, `action` hoặc `status` phù hợp

## 4.4. Database mapping tối thiểu (Supabase)

Bảng chính đã thống nhất từ phase 01:

- `tai_khoan`
- `ho_so_ung_vien`
- `tin_tuyen_dung`
- `don_ung_tuyen`
- `viec_lam_da_luu`
- `ghi_chu_tuyen_dung_noi_bo`
- `nhu_cau_tuyen_dung`

Khóa liên kết quan trọng:

- `don_ung_tuyen.tin_tuyen_dung_id -> tin_tuyen_dung.id`
- `don_ung_tuyen.ung_vien_id -> ho_so_ung_vien.id`
- `viec_lam_da_luu.tin_tuyen_dung_id -> tin_tuyen_dung.id`

## 4.5. File upload (R2)

Luồng dự kiến:

1. frontend xin URL ký tạm
2. upload CV lên R2
3. nhận `objectKey`
4. lưu `objectKey` vào bản ghi hồ sơ/đơn trong Supabase

---

## 5) Rủi ro còn lại và cách xử lý

## 5.1. Khác tên key trạng thái giữa dataset mẫu và nghiệp vụ

Dữ liệu mẫu đang có key tiếng Anh cho trạng thái job (`active`, `expired`), trong khi nghiệp vụ dùng key tiếng Việt chuẩn (`dang_tuyen`, `het_han`, ...).

Khuyến nghị:

- chuẩn hóa ở tầng API về bộ key nghiệp vụ trước khi trả cho frontend recruiter/candidate dashboard.

## 5.2. Chưa có test runner tự động cho frontend HTML

Repo hiện chưa có test script thực thi thật cho luồng tuyển dụng.

Khuyến nghị:

- bổ sung smoke tests (Playwright hoặc script DOM check) cho:
  - route context
  - trạng thái hết hạn
  - submit form có đủ field chính

---

## 6) Kết luận phase 06

Sau phase 06, tính năng Tuyển dụng đã đạt mức:

1. luồng người dùng rõ ràng theo vai trò
2. ngữ cảnh dữ liệu đủ cho backend xử lý
3. trạng thái nghiệp vụ thống nhất hơn giữa trang tổng quan và trang thao tác
4. có thể triển khai backend/database/auth bám demo hiện tại mà không phải đổi cấu trúc giao diện chính

