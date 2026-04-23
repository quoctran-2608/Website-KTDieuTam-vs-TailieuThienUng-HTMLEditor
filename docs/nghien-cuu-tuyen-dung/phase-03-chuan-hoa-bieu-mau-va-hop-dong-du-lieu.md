# Phase 03 — Chuẩn hóa biểu mẫu và hợp đồng dữ liệu

## 1) Mục tiêu phase

Chuẩn hóa toàn bộ biểu mẫu chính của tính năng Tuyển dụng để:

1. backend nhận dữ liệu thống nhất
2. dữ liệu có thể map thẳng vào Supabase
3. luồng tệp có thể map sang R2
4. không cần sửa lại cấu trúc biểu mẫu khi chuyển sang trang động

---

## 2) Quy tắc đã chốt cho biểu mẫu

Mỗi biểu mẫu nghiệp vụ phải có:

1. `id` cố định
2. `action` rõ trang đích
3. `method` rõ kiểu gửi
4. từng trường có `name`
5. trường quan trọng có `required`
6. trường nhập liệu có `maxlength`, `pattern`, `autocomplete` phù hợp

---

## 3) Biểu mẫu đã chuẩn hóa trong phase 03

## 3.1. `jobsFilterForm` (`tuyen-dung.html`)

Đã bổ sung:

- `action`, `method`
- `name` cho toàn bộ trường lọc
- thêm trường lọc còn thiếu trong UI:
  - `employmentType`
  - `workMode`

## 3.2. `candidateFilterForm` (`danh-sach-ung-vien.html`)

Đã bổ sung:

- `action`, `method`
- `name` cho `q`, `location`, `experience`, `sort`

## 3.3. `recruiterCandidateFilterForm` (`ung-vien-tuyen-dung.html`)

Đã bổ sung:

- `action`, `method`
- `name` cho `q`, `role`, `experience`, `status`, `sort`

## 3.4. `recruitmentLeadForm` (`dang-tin-tuyen-dung.html`)

Đã bổ sung:

- `action`, `method`
- `name` đầy đủ cho toàn bộ trường
- ràng buộc độ dài dữ liệu và mẫu số điện thoại
- mô tả ngắn gọn, thuần Việt

## 3.5. `employerJobForm` (`dang-tin-viec-lam.html`)

Đã chuẩn hóa thành biểu mẫu có cấu trúc rõ:

- thêm `id`, `action`, `method`
- chuyển các trường chính sang có `name`
- chuyển một số trường text tự do thành `select` có giá trị chuẩn:
  - `employmentType`
  - `status`
  - `workMode`
- chuẩn hóa `deadline` thành `type="date"`

## 3.6. `candidateProfileForm` (`ho-so-ung-vien.html`)

Đã bổ sung:

- `id`, `action`, `method`
- `name` đầy đủ cho hồ sơ ứng viên
- ràng buộc trường chính: họ tên, email, điện thoại, vị trí mục tiêu

## 3.7. `jobApplicationForm` (`ung-tuyen.html`)

Đã bổ sung:

- `id`, `action`, `method`
- `name` đầy đủ cho trường nộp hồ sơ
- ràng buộc dữ liệu đầu vào chính

## 3.8. Biểu mẫu đăng nhập (`dang-nhap-tuyen-dung.html`)

Đã đổi từ khối nhập liệu tự do sang 2 form rõ ràng:

1. `candidateLoginForm`
2. `employerLoginForm`

Mỗi form có:

- `action`, `method`
- trường `email`, `password` có `name`
- ràng buộc bắt buộc và độ dài mật khẩu

---

## 4) Hợp đồng dữ liệu frontend → backend

## 4.1. Nhóm lọc danh sách

Trường gửi:

- `q`
- `location`
- `role`
- `employmentType`
- `workMode`
- `experience`
- `status`
- `sort`

Mục tiêu:

- Pages Functions/Workers có thể đọc query trực tiếp để trả dữ liệu đúng bộ lọc

## 4.2. Nhóm biểu mẫu ứng viên

### Hồ sơ ứng viên

- `fullName`
- `email`
- `phone`
- `targetRole`
- `experience`
- `preferredLocation`
- `summary`
- `skills`

### Đơn ứng tuyển

- `fullName`
- `email`
- `phone`
- `experience`
- `coverLetter`
- `cvFileName`

`cvFileName` là dữ liệu hiển thị; phase upload tệp thật lên R2 sẽ bổ sung `cvObjectKey` hoặc `cvFileId`.

## 4.3. Nhóm biểu mẫu nhà tuyển dụng

### Gửi nhu cầu tuyển dụng

- `companyName`
- `contactName`
- `contactPhone`
- `contactEmail`
- `jobTitle`
- `jobLocation`
- `jobQuantity`
- `jobDeadline`
- `employmentType`
- `workMode`
- `salaryLabel`
- `experienceLevel`
- `jobNotes`

### Đăng tin tuyển dụng

- `title`
- `companyName`
- `location`
- `salaryLabel`
- `deadline`
- `employmentType`
- `status`
- `workMode`
- `description`
- `requirements`
- `contactEmail`

---

## 5) Ánh xạ hạ tầng

## 5.1. Supabase

Lưu dữ liệu text và dữ liệu quan hệ của toàn bộ biểu mẫu.

## 5.2. R2

Áp dụng cho tệp CV và tệp đính kèm ở phase upload tệp thật:

- frontend upload lên endpoint ký tạm
- nhận `objectKey`
- lưu `objectKey` vào Supabase

## 5.3. Pages Functions / Workers

Xử lý:

- kiểm tra hợp lệ đầu vào
- chuẩn hóa dữ liệu trước khi ghi Supabase
- trả thông báo nghiệp vụ thuần Việt cho frontend

---

## 6) Tiêu chí hoàn thành phase 03

1. tất cả form chính có `action`, `method`, `name`
2. trường bắt buộc có `required`
3. trường đầu vào có ràng buộc hợp lý
4. có tài liệu mapping field rõ cho backend stack

---

## 7) Ghi chú triển khai giao diện

- Các nút thao tác chính cho form đã dùng `button type="submit"` gắn trực tiếp tới form tương ứng.
- Tránh dùng liên kết điều hướng để giả lập hành vi gửi biểu mẫu.
- Câu chữ trên giao diện giữ thuần Việt, tập trung vào thao tác thực tế của người dùng.
