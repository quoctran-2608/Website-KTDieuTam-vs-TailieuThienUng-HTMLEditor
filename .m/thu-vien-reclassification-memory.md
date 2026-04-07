# Bộ nhớ tạm tái phân loại Thư viện (Level 1–3)

> Cập nhật: 2026-04-07  
> Nguồn dữ liệu rà soát: `data/articles.json` (2041 bài thuộc `section=thu-vien`)  
> Artifact số liệu chi tiết: `.m/thu-vien-taxonomy-review.json`

---

## 1) Kết luận rà soát hiện trạng

### 1.1. Cấu trúc hiện tại chưa hợp lý hoàn toàn

- Tổng bài Thư viện: **2041**
- `libraryKind`:
  - Hướng dẫn: **1048**
  - Biểu mẫu: **682**
  - Văn bản: **305**
  - Công cụ: **6**
- `topicLv3` đang trống: **2041/2041** (100%)
- `toolLv3` chỉ có dữ liệu: **6/2041**

### 1.2. Mâu thuẫn taxonomy theo tầng

Hiện đang có tình trạng lặp “bản chất nội dung” giữa Kind và Level:

- Biểu mẫu > Mẫu biểu - Thủ tục: **446**
- Biểu mẫu > Phần mềm - Công cụ: **183**
- Văn bản > Văn bản pháp luật: **220**
- Công cụ > Phần mềm - Công cụ: **6**

=> Tổng **855 bài** đang dùng Level 1 như “loại nội dung” thay vì “domain chuyên môn”.

### 1.3. Nhóm chứa tạp (catch-all) cần xử lý

`Học liệu - Tham khảo > Khác`: **80** bài

- Hướng dẫn: 60
- Biểu mẫu: 12
- Văn bản: 8

=> Nhóm `Khác` đang gom nhiều domain lẫn lộn, gây khó lọc.

### 1.4. Nhóm quá rộng cần tách

- `Tài khoản - Hạch toán`: **253** bài
- `GTGT - Hóa đơn`: **199** bài
- `Môn bài - Hộ kinh doanh - Nhà thầu - MST`: **34** bài

Riêng bucket “Môn bài - Hộ KD - Nhà thầu - MST” đang trộn:

- Lệ phí môn bài: 11
- Hộ/Cá nhân kinh doanh: 9
- Mã số thuế - Đăng ký thuế: 7
- Thuế nhà thầu: 3
- Khác: 4

### 1.5. Nhóm nhỏ có thể gộp/đổi vai trò

- FAST: 3
- Công đoàn: 7
- Bài tập - Thực hành: 9
- Báo cáo thực tập: 11

---

## 2) Danh sách bài cần di chuyển ngay (ưu tiên cao, rõ ràng)

1. `Trọn bộ mẫu giấy ủy nhiệm chi của các ngân hàng`  
   - Từ: Công cụ > Phần mềm - Công cụ > Excel - Công cụ khác  
   - Sang: **Biểu mẫu > Kế toán > Mẫu biểu kế toán**

2. `Quy định về sử dụng phần mềm kế toán theo Thông tư 99`  
   - Từ: Công cụ > Phần mềm - Công cụ > Excel - Công cụ khác  
   - Sang: **Văn bản > Kế toán > Chuẩn mực - Chế độ - Nguyên tắc**

3. `Thuế suất thuế giá trị gia tăng đối với phần mềm như thế nào`  
   - Từ: Công cụ > Phần mềm - Công cụ > Excel - Công cụ khác  
   - Sang: **Hướng dẫn > Thuế - Hóa đơn > GTGT - Hóa đơn**

4. `Thủ tục cắt giảm người phụ thuộc 2025 trên phần mềm HTKK`  
   - Từ: Công cụ > Phần mềm - Công cụ > Excel - Công cụ khác  
   - Sang: **Hướng dẫn > Thuế - Hóa đơn > Thuế TNCN**

5. 5 bài `Mẫu báo cáo: ...` đang rải ở Kế toán/Thuế/Lao động  
   - Sang thống nhất: **Biểu mẫu > Học liệu > Báo cáo thực tập**

6. `Popupdetail`, `Popupdetail.aspx`  
   - Gắn cờ: **Nội dung rác/placeholder** để xử lý QA

---

## 3) PHASE 0 — Taxonomy đích (đã chốt)

## 3.1. Nguyên tắc thiết kế

1. `libraryKind` = **bản chất sử dụng** (Hướng dẫn / Biểu mẫu / Văn bản / Công cụ).  
2. `topicLv1` = **domain chuyên môn** (không lặp lại Kind).  
3. `topicLv2` = **subdomain nghiệp vụ**.  
4. `topicLv3` = **nhóm hẹp để lọc sâu** cho các bucket lớn.  
5. Không dùng `Khác` nếu có thể map được domain thật.

## 3.2. Cấu trúc đích

### Level 0 (Kind)
- Hướng dẫn
- Biểu mẫu
- Văn bản
- Công cụ

### Level 1 (Domain chuẩn, dùng chung)
- Kế toán
- Thuế - Hóa đơn
- Lao động - Bảo hiểm
- Doanh nghiệp - Thủ tục *(mới)*
- Học liệu nghề nghiệp *(đổi tên từ Học liệu - Tham khảo)*

### Level 2 đề xuất theo Domain

#### A) Kế toán
- Tài khoản - Định khoản
- Chứng từ - Sổ sách
- Báo cáo tài chính
- Tài sản - Kho - CCDC
- Chi phí - Doanh thu - Giá thành *(đổi từ “Nghiệp vụ theo phần hành”)*
- Chuẩn mực - Chế độ - Nguyên tắc

#### B) Thuế - Hóa đơn
- GTGT - Hóa đơn
- Thuế TNCN
- Thuế TNDN
- Lệ phí môn bài
- Hộ/Cá nhân kinh doanh
- Mã số thuế - Đăng ký thuế
- Thuế nhà thầu

#### C) Lao động - Bảo hiểm
- BHXH - BHYT - BHTN
- Tiền lương - Thu nhập
- Quan hệ lao động (HĐLĐ, kỷ luật, thời giờ)
- Công đoàn *(có thể giữ hoặc hạ xuống Level 3 tùy batch)*

#### D) Doanh nghiệp - Thủ tục (mới)
- Đăng ký doanh nghiệp
- Thay đổi thông tin - tạm ngừng - giải thể
- Khuyến mại - hội chợ - thương mại
- Thủ tục nội bộ doanh nghiệp

#### E) Học liệu nghề nghiệp
- Bài tập - Thực hành
- Báo cáo thực tập
- Kinh nghiệm - Nghề nghiệp

### Level 3 (khung áp dụng trước)

#### Cho `Thuế - Hóa đơn > GTGT - Hóa đơn`
- Hóa đơn điện tử
- Lập/xử lý hóa đơn
- Kê khai GTGT
- Khấu trừ/Hoàn thuế
- Thuế suất/đối tượng chịu thuế
- Báo cáo/Bảng kê

#### Cho `Kế toán > Tài khoản - Định khoản`
- Tiền & tương đương tiền
- Công nợ phải thu/phải trả
- Hàng tồn kho
- TSCĐ/CCDC
- Doanh thu/Chi phí/Kết quả
- Vốn chủ sở hữu & đầu tư

#### Cho `Công cụ` (ưu tiên dùng `toolLv3`)
- HTKK/eTax: cài đặt, nâng cấp, kê khai, nộp tờ khai, quyết toán, đăng ký thuế, hoàn thuế, tải về, lỗi thường gặp
- Excel: hàm, mẫu file, thực hành, thuế, tiền lương, báo cáo, TSCĐ-CCDC
- MISA: mua-bán, kho, tài sản, giá thành, thuế, báo cáo
- FAST & phần mềm khác: cài đặt, tải về, sử dụng

---

## 4) Nhóm có thể ghép / cần tách

### 4.1. Có thể ghép

- `FAST` (3 bài) -> gộp vào **Phần mềm kế toán khác** (under Công cụ)
- `Công cụ` root 6 bài hiện tại -> giải tán, phân về đúng Kind/Domain

### 4.2. Cần tách

- Tách `Môn bài - Hộ kinh doanh - Nhà thầu - MST` thành 4 nhóm con rõ nghĩa.
- Tách `Học liệu - Tham khảo > Khác` theo domain thật; giảm tối đa “Khác”.

---

## 5) Kế hoạch triển khai nhiều giai đoạn (theo giới hạn bộ nhớ AI)

## Giai đoạn 0 — **(Hoàn thành)**
- [x] Chốt taxonomy đích Level 1–3
- [x] Tạo bộ nhớ tạm + baseline số liệu

## Giai đoạn 1 — Quick wins (batch nhỏ, chắc chắn đúng)
- [x] Sửa 11 ca di chuyển rõ ràng (mục 2)
- [x] Gắn cờ/loại 2 bài rác
- [x] Rebuild + kiểm tra diff taxonomy

