# Phase 01 — Khung dữ liệu và trạng thái nghiệp vụ Tuyển dụng

## 1) Mục tiêu

Chốt khung dữ liệu mức nghiệp vụ để các phase sau triển khai frontend và backend đồng nhất.

---

## 2) Thực thể chính và trường cốt lõi

## 2.1. `tai_khoan`

- `id`
- `vai_tro` (`ung_vien`, `nha_tuyen_dung`, `quan_tri`)
- `email`
- `so_dien_thoai`
- `trang_thai_tai_khoan`
- `ngay_tao`
- `ngay_cap_nhat`

## 2.2. `ho_so_ung_vien`

- `id`
- `tai_khoan_id`
- `ho_ten`
- `vi_tri_muc_tieu`
- `kinh_nghiem`
- `khu_vuc_mong_muon`
- `muc_luong_mong_muon`
- `hinh_thuc_lam_viec_mong_muon`
- `gioi_thieu`
- `ky_nang`
- `hoc_van`
- `san_sang_nhan_viec`
- `trang_thai_hien_thi`
- `ngay_cap_nhat`

## 2.3. `tin_tuyen_dung`

- `id`
- `nha_tuyen_dung_id`
- `tieu_de`
- `cong_ty`
- `khu_vuc`
- `dia_chi_lam_viec`
- `muc_luong_tu`
- `muc_luong_den`
- `muc_luong_hien_thi`
- `kinh_nghiem_yeu_cau`
- `hinh_thuc_lam_viec`
- `loai_hinh_cong_viec`
- `mo_ta_cong_viec`
- `yeu_cau_ung_vien`
- `quyen_loi`
- `han_nop_ho_so`
- `trang_thai_tin`
- `ngay_dang`
- `ngay_cap_nhat`

## 2.4. `don_ung_tuyen`

- `id`
- `tin_tuyen_dung_id`
- `ung_vien_id`
- `ho_so_su_dung_id`
- `thu_gioi_thieu`
- `tep_dinh_kem_cv`
- `trang_thai_don`
- `ghi_chu_nha_tuyen_dung`
- `thoi_gian_nop`
- `thoi_gian_cap_nhat`

## 2.5. `viec_lam_da_luu`

- `id`
- `ung_vien_id`
- `tin_tuyen_dung_id`
- `thoi_gian_luu`

## 2.6. `nhu_cau_tuyen_dung`

- `id`
- `ten_cong_ty`
- `nguoi_lien_he`
- `so_dien_thoai`
- `email`
- `vi_tri_can_tuyen`
- `khu_vuc_lam_viec`
- `muc_luong_tham_khao`
- `kinh_nghiem_mong_muon`
- `ghi_chu`
- `trang_thai_xu_ly`
- `thoi_gian_gui`

## 2.7. `ghi_chu_tuyen_dung_noi_bo`

- `id`
- `nha_tuyen_dung_id`
- `candidate_id`
- `job_id`
- `noi_dung`
- `thoi_gian_tao`
- `thoi_gian_cap_nhat`

---

## 3) Trạng thái chuẩn

## 3.1. Trạng thái tin tuyển dụng

| Giá trị | Ý nghĩa |
|---|---|
| `nhap` | Tin đang soạn, chưa hiển thị |
| `dang_tuyen` | Tin đang nhận hồ sơ |
| `tam_dung` | Tạm ẩn, có thể mở lại |
| `da_dong` | Kết thúc tuyển dụng |
| `het_han` | Quá hạn nộp hồ sơ |

## 3.2. Trạng thái đơn ứng tuyển

| Giá trị | Ý nghĩa |
|---|---|
| `moi_nop` | Ứng viên vừa nộp |
| `dang_xem` | Nhà tuyển dụng đang xem |
| `can_bo_sung` | Cần bổ sung hồ sơ |
| `da_lien_he` | Đã liên hệ ứng viên |
| `moi_phong_van` | Đã mời phỏng vấn |
| `tu_choi` | Không phù hợp |
| `trung_tuyen` | Ứng viên trúng tuyển |

## 3.3. Trạng thái nhu cầu tuyển dụng

| Giá trị | Ý nghĩa |
|---|---|
| `moi_gui` | Doanh nghiệp vừa gửi |
| `dang_xu_ly` | Đội ngũ đang xử lý |
| `da_duyet` | Đã duyệt và chuyển đăng tin |
| `tu_choi` | Từ chối do thiếu thông tin hoặc không phù hợp |

---

## 4) Dữ liệu bắt buộc cho thao tác chính

## 4.1. Lưu việc làm

- bắt buộc: `ung_vien_id`, `job_id`

## 4.2. Nộp hồ sơ

- bắt buộc: `ung_vien_id`, `job_id`, `application_id`
- tùy chọn: `tep_dinh_kem_cv`

## 4.3. Cập nhật trạng thái hồ sơ ứng viên (phía nhà tuyển dụng)

- bắt buộc: `candidate_id`, `job_id`, `application_id`, `trang_thai_don`

## 4.4. Ghi chú nội bộ

- bắt buộc: `nha_tuyen_dung_id`, `candidate_id`
- tùy chọn: `job_id`, `application_id`

---

## 5) Nguồn dữ liệu theo hạ tầng

## 5.1. Supabase

Lưu dữ liệu quan hệ:

- tài khoản
- hồ sơ
- tin tuyển dụng
- đơn ứng tuyển
- việc làm đã lưu
- ghi chú nội bộ

## 5.2. R2

Lưu tệp:

- CV
- tệp đính kèm hồ sơ
- tệp bổ sung nhà tuyển dụng yêu cầu

## 5.3. Pages Functions / Workers

Xử lý:

- chuẩn hóa đầu vào
- kiểm tra quyền
- cập nhật trạng thái
- trả dữ liệu đúng theo quyền xem

---

## 6) Điều kiện hoàn thành

1. toàn bộ thực thể chính có trường cốt lõi rõ ràng
2. trạng thái nghiệp vụ được chuẩn hóa
3. mỗi thao tác quan trọng có bộ định danh bắt buộc
4. ánh xạ dữ liệu sang Supabase, R2, Functions/Workers đã rõ

