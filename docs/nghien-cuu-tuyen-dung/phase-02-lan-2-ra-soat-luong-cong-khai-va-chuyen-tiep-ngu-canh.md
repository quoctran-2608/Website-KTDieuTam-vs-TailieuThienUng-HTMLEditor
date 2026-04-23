# Phase 02 (lần rà soát thứ hai) — Luồng công khai và chuyển tiếp ngữ cảnh

## 1) Mục tiêu phase

Rà soát sâu chặng công khai của người dùng:

1. từ danh sách việc làm (`tuyen-dung.html`)
2. sang trang chi tiết việc làm (`tuyen-dung/<slug>.html`)
3. sang ứng tuyển/lưu việc (`ung-tuyen.html`, `viec-lam-da-luu.html`)

Mục tiêu chính:

- không mất ngữ cảnh `job_id`
- CTA đúng theo trạng thái tin (đang tuyển / hết hạn)
- câu chữ và nhãn thao tác rõ ràng với người dùng cuối

---

## 2) Vấn đề phát hiện trước khi sửa

## 2.1. Chuyển tiếp ngữ cảnh chưa đồng đều

Một số link trong luồng ứng viên vẫn thiếu ngữ cảnh:

1. từ dashboard ứng viên sang chi tiết tin chưa luôn kèm `job_id`
2. từ danh sách đơn đã nộp sang chi tiết tin chưa kèm `application_id`
3. từ “dùng hồ sơ để ứng tuyển” đi thẳng vào trang nộp đơn nhưng chưa chọn vị trí

## 2.2. Trang danh sách việc làm chưa có hành vi lưu việc rõ ràng

Nút bookmark ở card việc làm mới dừng ở UI icon, chưa dẫn người dùng vào luồng “Việc làm đã lưu” với ngữ cảnh cụ thể.

## 2.3. Trang hồ sơ ứng viên công khai thiếu ngữ cảnh candidate khi chuyển qua trang nhà tuyển dụng

Các nút “Yêu cầu kết nối” và “Đăng nhập nhà tuyển dụng” chưa luôn mang `candidate_id`, nên dễ mất ngữ cảnh hồ sơ đang xem.

---

## 3) Đã sửa trong phase này

## 3.1. `site-shell.js`

Đã thêm/hoàn thiện 3 lớp hydrate ở phía client:

### a) `hydrateJobDetailActions()` (đã có từ phase trước, tiếp tục dùng)

- Tự suy ra `job_id` từ slug trang chi tiết việc làm
- Tự gắn query cho:
  - nộp đơn
  - lưu việc
- Với tin hết hạn:
  - CTA nộp đơn đổi thành “Xem tin tương tự”
  - chuyển về danh sách việc làm

### b) `hydrateCandidatePublicProfileActions()` (mới)

Khi đang ở `ung-vien/<slug>.html`:

1. link “Yêu cầu kết nối” -> `ung-vien-tuyen-dung.html` kèm:
   - `view=ho-so-phu-hop`
   - `candidate_id`
   - `from=ho-so-cong-khai`
2. link “Đăng nhập nhà tuyển dụng” -> `dang-nhap-tuyen-dung.html` kèm:
   - `candidate_id`
   - `from=ho-so-cong-khai`

### c) `hydrateJobsListSaveActions()` (mới)

Ở `tuyen-dung.html`:

1. nút bookmark mỗi job card:
   - lấy slug job từ link chi tiết
   - set `aria-pressed` đúng trạng thái nút
2. khi bấm lưu:
   - đổi icon đã lưu ngay trên UI
   - điều hướng sang `viec-lam-da-luu.html` kèm:
     - `action=luu-viec`
     - `job_id`
     - `from=danh-sach-viec-lam`
     - `title`

## 3.2. `ung-tuyen.html`

Đã sửa để rõ ngữ cảnh nộp đơn:

1. câu mô tả nhấn mạnh “theo đúng vị trí đang chọn”
2. link “Xem đơn đã ứng tuyển” có thêm query ngữ cảnh:
   - `view=tat-ca`
   - `from=ung-tuyen`
3. form thêm hidden field:
   - `job_id`
   - `application_id` (mẫu demo)

## 3.3. `ho-so-ung-vien.html`

Nút:

- từ “Dùng hồ sơ để ứng tuyển”
- đổi thành “Chọn việc phù hợp để ứng tuyển”
- điều hướng về `tuyen-dung.html#job-list`

Mục tiêu: tránh vào trang nộp đơn khi chưa có vị trí cụ thể.

## 3.4. `tai-khoan-ung-vien.html`

Các link job trong:

1. việc làm đã lưu
2. đơn đã nộp
3. gợi ý phù hợp

đã gắn thêm query ngữ cảnh:

- `from=tai-khoan-ung-vien`
- `job_id=...`

## 3.5. `viec-lam-da-luu.html`

Các link tiêu đề việc làm đã lưu được bổ sung:

- `from=viec-da-luu`
- `job_id=...`

## 3.6. `don-ung-tuyen.html`

Các link vị trí trong bảng đơn đã nộp được bổ sung:

- `from=don-ung-tuyen`
- `application_id=...`
- `job_id=...`

---

## 4) Lợi ích đạt được sau phase

1. luồng công khai và chuyển trang không “rơi mất” định danh quan trọng
2. thao tác “lưu việc” từ danh sách việc làm đã đi đúng vào trang đích
3. từ hồ sơ ứng viên công khai sang recruiter flow giữ được `candidate_id`
4. giảm rủi ro backend phải vá ngữ cảnh sau này

---

## 5) Điểm chưa xử lý trong phase này (để phase sau)

1. nội dung tĩnh trong từng file chi tiết việc làm còn có cụm “Ứng tuyển nhanh” trong phần mô tả (đang do template tĩnh sinh ra)
2. chưa build cơ chế hydrate query cho mọi link phụ trong prose (chỉ ưu tiên CTA chính và luồng nghiệp vụ cốt lõi)

Các điểm này sẽ được xử lý ở phase rà soát tiếp theo theo mức ưu tiên UX.

---

## 6) Tiêu chí hoàn thành phase 02 (lần 2)

1. từ job card -> lưu việc có route ngữ cảnh rõ
2. từ profile công khai -> recruiter flow giữ được `candidate_id`
3. trang ứng tuyển nhận được `job_id` từ form contract
4. link từ dashboard ứng viên sang chi tiết/đơn giữ ngữ cảnh tốt hơn trước

