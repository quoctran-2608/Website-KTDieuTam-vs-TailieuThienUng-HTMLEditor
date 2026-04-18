# Pre-go-live 15 phút — Admin Editor PHP

## Mục tiêu

Checklist siêu ngắn để team nội bộ chạy trước khi mở vận hành thật.

## Bước 1 — Chạy checker tổng

```bash
./tools/admin_phase6_pre_go_live.sh
```

Windows:

```bat
tools\admin_phase6_pre_go_live.bat
```

Pass khi:

- bước `[1/4]` báo `Pre-go-live: READY`
- bước `[2/4]` có file report `/tmp/admin-phase6-site-readiness.txt`
- không có lỗi dừng script

## Bước 2 — Nếu có PHP runtime thì chạy healthcheck thật

```bash
php admin/includes/healthcheck.php
```

Pass khi toàn bộ check đều `[OK]`.

## Bước 3 — Smoke thao tác 1 bài

1. Login admin
2. Tìm 1 bài trong `Bài viết`
3. Sửa nhẹ excerpt
4. Save draft
5. Publish
6. Mở bài public kiểm tra
7. Rollback gần nhất
8. Mở bài public xác nhận đã hồi phục

Pass khi cả publish và rollback đều thành công.

## Bước 4 — Xác nhận trace

Kiểm tra:

- `admin/storage/publish-history.json` có record mới
- `admin/storage/backups/` có file backup mới
- `admin/storage/audit.log` có event publish/rollback

## Quy tắc go-live

- Nếu bất kỳ bước nào fail -> **không go-live**, xử lý lỗi trước.
- Chỉ go-live khi cả 4 bước pass.

## Sign-off

Sau khi pass checklist, điền và lưu mẫu:

- `docs/editor-php/pre-go-live-signoff-template.md`
