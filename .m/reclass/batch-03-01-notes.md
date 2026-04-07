# Batch 3.1 notes — split mixed tax node (34 records)

## Objective
Split `Môn bài - Hộ kinh doanh - Nhà thầu - MST` into 4 level-2 nodes:
- `Lệ phí môn bài`
- `Hộ/Cá nhân kinh doanh`
- `Mã số thuế - Đăng ký thuế`
- `Thuế nhà thầu`

## Result summary
- Total records processed: 34
- Old mixed node remaining: 0
- New distribution:
  - Lệ phí môn bài: 11
  - Hộ/Cá nhân kinh doanh: 10
  - Mã số thuế - Đăng ký thuế: 9
  - Thuế nhà thầu: 4

## Mismatch review (2 records)
1) `cau-truc-ma-so-thue.html`
- Planned: `Hộ/Cá nhân kinh doanh`
- Actual: `Mã số thuế - Đăng ký thuế`
- Final decision: **keep actual** (content is primarily MST structure/reference).

2) `dang-ky-thue-lan-dau-cua-ca-nhan-ho-kinh-doanh-2025-theo-tt86.html`
- Planned: `Lệ phí môn bài`
- Actual: `Mã số thuế - Đăng ký thuế`
- Final decision: **keep actual** (content is registration-tax procedure, not môn bài rate/fee policy).

=> Accepted result: 32 strict matches + 2 reviewed mismatches (both accepted as correct).
