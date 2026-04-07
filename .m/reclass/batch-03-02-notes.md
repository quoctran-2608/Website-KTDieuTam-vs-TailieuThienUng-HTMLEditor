# Batch 3.2 notes — GTGT level-3 rollout (batch 1)

## Scope
- Target node: `Thuế - Hóa đơn > GTGT - Hóa đơn`
- Batch size: 100 bài đầu theo thứ tự slug (`<= doi-tuong-va-truong-hop-duoc-hoan-thue-gtgt`)
- Purpose: rollout level-3 taxonomy with controlled blast radius.

## Level-3 labels used
- Hóa đơn điện tử
- Lập/Xử lý hóa đơn
- Kê khai GTGT
- Khấu trừ/Hoàn thuế
- Thuế suất/Đối tượng chịu thuế
- Báo cáo/Bảng kê

## Results
- Processed in batch: 100/100 have non-empty `topicLv3`
- Outside batch scope: 104 bài GTGT còn `topicLv3` rỗng (đúng theo rollout gate)
- Distribution in batch:
  - Lập/Xử lý hóa đơn: 40
  - Hóa đơn điện tử: 24
  - Kê khai GTGT: 13
  - Khấu trừ/Hoàn thuế: 12
  - Thuế suất/Đối tượng chịu thuế: 7
  - Báo cáo/Bảng kê: 4

## Mapping comparison with planning csv
- Strict match: 97/100
- Mismatch: 3/100 (reviewed and accepted as actual-correct)

1) `cac-truong-hop-khong-phai-ke-khai-nop-thue-gtgt.html`
- Planned: Lập/Xử lý hóa đơn
- Actual: Kê khai GTGT
- Final: giữ **Kê khai GTGT** (trọng tâm là nghĩa vụ kê khai).

2) `cach-ke-khai-hoa-don-thay-the-khac-ky-cung-ky-theo-thong-tu-78.html`
- Planned: Kê khai GTGT
- Actual: Hóa đơn điện tử
- Final: giữ **Hóa đơn điện tử** (TT78 là ngữ cảnh HĐĐT; nội dung thiên về quy trình HĐĐT).

3) `cach-viet-hoa-don-dieu-chinh-tang-giam-thue-suat-gtgt.html`
- Planned: Lập/Xử lý hóa đơn
- Actual: Thuế suất/Đối tượng chịu thuế
- Final: giữ **Thuế suất/Đối tượng chịu thuế** (trọng tâm là điều chỉnh theo thuế suất).

## Technical notes
- Added `topicLv3Key/topicLv3Label` to record + `data/articles.json` + hub/taxonomy output.
- `build_taxonomy` and `write_taxonomy_data` now support level-3 children under each level-2 node.
