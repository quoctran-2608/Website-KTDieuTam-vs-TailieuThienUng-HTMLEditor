# Phase 6 Hardening & Go-live Runbook

## 1) Smoke test end-to-end (bắt buộc)

1. Login admin thành công
2. Vào `Bài viết`, tìm 1 bài test
3. Mở chi tiết bài
4. Sửa nhỏ (ví dụ thêm `[SMOKE]` vào excerpt)
5. Save draft
6. Publish
7. Mở public page kiểm tra nội dung mới
8. Rollback gần nhất
9. Mở public page xác nhận đã về trạng thái cũ

## 2) Regression checklist

- list/filter/pagination hoạt động bình thường
- parser detail vẫn parse-safe
- draft reopen không mất dữ liệu
- publish record có backup path
- rollback record có restored path
- audit log có event `article.publish.success` / `article.rollback.success`

## 3) Readiness checks

Chạy:

```bash
php admin/includes/healthcheck.php
python3 tools/site_html_readiness_audit.py
```

Pass condition:

- healthcheck ra toàn bộ `[OK]`
- readiness audit không có blocker mức cao

## 4) Incident handling (runbook ngắn)

### Case A: Publish fail

- kiểm tra message lỗi ở panel status
- kiểm tra quyền ghi file đích + thư mục backup
- thử Save draft lại rồi Publish

### Case B: Nội dung publish sai

- dùng `Rollback gần nhất` ngay
- xác nhận public page đã phục hồi
- đọc record trong `publish-history.json` để truy vết

### Case C: Không có record rollback

- kiểm tra `admin/storage/publish-history.json`
- nếu record bị thiếu, restore thủ công từ file backup trong `admin/storage/backups/`

## 5) Handoff vận hành nội bộ

- account dev mặc định phải đổi mật khẩu khi đưa vào môi trường thật
- quyền `editor`: thao tác nội dung
- quyền `admin`: publish/rollback + xử lý sự cố
- lưu lại lịch sử thao tác định kỳ từ `publish-history.json` + `audit.log`

