# Phase Checklists — Admin Editor PHP

> Nguyên tắc vận hành: làm **từng phase**, qua phase nào nghiệm thu phase đó, không nhảy cóc.

---

## Phase 0 — Scope lock & safety contract

## Mục tiêu
- khóa phạm vi MVP v1
- chốt field nào editable/read-only
- chốt tiêu chuẩn UX cơ bản cho list/filter/edit

## Checklist thực thi
- [x] chốt file `mvp-scope-v1.md`
- [x] chốt contract field v1 (editable/read-only)
- [x] chốt tiêu chuẩn backup + rollback
- [x] chốt Definition of Done cho mỗi phase
- [x] chốt tiêu chuẩn UI: list trực quan, filter rõ, empty state rõ

## Nghiệm thu phase
- [x] team thống nhất không mở rộng scope ngoài v1
- [x] có checklist làm việc cho phase 1 -> phase 6

---

## Phase 1 — Auth & admin shell

## Mục tiêu
- có cổng `/admin` an toàn

## Checklist thực thi
- [x] login/logout
- [x] role `admin/editor`
- [x] CSRF token
- [x] session timeout
- [x] lockout tạm khi nhập sai nhiều lần
- [x] layout shell admin (header/sidebar/content)

## Nghiệm thu phase
- [x] truy cập `/admin/*` khi chưa login bị chặn
- [x] đăng nhập đúng role vào được dashboard
- [x] log đăng nhập thành công/thất bại có lưu

---

## Phase 2 — Article list trực quan + filter

## Mục tiêu
- tìm bài nhanh như trải nghiệm hub hiện tại

## Checklist thực thi
- [x] đồng bộ index từ `data/articles.json` vào DB index table
- [x] search theo `title/id/href`
- [x] filter: `section`, `libraryKindKey`, `topicLv1Key`, `topicLv2Key`, date range
- [x] sort + pagination
- [x] filter chips + clear filters
- [x] trạng thái loading/empty/error rõ ràng

## Nghiệm thu phase
- [x] lọc `thu-vien`/`ban-tin` ra đúng tập bài
- [x] query phổ biến phản hồi nhanh, không lag UI
- [x] mở được trang chi tiết bài từ list

---

## Phase 3 — Article detail read-only + parser safety

## Mục tiêu
- đảm bảo parse/ghi bài an toàn trước khi cho sửa

## Checklist thực thi
- [x] hiển thị metadata hiện tại
- [x] hiển thị block `.article-prose`
- [x] hiển thị payload `article-meta`
- [x] parser detect được vùng editable
- [x] đánh dấu bài parse fail để xử lý ngoại lệ

## Nghiệm thu phase
- [x] tỷ lệ parse-safe đạt ngưỡng đã chốt (khuyến nghị >= 98%)
- [x] có report bài ngoại lệ

---

## Phase 4 — Editor form + draft + preview diff

## Mục tiêu
- editor sửa từng bài và lưu draft an toàn

## Checklist thực thi
- [x] form edit theo contract v1
- [x] save draft
- [x] before/after diff (metadata + prose)
- [x] preview render
- [x] validation bắt buộc
- [x] thông báo lỗi theo field, không mơ hồ

## Nghiệm thu phase
- [x] save draft/reopen draft không mất dữ liệu
- [x] preview phản ánh đúng nội dung sẽ publish

---

## Phase 5 — Publish + backup + rollback

## Mục tiêu
- publish ra file thật có kiểm soát

## Checklist thực thi
- [x] backup file trước khi ghi
- [x] ghi đúng block `.article-prose` + `article-meta`
- [x] sync metadata index cần thiết
- [x] audit log đầy đủ
- [x] rollback 1-click về bản gần nhất

## Nghiệm thu phase
- [x] publish xong mở public page thấy nội dung mới
- [x] rollback thành công, public page trở về trạng thái trước

---

## Phase 6 — Hardening & go-live nội bộ

## Mục tiêu
- hệ thống đủ ổn để dùng hàng ngày

## Checklist thực thi
- [x] smoke test end-to-end
- [x] regression cho list/filter/edit/publish/rollback
- [x] chạy readiness audit sau ca test
- [x] kiểm tra phân quyền editor/admin đúng
- [x] chốt runbook sự cố cơ bản

## Nghiệm thu phase
- [x] không còn blocker mức cao
- [x] có checklist vận hành cho người dùng nội bộ

---

## Gate rule trước khi qua phase kế tiếp

- Không đạt nghiệm thu phase hiện tại -> không qua phase kế tiếp.
- Mọi thay đổi contract phải cập nhật lại:
  - `mvp-scope-v1.md`
  - file checklist này
