# Phase 10 (lần rà soát thứ hai) — Mẫu biên bản họp ký duyệt go-live

## 1) Thông tin cuộc họp

- Tên cuộc họp: Họp ký duyệt go-live tính năng Tuyển dụng
- Dự án: ketoandieutam.com
- Ngày họp: …… / …… / ……
- Giờ họp: …… : …… đến …… : ……
- Hình thức: Trực tiếp / Online
- Chủ trì: ........................................
- Thư ký: ........................................

---

## 2) Thành phần tham dự

| Vai trò | Họ tên | Bộ phận | Có mặt (Y/N) |
|---|---|---|---|
| Product Owner |  | Product |  |
| Tech Lead Backend |  | Backend |  |
| Tech Lead Frontend |  | Frontend |  |
| QA Lead |  | QA |  |
| DevOps/Ops |  | Ops |  |
| Đại diện vận hành nội dung |  | Content/Ops |  |

---

## 3) Mục tiêu buổi họp

1. Xác nhận trạng thái sẵn sàng go-live backend cho tính năng Tuyển dụng.
2. Đối chiếu blocker theo checklist đã chốt.
3. Ra quyết định:
   - Go-live approved
   - hoặc Go-live rejected

---

## 4) Tóm tắt đầu vào trước cuộc họp

Nguồn tham chiếu bắt buộc:

1. `phase-08-lan-2-go-live-checklist-blocker-owner-deadline.md`
2. `phase-09-lan-2-executive-summary-chot-trien-khai.md`
3. `phase-07-goi-ban-giao-backend-api-database-auth.md`

Snapshot tại thời điểm họp:

- Tổng blocker: ..........
- Blocker DONE: ..........
- Blocker còn mở: ..........
- Số lỗi P0/P1 còn mở ở staging: ..........

---

## 5) Bảng rà soát blocker tại cuộc họp

| Mã | Hạng mục | Trạng thái trước họp | Trạng thái tại họp | Owner | Deadline cam kết | Ghi chú |
|---|---|---|---|---|---|---|
| B-01 | Auth ứng viên + nhà tuyển dụng |  |  |  |  |  |
| B-02 | Role guard API |  |  |  |  |  |
| B-03 | Schema DB lõi |  |  |  |  |  |
| B-04 | API jobs public |  |  |  |  |  |
| B-05 | API ứng viên (profile/save/apply) |  |  |  |  |  |
| B-06 | API nhà tuyển dụng (jobs/applications) |  |  |  |  |  |
| B-07 | Rule trạng thái bắt buộc |  |  |  |  |  |
| B-08 | Authorization dữ liệu |  |  |  |  |  |
| B-09 | Upload CV R2 (tối thiểu) |  |  |  |  |  |
| B-10 | Log truy vết hành vi |  |  |  |  |  |
| B-11 | Seed dữ liệu staging |  |  |  |  |  |
| B-12 | Kiểm thử luồng chính |  |  |  |  |  |

---

## 6) Kết quả nghiệm thu luồng người dùng

## 6.1. Luồng public

- [ ] Lọc/sắp xếp danh sách việc làm hoạt động đúng
- [ ] Chi tiết tin gắn đúng `job_id`
- [ ] Tin hết hạn không cho nộp mới

## 6.2. Luồng ứng viên

- [ ] Login ứng viên thành công, guard đúng
- [ ] Cập nhật hồ sơ theo `profile_id` thành công
- [ ] Lưu/bỏ lưu theo `job_id` đúng
- [ ] Nộp đơn mới cho tin còn tuyển thành công
- [ ] Với tin hết hạn chỉ cho xem lại hồ sơ

## 6.3. Luồng nhà tuyển dụng

- [ ] Login nhà tuyển dụng thành công, guard đúng
- [ ] Tạo/sửa tin theo `job_id` + `formMode` thành công
- [ ] Gia hạn/tạm dừng/đóng tin đúng rule trạng thái
- [ ] Lọc đơn theo `job_id`, `status`, `q`, `sort` đúng
- [ ] Đổi trạng thái đơn có log before/after

---

## 7) Quyết định tại cuộc họp

- Quyết định:
  - [ ] Go-live approved
  - [ ] Go-live rejected

- Lý do quyết định:
  - ............................................................................
  - ............................................................................

- Điều kiện bắt buộc sau họp (nếu rejected):
  1. ..........................................................................
  2. ..........................................................................
  3. ..........................................................................

- Mốc họp lại:
  - Ngày: …… / …… / ……
  - Giờ: …… : ……

---

## 8) Danh sách action items sau họp

| Mã việc | Nội dung | Owner | Hạn hoàn thành | Mức ưu tiên | Trạng thái |
|---|---|---|---|---|---|
| A-01 |  |  |  | P0 | TODO |
| A-02 |  |  |  | P1 | TODO |
| A-03 |  |  |  | P1 | TODO |
| A-04 |  |  |  | P2 | TODO |

---

## 9) Ký xác nhận

| Vai trò | Họ tên | Chữ ký | Thời điểm |
|---|---|---|---|
| Product Owner |  |  |  |
| Tech Lead Backend |  |  |  |
| QA Lead |  |  |  |
| DevOps/Ops |  |  |  |

---

## 10) Ghi chú sử dụng mẫu

1. Không bỏ qua mục 5 và 6 khi ký duyệt.
2. Nếu còn blocker chưa DONE, mặc định chọn “Go-live rejected”.
3. Biên bản sau ký duyệt phải lưu kèm link commit/release candidate và bản test report tương ứng.

