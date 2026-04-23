# Phase 08 — Checklist triển khai backend theo sprint

## 1) Mục tiêu

Chuyển toàn bộ hợp đồng đã chốt (phase 01 -> 07) thành checklist triển khai thực thi được, theo thứ tự sprint.

Checklist này dùng để:

1. phân việc rõ ràng cho backend
2. kiểm soát đầu ra từng sprint bằng tiêu chí nghiệm thu
3. giảm tối đa rủi ro “code xong mới phát hiện lệch frontend”

---

## 2) Nguyên tắc thực thi

1. Mỗi task phải có:
   - input rõ
   - output rõ
   - tiêu chí pass/fail
2. Không mở rộng scope khi task hiện tại chưa đạt pass.
3. Mọi thay đổi trạng thái phải lưu log thao tác.
4. Ưu tiên hoàn tất luồng end-to-end trước khi tối ưu.

---

## 3) Sprint 0 — Chuẩn bị nền tảng

## 3.1. Tạo môi trường và secret

### Task S0-01

- **Mục tiêu**: cấu hình môi trường Cloudflare + Supabase + R2.
- **Đầu ra**:
  - file env mẫu
  - danh sách secret đã cấu hình trên từng môi trường
- **Pass**:
  - deploy môi trường dev chạy được
  - endpoint health trả 200

### Task S0-02

- **Mục tiêu**: chuẩn hóa convention project backend.
- **Đầu ra**:
  - cấu trúc thư mục route/service/repo
  - quy ước đặt tên status/id/error code
- **Pass**:
  - có tài liệu ngắn cho team dùng chung

## 3.2. Dựng migration cơ sở dữ liệu

### Task S0-03

- **Mục tiêu**: tạo migration cho bảng lõi.
- **Bảng bắt buộc**:
  - `accounts`
  - `candidate_profiles`
  - `employers`
  - `jobs`
  - `applications`
  - `saved_jobs`
  - `employer_requests`
  - `recruiter_internal_notes`
- **Pass**:
  - migrate up/down thành công trên dev
  - có index cơ bản cho query chính

### Task S0-04

- **Mục tiêu**: seed dữ liệu mẫu tối thiểu.
- **Pass**:
  - có đủ dữ liệu cho test 3 luồng:
    - public list/detail jobs
    - candidate apply/save
    - employer manage job/application

---

## 4) Sprint 1 — Auth + Role Guard

## 4.1. Authentication

### Task S1-01

- **Mục tiêu**: login ứng viên.
- **API**: `POST /api/auth/login/candidate`
- **Input**: `email`, `password`
- **Output**: session hợp lệ + profile tối thiểu
- **Pass**:
  - login đúng tài khoản ứng viên thành công
  - sai mật khẩu trả lỗi chuẩn hóa

### Task S1-02

- **Mục tiêu**: login nhà tuyển dụng.
- **API**: `POST /api/auth/login/employer`
- **Pass**: tương tự S1-01 cho role nhà tuyển dụng.

### Task S1-03

- **Mục tiêu**: logout + kiểm tra phiên.
- **API**:
  - `POST /api/auth/logout`
  - `GET /api/auth/me`
- **Pass**:
  - logout xong gọi `/me` trả trạng thái chưa đăng nhập

## 4.2. Route guard

### Task S1-04

- **Mục tiêu**: chặn truy cập sai vai trò.
- **Quy tắc**:
  - candidate-only route chỉ `ung_vien`
  - employer-only route chỉ `nha_tuyen_dung`
- **Pass**:
  - gọi sai role trả 403 + thông điệp thuần Việt

---

## 5) Sprint 2 — Luồng public (việc làm + hồ sơ ứng viên công khai)

## 5.1. Jobs list/detail

### Task S2-01

- **Mục tiêu**: API danh sách việc làm.
- **API**: `GET /api/jobs`
- **Query**:
  - `q`, `location`, `role`, `employmentType`, `workMode`, `experience`, `sort`
