# Phase 01 — Ma trận trang và phân quyền Tuyển dụng

## 1) Mục tiêu

Tài liệu này khóa ranh giới truy cập từng trang để frontend và backend cùng bám một chuẩn.

---

## 2) Danh sách trang theo nhóm

## 2.1. Trang công khai

1. `tuyen-dung.html`
2. `tuyen-dung/<slug>.html`
3. `danh-sach-ung-vien.html`
4. `ung-vien/<slug>.html` (chỉ công khai thông tin đã ẩn liên hệ)
5. `dang-nhap-tuyen-dung.html`
6. `dang-tin-tuyen-dung.html` (form gửi nhu cầu tuyển dụng)

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

## 3) Ma trận truy cập

| Trang | Khách | Ứng viên | Nhà tuyển dụng | Quản trị |
|---|---:|---:|---:|---:|
| `tuyen-dung.html` | Có | Có | Có | Có |
| `tuyen-dung/<slug>.html` | Có | Có | Có | Có |
| `danh-sach-ung-vien.html` | Có | Có | Có | Có |
| `ung-vien/<slug>.html` | Có* | Có* | Có | Có |
| `dang-nhap-tuyen-dung.html` | Có | Có | Có | Có |
| `dang-tin-tuyen-dung.html` | Có | Có | Có | Có |
| `tai-khoan-ung-vien.html` | Không | Có | Không | Có |
| `ho-so-ung-vien.html` | Không | Có | Không | Có |
| `viec-lam-da-luu.html` | Không | Có | Không | Có |
| `don-ung-tuyen.html` | Không | Có | Không | Có |
| `ung-tuyen.html` | Không | Có | Không | Có |
| `nha-tuyen-dung.html` | Không | Không | Có | Có |
| `dang-tin-viec-lam.html` | Không | Không | Có | Có |
| `quan-ly-tin-tuyen-dung.html` | Không | Không | Có | Có |
| `chi-tiet-tin-tuyen-dung.html` | Không | Không | Có | Có |
| `ung-vien-tuyen-dung.html` | Không | Không | Có | Có |

\* Trang hồ sơ ứng viên công khai chỉ hiển thị thông tin liên hệ đã che.

---

## 4) Quy tắc chặn và chuyển hướng

## 4.1. Khi khách vào trang ứng viên cần đăng nhập

- chuyển hướng về `dang-nhap-tuyen-dung.html#dang-nhap-ung-vien`
- lưu đường dẫn quay lại sau đăng nhập

## 4.2. Khi khách vào trang nhà tuyển dụng cần đăng nhập

- chuyển hướng về `dang-nhap-tuyen-dung.html#dang-nhap-nha-tuyen-dung`
- lưu đường dẫn quay lại sau đăng nhập

## 4.3. Khi đăng nhập sai vai trò

- ứng viên cố vào trang nhà tuyển dụng: chuyển về `tai-khoan-ung-vien.html`
- nhà tuyển dụng cố vào trang ứng viên: chuyển về `nha-tuyen-dung.html`

---

## 5) Quy tắc hiển thị thông tin liên hệ ứng viên

Thông tin liên hệ đầy đủ (email, số điện thoại) chỉ hiển thị khi:

1. người dùng là nhà tuyển dụng đã đăng nhập
2. tài khoản nhà tuyển dụng hợp lệ
3. hồ sơ chưa bị khóa hoặc ẩn

Các trường hợp còn lại:

- chỉ hiển thị bản che một phần
- kèm nút mời đăng nhập nhà tuyển dụng

---

## 6) Ánh xạ hạ tầng kỹ thuật

## 6.1. Pages Functions

- chặn truy cập theo vai trò ở mức route
- trả về dữ liệu đã lọc theo quyền xem

## 6.2. Workers

- xử lý tác vụ nghiệp vụ cần hiệu năng biên
- xử lý các tuyến tích hợp mở rộng

## 6.3. Supabase

- nguồn xác thực đăng nhập
- nguồn phân quyền vai trò

## 6.4. R2

- lưu tệp CV và tệp đính kèm
- trả URL có kiểm soát truy cập khi cần

---

## 7) Tiêu chí chốt phase

1. mọi trang đã có nhóm truy cập rõ
2. có quy tắc chuyển hướng khi chưa đăng nhập
3. có quy tắc xử lý đăng nhập sai vai trò
4. có quy tắc khóa thông tin liên hệ ứng viên

