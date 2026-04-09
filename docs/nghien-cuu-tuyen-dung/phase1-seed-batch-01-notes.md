# Phase 1 — Seed batch 01 từ Sanketoan

## Mục tiêu batch
- Tạo batch seed đầu tiên cho khu Tuyển dụng
- Chuẩn hóa dữ liệu public từ `sanketoan.vn` sang format `.md`
- Đủ dữ liệu để bước sau build list + detail page

## Nguồn
- Site nguồn: `https://sanketoan.vn/`
- Nhóm URL seed: các trang chi tiết việc làm public dưới `/cong-viec/...`

## Số lượng
- Batch 01: **10 tin tuyển dụng**

## Quy tắc chuẩn hóa đã áp dụng
1. **Source of truth** là file `.md` trong `content/tuyen-dung/`
2. Giữ:
   - `sourceSite`
   - `sourceUrl`
3. `publishDate` tạm chuẩn hóa theo ngày seed:
   - `2026-04-09`
4. `employmentType` tạm gán:
   - `full-time`
5. `workMode` tạm gán:
   - `onsite`
6. `status`:
   - `active` nếu deadline còn hiệu lực tại thời điểm seed
7. `summary` và body:
   - được **viết lại ngắn gọn**
   - không mirror thô nguyên văn từ nguồn

## Lưu ý vận hành
- Đây là **seed data** để mở Phase 1, chưa phải batch public cuối cùng
- Trước khi đưa lên build public nên có bước:
  - rà deadline
  - rà quality body
  - rà trùng job / trùng công ty

## File liên quan
- `content/tuyen-dung/*.md`
- `docs/nghien-cuu-tuyen-dung/schema-du-lieu-tin-tuyen-dung.md`