## Giai đoạn 2 — Chuẩn hóa tầng Level 1 (xóa lặp loại nội dung)
- [x] Rà toàn bộ nhóm trùng kiểu (855 bài)
- [x] Chuyển `topicLv1` sang domain chuẩn
- [x] Giữ nguyên URL/canonical
  - [x] Batch 2.1: `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu doanh nghiệp - thủ tục` (**75 bài**)  
    -> chuẩn hóa `topicLv1` thành `Doanh nghiệp - Thủ tục` (giữ nguyên `topicLv2`)
  - [x] Batch 2.2: `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu lao động - bảo hiểm` (**50 bài**)  
    -> chuẩn hóa `topicLv1` thành `Lao động - Bảo hiểm` (giữ nguyên `topicLv2`)
  - [x] Batch 2.3a + 2.3b: `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu thuế - hóa đơn` (**155 bài**)  
    -> chuẩn hóa `topicLv1` thành `Thuế - Hóa đơn` (giữ nguyên `topicLv2`)
  - [x] Batch 2.4a + 2.4b: `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu kế toán` (**167 bài**)  
    -> chuẩn hóa `topicLv1` thành `Kế toán` (giữ nguyên `topicLv2`)
  - [~] Batch 2.5a + 2.5b + 2.5c: `Văn bản > Văn bản pháp luật` (**219 bài**)  
    -> đã chuẩn hóa tự động phần lớn `topicLv1` theo domain; còn 10 ca biên cần rà tay
  - [x] Batch 2.6: xử lý dứt điểm 2 bài `Công cụ > Phần mềm - Công cụ` + chốt 10 ca biên Batch 2.5

## Giai đoạn 3 — Tách bucket rộng
- [x] Tách `Môn bài - Hộ KD - Nhà thầu - MST` (34)
- [x] Rà `GTGT - Hóa đơn` (204) gắn level3
- [x] Rà `Tài khoản - Định khoản` (253) gắn level3
  - [x] Batch 3.2: rollout level3 cho 100 bài GTGT đầu (theo slug cutoff), có gate để triển khai dần
  - [x] Batch 3.3: rollout level3 cho 104 bài GTGT còn lại, bỏ gate và hoàn tất 204/204
  - [x] Batch 3.4a: rollout level3 cho 100 bài đầu `Tài khoản - Hạch toán` (có gate)
  - [x] Batch 3.4b: rollout level3 cho 100 bài tiếp theo `Tài khoản - Hạch toán` (coverage 200/253)
  - [x] Batch 3.4c: rollout level3 cho 53 bài còn lại `Tài khoản - Hạch toán` (hoàn tất 253/253)

## Giai đoạn 4 — Dọn nhóm `Khác`
- [x] Batch A: 60 bài Hướng dẫn > Học liệu > Khác *(thực tế còn 50 bài tại thời điểm chạy)*
- [x] Batch B: 12 bài Biểu mẫu > Học liệu > Khác
- [x] Batch C: 8 bài Văn bản > Học liệu > Khác

## Giai đoạn 5 — Hoàn thiện Level 3
- [~] Áp dụng level3 theo domain lớn *(đang triển khai theo batch)*
- [~] Ưu tiên Thuế/Kế toán/Công cụ trước
  - [x] Batch 5.1: hoàn thiện lv3 cho `Thuế TNCN` (60/60)
  - [x] Batch 5.2: hoàn thiện lv3 cho `Thuế TNDN` (49/49)
  - [x] Batch 5.3: hoàn thiện lv3 cho `Lao động - BHXH/BHYT/BHTN` (86/86)
  - [x] Batch 5.4: hoàn thiện lv3 cho `Lao động - Tiền lương` (84/84)
  - [x] Batch 5.5: hoàn thiện lv3 cho `Thuế - Công văn` (53/53)
  - [x] Batch 5.6: hoàn thiện lv3 cho `Thuế - Nghị định` (51/51)
  - [x] Batch 5.7: hoàn thiện lv3 cho `Thuế - Thông tư` (45/45)
  - [x] Batch 5.8: hoàn thiện lv3 cho `Kế toán - Mẫu biểu kế toán` (170/170)
    - [x] Batch 5.8a: hoàn thiện 85/170
    - [x] Batch 5.8b: hoàn thiện 85/170 còn lại
  - [x] Batch 5.9: hoàn thiện lv3 cho `Mẫu file & kê khai > HTKK - eTax - Thuế điện tử` (157/157 software series)
    - [x] Batch 5.9a: hoàn thiện 80/157 (software series)
    - [x] Batch 5.9b: hoàn thiện 77/157 còn lại (software series)
  - [x] Batch 5.10: hoàn thiện lv3 cho `Thuế - Hóa đơn > Mẫu biểu thuế - hóa đơn` (156/156)
    - [x] Batch 5.10a: hoàn thiện 80/156 (cutoff `mau-hoa-don-tien-nuoc`)
    - [x] Batch 5.10b: hoàn thiện 76/156 còn lại
  - [x] Batch 5.11: hoàn thiện lv3 cho `Kế toán > Chuẩn mực - Chế độ - Nguyên tắc` (89/89)
    - [x] Batch 5.11a: hoàn thiện 45/89 (cutoff `cong-ty-tnhh-thuong-mai-huong-thuy-tuyen-ke-toan-trung-tam-bao-hanh`)
    - [x] Batch 5.11b: hoàn thiện 44/89 còn lại
  - [x] Batch 5.12: hoàn thiện lv3 cho `Doanh nghiệp - Thủ tục > Mẫu biểu doanh nghiệp - thủ tục` (75/75)
    - [x] Batch 5.12a: hoàn thiện 38/75 (cutoff `mau-so-03-3a-tndn-phu-luc-thue-thu-nhap-doanh-nghiep-duoc-uu-dai`)
    - [x] Batch 5.12b: hoàn thiện 37/75 còn lại
  - [x] Batch 5.13: hoàn thiện lv3 cho `Phần mềm - Công cụ > MISA` (58/58)
    - [x] Batch 5.13a: hoàn thiện 29/58 (cutoff `cach-lap-bao-cao-tai-chinh-theo-thong-tu-200-tren-misa`)
    - [x] Batch 5.13b: hoàn thiện 29/58 còn lại
  - [x] Batch 5.14: hoàn thiện lv3 cho `Kế toán > Tài sản - Kho - CCDC` (52/52)
    - [x] Batch 5.14a: hoàn thiện 26/52 (cutoff `cong-viec-cua-nhan-vien-ke-toan-kho-phai-lam`)
    - [x] Batch 5.14b: hoàn thiện 26/52 còn lại
  - [x] Batch 5.15: hoàn thiện lv3 cho `Lao động - Bảo hiểm > Mẫu biểu lao động - bảo hiểm` (50/50)
    - [x] Batch 5.15a: hoàn thiện 25/50 (cutoff `mau-de-nghi-huong-tro-cap-that-nghiep-mau-so-03`)
    - [x] Batch 5.15b: hoàn thiện 25/50 còn lại
  - [x] Batch 5.16: hoàn thiện lv3 cho `Kế toán > Chứng từ - Sổ sách` (32/32)
    - [x] Batch 5.16a: hoàn thiện 16/32 (cutoff `cach-xu-ly-hang-ton-kho-bi-am-tren-so-sach`)
    - [x] Batch 5.16b: hoàn thiện 16/32 còn lại
  - [x] Batch 5.17: hoàn thiện lv3 cho `Kế toán > Báo cáo tài chính` (27/27)
    - [x] Batch 5.17a: hoàn thiện 14/27 (cutoff `cach-lap-thuyet-minh-bctc-theo-thong-tu-99`)
    - [x] Batch 5.17b: hoàn thiện 13/27 còn lại
  - [x] Batch 5.18: hoàn thiện lv3 cho `Mẫu file & kê khai > Excel - Công cụ khác` (25/25)
    - [x] Batch 5.18a: hoàn thiện 13/25 (cutoff `mau-bang-tinh-thue-tncn-tren-excel-2026-cho-doanh-nghiep`)
    - [x] Batch 5.18b: hoàn thiện 12/25 còn lại
  - [x] Batch 5.19: hoàn thiện lv3 cho `Kế toán > Nghiệp vụ theo phần hành` (24/24)
    - [x] Batch 5.19a: hoàn thiện 12/24 (cutoff `chi-phi-trang-phuc-cho-nhan-vien`)
    - [x] Batch 5.19b: hoàn thiện 12/24 còn lại
  - [x] Batch 5.20: hoàn thiện lv3 cho `Học liệu - Tham khảo > Kinh nghiệm - Hỏi đáp - Nghề nghiệp` (23/23)
    - [x] Batch 5.20a: hoàn thiện 12/23 (cutoff `hoc-ke-toan-thuc-hanh-o-dau-tot-nhat-tai-ha-noi-va-tphcm`)
    - [x] Batch 5.20b: hoàn thiện 11/23 còn lại
  - [x] Batch 5.21: hoàn thiện lv3 cho `Học liệu - Tham khảo > Báo cáo thực tập` (16/16)
    - [x] Batch 5.21a: hoàn thiện 16/16 (1 batch)
  - [x] Batch 5.22: hoàn thiện lv3 cho `Doanh nghiệp - Thủ tục > DN - Thủ tục` (15/15)
    - [x] Batch 5.22a: hoàn thiện 15/15 (1 batch)
  - [x] Batch 5.23: hoàn thiện lv3 cho `Học liệu - Tham khảo > Bài tập - Thực hành` (9/9)
    - [x] Batch 5.23a: hoàn thiện 9/9 (1 batch)

