# Chính sách phân loại nội dung: Thư viện / Bản tin

## 1) Kết luận chốt

Taxonomy public của site được chốt theo **2 menu lớn**:

- **Thư viện**
- **Bản tin**

Không dùng 3 menu công khai kiểu:

- Kiến thức
- Tài liệu
- Bản tin

vì mô hình 3 mục này dễ gây nhập nhằng khi thêm bài mới.

## 2) Ý nghĩa của 2 menu

### Thư viện

Đây là nơi chứa nội dung có giá trị **tra cứu và sử dụng lâu dài**.

Bao gồm:

- bài hướng dẫn
- bài giải thích nghiệp vụ
- bài thực hành / bài tập
- biểu mẫu
- file dùng ngay
- công cụ / phần mềm / tài nguyên

Nói ngắn gọn:

> **Thư viện = nội dung evergreen để học, tra cứu, áp dụng hoặc dùng lại**

### Bản tin

Đây là nơi chứa nội dung có giá trị **cập nhật và theo dõi thay đổi**.

Bao gồm:

- công văn đáng chú ý
- điểm mới chính sách
- bài cập nhật thay đổi luật / nghị định / thông tư
- bài bãi bỏ / tiếp tục giảm / điều chỉnh quy định

Nói ngắn gọn:

> **Bản tin = nội dung để biết có gì mới, có gì đổi, có gì cần lưu ý**

## 3) Phân tầng bên trong Thư viện

Top-level chỉ có 2 menu, nhưng bên trong `Thư viện` vẫn cần chia intent để:

- filter
- related content
- QA khi import

Các nhóm nội bộ hiện tại:

- **Hướng dẫn**
- **Biểu mẫu**
- **Công cụ**

### Level 2 và Level 3 đang dùng cho user

#### Hướng dẫn
- **Level 2**: nhóm nghiệp vụ chính theo `topic_lv1 / topic_lv2`
- **Level 3**: các nhánh con trong `Filters`

#### Biểu mẫu
- **Level 2**:
  - Mẫu biểu kế toán
  - Mẫu biểu thuế - hóa đơn
  - Mẫu biểu doanh nghiệp - thủ tục
  - Mẫu biểu lao động - bảo hiểm
- **Level 3**:
  - sinh từ tags hữu ích trong từng nhóm level 2
  - ví dụ: `TSCĐ`, `BCTC`, `CCDC`, `Khấu hao`, `Tiền lương`, `GTGT`, `TNCN`, `BHXH`...
- không dùng các tag quá chung làm level 3 như:
  - `Biểu mẫu`
  - `Mẫu biểu`
  - `Thủ tục`

#### Công cụ
- **Level 2**:
  - HTKK - eTax - Thuế điện tử
  - Excel - Công cụ khác
  - FAST
- **Level 3** hiện tại:
  - `HTKK - eTax - Thuế điện tử`
    - Cài đặt
    - Nâng cấp
    - Kê khai
    - Nộp tờ khai
    - Quyết toán
    - Đăng ký thuế
    - Hoàn thuế
    - Tải về
    - Lỗi thường gặp
  - `Excel - Công cụ khác`
    - Hàm Excel
    - Mẫu file
    - Thực hành
    - Thuế
    - TSCĐ / CCDC
    - Tiền lương
    - Báo cáo
  - `FAST`
    - Cài đặt
    - Tải về
    - Sử dụng

## 4) Rule quyết định rất ngắn

### Đưa vào `Thư viện` nếu:

- user đọc để **học / hiểu / làm theo**
- user cần **mẫu, biểu, file** để dùng lại
- user cần **công cụ / phần mềm / tài nguyên**
- nội dung vẫn còn giá trị tra cứu lâu dài sau khi qua “độ nóng”

### Đưa vào `Bản tin` nếu:

- user đọc để **nắm thay đổi mới**
- bài có tính **điểm mới / cảnh báo / cập nhật / thay đổi chính sách**
- bài thiên về “có gì vừa ban hành / vừa sửa / vừa ảnh hưởng”

## 5) Ví dụ điển hình

### `Thư viện`

- `Cách hạch toán bán hàng có khuyến mại trên Misa`
- `Mẫu bảng chấm công theo thông tư 200 và TT 133`
- `Bài tập định khoản kế toán có lời giải`
- `Tải phần mềm kế toán Fast Accounting dùng thử miễn phí`

### `Bản tin`

- `Bãi bỏ lệ phí môn bài từ năm 2026 theo Nghị quyết 198/2025/QH15`
- `Các công văn đáng chú ý về hóa đơn điện tử mới nhất năm 2025`
- `Các điểm mới về đối tượng đóng BHXH, BHYT bắt buộc từ ngày 1/7/2025`
- `Những điểm mới của Thông tư 119/2014/TT-BTC`

## 6) Bài có thể mang nhiều intent không?

**Có.**

Ví dụ:

- `Mẫu bảng cân đối tài khoản theo Thông tư 133 – Cách lập`

Bài này vừa:

- có tính **Biểu mẫu**
- vừa có tính **Hướng dẫn**

Nhưng về mặt public IA:

- vẫn chỉ có **1 primary top-level**
- nằm trong **Thư viện**

### Có bài vừa là Thư viện vừa là Bản tin không?

**Có thể có về mặt intent**, nhưng:

- chỉ giữ **1 primary section**
- nếu bài thiên cập nhật → `Bản tin`
- nếu bài thiên tra cứu lâu dài → `Thư viện`

Không tạo 2 URL thật cho cùng một bài.

## 7) Triển khai kỹ thuật hiện tại

Logic hiện tại nằm trong:

- `.m/build_sample_sections.py`

Nó có 2 lớp:

### Lớp 1 — classifier cũ theo 3 ý định nội dung

- `kien-thuc`
- `tai-lieu`
- `ban-tin`

Lớp này vẫn được giữ như **internal scoring model**

### Lớp 2 — map sang taxonomy public mới

- nếu ý định chính là `ban-tin` → public section = `ban-tin`
- còn lại → public section = `thu-vien`

Đồng thời sinh thêm:

- `libraryKindKey`
- `libraryKindLabel`
- `primarySection`
- `secondarySections`
- `legacyPrimarySection`

## 8) Nguyên tắc SEO / URL

- chỉ có **1 URL canonical**
- chỉ có **1 menu public chính**
- `secondarySections` chỉ dùng cho:
  - related
  - filter
  - QA
  - audit

## 9) Tool audit để kiểm tra lại toàn kho

```bash
python3 Ketoandieutam.com/tools/audit_content_classification.py
```

Report sinh ra tại:

- `content-classification-audit.md`

## 10) Khi nào cần chỉnh lại rule?

Chỉ chỉnh khi thấy một trong 3 tình huống:

1. cùng một pattern title bị xếp sai hàng loạt
2. người vận hành không đoán nổi bài mới nên vào menu nào
3. user đọc menu nhưng không dự đoán được loại nội dung bên trong

Khi chỉnh:

1. sửa rule chung trong builder
2. rebuild
3. chạy lại audit
4. đọc lại report trước khi chốt

---

Tóm lại:

- **Thư viện** = học / tra cứu / dùng lâu dài
- **Bản tin** = theo dõi thay đổi / điểm mới / chính sách

Đây là taxonomy public đơn giản hơn cho user, và cũng dễ vận hành hơn khi thêm bài mới.
