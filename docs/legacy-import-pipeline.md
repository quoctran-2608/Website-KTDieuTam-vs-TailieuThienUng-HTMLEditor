# Quy trình chuẩn import 2066 bài legacy vào `Ketoandieutam.com`

> Tài liệu này mô tả **pipeline vận hành chuẩn** để đưa hơn 2000 bài từ kho legacy vào `Ketoandieutam.com` một cách có kiểm soát, không làm kiểu vá tay từng bài.

## 1) Mục tiêu

Pipeline này dùng để:

1. scan trước toàn bộ kho nguồn
2. phát hiện rủi ro responsive/mobile
3. chuẩn hoá nội dung legacy trước khi xuất bản
4. import theo lô, có log, có report, có thứ tự ưu tiên QA

## 2) Phạm vi dữ liệu

- Nguồn chuẩn để quyết định phạm vi import:
  - `TailieuKeToanThienUng/index.html`
  - block `catalog-data`
- Tổng bài theo catalog hiện tại: **2066**
- Phân bổ dự kiến theo taxonomy public mới:
  - `Thư viện`: **1823**
  - `Bản tin`: **243**

## 3) Tool hiện có

### 3.1 Audit mobile legacy

- Tool: `tools/audit_legacy_mobile_readiness.py`
- Command:

```bash
python3 Ketoandieutam.com/tools/audit_legacy_mobile_readiness.py
```

- Output:
  - `docs/legacy-mobile-import-audit.md`

### 3.2 Audit phân loại nội dung

- Tool: `tools/audit_content_classification.py`
- Command:

```bash
python3 Ketoandieutam.com/tools/audit_content_classification.py
```

- Output:
  - `docs/content-classification-audit.md`

### 3.3 Build site

- Tool: `.m/build_sample_sections.py`
- Command:

```bash
python3 .m/build_sample_sections.py --mode sample
python3 .m/build_sample_sections.py --mode full
```

- Output chính:
  - HTML article/hub
  - `content-index.js`
  - `data/`
  - `sitemap.xml`
  - `robots.txt`
  - đối chiếu rule tại `docs/content-classification-policy.md`

### 3.3 Runtime safety net đang có sẵn trong site

- `article-layout.js`
- `assets/css/content-hub.css`

Hai file này hiện đã có các lớp bảo vệ:

- ép ảnh co theo màn hình
- gỡ width cứng của HTML legacy khi render
- clamp `margin-left` quá lớn trên mobile
- ép wrap text trong bảng
- card hóa bảng phức tạp trên mobile

> Lưu ý: đây là **lớp chặn cuối**, không phải lý do để bỏ qua bước sanitize lúc import.

## 4) Contract của pipeline

### 4.1 Input

- catalog nguồn trong `TailieuKeToanThienUng/index.html`
- file HTML gốc tương ứng trong `TailieuKeToanThienUng/`
- asset nguồn được tham chiếu trong nội dung

### 4.2 Output

- article HTML trong:
  - `thu-vien/`
  - `ban-tin/`
- hub page:
  - `thu-vien.html`
  - `ban-tin.html`
  - các page phân trang trong `/<section>/trang/<n>/index.html`
- ảnh bài viết trong:
  - `assets/images/content/`
- report/docs:
  - `docs/legacy-mobile-import-audit.md`
  - báo cáo batch import tương ứng

### 4.3 Failure mode cần theo dõi

- thiếu file nguồn trong catalog
- asset bị thiếu
- article có bảng quá rộng / quá nhiều width px inline
- HTML cũ chứa inline style gây tràn ngang
- URL trong nội dung trỏ sai sau khi đổi cấu trúc
- mobile spacing / overflow bị regress

### 4.4 Side effects

- tạo hoặc ghi đè file HTML trong 2 menu public
- copy asset vào `assets/images/content/`
- cập nhật `content-index.js`
- cập nhật report trong `docs/`

### 4.5 Quyền cần có

- read: kho nguồn legacy
- write: toàn bộ thư mục `Ketoandieutam.com/`
- execute: Python/Node để scan, build, verify

## 5) Risk snapshot hiện tại

Theo report audit mới nhất:

- bài có bảng: **1170**
- bài có bảng `>= 3 cột`: **839**
- bài có `width px inline`: **1948**
- bài có `margin-left inline`: **946**
- bài có ảnh width cứng: **1770**

Phân bổ risk theo section:

| Menu lớn | Critical | High | Medium | Low |
|---|---:|---:|---:|---:|
| Thư viện | 266 | 803 | 659 | 95 |
| Bản tin | 45 | 69 | 113 | 16 |

