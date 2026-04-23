# Phase 07 — Gói bàn giao Backend (API + Database + Authentication)

## 1) Mục tiêu

Tài liệu này là “handoff package” để đội backend triển khai trực tiếp theo frontend demo hiện tại.

Mục tiêu:

1. chốt mapping input/output giữa UI và API
2. chốt schema mức triển khai trên Supabase
3. chốt logic authentication + phân quyền route
4. chốt các quy tắc trạng thái và kiểm tra nghiệp vụ quan trọng

---

## 2) Phạm vi trang trong tính năng Tuyển dụng

## 2.1. Trang công khai

1. `tuyen-dung.html`
2. `tuyen-dung/<slug>.html`
3. `danh-sach-ung-vien.html`
4. `ung-vien/<slug>.html`
5. `dang-nhap-tuyen-dung.html`
6. `dang-tin-tuyen-dung.html`

## 2.2. Trang ứng viên (yêu cầu đăng nhập ứng viên)

1. `tai-khoan-ung-vien.html`
2. `ho-so-ung-vien.html`
3. `viec-lam-da-luu.html`
4. `don-ung-tuyen.html`
5. `ung-tuyen.html`

## 2.3. Trang nhà tuyển dụng (yêu cầu đăng nhập nhà tuyển dụng)

1. `nha-tuyen-dung.html`
2. `dang-tin-viec-lam.html`
3. `quan-ly-tin-tuyen-dung.html`
4. `chi-tiet-tin-tuyen-dung.html`
5. `ung-vien-tuyen-dung.html`

---

## 3) Hợp đồng route/query đã khóa

## 3.1. Định danh chính

- `job_id`
- `application_id`
- `profile_id`

## 3.2. Query ngữ cảnh thao tác

- `mode`: `tao-moi`, `sua`, `nhan-ban`, `nop-moi`, `xem-ho-so`, `cap-nhat-ho-so`, `chon-viec-de-ung-tuyen`
- `action`: `luu-viec`, `bo-luu`, `gia-han`, `tam-dung`, `dong-tin`, ...
- `tab`: `tat-ca`, `can-xu-ly`
- `view`: `theo-tin`, `tat-ca`, ...
- `status`: tùy trang (job/application status)

## 3.3. Query lọc danh sách

- `q`
- `location`
- `role`
- `employmentType`
- `workMode`
- `experience`
- `sort`
- `job_id` (ở trang nhà tuyển dụng theo dõi đơn)

---

## 4) Contract biểu mẫu (frontend -> backend)

## 4.1. `jobsFilterForm` (`tuyen-dung.html`, GET)

Fields:

- `q`
- `location`
- `role`
- `employmentType`
- `workMode`
- `experience`
- `sort`

## 4.2. `candidateFilterForm` (`danh-sach-ung-vien.html`, GET)

Fields:

- `q`
- `location`
- `experience`
- `sort`

## 4.3. `candidateLoginForm` (`dang-nhap-tuyen-dung.html`, POST)

Fields:

- `email`
- `password`

## 4.4. `employerLoginForm` (`dang-nhap-tuyen-dung.html`, POST)

Fields:

- `email`
- `password`

## 4.5. `recruitmentLeadForm` (`dang-tin-tuyen-dung.html`, POST)

Fields:

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

## 4.6. `candidateProfileForm` (`ho-so-ung-vien.html`, POST)

Fields:

- `fullName`
- `email`
- `phone`
- `targetRole`
- `experience`
- `preferredLocation`
- `summary`
- `skills`

## 4.7. `jobApplicationForm` (`ung-tuyen.html`, POST)

Fields:

- `fullName`
- `email`
- `phone`
- `experience`
- `coverLetter`
- `cvFileName`

Lưu ý:

- thực tế upload CV cần thêm `cvObjectKey` (R2) ở API layer.

## 4.8. `employerJobForm` (`dang-tin-viec-lam.html`, POST)

Fields:

- `job_id` (hidden)
- `formMode` (hidden)
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
- `submitAction` (`luu-nhap` | `dang-tin`)

## 4.9. `recruiterCandidateFilterForm` (`ung-vien-tuyen-dung.html`, GET)

Fields:

- `q`
- `job_id`
- `role`
- `experience`
- `status`
- `sort`

---

## 5) Trạng thái nghiệp vụ chuẩn

## 5.1. Trạng thái tin tuyển dụng

- `nhap`
- `dang_tuyen`
- `tam_dung`
- `da_dong`
- `het_han`

## 5.2. Trạng thái đơn ứng tuyển

- `moi_nop`
- `dang_xem`
- `can_bo_sung`
- `da_lien_he`
- `moi_phong_van`
- `tu_choi`
- `trung_tuyen`

## 5.3. Trạng thái nhu cầu tuyển dụng

- `moi_gui`
- `dang_xu_ly`
- `da_duyet`
- `tu_choi`

---

## 6) Schema gợi ý trên Supabase (mức triển khai)

## 6.1. Bảng `accounts`

- `id` (uuid, pk)
- `role` (`ung_vien` | `nha_tuyen_dung` | `quan_tri`)
- `email` (unique)
- `phone`
- `status`
- `created_at`
- `updated_at`

## 6.2. Bảng `candidate_profiles`

- `id` (text hoặc uuid, pk)
- `account_id` (fk -> `accounts.id`)
- `full_name`
- `email`
- `phone`
- `target_role`
- `experience_label`
- `preferred_location`
- `summary`
- `skills_text`
- `display_status`
- `updated_at`

## 6.3. Bảng `employers`

- `id` (uuid, pk)
- `account_id` (fk -> `accounts.id`)
- `company_name`
- `contact_name`
- `contact_email`
- `contact_phone`
- `created_at`
- `updated_at`

