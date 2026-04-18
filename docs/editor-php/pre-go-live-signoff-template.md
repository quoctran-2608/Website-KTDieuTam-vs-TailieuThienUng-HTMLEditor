# Pre-go-live Sign-off — Admin Editor PHP

> Dùng mẫu này để ký xác nhận trước khi bật vận hành nội bộ.

## 1) Thông tin phiên go-live

- Ngày: `YYYY-MM-DD`
- Môi trường: `staging / production-internal`
- Người vận hành chính:
- Người kiểm tra chéo:
- Commit triển khai:

## 2) Kết quả checklist nhanh 15 phút

- [ ] Đã chạy `./tools/admin_phase6_pre_go_live.sh` hoặc `tools\\admin_phase6_pre_go_live.bat`
- [ ] Kết quả checker: `Pre-go-live: READY`
- [ ] Đã lưu file readiness report
- [ ] Nếu có PHP runtime: `php admin/includes/healthcheck.php` pass

## 3) Smoke test bắt buộc (1 bài)

- [ ] Login admin thành công
- [ ] Tìm được bài từ list/filter
- [ ] Save draft thành công
- [ ] Publish thành công
- [ ] Mở public page thấy nội dung mới
- [ ] Rollback gần nhất thành công
- [ ] Mở public page thấy trạng thái hồi phục

## 4) Trace & audit

- [ ] `admin/storage/publish-history.json` có record mới
- [ ] `admin/storage/backups/` có backup mới
- [ ] `admin/storage/audit.log` có event publish/rollback
- [ ] Record có thông tin actor + timestamp

## 5) Rủi ro còn lại (nếu có)

- Rủi ro #1:
- Rủi ro #2:
- Biện pháp giảm thiểu:

## 6) Quyết định go-live

- [ ] GO
- [ ] NO-GO

Lý do:

## 7) Chữ ký xác nhận

- Người vận hành chính: ____________________
- Người kiểm tra chéo: ____________________
- Thời điểm ký: ____________________

