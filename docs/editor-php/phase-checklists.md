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
- [ ] đồng bộ index từ `data/articles.json` vào DB index table
- [ ] search theo `title/id/href`
- [ ] filter: `section`, `libraryKindKey`, `topicLv1Key`, `topicLv2Key`, date range
- [ ] sort + pagination
- [ ] filter chips + clear filters
- [ ] trạng thái loading/empty/error rõ ràng

## Nghiệm thu phase
- [ ] lọc `thu-vien`/`ban-tin` ra đúng tập bài
- [ ] query phổ biến phản hồi nhanh, không lag UI
- [ ] mở được trang chi tiết bài từ list

---

## Phase 3 — Article detail read-only + parser safety

## Mục tiêu
- đảm bảo parse/ghi bài an toàn trước khi cho sửa

## Checklist thực thi
- [ ] hiển thị metadata hiện tại
- [ ] hiển thị block `.article-prose`
- [ ] hiển thị payload `article-meta`
- [ ] parser detect được vùng editable
- [ ] đánh dấu bài parse fail để xử lý ngoại lệ

## Nghiệm thu phase
- [ ] tỷ lệ parse-safe đạt ngưỡng đã chốt (khuyến nghị >= 98%)
- [ ] có report bài ngoại lệ

---

## Phase 4 — Editor form + draft + preview diff

## Mục tiêu
- editor sửa từng bài và lưu draft an toàn

## Checklist thực thi
- [ ] form edit theo contract v1
- [ ] save draft
- [ ] before/after diff (metadata + prose)
- [ ] preview render
- [ ] validation bắt buộc
- [ ] thông báo lỗi theo field, không mơ hồ

## Nghiệm thu phase
- [ ] save draft/reopen draft không mất dữ liệu
- [ ] preview phản ánh đúng nội dung sẽ publish

---

## Phase 5 — Publish + backup + rollback

## Mục tiêu
- publish ra file thật có kiểm soát

## Checklist thực thi
- [ ] backup file trước khi ghi
- [ ] ghi đúng block `.article-prose` + `article-meta`
- [ ] sync metadata index cần thiết
- [ ] audit log đầy đủ
- [ ] rollback 1-click về bản gần nhất

## Nghiệm thu phase
- [ ] publish xong mở public page thấy nội dung mới
- [ ] rollback thành công, public page trở về trạng thái trước

---

## Phase 6 — Hardening & go-live nội bộ

## Mục tiêu
- hệ thống đủ ổn để dùng hàng ngày

## Checklist thực thi
- [ ] smoke test end-to-end
- [ ] regression cho list/filter/edit/publish/rollback
- [ ] chạy readiness audit sau ca test
- [ ] kiểm tra phân quyền editor/admin đúng
- [ ] chốt runbook sự cố cơ bản

## Nghiệm thu phase
- [ ] không còn blocker mức cao
- [ ] có checklist vận hành cho người dùng nội bộ

---

## Gate rule trước khi qua phase kế tiếp

- Không đạt nghiệm thu phase hiện tại -> không qua phase kế tiếp.
- Mọi thay đổi contract phải cập nhật lại:
  - `mvp-scope-v1.md`
  - file checklist này
