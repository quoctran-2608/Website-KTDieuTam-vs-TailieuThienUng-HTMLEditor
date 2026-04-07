# Freeze policy v2 — sau khi hoàn tất Lv3 (2026-04-07)

## 1) Trạng thái hiện tại
- Thư viện (`thu-vien`) đã đạt **Lv3 coverage 100%**.
- Số liệu chốt:
  - Tổng bài: **2039**
  - Có `topicLv3`: **2039**
  - Thiếu `topicLv3`: **0**
- QA hậu hoàn tất: **PASS**
  - Node gap: 0
  - Duplicate href: 0
  - `lv3 key -> multi label`: 0
  - `lv3 label -> multi key`: 0

## 2) Quyết định freeze
- Chuyển từ policy cũ (“để trống có chủ đích cho node pháp lý”) sang **freeze v2 toàn cục**:
  1. Khóa taxonomy Lv1–Lv3 hiện tại.
  2. Khóa ruleset classifier đã cho kết quả 100%.
  3. Không reclass hàng loạt trên tập lịch sử 2039 bài nếu không có RFC thay đổi.

## 3) Phạm vi được phép thay đổi sau freeze

### 3.1 Được phép
1. **Incremental ingestion** cho bài mới:
   - Chỉ classify bài mới.
   - Không sửa lại mapping của bài cũ trừ khi có lỗi rõ ràng.
2. **Hotfix có kiểm soát**:
   - Sửa lỗi sai key/label/href cụ thể, có artifact input/output.
3. **Bổ sung taxonomy có chủ đích**:
   - Chỉ khi có nhu cầu biên tập rõ ràng, không phá vỡ hệ hiện tại.

### 3.2 Không được phép (mặc định)
1. Tái phân loại hàng loạt toàn thư viện không có RFC.
2. Đổi nghĩa key Lv3 đã phát hành mà không có migration plan.
3. Chèn override ad-hoc không lưu trace batch.

## 4) Quy trình thay đổi chuẩn (sau freeze)
1. Tạo đề xuất thay đổi (RFC ngắn):
   - Mục tiêu
   - Phạm vi bài bị ảnh hưởng
   - Rủi ro dự kiến
2. Tạo batch CSV có thể kiểm toán:
   - `batch-XX-input.csv`
   - `batch-XX-output.csv`
   - `batch-XX-notes.md`
3. Rebuild full:
   - `python3 ../.m/build_sample_sections.py --mode full`
4. Chạy QA tối thiểu:
   - coverage không giảm ngoài phạm vi cho phép
   - duplicate href = 0
   - consistency key/label không vỡ
5. Cập nhật memory + report.

## 5) Gate điều kiện pass/fail
- **Pass** nếu đồng thời:
  - Không làm giảm integrity tổng thể
  - QA critical = 0
  - Có đủ artifact truy vết
- **Fail** nếu:
  - Xuất hiện node gap ngoài dự kiến
  - Sinh inconsistency key/label
  - Không thể tái lập kết quả từ batch + rebuild

## 6) Artifact chuẩn của mốc freeze v2
- Coverage snapshot:
  - `.m/reclass/final-lv3-coverage-snapshot.json`
  - `.m/reclass/final-lv3-coverage-snapshot.md`
- QA hậu hoàn tất:
  - `.m/reclass/final-post-completion-qa.json`
  - `.m/reclass/final-post-completion-qa.md`
- Báo cáo lock:
  - `.m/reclass/final-lock-report.md`

## 7) Chế độ vận hành sau cùng
- Mặc định: **Maintenance + Incremental**
- Không mở lại “full legal expansion” vì đã hoàn tất 100%; chỉ mở khi có taxonomy đổi chuẩn ở cấp hệ thống.
