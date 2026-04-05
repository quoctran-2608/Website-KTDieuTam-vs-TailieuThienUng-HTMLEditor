# Schema taxonomy canonical cho editor PHP

## Mục tiêu

Tạo một schema **ít field nhưng rõ vai** để editor PHP dễ dùng, ít gắn sai và dễ mở rộng.

---

## 1) Triết lý

- **Category tree** là taxonomy chính
- **Tags** là lớp phụ
- **Menu** map từ category ra UI, không đồng nhất hoàn toàn với taxonomy

---

## 2) Root categories

### Level 1

- `thu-vien`
- `ban-tin`

### Level 2 dưới `thu-vien`

- `huong-dan`
- `bieu-mau`
- `cong-cu`

### Level 2 dưới `ban-tin`

Đi theo chủ đề chính:

- `thue`
- `ke-toan`
- `lao-dong-bao-hiem`
- `doanh-nghiep-thu-tuc`

---

## 3) Canonical fields nên có trong editor

| Field | Kiểu | Bắt buộc | Ý nghĩa |
|---|---|---:|---|
| `primary_category_id` | string/int | Có | category canonical của bài |
| `secondary_category_ids[]` | array | Không | category phụ nếu thật sự cần |
| `tags[]` | array | Có | tags phụ cho search/filter/recommendation |

> Trong database/editor nên map `primary_category_id` ra cây category.

---

## 4) Mapping với dữ liệu hiện tại

| Dữ liệu hiện tại | Vai trò editor PHP |
|---|---|
| `section` | category root level 1 |
| `libraryKind` | category level 2 dưới `thu-vien` |
| `topicLv1` | domain nội bộ |
| `topicLv2` | subdomain nội bộ |
| `toolLv3` | variant level 3 cho `cong-cu` |
| `tags` | tags phụ |

---

## 5) Quy ước dùng trong editor

### Nếu bài thuộc `Thư viện`

Editor phải chọn:

1. `primary_category_id`
   - `thu-vien`
2. nhóm con:
   - `huong-dan`
   - `bieu-mau`
   - `cong-cu`
3. nhóm nghiệp vụ:
   - tương ứng `topicLv1`
4. nhóm con chi tiết:
   - tương ứng `topicLv2`
5. nếu là `cong-cu`:
   - chọn tiếp `toolLv3`

### Nếu bài thuộc `Bản tin`

Editor phải chọn:

1. `primary_category_id`
   - `ban-tin`
2. nhóm chủ đề:
   - tương ứng `topicLv1`
3. nhóm chi tiết:
   - tương ứng `topicLv2`

---

## 6) Rule tags

- tags không thay category
- tags chỉ dùng cho:
  - search
  - filters level sâu
  - related content
  - recommendation

### Không nên dùng làm tag

- các label quá chung:
  - `Biểu mẫu`
  - `Mẫu biểu`
  - `Thủ tục`
  - `Công cụ`
  - `Phần mềm`

---

## 7) Level 3 hiện tại cần cố định

### `Biểu mẫu`

Không dùng field riêng trong editor ngay lúc đầu.  
Level 3 hiện lấy từ tags hữu ích:

- `TSCĐ`
- `BCTC`
- `CCDC`
- `Khấu hao`
- `Tiền lương`
- `GTGT`
- `TNCN`
- `BHXH`

### `Công cụ`

Phải có field riêng `toolLv3`:

#### `HTKK - eTax - Thuế điện tử`
- `cai-dat`
- `nang-cap`
- `ke-khai`
- `nop-to-khai`
- `quyet-toan`
- `dang-ky-thue`
- `hoan-thue`
- `tai-ve`
- `loi-thuong-gap`

#### `Excel - Công cụ khác`
- `ham-excel`
- `mau-file`
- `thuc-hanh`
- `thue`
- `tscd-ccdc`
- `tien-luong`
- `bao-cao`

#### `FAST`
- `cai-dat`
- `tai-ve`
- `su-dung`

---

## 8) Recommendation cho UI editor

### Không nên
- hiện tất cả field cùng lúc

### Nên
- dùng form phụ thuộc:
  1. chọn root
  2. hiện field con tương ứng
  3. nếu là `cong-cu` mới hiện `toolLv3`

### Phải có
- preview breadcrumb
- preview badge card
- gợi ý tags tự động
- cảnh báo khi tags trùng với category quá chung

---

## 9) Artifact hỗ trợ

Builder hiện đã sinh:

- `data/articles.json`
- `data/hubs/*.json`
- `data/article-views/*.json`
- `data/taxonomy.json`
- `data/editor-taxonomy.json`
- `data/menu-config.json`

`data/taxonomy.json` nên được dùng làm nguồn cho:
- selector category tree trong editor PHP
- menu mapping UI
- validation taxonomy
