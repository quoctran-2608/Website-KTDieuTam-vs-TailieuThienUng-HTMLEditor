# Runbook moderation: brief tuyển dụng → job public

## Mục tiêu

Tài liệu này mô tả đúng flow vận hành hiện tại cho nhà tuyển dụng:

1. Doanh nghiệp tạo brief ở `dang-tin-tuyen-dung.html`
2. Nội bộ ingest brief vào queue
3. Tạo job draft từ brief
4. Biên tập file draft `.md`
5. Duyệt brief để job lên public

---

## 1) Bước 1 — Nhận brief `.json`

Doanh nghiệp điền form tại:
- `dang-tin-tuyen-dung.html`

Kết quả:
- tải được file `.json`
- gửi qua Zalo/email cho người phụ trách

---

## 2) Bước 2 — Ingest brief vào queue nội bộ

Lệnh:

```bash
python3 tools/ingest_employer_request.py /duong-dan/toi/brief.json
```

Kết quả:
- cập nhật `data/employer-requests.json`
- cập nhật `data/feeds/employer-requests-queue.json`
- cập nhật `docs/nghien-cuu-tuyen-dung/hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md`

Kiểm tra queue:

```bash
python3 tools/audit_employer_requests.py
```

---

## 3) Bước 3 — Tạo job draft từ brief

Lệnh:

```bash
python3 tools/create_job_draft_from_request.py <request-id>
```

Ví dụ:

```bash
python3 tools/create_job_draft_from_request.py brief-2026-04-09-15-00-00
```

Kết quả:
- tạo file draft tại `content/tuyen-dung/<slug>.md`
- brief đổi trạng thái từ `new` sang `reviewing`
- queue được ghi `jobDraftPath`

---

## 4) Bước 4 — Biên tập draft

Mở file draft vừa tạo trong:
- `content/tuyen-dung/<slug>.md`

Biên tập lại:
- `summary`
- `salaryLabel`
- `experienceLevel`
- body markdown:
  - `## Mô tả công việc`
  - `## Yêu cầu`
  - `## Quyền lợi`
  - `## Thời gian và địa điểm làm việc`
  - `## Cách ứng tuyển`

### Rule quan trọng
- draft phải giữ:
  - `status: draft`
- cho tới khi sẵn sàng public

---

## 5) Bước 5 — Duyệt brief và đưa job lên public

Lệnh:

```bash
python3 tools/moderate_employer_request.py <request-id> --action approve --note "Noi dung da duoc ra soat"
```

Ví dụ:

```bash
python3 tools/moderate_employer_request.py brief-2026-04-09-15-00-00 --action approve --note "Đã rà xong, cho public"
```

Nếu cần lưu người thao tác vào log:

```bash
python3 tools/moderate_employer_request.py <request-id> --action approve --actor editor-anh --note "Đã rà xong"
```

Kết quả:
- file draft đổi `status: draft` → `status: active`
- brief đổi trạng thái `reviewing` → `approved`
- brief được ghi thêm:
  - `jobPublicHref`
  - `publishedAt`
- tự chạy:
  - `tools/build_jobs.py`
  - `tools/audit_jobs_data.py`
  - `tools/audit_employer_requests.py`
- ghi event vào:
  - `data/logs/moderation-events.jsonl`

Job sẽ xuất hiện ở:
- `tuyen-dung.html`
- `tuyen-dung/<slug>.html`
- `data/jobs.json`
- `sitemap-jobs.xml`

---

## 6) Từ chối brief

Nếu brief không đạt:

```bash
python3 tools/moderate_employer_request.py <request-id> --action reject --note "Thiếu đầu mối liên hệ"
```

Có thể thêm actor:

```bash
python3 tools/moderate_employer_request.py <request-id> --action reject --actor editor-anh --note "Thiếu đầu mối liên hệ"
```

Kết quả:
- brief chuyển sang `rejected`
- không tạo / không public job

---

## 7) Đặt lại reviewing

Nếu cần đưa brief về trạng thái đang xử lý:

```bash
python3 tools/moderate_employer_request.py <request-id> --action reviewing --note "Cần hỏi lại doanh nghiệp về lương"
```

---

## 8) Kiểm tra sau khi duyệt

Chạy:

```bash
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
python3 tools/audit_employer_requests.py
python3 tools/audit_moderation_events.py --limit 30
python3 tools/render_moderation_dashboard.py --limit 30
```

Hoặc chạy one-shot:

```bash
python3 tools/run_moderation_ops.py --limit 30
```

Nếu cần cảnh báo khi không có event trong phạm vi lọc:

```bash
python3 tools/run_moderation_ops.py --day 2026-04-09 --actor editor-anh --limit 30 --fail-on-no-events
```

Nếu chạy định kỳ (cron) và cần lưu runlog:

```bash
tools/run_moderation_ops_daily.sh
```

Runlog:
- `.m/reclass/moderation-ops-runlog.jsonl`

Mục tiêu:
- job data không lỗi
- brief queue không lỗi
- job approved đã có mặt trên site

---

## 9) Điều cần nhớ

- **Không public trực tiếp từ brief**
- luôn đi qua bước:
  - `brief -> draft -> rà nội dung -> approve`
- builder hiện đã chặn draft không cho public

---

## 10) Tool list của flow này

- `tools/ingest_employer_request.py`
- `tools/create_job_draft_from_request.py`
- `tools/moderate_employer_request.py`
- `tools/audit_moderation_events.py`
- `tools/render_moderation_dashboard.py`
- `tools/run_moderation_ops.py`
- `tools/run_moderation_ops_daily.sh`
- `tools/build_jobs.py`
- `tools/audit_jobs_data.py`
- `tools/audit_employer_requests.py`