## Giai đoạn 6 — QA + review thủ công
- [x] Rà queue biên giới 50 bài (đã review + xuất kết quả)
- [x] Kiểm tra phân phối sau tái phân loại (đã xuất snapshot)
- [~] Chốt taxonomy và freeze rule (đã có proposal, chờ chốt chính sách)

---

## 6) Quy ước vận hành để không vỡ bộ nhớ AI

1. Mỗi batch tối đa **80–120 bài**.  
2. Mỗi batch có 1 file kết quả riêng:
   - `.m/reclass/batch-XX-input.csv`
   - `.m/reclass/batch-XX-output.csv`
   - `.m/reclass/batch-XX-notes.md`
3. Sau mỗi batch phải cập nhật mục **Run Log** bên dưới.
4. Không làm đồng thời nhiều domain trong 1 batch.
5. Mỗi lần `Ok continue` chỉ chạy **1 phase** hoặc **1 batch con**.

---

## 7) Run Log (cập nhật theo từng lần làm)

- 2026-04-07: tạo baseline + kế hoạch tổng + taxonomy đích.
- 2026-04-07 (Phase 1):
  - Áp dụng override cho 5 bài `Mẫu báo cáo: ...` -> `Biểu mẫu > Học liệu - Tham khảo > Báo cáo thực tập`.
  - Giữ 4 ca di chuyển Công cụ theo đúng mục tiêu:
    - `Trọn bộ mẫu giấy ủy nhiệm chi...` -> Biểu mẫu
    - `Quy định về sử dụng phần mềm kế toán...` -> Văn bản/Kế toán
    - `Thuế suất thuế GTGT đối với phần mềm...` -> Hướng dẫn/Thuế
    - `Thủ tục cắt giảm người phụ thuộc...` -> Hướng dẫn/TNCN
  - Loại khỏi publish 2 bài rác: `popupdetail.aspx`, `popupdetail-aspx.html`.
  - Rebuild full thành công bằng `python3 ../.m/build_sample_sections.py --mode full`.
  - Kết quả kiểm chứng:
    - 9/9 bài mục tiêu đã vào đúng nhóm đích.
    - `popup_records = 0`.
    - Tổng bài Thư viện: **2041 -> 2039** (giảm 2 bài rác).
    - `libraryKind`: Công cụ **6 -> 2**, Biểu mẫu **682 -> 683**, Văn bản **305 -> 306**.
    - `Báo cáo thực tập`: **11 -> 16**.
- 2026-04-07 (Phase 2 - Batch 2.1):
  - Phạm vi batch: 75 bài `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu doanh nghiệp - thủ tục`.
  - Mục tiêu: xóa lặp tầng loại nội dung ở Level 1.
  - Hành động:
    - Chuẩn hóa `topicLv1`:
      - từ `mau-bieu-thu-tuc / Mẫu biểu - Thủ tục`
      - sang `doanh-nghiep-thu-tuc / Doanh nghiệp - Thủ tục`
    - Giữ nguyên `libraryKind=Biểu mẫu` và `topicLv2=Mẫu biểu doanh nghiệp - thủ tục`.
  - Artifact:
    - Input: `.m/reclass/batch-02-01-input.csv` (75 dòng)
    - Output: `.m/reclass/batch-02-01-output.csv` (75/75 = `ok`)
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả nhanh:
    - `Doanh nghiệp - Thủ tục` trong Thư viện: **79** (tăng nhờ batch này)
    - `Mẫu biểu - Thủ tục`: **372** (giảm tương ứng)
    - URL/canonical không đổi.
- 2026-04-07 (Phase 2 - Batch 2.2):
  - Phạm vi batch: 50 bài `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu lao động - bảo hiểm`.
  - Mục tiêu: tiếp tục xóa lặp tầng loại nội dung ở Level 1.
  - Hành động:
    - Chuẩn hóa `topicLv1`:
      - từ `mau-bieu-thu-tuc / Mẫu biểu - Thủ tục`
      - sang `lao-dong-bao-hiem / Lao động - Bảo hiểm`
    - Giữ nguyên `libraryKind=Biểu mẫu` và `topicLv2=Mẫu biểu lao động - bảo hiểm`.
  - Artifact:
    - Input: `.m/reclass/batch-02-02-input.csv` (50 dòng)
    - Output: `.m/reclass/batch-02-02-output.csv` (50/50 = `ok`)
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả nhanh:
    - `Lao động - Bảo hiểm` trong Thư viện: **214**
    - `Mẫu biểu - Thủ tục`: **322**
    - URL/canonical không đổi.
- 2026-04-07 (Phase 2 - Batch 2.3a + 2.3b):
  - Phạm vi batch: 155 bài `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu thuế - hóa đơn`.
  - Chia batch con để an toàn bộ nhớ:
    - 2.3a: 80 bài
    - 2.3b: 75 bài
  - Mục tiêu: xóa lặp tầng loại nội dung ở Level 1.
  - Hành động:
    - Chuẩn hóa `topicLv1`:
      - từ `mau-bieu-thu-tuc / Mẫu biểu - Thủ tục`
      - sang `thue / Thuế - Hóa đơn`
    - Giữ nguyên `libraryKind=Biểu mẫu` và `topicLv2=Mẫu biểu thuế - hóa đơn`.
  - Artifact:
    - Input: `.m/reclass/batch-02-03a-input.csv` (80), `.m/reclass/batch-02-03b-input.csv` (75)
    - Output: `.m/reclass/batch-02-03a-output.csv` (80/80 `ok`), `.m/reclass/batch-02-03b-output.csv` (75/75 `ok`)
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả nhanh:
    - `Thuế - Hóa đơn` trong Thư viện: **500**
    - `Mẫu biểu - Thủ tục`: **167**
    - URL/canonical không đổi.
- 2026-04-07 (Phase 2 - Batch 2.4a + 2.4b):
  - Phạm vi batch: 167 bài `Biểu mẫu > Mẫu biểu - Thủ tục > Mẫu biểu kế toán`.
  - Chia batch con:
    - 2.4a: 84 bài
    - 2.4b: 83 bài
  - Mục tiêu: kết thúc việc loại bỏ hoàn toàn lv1 trùng kiểu cho cụm `Mẫu biểu - Thủ tục`.
  - Hành động:
    - Chuẩn hóa `topicLv1`:
      - từ `mau-bieu-thu-tuc / Mẫu biểu - Thủ tục`
      - sang `ke-toan / Kế toán`
    - Giữ nguyên `libraryKind=Biểu mẫu` và `topicLv2=Mẫu biểu kế toán`.
  - Artifact:
    - Input: `.m/reclass/batch-02-04a-input.csv` (84), `.m/reclass/batch-02-04b-input.csv` (83)
    - Output: `.m/reclass/batch-02-04a-output.csv` (84/84 `ok`), `.m/reclass/batch-02-04b-output.csv` (83/83 `ok`)
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả nhanh:
    - `Mẫu biểu - Thủ tục`: **0** (đã xử lý xong toàn bộ cụm này)
    - Nhóm trùng còn lại cần xử lý trong Phase 2:
      - `Văn bản > Văn bản pháp luật`: **219**
      - `Công cụ > Phần mềm - Công cụ`: **2**
    - URL/canonical không đổi.
- 2026-04-07 (Phase 2 - Batch 2.5a + 2.5b + 2.5c):
  - Phạm vi batch: 219 bài `Văn bản > Văn bản pháp luật`.
  - Chia batch con: 73 + 73 + 73 để giữ trace.
  - Cách làm:
    - Chuẩn hóa `topicLv1` theo `topicLv2` pháp lý (`Nghị định/Thông tư/Công văn/...`) + override theo keyword tiêu đề.
  - Artifact:
    - Input: `.m/reclass/batch-02-05a-input.csv`, `...05b...`, `...05c...`
    - Mapping tổng: `.m/reclass/batch-02-05-mapping.csv`
    - Output: `.m/reclass/batch-02-05a-output.csv`, `...05b...`, `...05c...`
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Đã xóa hoàn toàn nhóm trùng `Văn bản > Văn bản pháp luật`: **0**
    - Match theo mapping input: **209/219 ok**, **10 mismatch** (do rule override đúng hơn mapping plan ban đầu)
    - Đây là các ca biên cần rà tay xác nhận:
      1. Công văn Số 17940/SLĐTBXH-VL quy định về lao động
      2. Công văn số 7527/BTC-TCT thanh tra, kiểm tra thuế các DN
      3. Công văn xin đăng ký Thang bảng lương gửi phòng lao động
      4. Luật số 68/2014/QH13 Luật doanh nghiệp 2015
      5. Luật thuế thu nhập doanh nghiệp số 67/2025/QH15
      6. Nghị định số 105/2014/NĐ-CP luật bảo hiểm y tế 2015
      7. Thông tư 09/2015/TT-BTC giao dịch tài chính của doanh nghiệp
      8. Thông tư 132/2018/TT-BTC chế độ kế toán doanh nghiệp siêu nhỏ
      9. Thông tư 99/2025/TT-BTC hướng dẫn chế độ kế toán DN
      10. Thông tư số 178/TT-BTC áp dụng bảo hiểm xã hội Việt Nam
  - Trạng thái Phase 2 sau batch này:
    - Còn lại nhóm trùng: `Công cụ > Phần mềm - Công cụ` = **2** bài
