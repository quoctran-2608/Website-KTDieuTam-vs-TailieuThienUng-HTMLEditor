# Hướng dẫn nhanh: Bạn cần làm gì với các file `tools/` và `docs/` mới

## 1) Mục tiêu của bộ file mới
Bộ file này dùng để **vận hành và giám sát taxonomy Lv3** sau khi đã lock:
- Chạy build + QA định kỳ
- Ghi log lịch sử
- Cảnh báo sớm khi có lỗi/drift
- Xuất dashboard để theo dõi

---

## 2) Các file trong `tools/` và chức năng

### A. File chạy chính
1. `tools/run_lv3_maintenance.py`  
   - Chạy maintenance (build + QA hoặc QA-only)
   - Xuất artifact `.json/.md`
   - Có thể append vào runlog

2. `tools/run_lv3_maintenance_daily.sh`  
   - Wrapper chạy hàng ngày (cron-friendly)
   - Tự gọi:
     - maintenance
     - health check
     - dashboard render

### B. File giám sát
3. `tools/maintenance_health_check.py`  
   - Kiểm tra run gần nhất có ổn không
   - Trả về `PASS/FAIL`, `severity`, `reason`
   - Có alert line để grep/log monitor

4. `tools/render_maintenance_dashboard.py`  
   - Đọc runlog
   - Xuất dashboard:
     - Markdown: `.m/reclass/maintenance-dashboard.md`
     - CSV: `.m/reclass/maintenance-dashboard.csv`

---

## 3) File trong `docs/` bạn nên đọc

1. `docs/lv3-maintenance-runbook.md`  
   - Tài liệu kỹ thuật đầy đủ (flag, contract, policy).

2. `docs/huong-dan-su-dung-file-tools-docs.md` *(file này)*  
   - Bản dễ hiểu, ngắn gọn, để vận hành hàng ngày.

---

## 4) Quy trình bạn nên làm mỗi ngày

### Cách 1 (khuyên dùng): chạy wrapper 1 lệnh
```bash
tools/run_lv3_maintenance_daily.sh
```

Kết quả sẽ có ở `.m/reclass/`:
- `maintenance-qa-<tag>.json`
- `maintenance-qa-<tag>.md`
- `maintenance-dashboard.md`
- `maintenance-dashboard.csv`
- `maintenance-runlog.jsonl`

### Cách 2: chạy tay từng bước
```bash
python3 tools/run_lv3_maintenance.py --mode full --append-runlog
python3 tools/maintenance_health_check.py --max-age-hours 24 --max-future-skew-seconds 300 --require-latest-pass --require-zero-critical --min-coverage-ratio 1.0 --emit-pass-alert
python3 tools/render_maintenance_dashboard.py --out-md .m/reclass/maintenance-dashboard.md --out-csv .m/reclass/maintenance-dashboard.csv
```

---

## 5) Cách đọc kết quả nhanh

### PASS
- `status=PASS`
- `severity=INFO` (hoặc `WARN` nếu có tín hiệu rủi ro nhẹ)

### FAIL (cần xử lý)
- `status=FAIL`
- xem `reason=...` trong health check  
Ví dụ:
- `critical_issues_detected`
- `coverage_below_threshold`
- `future_timestamp_skew`
- `stale_latest_run`

---

## 6) Khi FAIL thì bạn làm gì?

1. Mở file JSON mới nhất:
   - `.m/reclass/maintenance-qa-<tag>.json`
2. Xác định node/bài lỗi trong các mục:
   - `missingLv3`
   - `nodeGaps`
   - `criticalIssues`
3. Tạo batch reclass (input/output/notes), sửa rule nếu cần, rồi chạy lại maintenance.

---

## 7) Thiết lập cron (nếu cần tự động)
Ví dụ chạy mỗi ngày lúc 01:30:

```cron
30 1 * * * cd /mnt/d/WORKING/KetoanThienUng/Ketoandieutam.com && ./tools/run_lv3_maintenance_daily.sh >> .m/reclass/cron-maintenance.log 2>&1
```

---

## 8) Checklist ngắn gọn cho bạn
- [ ] Giữ 4 file `tools/` như trên trong repo
- [ ] Dùng `run_lv3_maintenance_daily.sh` để chạy định kỳ
- [ ] Theo dõi `maintenance-dashboard.md` và `maintenance-dashboard.csv`
- [ ] Nếu FAIL: xử lý theo `maintenance-qa-<tag>.json`
- [ ] Không sửa taxonomy hàng loạt nếu chưa có RFC

