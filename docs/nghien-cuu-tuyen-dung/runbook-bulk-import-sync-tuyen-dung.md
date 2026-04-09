# Runbook bulk import & safe-sync dữ liệu tuyển dụng

## Mục tiêu

Tài liệu này dùng cho 2 việc:

1. import thêm batch mới từ Sanketoan
2. sync lại metadata cứng của các job seed hiện có

---

## 1) Import batch mới từ Sanketoan

### Lệnh cơ bản

```bash
python3 tools/import_sanketoan_jobs.py --limit 10 --offset 30 --prefix batch-04
```

### Ý nghĩa
- `--limit`: số tin muốn lấy
- `--offset`: bỏ qua bao nhiêu tin đầu từ homepage Sanketoan
- `--prefix`: tên batch để ghi note/review

### Sau khi import

Chạy tiếp:

```bash
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
```

### Artifact sẽ có
- `.m/reclass/jobs-seed-<batch>-review.json`
- `docs/nghien-cuu-tuyen-dung/phase1-seed-<batch>-notes.md`

---

## 2) Sync metadata cứng từ Sanketoan

### Dry-run

```bash
python3 tools/sync_sanketoan_jobs.py
```

Mục tiêu:
- xem drift metadata
- chưa ghi đè source local

### Safe apply

```bash
python3 tools/sync_sanketoan_jobs.py --apply
```

### Force apply

```bash
python3 tools/sync_sanketoan_jobs.py --force-apply
```

---

## 3) Khác nhau giữa safe apply và force apply

### `--apply`
Chỉ cập nhật nếu metadata mới **tốt hơn** local.

Ví dụ:
- location rõ hơn
- salary label đẹp hơn
- field local đang trống

### `--force-apply`
Ghi đè toàn bộ drift metadata.

Chỉ dùng khi:
- đã review report
- chắc chắn muốn bám sát nguồn ngoài

---

## 4) Report sync

Tool sẽ ghi:

- `docs/nghien-cuu-tuyen-dung/bao-cao-sync-sanketoan.md`

Report này tách rõ:
- thay đổi thật có thể apply
- drift bị giữ local

---

## 5) Quy trình khuyến nghị

### Nếu đang mở batch mới
1. import batch mới
2. build
3. audit
4. rà nhanh 5–10 file vừa import

### Nếu đang bảo trì batch cũ
1. chạy sync dry-run
2. đọc report
3. chỉ khi thấy hợp lý mới dùng `--apply`
4. build lại
5. audit lại

---

## 6) Lệnh trọn gói khuyến nghị

### Import thêm batch

```bash
python3 tools/import_sanketoan_jobs.py --limit 10 --offset 30 --prefix batch-04
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
```

### Bảo trì metadata an toàn

```bash
python3 tools/sync_sanketoan_jobs.py
python3 tools/sync_sanketoan_jobs.py --apply
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
```

---

## 7) Điều cần nhớ

- Không bulk copy HTML source ngoài vào public trực tiếp.
- Seed/import chỉ là bước đầu.
- Local đã biên tập sạch hơn thì **ưu tiên giữ local**.
- Chỉ force apply khi có lý do rõ ràng.