- **Pass**:
  - kết quả lọc khớp UI `tuyen-dung.html`

### Task S2-02

- **Mục tiêu**: API chi tiết việc làm.
- **API**: `GET /api/jobs/:slug`
- **Output bắt buộc**:
  - `job_status`
  - `deadline`
  - `is_applicable`
- **Pass**:
  - tin hết hạn trả `is_applicable=false`

## 5.2. Public candidate directory

### Task S2-03

- **Mục tiêu**: API danh sách hồ sơ công khai.
- **API**: `GET /api/candidates`
- **Pass**:
  - dữ liệu contact nhạy cảm được che đúng quy tắc

### Task S2-04

- **Mục tiêu**: API chi tiết hồ sơ công khai.
- **API**: `GET /api/candidates/:slug`
- **Pass**:
  - guest không thấy full contact
  - employer hợp lệ thấy full contact

---

## 6) Sprint 3 — Luồng ứng viên (profile + save + apply)

## 6.1. Profile

### Task S3-01

- **Mục tiêu**: lưu/cập nhật hồ sơ ứng viên.
- **API**: `POST /api/candidate/profile`
- **Map form**: `candidateProfileForm`
- **Pass**:
  - validate field bắt buộc theo frontend

## 6.2. Saved jobs

### Task S3-02

- **Mục tiêu**: lưu việc làm.
- **API**: `POST /api/candidate/saved-jobs`
- **Input**: `job_id`
- **Pass**:
  - idempotent theo (`candidate_profile_id`, `job_id`)

### Task S3-03

- **Mục tiêu**: bỏ lưu việc làm.
- **API**: `DELETE /api/candidate/saved-jobs/:job_id`
- **Pass**:
  - bỏ lưu thành công, danh sách cập nhật đúng

## 6.3. Applications

### Task S3-04

- **Mục tiêu**: nộp đơn ứng tuyển.
- **API**: `POST /api/candidate/applications`
- **Map form**: `jobApplicationForm`
- **Rule bắt buộc**:
  - job `het_han`/`da_dong` => reject
- **Pass**:
  - đơn tạo thành công cho job hợp lệ
  - reject đúng cho job không còn nhận đơn

### Task S3-05

- **Mục tiêu**: lấy danh sách đơn đã nộp.
- **API**: `GET /api/candidate/applications`
- **Pass**:
  - phân trang + lọc cơ bản chạy ổn định

---

## 7) Sprint 4 — Luồng nhà tuyển dụng (jobs + applications + notes)

## 7.1. Employer jobs

### Task S4-01

- **Mục tiêu**: tạo/cập nhật tin tuyển dụng.
- **API**: `POST /api/employer/jobs`
- **Map form**: `employerJobForm`
- **Pass**:
  - `formMode=tao-moi` tạo mới
  - `formMode=sua` cập nhật đúng `job_id`

### Task S4-02

- **Mục tiêu**: thao tác trạng thái tin.
- **API**: `POST /api/employer/jobs/:job_id/actions`
- **Action**:
  - `gia-han`
  - `tam-dung`
  - `dong-tin`
- **Pass**:
  - trạng thái chuyển đúng rule
  - ghi log before/after

### Task S4-03

- **Mục tiêu**: danh sách + chi tiết tin cho employer.
- **API**:
  - `GET /api/employer/jobs`
  - `GET /api/employer/jobs/:job_id`
- **Pass**:
  - chỉ trả jobs thuộc employer hiện tại

## 7.2. Employer applications

### Task S4-04

- **Mục tiêu**: danh sách đơn theo filter recruiter.
- **API**: `GET /api/employer/applications`
- **Query**:
  - `q`, `job_id`, `role`, `experience`, `status`, `sort`
- **Pass**:
  - khớp UI `ung-vien-tuyen-dung.html`

### Task S4-05