## 6) Nguyên tắc vận hành

1. **Không import full một phát rồi mới test.**
2. **Không sửa tay từng bài trước khi có rule chung.**
3. **Ưu tiên build-time sanitizer**, runtime chỉ là lớp bảo hiểm.
4. **Batch nhỏ trước, batch lớn sau.**
5. **QA theo risk bucket**, không test ngẫu nhiên.

## 7) Pipeline chuẩn đề xuất

## Bước A — Chốt baseline trước khi import

Checklist:

- [ ] `site-shell.js` ổn
- [ ] `article-layout.js` ổn
- [ ] `assets/css/styles.css` ổn
- [ ] `assets/css/content-hub.css` ổn
- [ ] responsive hub/article không còn lỗi header/menu
- [ ] runtime safety net cho bài legacy đang hoạt động

Mục tiêu:

- giao diện đích phải ổn trước
- tránh vừa import vừa sửa template nền

## Bước B — Chạy audit toàn kho nguồn

Command:

```bash
python3 Ketoandieutam.com/tools/audit_legacy_mobile_readiness.py
python3 Ketoandieutam.com/tools/audit_content_classification.py
```

Phải kiểm tra các phần sau trong report:

- top bài `critical`
- top bài nhiều bảng `>= 3 cột`
- top bài nhiều `width px`
- top bài `margin-left` lớn
- bài đổi primary section
- bài có nhiều intent (`primary + secondary`)

Mục đích:

- biết trước bài nào sẽ “vỡ” trên mobile
- biết trước bài nào dễ xếp sai menu
- lên batch theo rủi ro

## Bước C — Chia batch import

Khuyến nghị chia theo 4 vòng:

### Vòng 1 — pilot thật nhỏ

- 10–20 bài / section
- mix đủ loại:
  - không bảng
  - có bảng 2 cột
  - có bảng 3+ cột
  - bài có ảnh lớn
  - bài có margin-left cũ

### Vòng 2 — medium risk

- import nhóm `medium`
- loại trừ `critical`

### Vòng 3 — high

- import nhóm `high`
- QA mobile kỹ hơn

### Vòng 4 — critical

- import cuối cùng
- test tay theo danh sách ưu tiên trong report

> Không nên đưa `critical` vào vòng 1.

## Bước D — Sanitize nội dung lúc import

Đây là bước quan trọng nhất.

### D1. Rewrite link và asset

- đổi link nội bộ cũ về cấu trúc mới:
  - `/thu-vien/<slug>.html`
  - `/ban-tin/<slug>.html`
- không giữ song song folder/file public kiểu `kien-thuc/`, `tai-lieu/`
- nếu cần redirect URL cũ trong giai đoạn triển khai thật, xử lý ở tầng server/CDN hoặc hosting rule
- copy asset tham chiếu thật sự dùng vào:
  - `assets/images/content/`

### D2. Gỡ width cứng

Phải xóa hoặc rewrite:

- `width="..."`
- `width: ...px`
- `min-width: ...px`
- `max-width: ...px`

Áp dụng cho:

- `table`
- `td`
- `th`
- `img`
- `iframe`
- `embed`
- `object`
- `div`
- `span`

### D3. Chuẩn hóa ảnh

- `max-width: 100%`
- `height: auto`
- bỏ `height="..."` cứng nếu có

### D4. Hạ indent legacy

Clamp các giá trị kiểu:

- `margin-left: 40px`
- `margin-left: 80px`
- `margin-left: 160px`

Khuyến nghị:

- desktop: giữ nguyên nếu không phá bố cục
- mobile: clamp về **<= 16px**

### D5. Phân loại bảng

#### Bảng 2 cột đơn giản

Ví dụ:

- bảng “Quốc hội / Cộng hòa…”
- bảng ký tên
- bảng thông tin song song ngắn

=> giữ table thường trên desktop  
=> mobile vẫn **fit-to-viewport** nếu bảng rộng hơn khung

#### Bảng 3 cột trở lên

Không phải bảng nào nhiều cột cũng card hóa.

##### A. Bảng dữ liệu / bảng giải thích

Từ rule mới, **không card hóa nữa**.

=> giữ table, mobile **fit-to-viewport**

Ví dụ:

- bảng công thức
- bảng thuế suất
- bảng danh sách chỉ tiêu
- bảng so sánh / đối chiếu

##### B. Bảng biểu mẫu hẹp, nhiều cột nhỏ

Nhóm này phải **giữ gần bản gốc**, không card hóa.

Dấu hiệu nhận diện:

