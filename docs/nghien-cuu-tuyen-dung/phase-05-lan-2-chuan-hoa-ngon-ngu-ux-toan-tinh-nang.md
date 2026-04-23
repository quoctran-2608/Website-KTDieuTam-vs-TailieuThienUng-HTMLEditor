# Phase 05 (lần rà soát thứ hai) — Chuẩn hóa ngôn ngữ UX toàn tính năng

## 1) Mục tiêu phase

Chuẩn hóa câu chữ hiển thị trên toàn bộ tính năng Tuyển dụng để:

1. rõ nghĩa với người dùng cuối
2. thống nhất thuật ngữ giữa các trang
3. tránh cụm từ mơ hồ hoặc dễ hiểu sai theo ngữ cảnh nghiệp vụ

---

## 2) Vấn đề phát hiện trước khi sửa

## 2.1. Cụm “Ứng tuyển nhanh” còn xuất hiện dày ở trang chi tiết việc làm

Cụm này xuất hiện ở:

- CTA đầu trang
- CTA cuối trang
- mô tả “Cách ứng tuyển”

trong hầu hết `tuyen-dung/*.html`.

## 2.2. Trang đơn của nhà tuyển dụng còn tiêu đề cũ

`ung-vien-tuyen-dung.html` vẫn dùng tiêu đề/breadcrumb “Ứng viên tuyển dụng”, chưa đồng nhất với trọng tâm thực tế là “Đơn ứng tuyển”.

## 2.3. CTA ở hồ sơ ứng viên công khai chưa thật rõ mục đích

Trong `ung-vien/*.html`, các nút dạng:

- “Xem liên hệ”
- “Yêu cầu kết nối ứng viên”
- “Mở trang ứng viên tuyển dụng”

chưa giải thích rõ hành động người dùng sẽ nhận được.

---

## 3) Đã sửa trong phase này

## 3.1. Chuẩn hóa “Ứng tuyển nhanh” -> “Nộp đơn ứng tuyển” trên toàn bộ job detail

Đã thay hàng loạt trong **24 trang** `tuyen-dung/*.html`:

1. text CTA đầu trang
2. text CTA mobile bar
3. text trong phần “Cách ứng tuyển”

Từ:

- “Ứng tuyển nhanh”

Sang:

- “Nộp đơn ứng tuyển”

## 3.2. Đổi tiêu đề trang recruiter applications

`ung-vien-tuyen-dung.html`:

1. `<title>`:
   - “Ứng viên tuyển dụng” -> “Đơn ứng tuyển”
2. breadcrumb cuối:
   - “Ứng viên tuyển dụng” -> “Đơn ứng tuyển”

## 3.3. Chuẩn hóa wording CTA trên hồ sơ ứng viên công khai bằng hydrate

Trong `site-shell.js` tại `hydrateCandidatePublicProfileActions()`:

1. với link mở trang nhà tuyển dụng theo hồ sơ:
   - “Mở trang ứng viên tuyển dụng” -> “Xem đơn ứng tuyển theo hồ sơ”
2. với link yêu cầu kết nối:
   - “Yêu cầu kết nối...” -> “Gửi yêu cầu mở liên hệ”
3. với link login:
   - “Xem liên hệ” -> “Đăng nhập để xem liên hệ”

Lý do dùng hydrate:

- giảm phải sửa tay 9 file `ung-vien/*.html`
- giữ đồng bộ wording theo một nguồn duy nhất

---

## 4) Lợi ích sau phase

1. người dùng dễ hiểu hành động hơn khi bấm CTA
2. ngôn ngữ nhất quán giữa list/detail/apply
3. giảm thuật ngữ mơ hồ trong luồng recruiter
4. thuận lợi khi triển khai backend vì intent UI rõ hơn

---

## 5) Tiêu chí hoàn thành phase 05 (lần 2)

1. không còn “Ứng tuyển nhanh” trong `tuyen-dung/*.html`
2. trang `ung-vien-tuyen-dung.html` đồng nhất tiêu đề “Đơn ứng tuyển”
3. CTA chính trong `ung-vien/*.html` dùng wording rõ mục đích sau khi hydrate
4. không phát sinh link placeholder do các thay đổi câu chữ

