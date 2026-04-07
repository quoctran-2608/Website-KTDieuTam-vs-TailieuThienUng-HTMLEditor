# Batch 2.6 notes — finalize Phase 2

## Scope
- Close remaining duplicate groups after batches 2.1–2.5.
- Resolve 2 records under `Công cụ > Phần mềm - Công cụ`.
- Resolve 10 edge records from batch 2.5 mismatches.

## Actions taken
1. Added explicit `ARTICLE_TOPIC_OVERRIDES` for 12 source files (2 + 10 edge).
2. Fixed build crash in taxonomy writer when `cong-cu` has 0 record (safe `toolVariants` init).
3. Rebuilt full dataset.

## Final decisions for edge records
- `cong-van-so-17940-sldtbxh-vl-quy-dinh-ve-lao-dong.htm` -> `Lao động - Bảo hiểm`
- `cong-van-so-7527-btc-tct-thanh-tra-kiem-tra-thue-cac-dn.htm` -> `Thuế - Hóa đơn`
- `cong-van-xin-dang-ky-thang-bang-luong-gui-phong-lao-dong.htm` -> `Lao động - Bảo hiểm`
- `luat-so-68-2014-qh13-luat-doanh-nghiep-2015.htm` -> `Doanh nghiệp - Thủ tục`
- `luat-thue-thu-nhap-doanh-nghiep-so-67-2025-qh15.htm` -> `Thuế - Hóa đơn`
- `nghi-dinh-so-105-2014-nd-cp-luat-bao-hiem-y-te-nam-2015.htm` -> `Lao động - Bảo hiểm`
- `thong-tu-09-2015-tt-btc-giao-dich-tai-chinh-cua-doanh-nghiep.htm` -> `Doanh nghiệp - Thủ tục`
- `thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.htm` -> `Kế toán`
- `thong-tu-99-2025-tt-btc-huong-dan-che-do-ke-toan-dn.htm` -> `Kế toán`
- `thong-tu-so-178-tt-btc-ap-dung-bao-hiem-xa-hoi-viet-nam.htm` -> `Lao động - Bảo hiểm`

## Verification summary
- Duplicate groups remaining:
  - `Biểu mẫu > Mẫu biểu - Thủ tục`: 0
  - `Biểu mẫu > Phần mềm - Công cụ`: 0
  - `Văn bản > Văn bản pháp luật`: 0
  - `Công cụ > Phần mềm - Công cụ`: 0

=> Phase 2 objective achieved (duplicate Lv1-by-type groups cleared).
