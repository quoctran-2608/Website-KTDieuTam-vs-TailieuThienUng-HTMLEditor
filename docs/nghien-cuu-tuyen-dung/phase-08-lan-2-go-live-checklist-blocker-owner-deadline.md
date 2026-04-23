# Phase 08 (lần rà soát thứ hai) — Go-live checklist (Blocker / Non-blocker / Owner / Deadline)

## 1) Mục tiêu

Tạo bảng ký duyệt ngắn gọn để chuyển từ “frontend backend-ready” sang “triển khai backend thật” với:

1. danh sách việc bắt buộc trước go-live
2. người chịu trách nhiệm
3. hạn hoàn thành rõ ràng

---

## 2) Cách dùng checklist

1. Cập nhật cột **Owner** theo người phụ trách thực tế.
2. Cập nhật cột **Deadline** theo ngày cam kết.
3. Chỉ cho phép go-live khi tất cả mục **Blocker** đạt trạng thái:
   - `DONE`

Trạng thái đề xuất:

- `TODO`
- `IN PROGRESS`
- `DONE`
- `BLOCKED`

---

## 3) Blocker (bắt buộc xong trước go-live)

| Mã | Hạng mục blocker | Mô tả đầu ra bắt buộc | Owner | Deadline | Trạng thái |
|---|---|---|---|---|---|
| B-01 | Auth ứng viên + nhà tuyển dụng | Hoàn thành login/logout/me cho 2 role, session ổn định | Backend | TBD | TODO |
| B-02 | Role guard API | Route candidate/employer trả 403 đúng khi sai role | Backend | TBD | TODO |
| B-03 | Schema DB lõi | Có bảng `accounts`, `candidate_profiles`, `employers`, `jobs`, `applications`, `saved_jobs` + index cơ bản | Backend | TBD | TODO |
| B-04 | API jobs public | `GET /api/jobs`, `GET /api/jobs/:slug` trả đủ `job_status`, `deadline`, `is_applicable` | Backend | TBD | TODO |
| B-05 | API ứng viên (profile/save/apply) | Hoàn tất create/update profile, save/bỏ lưu, nộp đơn | Backend | TBD | TODO |
| B-06 | API nhà tuyển dụng (jobs/applications) | Hoàn tất tạo/sửa tin, đổi trạng thái tin, lọc đơn, đổi trạng thái đơn | Backend | TBD | TODO |
| B-07 | Rule trạng thái bắt buộc | Tin `het_han`/`da_dong` không nhận nộp mới; validate server-side bắt buộc | Backend | TBD | TODO |
| B-08 | Authorization dữ liệu | Employer chỉ thấy jobs/applications thuộc doanh nghiệp của mình | Backend | TBD | TODO |
| B-09 | Upload CV R2 (tối thiểu) | Có presign + lưu `cv_object_key` + chặn truy cập trái quyền | Backend | TBD | TODO |
| B-10 | Log truy vết hành vi | Ghi tối thiểu `request_id`, `actor_id`, `role`, `action`, `entity`, `before/after_status` | Backend | TBD | TODO |
| B-11 | Seed dữ liệu staging | Có dataset đủ test 3 luồng: public/candidate/employer | Backend | TBD | TODO |
| B-12 | Kiểm thử luồng chính | Test pass cho các luồng nêu ở mục 5 (candidate + employer + public) | QA | TBD | TODO |

---

## 4) Non-blocker (nên làm sớm sau go-live)

| Mã | Hạng mục non-blocker | Giá trị mang lại | Owner | Deadline | Trạng thái |
|---|---|---|---|---|---|
| N-01 | Bộ test tự động E2E | Giảm regression khi thêm tính năng mới | QA / Backend | TBD | TODO |
| N-02 | Dashboard chỉ số tuyển dụng | Theo dõi chuyển đổi theo job/application status | Product / Backend | TBD | TODO |
| N-03 | Cảnh báo SLA xử lý đơn | Nhắc đơn mới chưa xử lý sau X giờ | Backend | TBD | TODO |
| N-04 | Tối ưu trải nghiệm mobile sâu hơn | Tăng độ mượt cho form dài và bảng recruiter | Frontend | TBD | TODO |
| N-05 | Đồng bộ nội dung thông báo email/Zalo | Ngôn ngữ nhất quán giữa web và kênh liên hệ | Content / Ops | TBD | TODO |

---

## 5) Ma trận nghiệm thu go-live (tối thiểu)

## 5.1. Luồng public

1. vào `tuyen-dung.html`, lọc/sắp xếp đúng
2. vào chi tiết `tuyen-dung/<slug>.html`, CTA tự gắn đúng `job_id`
3. tin hết hạn không cho nộp mới

## 5.2. Luồng ứng viên

1. login ứng viên -> vào đúng tài khoản
2. cập nhật hồ sơ lưu thành công theo `profile_id`
3. lưu/bỏ lưu việc làm hoạt động đúng theo `job_id`
4. nộp đơn mới thành công cho tin còn tuyển
5. với tin hết hạn: chỉ xem lại hồ sơ, không nộp mới

## 5.3. Luồng nhà tuyển dụng

1. login nhà tuyển dụng -> vào dashboard recruiter
2. tạo/sửa tin theo `job_id` + `formMode`
3. thao tác `gia-han` / `tam-dung` / `dong-tin` đúng trạng thái
4. lọc đơn theo `job_id`, `status`, `q`, `sort`
5. đổi trạng thái đơn lưu đúng và có log before/after

---

## 6) Quyết định go-live

Chỉ chấp thuận go-live khi:

1. 100% mục **Blocker** = `DONE`
2. Ma trận nghiệm thu mục 5 pass đầy đủ
3. Không còn lỗi nghiêm trọng P0/P1 ở staging

Mẫu quyết định:

- **Go-live approved** / **Go-live rejected**
- Lý do:
- Người duyệt:
- Thời điểm:

