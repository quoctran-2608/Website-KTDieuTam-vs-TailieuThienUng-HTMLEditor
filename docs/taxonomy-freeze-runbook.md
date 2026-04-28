# Taxonomy Freeze Runbook

- Updated: `2026-04-28`
- Scope: `thu-vien` taxonomy + generated artifacts
- Mode: **freeze**

## 1) Mục tiêu freeze

- Ngăn các đợt reclassify qua lại (toggle) làm dao động taxonomy.
- Chỉ cho phép thay đổi **one-way** có lợi ích ròng và có preflight rõ.
- Giữ trạng thái ổn định để bàn giao backend (Cloudflare Functions / Workers + Supabase).

## 2) Nguồn sự thật

- `data/articles.json`
- `docs/taxonomy-freeze-checkpoint.json`
- `docs/taxonomy-freeze-policy.json`

## 3) Quy tắc vận hành

### Được phép

1. Rebuild artifacts từ taxonomy hiện tại.
2. Đồng bộ metadata bài viết theo taxonomy hiện tại.
3. One-way reclassify khi:
   - queue one-way > 0
   - target lv3 key có sẵn
   - no-op = 0

### Không được phép

1. Chạy batch toggle hai chiều.
2. Thêm lv3 key mới nếu chưa có sign-off biên tập.
3. Apply bulk nếu chưa có preflight count/no-op.
4. Sửa tay trực tiếp file generated.

## 4) Checklist trước khi apply

1. Candidate count rõ ràng.
2. Target key availability = pass.
3. Duplicate href = 0.
4. No-op = 0.
5. Planned count khớp batch target.

## 5) Checklist sau khi apply

1. Rebuild thành công.
2. Cập nhật `docs/thu-vien-phase*.json` + `docs/thu-vien-phase*.md`.
3. Xác nhận các file generated:
   - `content-index.js`
   - `sitemap.xml`
   - `data/articles.json`
4. Verify residual queue.

## 6) Trạng thái freeze hiện tại

- One-way queues: `0` trên toàn bộ q1..q4.
- Chỉ còn toggle pool `thue_pattern`.
- Khuyến nghị: **không chạy thêm batch toggle**.

## 7) Điều kiện mở freeze

Mở freeze khi có ít nhất 1 điều kiện:

1. Scope biên tập mới được duyệt.
2. Bộ key taxonomy mới được duyệt.
3. Xuất hiện one-way queue mới có lợi ích ròng.

## 8) Lệnh chuẩn (tham khảo)

```bash
python3 tools/reclassify_phaseXX_*.py
```

Sau đó phải có:

- report json
- report md
- rebuild artifacts pass

