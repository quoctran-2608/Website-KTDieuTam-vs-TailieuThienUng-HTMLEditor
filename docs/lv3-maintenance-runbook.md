# Runbook vận hành sau lock Lv3 (Maintenance + Incremental)

## 1) Mục tiêu
- Duy trì integrity taxonomy Lv1–Lv3 sau khi đã lock coverage 100%.
- Chuẩn hóa cách chạy:
  1. Rebuild dữ liệu xuất bản.
  2. QA snapshot.
  3. Lưu artifact truy vết.

## 2) Một lệnh chuẩn (khuyến nghị)
Từ thư mục `ketoandieutam.vn`:

```bash
python3 tools/run_lv3_maintenance.py --mode full
```

Lệnh này sẽ:
1. Chạy rebuild:
   - `python3 ../.m/build_sample_sections.py --mode full`
2. Chạy QA trên `data/articles.json`:
   - missing Lv3
   - node gap
   - duplicate href
   - inconsistency key/label
3. Xuất artifact vào `.m/reclass/`:
   - `maintenance-build-<tag>.log`
   - `maintenance-qa-<tag>.json`
   - `maintenance-qa-<tag>.md`

### Kèm runlog (khuyến nghị)
```bash
python3 tools/run_lv3_maintenance.py --mode full --append-runlog
```

Sẽ append 1 dòng JSON vào:
- `.m/reclass/maintenance-runlog.jsonl`

Mặc định tool cũng xoay vòng runlog:
- giữ bản ghi trong **90 ngày gần nhất**
- đồng thời giữ tối đa **5000 dòng mới nhất**

## 3) Chế độ chỉ QA (không rebuild)
Khi đã rebuild trước đó và chỉ muốn kiểm tra nhanh:

```bash
python3 tools/run_lv3_maintenance.py --skip-build
```

Có thể kết hợp runlog:
```bash
python3 tools/run_lv3_maintenance.py --skip-build --append-runlog
```

## 3.1 Wrapper chạy hàng ngày (cron-friendly)
```bash
tools/run_lv3_maintenance_daily.sh
```

Script này:
- chạy full maintenance
- gắn tag `daily-<timestamp>`
- tự append runlog.
- tự áp dụng runlog rotation (90 ngày / 5000 dòng).
- chạy health check (`tools/maintenance_health_check.py`)
- render dashboard markdown (`tools/render_maintenance_dashboard.py`).
- xuất dashboard CSV (`tools/render_maintenance_dashboard.py`).

Wrapper daily mặc định bật guard coverage:
- `--min-coverage-ratio 1.0`
- `--max-future-skew-seconds 300`

### Mẫu cron entry (Linux/WSL)
Ví dụ chạy mỗi ngày lúc 01:30:

```cron
30 1 * * * cd /mnt/d/WORKING/KetoanThienUng/ketoandieutam.vn && ./tools/run_lv3_maintenance_daily.sh >> .m/reclass/cron-maintenance.log 2>&1
```

## 4) Điều kiện PASS/FAIL
- **PASS** khi tất cả = 0:
  - missingLv3
  - nodeGaps
  - duplicateHref
  - lv3KeyMultiLabel
  - lv3LabelMultiKey
- **FAIL** nếu có bất kỳ chỉ số nào > 0.

## 5) Quy trình incremental ingestion cho bài mới
1. Cập nhật nguồn/crawl bài mới theo pipeline hiện hành.
2. Chạy:
   - `python3 tools/run_lv3_maintenance.py --mode full`
3. Nếu FAIL:
   - xác định node bị ảnh hưởng từ file `maintenance-qa-*.json`
   - tạo batch CSV input/output/notes trong `.m/reclass/`
   - cập nhật classifier/override tương ứng
   - rebuild + QA lại.
4. Nếu PASS:
   - cập nhật memory runlog và lưu artifact theo mốc thời gian.

