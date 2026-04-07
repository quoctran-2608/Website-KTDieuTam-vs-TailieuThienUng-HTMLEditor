# Batch 3.3 notes — complete GTGT level-3 rollout

## Scope
- Continue from Batch 3.2.
- Process remaining 104 bài in `Thuế - Hóa đơn > GTGT - Hóa đơn`.
- Remove temporary rollout gate so classifier applies to all GTGT items.

## Result summary
- GTGT total: 204
- GTGT with non-empty `topicLv3`: 204/204 (100%)
- Final level-3 distribution:
  - Lập/Xử lý hóa đơn: 89
  - Hóa đơn điện tử: 49
  - Kê khai GTGT: 24
  - Khấu trừ/Hoàn thuế: 24
  - Thuế suất/Đối tượng chịu thuế: 12
  - Báo cáo/Bảng kê: 6

## Mapping comparison (batch 3.3 input plan)
- Strict match: 103/104
- Mismatch: 1/104
  - `hoa-don-do-co-quan-thue-dat-in-phat-hanh-cap-ban.html`
    - Planned: Hóa đơn điện tử
    - Actual: Lập/Xử lý hóa đơn
    - Final decision: giữ **Lập/Xử lý hóa đơn** (nội dung hóa đơn do cơ quan thuế đặt in/cấp bán, không phải luồng HĐĐT).

## Technical changes in this batch
- Removed GTGT batch cutoff gate from classifier.
- Kept same 6 lv3 labels and regex logic.
- Rebuild full successful.