## 6.4. Bảng `jobs`

- `id` (text: `job/<slug>` hoặc uuid)
- `slug` (unique)
- `employer_id` (fk -> `employers.id`)
- `title`
- `company_name`
- `location`
- `salary_label`
- `deadline`
- `employment_type`
- `work_mode`
- `description`
- `requirements`
- `contact_email`
- `status` (enum job status)
- `published_at`
- `updated_at`

## 6.5. Bảng `applications`

- `id` (text: `app-...` hoặc uuid)
- `job_id` (fk -> `jobs.id`)
- `candidate_profile_id` (fk -> `candidate_profiles.id`)
- `full_name`
- `email`
- `phone`
- `experience_label`
- `cover_letter`
- `cv_file_name`
- `cv_object_key` (R2 key)
- `status` (enum application status)
- `submitted_at`
- `updated_at`

## 6.6. Bảng `saved_jobs`

- `id` (uuid, pk)
- `candidate_profile_id` (fk -> `candidate_profiles.id`)
- `job_id` (fk -> `jobs.id`)
- `created_at`
- unique (`candidate_profile_id`, `job_id`)

## 6.7. Bảng `employer_requests`

- `id` (text: `employer_request_id` hoặc uuid)
- `company_name`
- `contact_name`
- `contact_phone`
- `contact_email`
- `job_title`
- `job_location`
- `job_quantity`
- `job_deadline`
- `employment_type`
- `work_mode`
- `salary_label`
- `experience_level`
- `job_notes`
- `status` (enum employer request status)
- `submitted_at`

## 6.8. Bảng `recruiter_internal_notes`

- `id` (uuid, pk)
- `employer_id`
- `candidate_profile_id`
- `job_id` (nullable)
- `application_id` (nullable)
- `content`
- `created_at`
- `updated_at`

---

## 7) API đề xuất (Pages Functions / Workers)

## 7.1. Public read

1. `GET /api/jobs`
   - query filter list page
2. `GET /api/jobs/:slug`
   - trả chi tiết + trạng thái áp dụng (`is_applicable`)
3. `GET /api/candidates`
   - danh sách hồ sơ công khai (ẩn contact nhạy cảm)
4. `GET /api/candidates/:slug`
   - chi tiết hồ sơ theo quyền

## 7.2. Auth

1. `POST /api/auth/login/candidate`
2. `POST /api/auth/login/employer`
3. `POST /api/auth/logout`
4. `GET /api/auth/me`

## 7.3. Candidate protected

1. `POST /api/candidate/profile`
2. `POST /api/candidate/saved-jobs`
3. `DELETE /api/candidate/saved-jobs/:job_id`
4. `POST /api/candidate/applications`
5. `GET /api/candidate/applications`

## 7.4. Employer protected

1. `POST /api/employer/jobs` (create/update theo `job_id` + `formMode`)
2. `POST /api/employer/jobs/:job_id/actions` (`gia-han`/`tam-dung`/`dong-tin`)
3. `GET /api/employer/jobs`
4. `GET /api/employer/jobs/:job_id`
5. `GET /api/employer/applications`
6. `PATCH /api/employer/applications/:application_id/status`
7. `POST /api/employer/notes`
8. `PATCH /api/employer/notes/:id`

## 7.5. File upload (R2)

1. `POST /api/uploads/cv/presign`
2. frontend upload trực tiếp R2
3. submit application/profile kèm `cv_object_key`

---

## 8) Authentication + Authorization

## 8.1. Auth

- dùng Supabase Auth (email/password)
- session token lưu HttpOnly cookie ở edge

## 8.2. Role guard

- middleware theo route group:
  - candidate routes chỉ `ung_vien`
  - employer routes chỉ `nha_tuyen_dung`
  - admin routes chỉ `quan_tri`

## 8.3. Data guard

Ví dụ:

1. ứng viên chỉ xem/sửa profile của chính mình
2. nhà tuyển dụng chỉ xem job/application thuộc `employer_id` của họ
3. thông tin liên hệ ứng viên đầy đủ chỉ trả cho employer hợp lệ

---

## 9) Quy tắc nghiệp vụ bắt buộc khi code backend

1. job `het_han` hoặc `da_dong`:
   - không cho tạo application mới
2. job `tam_dung`:
   - tạm ngưng nhận mới
   - vẫn xem và xử lý application hiện có
3. cập nhật status application:
   - phải kiểm tra đủ `application_id + job_id + candidate_id`
4. lưu việc làm:
   - idempotent theo (`candidate_profile_id`, `job_id`)

---

## 10) Truy vết và quan sát

Mỗi thao tác ghi log tối thiểu:

- `request_id`
- `actor_id`
- `actor_role`
- `action`
- `entity_type`
- `entity_id`
- `before_status`
- `after_status`
- `created_at`

Mục tiêu:

- debug được theo dấu vết
- replay được sự cố nghiệp vụ

---

## 11) Rủi ro còn lại cần thống nhất trước khi chạy production

1. Quy ước ID:
   - chọn dứt khoát uuid hay slug-based text id cho `jobs/applications`
2. Chuẩn hóa status ở data seed cũ:
   - map `active/expired` -> `dang_tuyen/het_han` tại API layer
3. Chính sách che thông tin liên hệ ứng viên:
   - mức che cho guest/candidate/employer cần thống nhất bằng rule cụ thể

---

## 12) Kết luận bàn giao

Với bộ tài liệu Phase 01 -> 07 và các chỉnh sửa frontend đã chốt:

1. backend có thể bắt đầu triển khai ngay theo demo hiện tại
2. database có khung rõ để dựng migration
3. auth + role guard đã có ranh giới cụ thể
4. không cần đổi lại cấu trúc UI chính nếu bám đúng contract ở tài liệu này