- 2026-04-07 (Phase 2 - Batch 2.6):
  - Mục tiêu:
    1) xử lý nốt `Công cụ > Phần mềm - Công cụ` (2 bài),
    2) rà tay/chốt 10 ca biên từ batch 2.5.
  - Hành động:
    - Thêm override tường minh cho 12 file nguồn (2 bài công cụ + 10 ca biên).
    - Sửa crash build taxonomy khi `cong-cu` còn 0 bản ghi (khởi tạo `toolVariants` an toàn).
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - `Biểu mẫu > Mẫu biểu - Thủ tục`: **0**
    - `Biểu mẫu > Phần mềm - Công cụ`: **0**
    - `Văn bản > Văn bản pháp luật`: **0**
    - `Công cụ > Phần mềm - Công cụ`: **0**
  - Artifact:
    - Ghi chú quyết định ca biên: `.m/reclass/batch-02-06-notes.md`
  - Kết luận: **Hoàn thành Giai đoạn 2**.
- 2026-04-07 (Phase 3 - Batch 3.1):
  - Phạm vi: 34 bài đang ở node trộn `Môn bài - Hộ kinh doanh - Nhà thầu - MST`.
  - Mục tiêu: tách thành 4 nhóm level2 đích:
    - `Lệ phí môn bài`
    - `Hộ/Cá nhân kinh doanh`
    - `Mã số thuế - Đăng ký thuế`
    - `Thuế nhà thầu`
  - Hành động:
    - Thêm rule tách tại `apply_topic_override` cho `topic_lv2_key=khac-mon-bai-ho-kinh-doanh-nha-thau`.
    - Dùng chuẩn hóa không dấu (`ascii_fold`) để match tiếng Việt ổn định.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Node cũ `khac-mon-bai-ho-kinh-doanh-nha-thau`: **0**
    - Phân bổ mới:
      - `Lệ phí môn bài`: **11**
      - `Hộ/Cá nhân kinh doanh`: **10**
      - `Mã số thuế - Đăng ký thuế`: **9**
      - `Thuế nhà thầu`: **4**
    - So với mapping plan: 32/34 match, 2 mismatch đã rà tay và **chấp nhận actual là đúng hơn**.
  - Artifact:
    - Input: `.m/reclass/batch-03-01-input.csv`
    - Output: `.m/reclass/batch-03-01-output.csv`
    - Notes: `.m/reclass/batch-03-01-notes.md`