- có nhiều cột hẹp kiểu `31px`, `36px`, `48px`, `60px`, `72px`
- `maxCols >= 8`
- số lượng width nhỏ (`<= 72px`) đủ nhiều  
  - rule hiện tại: `smallCount >= 8`  
  - hoặc `smallCount >= 6` khi bảng có rất nhiều width inline (`>= 40`)
- thường là bảng lương, bảng chấm công, bảng kê, mẫu lao động - thuế

=> xử lý chuẩn:

- giữ width gốc của `table`
- **không xóa width inline của `td/th`**
- `table-layout: auto`
- với `td/th`:  
  - `word-break: normal`  
  - `overflow-wrap: normal`
- mobile: **fit-to-viewport** (co bảng vừa khung), không ưu tiên scroll ngang và không card hóa

### D5.1 Rule mobile hiện tại cho tất cả bảng

Trên mobile, mọi bảng trong bài đều ưu tiên:

- **fit-to-viewport**
- không dựng card stack
- có **safe fix** chống clip phần cuối sau khi scale

Nhóm bảng biểu mẫu hẹp ở mục D5-B sẽ được cộng thêm lớp preserve-source-width để nhìn gần bản gốc hơn.

### D6. Ép wrap text trong cell

Chỉ áp dụng mặc định cho bảng thường:

- `white-space: normal`
- `word-break: break-word`
- `overflow-wrap: anywhere`

Không áp dụng máy móc cho bảng biểu mẫu hẹp như mục D5-B.

## Bước E — Build article/hub

Khi build batch:

1. render article HTML
2. render hub theo section
3. render phân trang tĩnh
4. cập nhật `content-index.js`
5. copy asset
6. sinh report batch

Mỗi batch phải để lại dấu vết:

- số bài đã import
- số asset copy
- số asset thiếu
- danh sách file nguồn → file đích

## Bước F — QA sau mỗi batch

### F1. Smoke check bắt buộc

- [ ] mở hub desktop
- [ ] mở hub mobile
- [ ] mở 1 bài không bảng
- [ ] mở 1 bài có bảng 2 cột
- [ ] mở 1 bài có bảng 3+ cột
- [ ] kiểm tra thumbnail hub
- [ ] kiểm tra back-to-list
- [ ] kiểm tra sidebar / related / next-prev

### F2. QA mobile

Các lỗi phải check:

- còn cuộn ngang toàn trang không
- bảng đã card hóa đúng chưa
- text trong card có bị cắt không
- ảnh có bị vượt màn hình không
- breadcrumbs có dính header không
- hero có bị dính menu không

### F3. QA SEO/cấu trúc

- canonical đúng
- robots đúng
- page 2 / trang n đúng relative path
- asset path không quay về link cũ

## Bước G — Quy tắc go/no-go

### Cho phép đi tiếp nếu:

- batch hiện tại không còn overflow toàn trang trên mobile
- hub + article + asset path ổn
- report asset thiếu = 0 hoặc đã giải thích rõ

### Không đi tiếp nếu:

- còn lỗi cuộn ngang toàn trang
- nhiều bài mất ảnh
- link nội dung trỏ sai
- card hóa bảng làm mất dữ liệu

## 8) Thứ tự ưu tiên QA thực tế

Sau khi import full, test theo thứ tự:

1. tất cả bài `critical`
2. bài `high` có `bảng >= 3 cột`
3. bài có `max width >= 700px`
4. bài có `margin-left >= 80px`
5. bài file lớn `> 1 MB`

## 9) Mẫu run record cho mỗi lần import

Mỗi lần chạy batch nên lưu 1 record với cấu trúc này:

```md
# Import run record

- thời gian:
- người chạy:
- batch:
- section:
- số bài:
- source selector:
- command:
- report đầu vào:
- output:
- asset copied:
- asset missing:
- lỗi phát sinh:
- quyết định go/no-go:
- việc cần làm tiếp:
```

## 10) Cách dùng tài liệu này lần sau

Khi quay lại import 2000+ bài:

1. đọc `docs/legacy-mobile-import-audit.md`
2. đọc `docs/content-classification-audit.md`
3. đọc `docs/content-classification-policy.md`
4. đọc lại file này
5. chốt batch
6. sanitize khi import
7. QA theo risk bucket
8. chỉ sau khi batch ổn mới tăng quy mô

## 11) Kết luận

Muốn làm nhanh mà không vỡ:

- **scan trước**
- **sanitize khi import**
- **runtime safety net**
- **QA theo risk**

Nếu bỏ một trong bốn lớp này, rollout 2000+ bài rất dễ hỏng mobile, hỏng asset hoặc hỏng liên kết.
