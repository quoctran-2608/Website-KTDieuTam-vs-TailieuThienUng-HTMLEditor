# Runbook vòng đời job public

## Mục tiêu

Tài liệu này dùng để quản lý vòng đời tin tuyển dụng **đã có source `.md`**:

- chuyển `active` → `closed`
- chuyển `closed` → `active`
- đưa job về `draft`
- cập nhật hạn nộp nếu cần

---

## 1) Tool sử dụng

```bash
python3 tools/manage_job_public_status.py <slug-hoac-path> --status <draft|active|closed>
```

Nếu cần ghi rõ người thao tác:

```bash
python3 tools/manage_job_public_status.py <slug-hoac-path> --status closed --actor editor-anh
```

Ví dụ:

```bash
python3 tools/manage_job_public_status.py ke-toan-tong-hop-cong-ty-a --status closed
```

---

## 2) Ý nghĩa từng trạng thái

### `active`
- job còn hiển thị public
- có trong:
  - `tuyen-dung.html`
  - `data/jobs.json`
  - `sitemap-jobs.xml`

### `closed`
- job không còn hiển thị public
- builder sẽ loại khỏi:
  - list page
  - detail public
  - JSON public
  - sitemap jobs

### `draft`
- job ở trạng thái nháp
- không public
- dùng khi cần biên tập thêm

---

## 3) Đóng một job

```bash
python3 tools/manage_job_public_status.py <slug> --status closed
```

Kết quả:
- source `.md` đổi `status: closed`
- builder chạy lại
- job biến mất khỏi public
- ghi event vào `data/logs/moderation-events.jsonl`

---

## 4) Mở lại một job

```bash
python3 tools/manage_job_public_status.py <slug> --status active
```

### Lưu ý
Nếu `deadline` đã qua:
- builder sẽ tự map hiệu lực sang `expired`

Nếu cần mở lại thật sự, nên cập nhật luôn deadline:

```bash
python3 tools/manage_job_public_status.py <slug> --status active --deadline 2026-05-30
```

---

## 5) Đưa job về draft

```bash
python3 tools/manage_job_public_status.py <slug> --status draft
```

Dùng khi:
- cần biên tập lại nội dung
- cần rà lại lương / đầu mối / yêu cầu
- chưa muốn hiển thị public

---

## 6) Build & audit

Tool này mặc định sẽ tự chạy:

```bash
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
python3 tools/audit_moderation_events.py --limit 30
python3 tools/render_moderation_dashboard.py --limit 30
python3 tools/run_moderation_ops.py --limit 30
```

Nếu chỉ muốn sửa source mà chưa build ngay:

```bash
python3 tools/manage_job_public_status.py <slug> --status closed --skip-build
```

---

## 7) Ghi chú vận hành

- Không sửa HTML public trực tiếp.
- Chỉ sửa source `.md`.
- Nếu job đến từ brief nhà tuyển dụng:
  - nên kiểm tra queue/moderation note trước khi đóng hoặc mở lại.

---

## 8) Kết luận

Đây là cách ngắn nhất và an toàn nhất để quản vòng đời job public trong kiến trúc static hiện tại.