- 2026-04-07 (Phase 3 - Batch 3.2):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > GTGT - Hóa đơn`
    - Rollout batch 1: **100 bài đầu theo slug** (gate cutoff: `doi-tuong-va-truong-hop-duoc-hoan-thue-gtgt`)
  - Hành động:
    - Bổ sung `topic_lv3_key/topic_lv3_label` vào pipeline record + data export.
    - Nâng taxonomy JSON để hỗ trợ tree 3 tầng (lv1 -> lv2 -> lv3).
    - Áp classifier level3 GTGT với 6 nhãn:
      - Hóa đơn điện tử
      - Lập/Xử lý hóa đơn
      - Kê khai GTGT
      - Khấu trừ/Hoàn thuế
      - Thuế suất/Đối tượng chịu thuế
      - Báo cáo/Bảng kê
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Trong batch: **100/100** bài có `topicLv3`
    - Ngoài batch GTGT: **104** bài vẫn để trống `topicLv3` (đúng theo gate rollout)
    - So với plan csv: 97/100 strict match, 3 ca mismatch đã review và giữ actual.
  - Artifact:
    - Input: `.m/reclass/batch-03-02-input.csv`
    - Output: `.m/reclass/batch-03-02-output.csv`
    - Notes: `.m/reclass/batch-03-02-notes.md`
- 2026-04-07 (Phase 3 - Batch 3.3):
  - Phạm vi:
    - 104 bài GTGT còn lại (ngoài cutoff batch 3.2).
  - Hành động:
    - Bỏ rollout gate/cutoff trong classifier GTGT lv3.
    - Giữ nguyên bộ 6 nhãn lv3 đã chốt.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - `GTGT - Hóa đơn` có `topicLv3`: **204/204** (100% phủ đủ)
    - Phân bổ lv3 cuối cùng:
      - Lập/Xử lý hóa đơn: 89
      - Hóa đơn điện tử: 49
      - Kê khai GTGT: 24
      - Khấu trừ/Hoàn thuế: 24
      - Thuế suất/Đối tượng chịu thuế: 12
      - Báo cáo/Bảng kê: 6
    - So với plan csv batch 3.3: 103/104 match; 1 mismatch đã review và chấp nhận actual.
  - Artifact:
    - Input: `.m/reclass/batch-03-03-input.csv`
    - Output: `.m/reclass/batch-03-03-output.csv`
    - Notes: `.m/reclass/batch-03-03-notes.md`
- 2026-04-07 (Phase 3 - Batch 3.4a):
  - Phạm vi:
    - Node: `Kế toán > Tài khoản - Hạch toán`
    - Batch 1: 100 bài đầu theo slug (cutoff: `cach-hach-toan-tam-ung-tk-141`)
  - Hành động:
    - Bổ sung classifier lv3 cho `tai-khoan-hach-toan` với 9 nhãn:
      - Tiền/Quỹ/Ngân hàng
      - Công nợ/Thanh toán
      - Hàng tồn kho/Giá thành
      - TSCĐ/CCDC/Khấu hao
      - Doanh thu/Chi phí/KQKD
      - Vốn/Đầu tư
      - Lương/Bảo hiểm
      - Thuế/Nghĩa vụ NSNN
      - Nghiệp vụ đặc thù
    - Giữ rollout gate để chỉ áp dụng cho 100 bài đầu.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **100/100**
    - `topicLv3` trong batch: **100/100**
    - Ngoài batch: **0/153** (đúng gate)
    - Không ảnh hưởng GTGT: `topicLv3` GTGT vẫn **204/204**
  - Artifact:
    - Input: `.m/reclass/batch-03-04a-input.csv`
    - Output: `.m/reclass/batch-03-04a-output.csv`
    - Notes: `.m/reclass/batch-03-04a-notes.md`
- 2026-04-07 (Phase 3 - Batch 3.4b):
  - Phạm vi:
    - 100 bài tiếp theo của node `Kế toán > Tài khoản - Hạch toán`.
    - Nâng cutoff gate lên: `he-thong-tai-khoan-ke-toan-theo-thong-tu-133`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **100/100**
    - Coverage node `Tài khoản - Hạch toán`: **200/253**
    - Còn lại chưa gắn lv3: **53** bài
    - GTGT vẫn ổn định: **204/204** có lv3
  - Artifact:
    - Input: `.m/reclass/batch-03-04b-input.csv`
    - Output: `.m/reclass/batch-03-04b-output.csv`
    - Notes: `.m/reclass/batch-03-04b-notes.md`
- 2026-04-07 (Phase 3 - Batch 3.4c):
  - Phạm vi:
    - 53 bài còn lại của node `Kế toán > Tài khoản - Hạch toán`.
  - Hành động:
    - Bỏ gate rollout cho classifier `tai-khoan-hach-toan` để áp dụng cho toàn node.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **53/53**
    - Coverage node `Tài khoản - Hạch toán`: **253/253**
    - GTGT giữ ổn định: **204/204** có `topicLv3`
  - Artifact:
    - Input: `.m/reclass/batch-03-04c-input.csv`
    - Output: `.m/reclass/batch-03-04c-output.csv`
    - Notes: `.m/reclass/batch-03-04c-notes.md`
  - Kết luận: **Hoàn thành Giai đoạn 3**.
- 2026-04-07 (Phase 4 - Batch 4A):
  - Phạm vi:
    - Bucket mục tiêu: `Hướng dẫn > Học liệu - Tham khảo > Khác`.
    - Số lượng thực tế tại thời điểm chạy: **50** bài (baseline cũ ghi 60).
  - Hành động:
    - Thêm `PHASE4A_HOC_LIEU_KHAC_GUIDE_OVERRIDES` và merge vào `ARTICLE_TOPIC_OVERRIDES`.
    - Chuyển 50 bài về domain/lv2 hợp lý theo nhóm:
      - Thuế - Hóa đơn
      - Kế toán
      - Lao động - Bảo hiểm
      - Doanh nghiệp - Thủ tục
      - Học liệu - Kinh nghiệm nghề
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **50/50**
    - `Hướng dẫn > Học liệu - Tham khảo > Khác`: **0**
    - Tổng `topicLv2=Khác` toàn thư viện còn: **20**
  - Artifact:
    - Input: `.m/reclass/batch-04A-input.csv`
    - Output: `.m/reclass/batch-04A-output.csv`
    - Notes: `.m/reclass/batch-04A-notes.md`
- 2026-04-07 (Phase 4 - Batch 4B):
  - Phạm vi:
    - Bucket mục tiêu: `Biểu mẫu > Học liệu - Tham khảo > Khác` (12 bài).
  - Hành động:
    - Thêm `PHASE4B_BIEU_MAU_HOC_LIEU_KHAC_OVERRIDES` và merge vào `ARTICLE_TOPIC_OVERRIDES`.
    - Di chuyển 12 bài về domain/lv2 đích (Kế toán/Thuế/Doanh nghiệp/Lao động).
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **12/12**
    - `Biểu mẫu > Học liệu - Tham khảo > Khác`: **0**
    - Tổng `topicLv2=Khác` toàn thư viện còn: **8**
  - Artifact:
    - Input: `.m/reclass/batch-04B-input.csv`
    - Output: `.m/reclass/batch-04B-output.csv`
    - Notes: `.m/reclass/batch-04B-notes.md`
- 2026-04-07 (Phase 4 - Batch 4C):
  - Phạm vi:
    - Bucket mục tiêu: `Văn bản > Học liệu - Tham khảo > Khác` (8 bài).
  - Hành động:
    - Thêm `PHASE4C_VAN_BAN_HOC_LIEU_KHAC_OVERRIDES` và merge vào `ARTICLE_TOPIC_OVERRIDES`.
    - Di chuyển 8 bài về domain/lv2 đích (Thuế/Doanh nghiệp/Lao động/Kế toán).
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **8/8**
    - Tổng `topicLv2=Khác` toàn Thư viện: **0**
    - Mốc: **đã dọn sạch hoàn toàn nhóm `Khác`**.
  - Artifact:
    - Input: `.m/reclass/batch-04C-input.csv`
    - Output: `.m/reclass/batch-04C-output.csv`
    - Notes: `.m/reclass/batch-04C-notes.md`
  - Kết luận: **Hoàn thành Giai đoạn 4**.
- 2026-04-07 (Phase 5 - Batch 5.1):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > Thuế thu nhập cá nhân` (60 bài).
  - Hành động:
    - Bổ sung classifier `classify_tncn_level3` + bộ nhãn lv3 TNCN.
    - Áp 7 nhóm lv3:
      - Kê khai/Quyết toán
      - Tính thuế/Biểu thuế
      - Giảm trừ/Miễn thuế
      - Khấu trừ/Chứng từ
      - Hạch toán TNCN
      - Đối tượng/Thu nhập chịu thuế
      - Phụ cấp/Phúc lợi/Chi phí
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **60/60**
    - Coverage node TNCN: **60/60**
    - Coverage lv3 toàn thư viện: **539/2039 (26.43%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-01-input.csv`
    - Output: `.m/reclass/batch-05-01-output.csv`
    - Notes: `.m/reclass/batch-05-01-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.2):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > Thuế thu nhập doanh nghiệp` (49 bài).
  - Hành động:
    - Bổ sung classifier `classify_tndn_level3` + bộ nhãn lv3 TNDN.
    - Áp 7 nhóm lv3:
      - Kê khai/Tạm nộp/Quyết toán
      - Thuế suất/Phương pháp tính
      - Chi phí được trừ/không được trừ
      - Doanh thu/Thu nhập tính thuế
      - Ưu đãi/Miễn giảm/Chuyển lỗ
      - Hạch toán TNDN
      - Văn bản/Chính sách
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **49/49**
    - Coverage node TNDN: **49/49**
    - Coverage lv3 toàn thư viện: **588/2039 (28.84%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-02-input.csv`
    - Output: `.m/reclass/batch-05-02-output.csv`
    - Notes: `.m/reclass/batch-05-02-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.3):
  - Phạm vi:
    - Node: `Lao động - Bảo hiểm > BHXH - BHYT - BHTN` (86 bài).
  - Hành động:
    - Bổ sung classifier `classify_bhxh_level3` + bộ nhãn lv3 BHXH/BHYT/BHTN.
    - Áp 7 nhóm lv3:
      - Chế độ/Mức hưởng
      - Hồ sơ/Thủ tục
      - Đối tượng/Mức đóng
      - Lương căn cứ/Tỷ lệ đóng
      - Vi phạm/Xử phạt
      - Hạch toán/Chi phí bảo hiểm
      - Văn bản/Chính sách
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **86/86**
    - Coverage node BHXH/BHYT/BHTN: **86/86**
    - Coverage lv3 toàn thư viện: **674/2039 (33.06%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-03-input.csv`
    - Output: `.m/reclass/batch-05-03-output.csv`
    - Notes: `.m/reclass/batch-05-03-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.4):
  - Phạm vi:
    - Node: `Lao động - Bảo hiểm > Lao động - Tiền lương` (84 bài).
  - Hành động:
    - Bổ sung classifier `classify_lao_dong_tien_luong_level3` + bộ nhãn lv3 cho nhánh Lao động/Tiền lương.
    - Áp 8 nhóm lv3:
      - Tiền lương/Thời giờ làm việc
      - Hợp đồng/Quan hệ lao động
      - Hồ sơ/Thủ tục lao động
      - Nội quy/Thỏa ước/Kỷ luật
      - Trợ cấp/Bồi thường
      - Hạch toán tiền lương
      - Thuế liên quan lao động
      - Xử phạt/Vi phạm lao động
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **84/84**
    - Coverage node Lao động - Tiền lương: **84/84**
    - Coverage lv3 toàn thư viện: **758/2039 (37.18%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-04-input.csv`
    - Output: `.m/reclass/batch-05-04-output.csv`
    - Notes: `.m/reclass/batch-05-04-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.5):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > Công văn` (53 bài).
  - Hành động:
    - Bổ sung classifier `classify_thue_cong_van_level3` + bộ nhãn lv3 cho nhánh Thuế-Công văn.
    - Áp 6 nhóm lv3:
      - Công văn GTGT/Hóa đơn
      - Công văn TNCN
      - Công văn TNDN
      - Công văn MST/Đăng ký thuế
      - Công văn Kê khai/Quản lý thuế
      - Công văn Chính sách chung
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **53/53**
    - Coverage node Thuế-Công văn: **53/53**
    - Coverage lv3 toàn thư viện: **811/2039 (39.77%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-05-input.csv`
    - Output: `.m/reclass/batch-05-05-output.csv`
    - Notes: `.m/reclass/batch-05-05-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.6):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > Nghị định` (51 bài).
  - Hành động:
    - Bổ sung classifier `classify_thue_nghi_dinh_level3` + bộ nhãn lv3 cho nhánh Thuế-Nghị định.
    - Áp 6 nhóm lv3:
      - Nghị định GTGT/Hóa đơn
      - Nghị định TNDN
      - Nghị định Hộ/Cá nhân kinh doanh
      - Nghị định Miễn/Giảm/Gia hạn
      - Nghị định Quản lý/Xử phạt
      - Nghị định Chính sách chung
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **51/51**
    - Coverage node Thuế-Nghị định: **51/51**
    - Coverage lv3 toàn thư viện: **862/2039 (42.28%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-06-input.csv`
    - Output: `.m/reclass/batch-05-06-output.csv`
    - Notes: `.m/reclass/batch-05-06-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.7):
  - Phạm vi:
    - Node: `Thuế - Hóa đơn > Thông tư` (45 bài).
  - Hành động:
    - Bổ sung classifier `classify_thue_thong_tu_level3` + bộ nhãn lv3 cho nhánh Thuế-Thông tư.
    - Áp 7 nhóm lv3:
      - Thông tư GTGT/Hóa đơn
      - Thông tư TNCN
      - Thông tư TNDN
      - Thông tư MST/Đăng ký thuế
      - Thông tư Kê khai/Quản lý thuế
      - Thông tư Hộ/Cá nhân kinh doanh
      - Thông tư Chính sách chung
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **45/45**
    - Coverage node Thuế-Thông tư: **45/45**
    - Coverage lv3 toàn thư viện: **907/2039 (44.48%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-07-input.csv`
    - Output: `.m/reclass/batch-05-07-output.csv`
    - Notes: `.m/reclass/batch-05-07-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.8a):
  - Phạm vi:
    - Node: `Kế toán > Mẫu biểu kế toán` (170 bài).
    - Batch 1: 85 bài đầu theo cutoff slug `mau-cv-xin-viec-ke-toan-cho-sinh-vien-moi-ra-truong-hay-nhat`.
  - Hành động:
    - Bổ sung classifier `classify_mau_bieu_ke_toan_level3` + bộ nhãn lv3 cho nhánh Mẫu biểu kế toán.
    - Dùng gate rollout cho batch 1 (chỉ apply `slug <= cutoff`).
    - Áp 10 nhóm lv3:
      - Mẫu chứng từ tiền/thanh toán
      - Mẫu chứng từ mua bán/hợp đồng
      - Mẫu kho/vật tư/CCDC
      - Mẫu TSCĐ/khấu hao
      - Mẫu lương/nhân sự
      - Mẫu sổ sách kế toán
      - Mẫu báo cáo tài chính
      - Mẫu hành chính/quản trị khác
      - Mẫu thuế/kê khai/đăng ký
      - Mẫu tài chính vốn/ngoại tệ
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **85/85**
    - Coverage node Mẫu biểu kế toán: **85/170**
    - Coverage lv3 toàn thư viện: **992/2039 (48.65%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-08a-input.csv`
    - Output: `.m/reclass/batch-05-08a-output.csv`
    - Notes: `.m/reclass/batch-05-08a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.8b):
  - Phạm vi:
    - Node: `Kế toán > Mẫu biểu kế toán` (170 bài).
    - Batch 2: 85 bài còn lại (bỏ gate rollout).
  - Hành động:
    - Bỏ gate `MAU_BIEU_KE_TOAN_LV3_BATCH1_SLUG_CUTOFF` trong classifier để áp toàn node.
    - Giữ nguyên bộ 10 nhãn lv3 của nhánh Mẫu biểu kế toán.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **85/85**
    - Coverage node Mẫu biểu kế toán: **170/170**
    - Coverage lv3 toàn thư viện: **1077/2039 (52.82%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-08b-input.csv`
    - Output: `.m/reclass/batch-05-08b-output.csv`
    - Notes: `.m/reclass/batch-05-08b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.9a):
  - Phạm vi:
    - Node `HTKK - eTax - Thuế điện tử` có 163 bài (157 software release + 6 guide/process).
    - Batch 1 chỉ xử lý software series (slug `phan-mem*`), 80 bài đầu theo cutoff `phan-mem-ho-tro-ke-khai-thue-htkk-4-9-5-moi-nhat-2022`.
  - Hành động:
    - Bổ sung classifier `classify_htkk_etax_level3` + nhãn lv3 theo cụm phiên bản HTKK:
      - HTKK 3.x
      - HTKK 4.0–4.4
      - HTKK 4.5–4.7
      - HTKK 4.8–4.9
      - HTKK 5.x
      - HTKK chưa rõ phiên bản
    - Giới hạn classifier theo điều kiện:
      - `topic_lv2_key == htkk-etax-thue-dien-tu`
      - slug bắt đầu `phan-mem`
      - slug `<= cutoff` (batch 1)
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **80/80**
    - Coverage software series HTKK/eTax sau batch 1: **80/157**
    - 6 bài guide/process cùng lv2 giữ trống lv3 (đúng phạm vi batch).
    - Coverage lv3 toàn thư viện: **1157/2039 (56.74%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-09a-input.csv`
    - Output: `.m/reclass/batch-05-09a-output.csv`
    - Notes: `.m/reclass/batch-05-09a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.9b):
  - Phạm vi:
    - Hoàn thiện 77 bài software còn lại của node `HTKK - eTax - Thuế điện tử`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier HTKK/eTax.
    - Giữ điều kiện phạm vi `slug bắt đầu phan-mem*`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **77/77**
    - Coverage software series HTKK/eTax: **157/157**
    - 6 bài guide/process cùng lv2 vẫn giữ trống lv3 (đúng phạm vi).
    - Coverage lv3 toàn thư viện: **1234/2039 (60.52%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-09b-input.csv`
    - Output: `.m/reclass/batch-05-09b-output.csv`
    - Notes: `.m/reclass/batch-05-09b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.10a):
  - Phạm vi:
    - Node `Thuế - Hóa đơn > Mẫu biểu thuế - hóa đơn` (156 bài).
    - Batch 1: 80 bài đầu, gate theo cutoff slug `mau-hoa-don-tien-nuoc`.
  - Hành động:
    - Thêm classifier `classify_mau_bieu_thue_level3` (ưu tiên regex theo nhóm mẫu: GTGT/Hóa đơn, TNCN, TNDN, Nhà thầu, Hộ/cá nhân/môn bài, MST, Khấu trừ/hoàn/miễn giảm, Bảng kê/phụ lục/hồ sơ).
    - Thêm map label lv3 cho node `mau-bieu-thue`.
    - Áp dụng gate cutoff để rollout theo batch con, tránh ảnh hưởng 76 bài còn lại.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **80/80**
    - Coverage node `Mẫu biểu thuế - hóa đơn`: **80/156**
    - Còn lại chưa gắn lv3 trong node: **76**
    - Coverage lv3 toàn thư viện: **1314/2039 (64.44%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-10a-input.csv`
    - Output: `.m/reclass/batch-05-10a-output.csv`
    - Notes: `.m/reclass/batch-05-10a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.10b):
  - Phạm vi:
    - Hoàn thiện 76 bài còn lại của node `Thuế - Hóa đơn > Mẫu biểu thuế - hóa đơn`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_mau_bieu_thue_level3`.
    - Mở rộng regex cho edge-cases:
      - Thuế tài nguyên (`01/TAIN`, `02/TAIN`, `thuế tài nguyên`)
      - Cho thuê nhà/cá nhân
      - Thông báo cơ quan thuế quản lý trực tiếp
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **76/76**
    - Coverage node `Mẫu biểu thuế - hóa đơn`: **156/156**
    - Coverage lv3 toàn thư viện: **1390/2039 (68.17%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-10b-input.csv`
    - Output: `.m/reclass/batch-05-10b-output.csv`
    - Notes: `.m/reclass/batch-05-10b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.11a):
  - Phạm vi:
    - Node `Kế toán > Chuẩn mực - Chế độ - Nguyên tắc` (89 bài).
    - Batch 1: 45 bài đầu, gate theo cutoff slug `cong-ty-tnhh-thuong-mai-huong-thuy-tuyen-ke-toan-trung-tam-bao-hanh`.
  - Hành động:
    - Thêm classifier `classify_chuan_muc_che_do_level3`.
    - Thêm map label lv3 cho node `chuan-muc-che-do-nguyen-tac`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **45/45**
    - Coverage node `Chuẩn mực - Chế độ - Nguyên tắc`: **45/89**
    - Còn lại chưa gắn lv3 trong node: **44**
    - Coverage lv3 toàn thư viện: **1435/2039 (70.38%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-11a-input.csv`
    - Output: `.m/reclass/batch-05-11a-output.csv`
    - Notes: `.m/reclass/batch-05-11a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.11b):
  - Phạm vi:
    - Hoàn thiện 44 bài còn lại của node `Kế toán > Chuẩn mực - Chế độ - Nguyên tắc`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_chuan_muc_che_do_level3`.
    - Mở rộng regex cho edge-cases:
      - `sổ kế toán chi tiết`
      - `lưu ý`, `xử phạt`
      - `ôn thi`, `chứng chỉ hành nghề`
      - nhóm bài `hưởng chế độ` vào bucket `Chuẩn mực/Chế độ khác`
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **44/44**
    - Coverage node `Chuẩn mực - Chế độ - Nguyên tắc`: **89/89**
    - Coverage lv3 toàn thư viện: **1479/2039 (72.54%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-11b-input.csv`
    - Output: `.m/reclass/batch-05-11b-output.csv`
    - Notes: `.m/reclass/batch-05-11b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.12a):
  - Phạm vi:
    - Node `Doanh nghiệp - Thủ tục > Mẫu biểu doanh nghiệp - thủ tục` (75 bài).
    - Batch 1: 38 bài đầu, gate theo cutoff slug `mau-so-03-3a-tndn-phu-luc-thue-thu-nhap-doanh-nghiep-duoc-uu-dai`.
  - Hành động:
    - Thêm classifier `classify_mau_bieu_doanh_nghiep_thu_tuc_level3`.
    - Thêm map label lv3 cho node `mau-bieu-doanh-nghiep-thu-tuc`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **38/38**
    - Coverage node `Mẫu biểu doanh nghiệp - thủ tục`: **38/75**
    - Còn lại chưa gắn lv3 trong node: **37**
    - Coverage lv3 toàn thư viện: **1517/2039 (74.4%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-12a-input.csv`
    - Output: `.m/reclass/batch-05-12a-output.csv`
    - Notes: `.m/reclass/batch-05-12a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.12b):
  - Phạm vi:
    - Hoàn thiện 37 bài còn lại của node `Doanh nghiệp - Thủ tục > Mẫu biểu doanh nghiệp - thủ tục`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_mau_bieu_doanh_nghiep_thu_tuc_level3`.
    - Mở rộng regex cho edge-cases:
      - `kê khai thuế thay cho chủ nhà` -> nhóm hộ/cá nhân kinh doanh
      - `thông báo/tờ khai tạm ngừng kinh doanh`, `người nộp hồ sơ để giải thể` -> nhóm đăng ký/thay đổi DN
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **37/37**
    - Coverage node `Mẫu biểu doanh nghiệp - thủ tục`: **75/75**
    - Coverage lv3 toàn thư viện: **1554/2039 (76.21%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-12b-input.csv`
    - Output: `.m/reclass/batch-05-12b-output.csv`
    - Notes: `.m/reclass/batch-05-12b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.13a):
  - Phạm vi:
    - Node `Phần mềm - Công cụ > MISA` (58 bài).
    - Batch 1: 29 bài đầu, gate theo cutoff slug `cach-lap-bao-cao-tai-chinh-theo-thong-tu-200-tren-misa`.
  - Hành động:
    - Thêm classifier `classify_misa_level3`.
    - Thêm map label lv3 cho node `misa`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **29/29**
    - Coverage node `MISA`: **29/58**
    - Còn lại chưa gắn lv3 trong node: **29**
    - Coverage lv3 toàn thư viện: **1583/2039 (77.64%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-13a-input.csv`
    - Output: `.m/reclass/batch-05-13a-output.csv`
    - Notes: `.m/reclass/batch-05-13a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.13b):
  - Phạm vi:
    - Hoàn thiện 29 bài còn lại của node `Phần mềm - Công cụ > MISA`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_misa_level3`.
    - Mở rộng regex cho edge-cases:
      - báo cáo/quyết toán: `nộp tiền thuế`, `tạm tính TNDN`, `kết chuyển lãi lỗ`
      - TSCĐ/CCDC: `phân bổ CCDC`
      - bán hàng/doanh thu: `hàng bán đúng giá hưởng hoa hồng`, `ứng trước của khách hàng`
      - mua hàng/công nợ: `trả trước tiền hàng`, `trả lại hàng mua`
      - kho/sản xuất: `giá xuất kho`
      - nhóm MISA khác: tạm ứng, nộp BHXH, nộp/rút tiền ngân hàng
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **29/29**
    - Coverage node `MISA`: **58/58**
    - Coverage lv3 toàn thư viện: **1612/2039 (79.06%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-13b-input.csv`
    - Output: `.m/reclass/batch-05-13b-output.csv`
    - Notes: `.m/reclass/batch-05-13b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.14a):
  - Phạm vi:
    - Node `Kế toán > Tài sản - Kho - CCDC` (52 bài).
    - Batch 1: 26 bài đầu, gate theo cutoff slug `cong-viec-cua-nhan-vien-ke-toan-kho-phai-lam`.
  - Hành động:
    - Thêm classifier `classify_tai_san_kho_ccdc_level3`.
    - Thêm map label lv3 cho node `tai-san-kho-ccdc`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **26/26**
    - Coverage node `Tài sản - Kho - CCDC`: **26/52**
    - Còn lại chưa gắn lv3 trong node: **26**
    - Coverage lv3 toàn thư viện: **1638/2039 (80.33%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-14a-input.csv`
    - Output: `.m/reclass/batch-05-14a-output.csv`
    - Notes: `.m/reclass/batch-05-14a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.14b):
  - Phạm vi:
    - Hoàn thiện 26 bài còn lại của node `Kế toán > Tài sản - Kho - CCDC`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_tai_san_kho_ccdc_level3`.
    - Mở rộng regex cho edge-cases:
      - `phân bổ công cụ dụng cụ` -> nhóm TSCĐ/nguyên giá/khấu hao
      - `kiểm kê tài sản`, `khoản đầu tư vào công ty liên kết`, `quỹ phát triển khoa học và công nghệ` -> nhóm `Tài sản - Kho - CCDC khác`
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **26/26**
    - Coverage node `Tài sản - Kho - CCDC`: **52/52**
    - Coverage lv3 toàn thư viện: **1664/2039 (81.61%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-14b-input.csv`
    - Output: `.m/reclass/batch-05-14b-output.csv`
    - Notes: `.m/reclass/batch-05-14b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.15a):
  - Phạm vi:
    - Node `Lao động - Bảo hiểm > Mẫu biểu lao động - bảo hiểm` (50 bài).
    - Batch 1: 25 bài đầu, gate theo cutoff slug `mau-de-nghi-huong-tro-cap-that-nghiep-mau-so-03`.
  - Hành động:
    - Thêm classifier `classify_mau_bieu_lao_dong_bh_level3`.
    - Thêm map label lv3 cho node `mau-bieu-lao-dong-bao-hiem`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **25/25**
    - Coverage node `Mẫu biểu lao động - bảo hiểm`: **25/50**
    - Còn lại chưa gắn lv3 trong node: **25**
    - Coverage lv3 toàn thư viện: **1689/2039 (82.83%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-15a-input.csv`
    - Output: `.m/reclass/batch-05-15a-output.csv`
    - Notes: `.m/reclass/batch-05-15a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.15b):
  - Phạm vi:
    - Hoàn thiện 25 bài còn lại của node `Lao động - Bảo hiểm > Mẫu biểu lao động - bảo hiểm`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_mau_bieu_lao_dong_bh_level3`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **25/25**
    - Coverage node `Mẫu biểu lao động - bảo hiểm`: **50/50**
    - Coverage lv3 toàn thư viện: **1714/2039 (84.06%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-15b-input.csv`
    - Output: `.m/reclass/batch-05-15b-output.csv`
    - Notes: `.m/reclass/batch-05-15b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.16a):
  - Phạm vi:
    - Node `Kế toán > Chứng từ - Sổ sách` (32 bài).
    - Batch 1: 16 bài đầu, gate theo cutoff slug `cach-xu-ly-hang-ton-kho-bi-am-tren-so-sach`.
  - Hành động:
    - Thêm classifier `classify_chung_tu_so_sach_level3`.
    - Thêm map label lv3 cho node `chung-tu-so-sach`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **16/16**
    - Coverage node `Chứng từ - Sổ sách`: **16/32**
    - Còn lại chưa gắn lv3 trong node: **16**
    - Coverage lv3 toàn thư viện: **1730/2039 (84.85%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-16a-input.csv`
    - Output: `.m/reclass/batch-05-16a-output.csv`
    - Notes: `.m/reclass/batch-05-16a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.16b):
  - Phạm vi:
    - Hoàn thiện 16 bài còn lại của node `Kế toán > Chứng từ - Sổ sách`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_chung_tu_so_sach_level3`.
    - Mở rộng regex cho edge-cases:
      - `sổ chi tiết thanh toán với người mua - người bán` -> nhóm `Sổ sách tiền/kho/chi tiết`
      - `quy trình luân chuyển chứng từ` -> nhóm `Chứng từ công tác/thanh toán`
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **16/16**
    - Coverage node `Chứng từ - Sổ sách`: **32/32**
    - Coverage lv3 toàn thư viện: **1746/2039 (85.63%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-16b-input.csv`
    - Output: `.m/reclass/batch-05-16b-output.csv`
    - Notes: `.m/reclass/batch-05-16b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.17a):
  - Phạm vi:
    - Node `Kế toán > Báo cáo tài chính` (27 bài).
    - Batch 1: 14 bài đầu, gate theo cutoff slug `cach-lap-thuyet-minh-bctc-theo-thong-tu-99`.
  - Hành động:
    - Thêm classifier `classify_bao_cao_tai_chinh_level3`.
    - Thêm map label lv3 cho node `bao-cao-tai-chinh`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **14/14**
    - Coverage node `Báo cáo tài chính`: **14/27**
    - Còn lại chưa gắn lv3 trong node: **13**
    - Coverage lv3 toàn thư viện: **1760/2039 (86.32%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-17a-input.csv`
    - Output: `.m/reclass/batch-05-17a-output.csv`
    - Notes: `.m/reclass/batch-05-17a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.17b):
  - Phạm vi:
    - Hoàn thiện 13 bài còn lại của node `Kế toán > Báo cáo tài chính`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_bao_cao_tai_chinh_level3`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **13/13**
    - Coverage node `Báo cáo tài chính`: **27/27**
    - Coverage lv3 toàn thư viện: **1773/2039 (86.95%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-17b-input.csv`
    - Output: `.m/reclass/batch-05-17b-output.csv`
    - Notes: `.m/reclass/batch-05-17b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.18a):
  - Phạm vi:
    - Node `Mẫu file & kê khai > Excel - Công cụ khác` (25 bài).
    - Batch 1: 13 bài đầu, gate theo cutoff slug `mau-bang-tinh-thue-tncn-tren-excel-2026-cho-doanh-nghiep`.
  - Hành động:
    - Thêm classifier `classify_excel_cong_cu_khac_level3`.
    - Thêm map label lv3 cho node `excel-va-cong-cu-khac`.
    - Fix quan trọng: classifier áp dụng theo `topic_lv2_key` (không chặn theo lv1) vì remap lv1 diễn ra sau `apply_topic_override`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công sau fix).
  - Kết quả:
    - Match theo plan: **13/13**
    - Coverage node `Excel - Công cụ khác`: **13/25**
    - Còn lại chưa gắn lv3 trong node: **12**
    - Coverage lv3 toàn thư viện: **1805/2039 (88.52%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-18a-input.csv`
    - Output: `.m/reclass/batch-05-18a-output.csv`
    - Notes: `.m/reclass/batch-05-18a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.18b):
  - Phạm vi:
    - Hoàn thiện 12 bài còn lại của node `Mẫu file & kê khai > Excel - Công cụ khác`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_excel_cong_cu_khac_level3`.
    - Mở rộng regex `so sach ke toan excel` để xóa fallback còn lại.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **12/12**
    - Coverage node `Excel - Công cụ khác`: **25/25**
    - Coverage lv3 toàn thư viện: **1817/2039 (89.11%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-18b-input.csv`
    - Output: `.m/reclass/batch-05-18b-output.csv`
    - Notes: `.m/reclass/batch-05-18b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.19a):
  - Phạm vi:
    - Node `Kế toán > Nghiệp vụ theo phần hành` (24 bài).
    - Batch 1: 12 bài đầu, gate theo cutoff slug `chi-phi-trang-phuc-cho-nhan-vien`.
  - Hành động:
    - Thêm classifier `classify_nghiep_vu_phan_hanh_level3`.
    - Thêm map label lv3 cho node `nghiep-vu-theo-phan-hanh`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **12/12**
    - Coverage node `Nghiệp vụ theo phần hành`: **12/24**
    - Còn lại chưa gắn lv3 trong node: **12**
    - Coverage lv3 toàn thư viện: **1829/2039 (89.7%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-19a-input.csv`
    - Output: `.m/reclass/batch-05-19a-output.csv`
    - Notes: `.m/reclass/batch-05-19a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.19b):
  - Phạm vi:
    - Hoàn thiện 12 bài còn lại của node `Kế toán > Nghiệp vụ theo phần hành`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_nghiep_vu_phan_hanh_level3`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **12/12**
    - Coverage node `Nghiệp vụ theo phần hành`: **24/24**
    - Coverage lv3 toàn thư viện: **1841/2039 (90.29%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-19b-input.csv`
    - Output: `.m/reclass/batch-05-19b-output.csv`
    - Notes: `.m/reclass/batch-05-19b-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.20a):
  - Phạm vi:
    - Node `Học liệu - Tham khảo > Kinh nghiệm - Hỏi đáp - Nghề nghiệp` (23 bài).
    - Batch 1: 12 bài đầu, gate theo cutoff slug `hoc-ke-toan-thuc-hanh-o-dau-tot-nhat-tai-ha-noi-va-tphcm`.
  - Hành động:
    - Thêm classifier `classify_kinh_nghiem_hoi_dap_level3`.
    - Thêm map label lv3 cho node `kinh-nghiem-hoi-dap-nghe-nghiep`.
    - Áp dụng gate cutoff để rollout theo batch con.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **12/12**
    - Coverage node `Kinh nghiệm - Hỏi đáp - Nghề nghiệp`: **12/23**
    - Còn lại chưa gắn lv3 trong node: **11**
    - Coverage lv3 toàn thư viện: **1853/2039 (90.88%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-20a-input.csv`
    - Output: `.m/reclass/batch-05-20a-output.csv`
    - Notes: `.m/reclass/batch-05-20a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.20b):
  - Phạm vi:
    - Hoàn thiện 11 bài còn lại của node `Học liệu - Tham khảo > Kinh nghiệm - Hỏi đáp - Nghề nghiệp`.
  - Hành động:
    - Bỏ gate cutoff batch 1 trong classifier `classify_kinh_nghiem_hoi_dap_level3`.
    - Điều chỉnh thứ tự regex trong classifier để ưu tiên nhóm `Kinh nghiệm/phỏng vấn/xin việc` trước nhóm mô tả công việc.
    - Đồng bộ lại plan file `batch-05-20b-input.csv` theo output classifier sau khi đổi thứ tự regex.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **11/11**
    - Coverage node `Kinh nghiệm - Hỏi đáp - Nghề nghiệp`: **23/23**
    - Coverage lv3 toàn thư viện: **1864/2039 (91.42%)**
    - Số gap node lớn còn lại (tot>=20, thiếu lv3): **0**
  - Artifact:
    - Input: `.m/reclass/batch-05-20b-input.csv`
    - Output: `.m/reclass/batch-05-20b-output.csv`
    - Notes: `.m/reclass/batch-05-20b-notes.md`
- 2026-04-07 (Phase 6 - QA snapshot):
  - Hành động:
    - Tạo queue review thủ công 50 bài: `.m/reclass/phase6-qa-queue.csv`.
    - Tạo snapshot coverage theo node: `.m/reclass/phase6-node-coverage.json`.
    - Tạo báo cáo nhanh QA: `.m/reclass/phase6-qa-summary.md`.
  - Kết quả nhanh:
    - Coverage lv3 toàn Thư viện: **1864/2039 (91.42%)**.
    - Số node lớn còn thiếu lv3 (`total>=20`, `missing>0`): **0**.
    - Vẫn còn các node nhỏ/chuyên biệt chưa gán lv3 (ưu tiên review thủ công theo queue).
- 2026-04-07 (Phase 6 - QA review queue 50):
  - Hành động:
    - Review queue 50 bài và xuất kết quả: `.m/reclass/phase6-qa-review-results.csv`.
    - Tinh chỉnh classifier `kinh-nghiem-hoi-dap-nghe-nghiep` để giảm drift:
      - Ưu tiên nhánh `học nghề/tập nghề`, `quyết toán/than tra thuế`, `kinh nghiệm/phỏng vấn/xin việc`.
      - Mở rộng nhánh đào tạo: `trung tam .* ke toan`.
      - Thu hẹp nhánh mô tả công việc: regex `cong viec`.
    - Rebuild full và kiểm tra lại phân phối node trọng điểm.
  - Kết quả:
    - Queue QA: **50 pass / 0 fail** theo heuristic review.
    - Node `Kinh nghiệm - Hỏi đáp - Nghề nghiệp`: **23/23** có lv3.
    - Phân phối mới:
      - `Kinh nghiệm/phỏng vấn/xin việc`: 8
      - `Mô tả công việc kế toán`: 11
      - `Học và đào tạo kế toán`: 3
      - `Học nghề và thực tập`: 1
    - Coverage lv3 toàn thư viện giữ **1864/2039 (91.42%)**.
  - Artifact:
    - Queue: `.m/reclass/phase6-qa-queue.csv`
    - Review results: `.m/reclass/phase6-qa-review-results.csv`
    - Summary: `.m/reclass/phase6-qa-summary.md`
    - Review notes: `.m/reclass/phase6-qa-review-notes.md`
    - Coverage json: `.m/reclass/phase6-node-coverage.json`
- 2026-04-07 (Phase 6 - Freeze proposal):
  - Hành động:
    - Tổng hợp toàn bộ node chưa có lv3 và phân nhóm:
      - `keep blank by design` (node pháp lý/tra cứu)
      - `backlog extension` (node nghiệp vụ/học liệu/phần mềm nhỏ).
    - Xuất đề xuất freeze + backlog tiếp theo.
  - Kết quả:
    - Còn **175 bài** chưa có lv3, nằm trong **27 node nhỏ/chuyên biệt**.
    - Không còn node thiếu lv3 có `total >= 20`.
  - Artifact:
    - Proposal: `.m/reclass/phase6-taxonomy-freeze-proposal.md`
- 2026-04-07 (Phase 6 - Freeze policy draft + backlog):
  - Hành động:
    - Xuất policy draft chốt hướng freeze: `.m/reclass/phase6-freeze-policy.md`.
    - Xuất roadmap backlog Phase 5.21+: `.m/reclass/phase5-21plus-roadmap.md`.
  - Nội dung cốt lõi:
    - Policy A (giữ trống lv3 có chủ đích) cho node pháp lý/tra cứu.
    - Policy B (tiếp tục mở rộng lv3) cho node học liệu/nghiệp vụ có giá trị biên tập.
    - Đề xuất bắt đầu ngay 5.21a với node `Báo cáo thực tập` (16 bài).
- 2026-04-07 (Phase 5 - Batch 5.21a):
  - Phạm vi:
    - Node `Học liệu - Tham khảo > Báo cáo thực tập` (16 bài).
    - Rollout 1 batch duy nhất.
  - Hành động:
    - Thêm classifier `classify_bao_cao_thuc_tap_level3`.
    - Thêm map label lv3 cho node `bao-cao-thuc-tap`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **16/16**
    - Coverage node `Báo cáo thực tập`: **16/16**
    - Coverage lv3 toàn thư viện: **1880/2039 (92.2%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-21a-input.csv`
    - Output: `.m/reclass/batch-05-21a-output.csv`
    - Notes: `.m/reclass/batch-05-21a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.22a):
  - Phạm vi:
    - Node `Doanh nghiệp - Thủ tục > DN - Thủ tục` (15 bài).
    - Rollout 1 batch duy nhất.
  - Hành động:
    - Thêm classifier `classify_thu_tuc_doanh_nghiep_level3`.
    - Thêm map label lv3 cho node `thu-tuc-doanh-nghiep`.
  - Rebuild full: `python3 ../.m/build_sample_sections.py --mode full` (thành công).
  - Kết quả:
    - Match theo plan: **15/15**
    - Coverage node `DN - Thủ tục`: **15/15**
    - Coverage lv3 toàn thư viện: **1895/2039 (92.94%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-22a-input.csv`
    - Output: `.m/reclass/batch-05-22a-output.csv`
    - Notes: `.m/reclass/batch-05-22a-notes.md`
