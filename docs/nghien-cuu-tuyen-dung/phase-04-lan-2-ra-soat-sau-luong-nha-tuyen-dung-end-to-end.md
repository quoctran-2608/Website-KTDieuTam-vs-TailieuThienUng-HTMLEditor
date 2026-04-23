# Phase 04 (lần rà soát thứ hai) — Rà soát sâu luồng nhà tuyển dụng end-to-end

## 1) Mục tiêu phase

Rà soát kỹ toàn chặng nhà tuyển dụng:

1. đăng nhập nhà tuyển dụng
2. vào trang tổng quan
3. quản lý danh sách tin
4. xem chi tiết tin
5. mở danh sách đơn ứng tuyển theo tin
6. quay lại trang đăng tin / trang tổng quan

Mục tiêu:

- link chuyển bước luôn có ngữ cảnh đủ dùng cho backend
- câu chữ rõ ràng giữa “hồ sơ ứng viên” và “đơn ứng tuyển”
- menu và breadcrumb đồng bộ toàn bộ recruiter flow

---

## 2) Vấn đề phát hiện trước khi sửa

## 2.1. Một số link recruiter chưa có ngữ cảnh `from=...`

Nhiều link quay về trang tổng quan (`nha-tuyen-dung.html`) thiếu `from`, gây khó truy vết hành trình thao tác.

## 2.2. Một số link quay về tuyển dụng dùng URL chung

`tuyen-dung.html` chưa có `#job-list` ở vài trang recruiter, làm trải nghiệm quay lại danh sách việc làm chưa nhất quán.

## 2.3. Nhãn menu còn mơ hồ “Ứng viên”

Ở luồng nhà tuyển dụng, mục này thực chất là trang quản lý **đơn ứng tuyển** theo từng tin.

## 2.4. Link hành động từ chi tiết tin về quản lý tin thiếu `tab`

Các link `action=tam-dung`, `action=dong-tin` thiếu `tab=tat-ca`, dễ mất trạng thái tab khi quay về.

## 2.5. Form lọc đơn ứng tuyển chưa giữ `view` ổn định

`recruiterCandidateFilterForm` chưa có hidden `view=theo-tin`, khi submit GET có thể làm rơi mode hiển thị dự kiến.

---

## 3) Đã sửa trong phase này

## 3.1. `dang-nhap-tuyen-dung.html`

Đã thêm ngữ cảnh nguồn vào cho login nhà tuyển dụng:

- `employerLoginForm` action:
  - `nha-tuyen-dung.html?from=dang-nhap-nha-tuyen-dung`

## 3.2. `dang-tin-tuyen-dung.html`

Đã chỉnh:

1. link xem việc làm:
   - `tuyen-dung.html#job-list`
2. wording:
   - “Khu Tuyển dụng...” -> “Trang tuyển dụng...”

## 3.3. `nha-tuyen-dung.html`

Đã chỉnh:

1. meta description:
   - bỏ cụm “hồ sơ ứng viên quan tâm”
   - thay bằng “đơn ứng tuyển theo từng tin”
2. breadcrumb “Tuyển dụng”:
   - `tuyen-dung.html#job-list`
3. menu:
   - “Ứng viên” -> “Đơn ứng tuyển”

## 3.4. `quan-ly-tin-tuyen-dung.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng”:
   - `tuyen-dung.html#job-list`
2. link “Trang nhà tuyển dụng” và menu “Tổng quan” thêm:
   - `?from=quan-ly-tin`
3. menu:
   - “Ứng viên” -> “Đơn ứng tuyển”

## 3.5. `chi-tiet-tin-tuyen-dung.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng”:
   - `tuyen-dung.html#job-list`
2. link về tổng quan thêm:
   - `nha-tuyen-dung.html?from=chi-tiet-tin`
3. menu:
   - “Ứng viên” -> “Đơn ứng tuyển”
4. link thao tác:
   - `action=tam-dung` / `action=dong-tin`
   - bổ sung `tab=tat-ca`

## 3.6. `dang-tin-viec-lam.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng”:
   - `tuyen-dung.html#job-list`
2. link về trang nhà tuyển dụng thêm:
   - `?from=dang-tin-viec-lam`
3. menu “Tổng quan” và CTA về tổng quan đồng bộ `from`
4. menu:
   - “Ứng viên” -> “Đơn ứng tuyển”

## 3.7. `ung-vien-tuyen-dung.html`

Đã chỉnh:

1. breadcrumb “Tuyển dụng”:
   - `tuyen-dung.html#job-list`
2. link về tổng quan thêm:
   - `nha-tuyen-dung.html?from=ung-vien-tuyen-dung`
3. menu:
   - “Ứng viên” -> “Đơn ứng tuyển”
4. form lọc đơn:
   - thêm hidden `view=theo-tin`

---

## 4) Lợi ích sau phase

1. recruiter flow liền mạch hơn khi đi qua nhiều màn hình thao tác
2. backend trace tốt hơn nhờ `from=...` tại các điểm quay lại tổng quan
3. menu/breadcrumb rõ ý nghĩa nghiệp vụ “đơn ứng tuyển”
4. giữ ổn định mode lọc/list của trang đơn ứng tuyển

---

## 5) Tiêu chí hoàn thành phase 04 (lần 2)

1. các link trọng yếu recruiter không còn “mất ngữ cảnh”
2. menu các trang recruiter dùng nhãn nhất quán “Đơn ứng tuyển”
3. link hành động quản lý tin giữ được `tab=tat-ca`
4. form lọc recruiter giữ được `view=theo-tin` khi submit GET

