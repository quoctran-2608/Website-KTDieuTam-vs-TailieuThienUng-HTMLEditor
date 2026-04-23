# Phase 01 — Chốt nghiệp vụ và hợp đồng tính năng Tuyển dụng

## 1) Mục tiêu phase

Khóa phạm vi nghiệp vụ để toàn bộ frontend Tuyển dụng trở thành bản chuẩn cho backend triển khai bằng:

- Cloudflare Pages Functions
- Cloudflare Workers
- Supabase
- Cloudflare R2

Sau phase này, team có thể thống nhất:

1. vai trò người dùng
2. luồng xử lý chính
3. dữ liệu cốt lõi
4. trạng thái nghiệp vụ
5. ranh giới trang công khai và trang cần đăng nhập

---

## 2) Quyết định nghiệp vụ đã chốt

## 2.1. Mô hình nhà tuyển dụng

Chốt mô hình 2 lớp:

1. **Lớp 1: Gửi nhu cầu tuyển dụng**  
   Dành cho doanh nghiệp chưa có tài khoản hoặc chưa muốn tự đăng tin.  
   Trang sử dụng: `dang-tin-tuyen-dung.html`

2. **Lớp 2: Tự quản lý tin tuyển dụng**  
   Dành cho nhà tuyển dụng đã đăng nhập.  
   Trang sử dụng: `nha-tuyen-dung.html`, `dang-tin-viec-lam.html`, `quan-ly-tin-tuyen-dung.html`, `chi-tiet-tin-tuyen-dung.html`, `ung-vien-tuyen-dung.html`

## 2.2. Mô hình ứng viên

Ứng viên có một tài khoản dùng cho toàn bộ thao tác:

- tạo hồ sơ
- lưu việc làm
- nộp hồ sơ
- theo dõi trạng thái đơn

---

## 3) Vai trò và quyền truy cập

## 3.1. Vai trò

1. `khach` (chưa đăng nhập)
2. `ung_vien`
3. `nha_tuyen_dung`
4. `quan_tri` (nội bộ)

## 3.2. Ma trận quyền tóm tắt

| Chức năng | Khách | Ứng viên | Nhà tuyển dụng | Quản trị |
|---|---:|---:|---:|---:|
| Xem danh sách việc làm | Có | Có | Có | Có |
| Xem chi tiết việc làm | Có | Có | Có | Có |
| Lưu việc làm | Không | Có | Không | Có |
| Nộp hồ sơ ứng tuyển | Không | Có | Không | Có |
| Xem thông tin liên hệ ứng viên đầy đủ | Không | Không | Có | Có |
| Tạo/sửa/đóng tin tuyển dụng | Không | Không | Có | Có |
| Quản lý ghi chú nội bộ tuyển dụng | Không | Không | Có | Có |
| Kiểm duyệt nhu cầu tuyển dụng | Không | Không | Không | Có |

---

## 4) Thực thể dữ liệu cốt lõi

## 4.1. Danh sách thực thể

1. `tai_khoan`
2. `ho_so_ung_vien`
3. `tin_tuyen_dung`
4. `don_ung_tuyen`
5. `viec_lam_da_luu`
6. `nhu_cau_tuyen_dung` (doanh nghiệp gửi)
7. `ghi_chu_tuyen_dung_noi_bo`

## 4.2. Quan hệ dữ liệu

- một `tai_khoan` ứng viên có một `ho_so_ung_vien`
- một `tai_khoan` nhà tuyển dụng có nhiều `tin_tuyen_dung`
- một `tin_tuyen_dung` có nhiều `don_ung_tuyen`
- một `tai_khoan` ứng viên có nhiều `don_ung_tuyen`
- một `tai_khoan` ứng viên có nhiều `viec_lam_da_luu`
- một `tin_tuyen_dung` có nhiều `ghi_chu_tuyen_dung_noi_bo`

---

## 5) Trạng thái nghiệp vụ

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

## 5.3. Trạng thái nhu cầu tuyển dụng (form doanh nghiệp)