- **Mục tiêu**: cập nhật trạng thái đơn.
- **API**: `PATCH /api/employer/applications/:application_id/status`
- **Input bắt buộc**:
  - `job_id`
  - `candidate_id`
  - `status`
- **Pass**:
  - reject nếu thiếu định danh
  - status chuyển đúng enum nghiệp vụ

## 7.3. Internal notes

### Task S4-06

- **Mục tiêu**: tạo/sửa ghi chú nội bộ tuyển dụng.
- **API**:
  - `POST /api/employer/notes`
  - `PATCH /api/employer/notes/:id`
- **Pass**:
  - note chỉ employer sở hữu mới xem/sửa được

---

## 8) Sprint 5 — Upload CV lên R2

### Task S5-01

- **Mục tiêu**: cấp URL ký tạm.
- **API**: `POST /api/uploads/cv/presign`
- **Pass**:
  - URL hết hạn đúng cấu hình

### Task S5-02

- **Mục tiêu**: gắn `cv_object_key` vào profile/application.
- **Pass**:
  - truy xuất file theo quyền hợp lệ

### Task S5-03

- **Mục tiêu**: chặn truy cập file trái quyền.
- **Pass**:
  - candidate không xem CV riêng của candidate khác
  - employer chỉ xem CV thuộc job của họ

---

## 9) Sprint 6 — Observability + hardening + regression

### Task S6-01

- **Mục tiêu**: chuẩn log hành vi.
- **Log bắt buộc**:
  - `request_id`, `actor_id`, `actor_role`, `action`, `entity_type`, `entity_id`, `before_status`, `after_status`, `created_at`
- **Pass**:
  - truy vết được 1 luồng hoàn chỉnh từ request -> DB update

### Task S6-02

- **Mục tiêu**: test hồi quy các luồng chính.
- **Scope test**:
  - auth + guard
  - candidate apply/save/profile
  - employer manage jobs/applications
  - expired job behavior
- **Pass**:
  - toàn bộ test critical pass

### Task S6-03

- **Mục tiêu**: review bảo mật trước release.
- **Checklist**:
  - validate input server-side
  - sanitize output
  - rate limit login/apply endpoints
  - kiểm tra quyền truy cập dữ liệu nhạy cảm
- **Pass**:
  - không còn lỗ hổng mức cao trong checklist nội bộ

---

## 10) Ma trận nghiệm thu theo luồng người dùng

## 10.1. Luồng ứng viên

1. đăng nhập ứng viên
2. cập nhật hồ sơ
3. lưu việc làm
4. nộp đơn cho job còn hạn
5. bị chặn nộp đơn cho job hết hạn
6. xem lại danh sách đơn đã nộp

## 10.2. Luồng nhà tuyển dụng

1. đăng nhập nhà tuyển dụng
2. tạo tin mới
3. sửa tin
4. tạm dừng / gia hạn / đóng tin
5. xem đơn theo từng tin
6. cập nhật trạng thái đơn
7. ghi chú nội bộ

## 10.3. Luồng công khai

1. lọc danh sách việc làm
2. xem chi tiết việc làm
3. lọc danh sách hồ sơ ứng viên công khai
4. xem hồ sơ công khai với contact đã che

---

## 11) Định nghĩa hoàn thành (Definition of Done)

Một sprint được coi là hoàn thành khi:

1. tất cả task trong sprint đạt pass condition
2. có log/trace đủ để replay lỗi
3. không phá regression luồng đã hoàn tất ở sprint trước
4. tài liệu contract và enum được cập nhật nếu có thay đổi

---

## 12) Kết luận

Checklist này là bản thi công backend theo thứ tự ưu tiên, bám đúng frontend demo hiện tại.

Đội backend có thể dùng trực tiếp để:

1. chia việc theo sprint
2. code theo task có tiêu chí rõ
3. nghiệm thu từng phần mà không mơ hồ phạm vi