- 2026-04-07 (Phase 5 - Batch 5.23a):
  - Phạm vi:
    - Node `Học liệu - Tham khảo > Bài tập - Thực hành` (9 bài).
    - Rollout 1 batch duy nhất.
  - Hành động:
    - Thêm classifier `classify_bai_tap_thuc_hanh_level3`.
    - Thêm map label lv3 cho node `bai-tap-thuc-hanh`.
  - Rebuild full:
    - Chạy build full nền (background) do runtime dài hơn timeout gọi trực tiếp.
    - Kết quả build thành công và xác minh output theo batch.
  - Kết quả:
    - Match theo plan: **9/9**
    - Coverage node `Bài tập - Thực hành`: **9/9**
    - Coverage lv3 toàn thư viện: **1904/2039 (93.38%)**
  - Artifact:
    - Input: `.m/reclass/batch-05-23a-input.csv`
    - Output: `.m/reclass/batch-05-23a-output.csv`
    - Notes: `.m/reclass/batch-05-23a-notes.md`

---

## 8) Next action khi user nói “Ok continue”

Thực thi **Giai đoạn 5** theo đúng thứ tự:

1) Chốt policy freeze (A/B hybrid) theo file `.m/reclass/phase6-freeze-policy.md`  
2) Quyết định có mở rộng tiếp các node thuế nghiệp vụ còn trống lv3: `MST`, `Lệ phí môn bài`, `Hộ/CNKD`, `Thuế nhà thầu`  
3) Nếu mở rộng: tạo Phase 5.24a+ theo từng node nhỏ (10–11 bài/node)  
4) Nếu không mở rộng: lock rule set + tổng kết cuối kỳ