- `moi_gui`
- `dang_xu_ly`
- `da_duyet`
- `tu_choi`

---

## 6) Hợp đồng trang (page contract)

## 6.1. Khu công khai

1. `tuyen-dung.html`  
   - đầu vào: bộ lọc, từ khóa  
   - đầu ra: danh sách tin theo điều kiện  
   - thao tác chính: xem tin, đăng nhập, chuyển sang khu ứng viên/nhà tuyển dụng

2. `tuyen-dung/<slug>.html`  
   - đầu vào: mã slug tin  
   - đầu ra: chi tiết tin, thông tin công ty, hạn nộp, nút ứng tuyển

3. `danh-sach-ung-vien.html`, `ung-vien/<slug>.html`  
   - đầu vào: bộ lọc hồ sơ ứng viên  
   - đầu ra: hồ sơ công khai đã ẩn thông tin liên hệ đầy đủ

4. `dang-nhap-tuyen-dung.html`  
   - đầu vào: email + mật khẩu theo vai trò  
   - đầu ra: chuyển vào đúng khu thao tác theo vai trò

## 6.2. Khu ứng viên (cần đăng nhập)

- `tai-khoan-ung-vien.html`
- `ho-so-ung-vien.html`
- `viec-lam-da-luu.html`
- `don-ung-tuyen.html`
- `ung-tuyen.html`

## 6.3. Khu nhà tuyển dụng (cần đăng nhập)

- `nha-tuyen-dung.html`
- `dang-tin-viec-lam.html`
- `quan-ly-tin-tuyen-dung.html`
- `chi-tiet-tin-tuyen-dung.html`
- `ung-vien-tuyen-dung.html`

---

## 7) Hợp đồng định danh (id bắt buộc trong luồng)

Các thao tác backend phải có định danh rõ:

- `job_id` cho mọi thao tác liên quan tin tuyển dụng
- `candidate_id` cho thao tác hồ sơ ứng viên
- `application_id` cho thao tác đơn ứng tuyển
- `employer_request_id` cho nhu cầu tuyển dụng doanh nghiệp

Nếu thiếu các định danh này, không đủ điều kiện chuyển sang phase backend.

---

## 8) Ánh xạ công nghệ backend

## 8.1. Supabase

- quản lý đăng nhập, phiên đăng nhập, phân vai
- lưu dữ liệu nghiệp vụ chính:
  - tài khoản
  - hồ sơ
  - tin tuyển dụng
  - đơn ứng tuyển
  - việc làm đã lưu
  - ghi chú nội bộ

## 8.2. Cloudflare R2

- lưu tệp:
  - CV
  - tệp đính kèm hồ sơ
  - tệp liên quan tuyển dụng khi cần

## 8.3. Cloudflare Pages Functions / Workers

- lớp API trung gian cho frontend
- kiểm tra quyền theo vai trò
- điều phối nghiệp vụ cần xử lý ở biên
- kết nối Supabase và R2

---

## 9) Quy tắc ngôn ngữ trên frontend

Toàn bộ chữ hiển thị cho người dùng phải:

1. thuần Việt
2. rõ ràng
3. dễ hiểu
4. dùng được ngay

Không dùng trên giao diện người dùng:

- câu từ mang tính báo cáo nội bộ
- câu từ thông báo tiến độ làm phần mềm
- ngôn ngữ nửa Việt nửa Anh
- thông điệp kiểu kỹ thuật dành cho đội phát triển

---

## 10) Tiêu chí hoàn thành Phase 01

Phase 01 được xem là hoàn tất khi đáp ứng đủ:

1. chốt được mô hình 2 lớp cho nhà tuyển dụng
2. có ma trận quyền theo vai trò
3. chốt danh sách thực thể và trạng thái nghiệp vụ
4. chốt hợp đồng trang công khai và trang cần đăng nhập
5. chốt id bắt buộc cho các thao tác chính
6. chốt nguyên tắc ngôn ngữ hiển thị thuần Việt cho frontend