## 6) Contract công cụ `tools/run_lv3_maintenance.py`
### Input
- `--mode {sample|full}`
- `--skip-build`
- `--tag`
- `--out-dir`
- `--section`
- `--append-runlog`
- `--runlog-path`
- `--runlog-retention-days`
- `--runlog-max-lines`
- `--skip-runlog-rotate`

### Output
- stdout:
  - `status=PASS|FAIL`
  - đường dẫn artifact
  - summary critical issues
- file:
  - `maintenance-qa-<tag>.json`
  - `maintenance-qa-<tag>.md`
  - `maintenance-build-<tag>.log` (nếu có build)
  - `maintenance-runlog.jsonl` (nếu bật `--append-runlog`)

### Failure
- Return code khác 0 nếu:
  - build fail
  - hoặc QA fail (critical issue > 0)

### Side effects
- Ghi file artifact trong `.m/reclass`.

### Permissions
- Read: `.m/build_sample_sections.py`, `data/articles.json`
- Write: `.m/reclass/*`
- Execute: python build command

## 7) Policy xoay vòng runlog
- File: `.m/reclass/maintenance-runlog.jsonl`
- Mặc định:
  - retention: 90 ngày
  - max lines: 5000
- Có thể override:
  - `--runlog-retention-days <N>`
  - `--runlog-max-lines <N>`
- Nếu cần tắt rotation tạm thời:
  - `--skip-runlog-rotate`

## 8) Health check + Dashboard

### Health check
```bash
python3 tools/maintenance_health_check.py --max-age-hours 24 --require-latest-pass --require-zero-critical
```

Output:
- `status=PASS|FAIL`
- `severity=INFO|WARN|CRIT`
- `reason=...`
- thông tin latest run + tuổi run gần nhất.
- thêm 1 dòng alert template (`alert=...`) khi FAIL.

Để guard coverage drift:
```bash
python3 tools/maintenance_health_check.py --max-age-hours 24 --require-latest-pass --require-zero-critical --min-coverage-ratio 1.0
```

Để guard clock skew (timestamp tương lai bất thường):
```bash
python3 tools/maintenance_health_check.py --max-age-hours 24 --max-future-skew-seconds 300 --require-latest-pass --require-zero-critical --min-coverage-ratio 1.0
```

Alias tương đương theo ngữ nghĩa tuổi âm:
```bash
python3 tools/maintenance_health_check.py --max-age-hours 24 --max-negative-age-seconds 300 --require-latest-pass --require-zero-critical --min-coverage-ratio 1.0
```

Nếu muốn luôn in alert line cả khi PASS:
```bash
python3 tools/maintenance_health_check.py --max-age-hours 24 --require-latest-pass --require-zero-critical --min-coverage-ratio 1.0 --emit-pass-alert
```

Các flag chính của health check:
- `--max-age-hours`
- `--max-future-skew-seconds`
- `--max-negative-age-seconds` (alias)
- `--require-latest-pass`
- `--require-zero-critical`
- `--min-coverage-ratio`
- `--alert-prefix`
- `--emit-pass-alert`

Quy ước severity:
- `CRIT`: health FAIL hoặc lỗi nghiêm trọng.
- `WARN`: không FAIL nhưng có tín hiệu rủi ro (ví dụ skew nhẹ, invalid lines, run gần stale).
- `INFO`: trạng thái bình thường.

Lưu ý:
- Record cũ trước khi thêm trường này có thể hiển thị `UNKNOWN` trong dashboard.

### Render dashboard
```bash
python3 tools/render_maintenance_dashboard.py --out-md .m/reclass/maintenance-dashboard.md --out-csv .m/reclass/maintenance-dashboard.csv
```

Output:
- dashboard tổng hợp lịch sử run tại:
  - `.m/reclass/maintenance-dashboard.md`
  - `.m/reclass/maintenance-dashboard.csv`
  - CSV có thêm cột `coverageRatio` để chart nhanh.
  - CSV có thêm `ageSeconds`, `futureTimestamp` để phát hiện clock-skew.
  - có thống kê `Severity distribution`.
