# Bộ tài liệu nghiên cứu & triển khai Tuyển dụng

## Mục tiêu

Thư mục này gom toàn bộ tài liệu liên quan đến:
- nghiên cứu tính năng Tuyển dụng
- schema dữ liệu
- roadmap theo phase
- seed data
- moderation queue
- runbook vận hành

---

## Nên đọc theo thứ tự

### 1) Định hướng & kiến trúc
- `roadmap-html-marketplace-to-php.md`
- `nghien-cuu-ke-hoach-bo-sung-tuyen-dung.md`
- `ke-hoach-design-tinh-nang-tuyen-dung.md`
- `roadmap-trien-khai-tinh-nang-tuyen-dung-theo-phase.md`

### 2) Dữ liệu & schema
- `schema-du-lieu-tin-tuyen-dung.md`
- `schema-brief-nhu-cau-tuyen-dung.md`

### 3) Seed data & audit
- `phase1-seed-batch-01-notes.md`
- `phase1-seed-batch-02-notes.md`
- `phase1-seed-batch-03-notes.md`
- `bao-cao-audit-du-lieu-tuyen-dung.md`
- `bao-cao-audit-brief-tuyen-dung.md`

### 4) Moderation & vận hành
- `hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md`
- `runbook-moderation-brief-to-public.md`
- `runbook-vong-doi-job-public.md`
- `runbook-bulk-import-sync-tuyen-dung.md`
- `dashboard-moderation-events.md`

---

## Các artifact quan trọng trong repo

### Source job public
- `content/tuyen-dung/*.md`

### Job public build ra
- `tuyen-dung.html`
- `tuyen-dung/*.html`
- `dang-nhap-tuyen-dung.html`
- `tai-khoan-ung-vien.html`
- `ho-so-ung-vien.html`
- `viec-lam-da-luu.html`
- `don-ung-tuyen.html`
- `ung-tuyen.html`
- `nha-tuyen-dung.html`
- `dang-tin-viec-lam.html`
- `quan-ly-tin-tuyen-dung.html`
- `chi-tiet-tin-tuyen-dung.html`
- `ung-vien-tuyen-dung.html`
- `data/jobs.json`
- `data/feeds/tuyen-dung.json`

### Brief nội bộ cho nhà tuyển dụng
- `data/employer-requests.json`
- `data/feeds/employer-requests-queue.json`

### Moderation log (append-only)
- `data/logs/moderation-events.jsonl`

---

## Tool liên quan

- `tools/import_sanketoan_jobs.py`
- `tools/sync_sanketoan_jobs.py`
- `tools/build_jobs.py`
- `tools/audit_jobs_data.py`
- `tools/ingest_employer_request.py`
- `tools/audit_employer_requests.py`
- `tools/create_job_draft_from_request.py`
- `tools/moderate_employer_request.py`
- `tools/manage_job_public_status.py`
- `tools/audit_moderation_events.py`
- `tools/render_moderation_dashboard.py`
- `tools/run_moderation_ops.py`
- `tools/run_moderation_ops_daily.sh`

---

## Kết luận

Nếu bắt đầu làm việc với tính năng Tuyển dụng, hãy mở file này trước để định hướng nhanh và tránh đọc sai thứ tự tài liệu.
