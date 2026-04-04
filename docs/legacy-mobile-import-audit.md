# Báo cáo audit mobile overflow cho kho bài legacy

- Thời điểm tạo: 2026-04-03 18:27:47
- Nguồn scan: `TailieuKeToanThienUng/index.html` (`catalog-data`)
- Script tạo báo cáo: `tools/audit_legacy_mobile_readiness.py`
- File report: `docs/legacy-mobile-import-audit.md`

## 1) Mục đích

Scan toàn bộ **2066 bài nguồn** trước khi nhập vào `Ketoandieutam.com`, để:

1. nhận diện sớm bài có nguy cơ **tràn ngang trên mobile**
2. gom bài theo mức độ rủi ro để **ưu tiên QA**
3. chốt **quy trình kỹ thuật chuẩn** cho lần import tiếp theo

## 2) Cách chạy lại

```bash
python3 Ketoandieutam.com/tools/audit_legacy_mobile_readiness.py
```

## 3) Snapshot tổng quan

| Chỉ số | Giá trị |
|---|---:|
| Tổng bài scan theo catalog | 2.066 |
| Bài có bảng | 1.170 |
| Bài có bảng >= 3 cột | 839 |
| Bài có width px inline | 1.948 |
| Bài có margin-left inline | 946 |
| Bài có ảnh width cứng | 1.770 |
| Critical | 311 |
| High | 872 |
| Medium | 772 |
| Low | 111 |

## 4) Cách đọc mức độ rủi ro

- **critical**: bài rất nặng hoặc có nhiều bảng phức tạp / width px cứng; cần QA tay sau import
- **high**: có nhiều width px, nhiều bảng >= 3 cột, margin-left lớn; cần test mobile sớm
- **medium**: có dấu hiệu mobile risk nhưng thường xử lý được bằng pipeline chuẩn
- **low**: gần như không có dấu hiệu overflow lớn

## 5) Giải pháp kỹ thuật chuẩn khi import

### 5.1 Build-time sanitizer là lớp xử lý chính

Khi nhập bài vào `Ketoandieutam.com`, nên chạy sanitizer **ngay lúc build** thay vì chờ runtime:

1. **Bỏ width cứng**
   - xóa `width="..."`
   - xóa hoặc rewrite `width/min-width/max-width: ...px`
   - áp dụng cho `table`, `td`, `th`, `img`, `iframe`, `embed`, `object`, `div`, `span`

2. **Chuẩn hóa ảnh**
   - `max-width: 100%`
   - `height: auto`
   - bỏ `height="..."` cứng nếu có

3. **Hạ indent legacy**
   - các `margin-left: 40px`, `80px`... nên clamp về mức nhỏ trên mobile
   - khuyến nghị: mobile không quá `16px`

4. **Phân loại bảng**
   - bảng **2 cột đơn giản**: giữ dạng table, mobile fit-to-viewport khi cần
   - bảng **>= 3 cột dạng dữ liệu**: giữ dạng table, mobile fit-to-viewport
   - bảng header pháp lý kiểu “Quốc hội / Cộng hòa...” thường là 2 cột borderless, không card hóa
   - bảng **biểu mẫu hẹp nhiều cột nhỏ** (`31px`, `36px`, `48px`, `60px`, `72px`...) phải xử lý theo hướng **preserve-source-width + fit-to-viewport**  
     - rule hiện tại: `maxCols >= 8` và `smallCount >= 8`  
     - hoặc `smallCount >= 6` nếu bảng có rất nhiều width inline (`>= 40`)

5. **Wrap text trong ô**
   - mặc định:
     - `white-space: normal`
     - `word-break: break-word`
     - `overflow-wrap: anywhere`
   - ngoại lệ với bảng biểu mẫu hẹp:
     - `word-break: normal`
     - `overflow-wrap: normal`

### 5.2 Runtime safety net là lớp chặn cuối

Hiện đã có lớp runtime trong:

- `article-layout.js`
- `assets/css/content-hub.css`

Các lớp này đang làm:

- normalize width cứng của HTML legacy
- clamp margin-left trên mobile
- ép bảng wrap cell
- mobile: **fit-to-viewport cho mọi bảng**
- với bảng biểu mẫu hẹp: giữ width gốc của table, giữ width cột nhỏ và fit bảng vào viewport mobile
- áp dụng **safe fix** để tránh wrapper tính thiếu chiều cao và cắt phần cuối bảng sau khi scale

### 5.3 Quy trình rollout khuyến nghị

1. import bài bằng build-time sanitizer
2. để runtime safety net tiếp tục bảo vệ
3. test thủ công theo thứ tự:
   - critical
   - high
   - medium có nhiều bảng

## 6) Danh sách ưu tiên xử lý cao nhất

| # | Mức | Chuyên mục gốc | File nguồn | Kích thước | Bảng | Bảng >= 3 cột | Width px | Max width | Margin-left max | Ghi chú |
|---:|---|---|---|---:|---:|---:|---:|---:|---:|---|
| 1 | critical | Mẫu biểu - Thủ tục | `mau-thuyet-minh-bao-cao-tai-chinh-theo-thong-tu-99.htm` | 770 KB | 110 | 110 | 4 | 555px | 0px | 110 bảng >= 3 cột; 4 style width px; 110 width attr; 1 ảnh width cứng |
| 2 | critical | Văn bản pháp luật | `thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-…` | 769 KB | 88 | 63 | 2123 | 871px | 0px | 63 bảng >= 3 cột; 2123 style width px; 77 width attr; 1 ảnh width cứng |
| 3 | critical | Kế toán | `cac-loai-so-nhat-ky-chung-tu-bang-ke-theo-thong-tu-99.htm` | 609 KB | 67 | 65 | 56 | 560px | 40px | 65 bảng >= 3 cột; 56 style width px; 58 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 4 | critical | Phần mềm - Công cụ | `cach-kiem-tra-doi-chieu-chung-tu-so-sach-ke-toan-tren-…` | 289 KB | 0 | 0 | 105 | 550px | 160px | 105 style width px; 105 ảnh width cứng; margin-left tối đa 160px |
| 5 | critical | Phần mềm - Công cụ | `phan-mem-ho-tro-ke-khai-thue-htkk-4-2-2-moi-nhat-2019.htm` | 191 KB | 34 | 34 | 738 | 560px | 0px | 34 bảng >= 3 cột; 738 style width px; 34 width attr; 4 ảnh width cứng |
| 6 | critical | Phần mềm - Công cụ | `mau-ban-thuyet-minh-bao-cao-tai-chinh-theo-qd-15-excel.htm` | 175 KB | 30 | 30 | 686 | 703px | 0px | 30 bảng >= 3 cột; 686 style width px; 29 width attr |
| 7 | critical | Mẫu biểu - Thủ tục | `mau-to-khai-dang-ky-thu-01-dk-tct.htm` | 123 KB | 32 | 22 | 188 | 631px | 80px | 22 bảng >= 3 cột; 188 style width px; 19 width attr; 5 ảnh width cứng; margin-left tối đa… |
| 8 | critical | Văn bản pháp luật | `thong-tu-04-vbhn-btc-van-ban-hop-nhat-ve-thue-tncn.htm` | 379 KB | 29 | 24 | 280 | 550px | 0px | 24 bảng >= 3 cột; 280 style width px; 1 width attr; 1 ảnh width cứng |
| 9 | critical | Văn bản pháp luật | `thong-tu-111-2013-tt-btc-huong-dan-thi-ha-nh-luat-thue-…` | 387 KB | 29 | 24 | 279 | 550px | 0px | 24 bảng >= 3 cột; 279 style width px; 4 width attr |
| 10 | critical | Văn bản pháp luật | `thong-tu-59-2015-bldtbxh-huong-dan-luat-bhxh-bat-buoc.htm` | 260 KB | 32 | 21 | 187 | 568px | 0px | 21 bảng >= 3 cột; 187 style width px; 4 width attr; 3 ảnh width cứng |
| 11 | critical | Văn bản pháp luật | `nghi-dinh-145-2020-nd-cp-huong-dan-thi-hanh-luat-lao-…` | 898 KB | 60 | 16 | 153 | 560px | 0px | 16 bảng >= 3 cột; 153 style width px; 25 width attr; 1 ảnh width cứng |
| 12 | critical | Văn bản pháp luật | `nghi-dinh-70-2025-nd-cp-sua-doi-nghi-dinh-123-ve-hoa-don-…` | 440 KB | 53 | 21 | 59 | 555px | 0px | 21 bảng >= 3 cột; 59 style width px; 53 width attr; 1 ảnh width cứng |
| 13 | critical | Mẫu biểu - Thủ tục | `mau-bieu-chung-tu-ke-toan-hang-ton-kho-theo-thong-tu-99.htm` | 108 KB | 21 | 18 | 453 | 555px | 40px | 18 bảng >= 3 cột; 453 style width px; 16 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 14 | critical | Văn bản pháp luật | `nghi-dinh-122-2020-nd-cp-phoi-hop-thu-tuc-thanh-lap-doanh-…` | 216 KB | 30 | 16 | 458 | 560px | 0px | 16 bảng >= 3 cột; 458 style width px; 30 width attr; 1 ảnh width cứng |
| 15 | critical | Mẫu biểu - Thủ tục | `mau-bao-cao-tai-chinh-giua-nien-do-theo-thong-tu-99.htm` | 55 KB | 25 | 23 | 13 | 555px | 0px | 23 bảng >= 3 cột; 13 style width px; 29 width attr; 1 ảnh width cứng |
| 16 | critical | Văn bản pháp luật | `nghi-dinh-92-2021-nd-cp-giam-thue-tndn-gtgt-nam-2021.htm` | 769 KB | 33 | 14 | 181 | 560px | 0px | 14 bảng >= 3 cột; 181 style width px; 7 width attr; 1 ảnh width cứng |
| 17 | critical | Học liệu - Tham khảo | `dang-ky-thanh-lap-chi-nhanh-vpdd-dia-diem-kinh-doanh.htm` | 180 KB | 41 | 19 | 19 | 619px | 40px | 19 bảng >= 3 cột; 19 style width px; 43 width attr; margin-left tối đa 40px |
| 18 | critical | Thuế - Hóa đơn | `cach-tinh-thue-thu-nhap-ca-nhan-theo-luong-net.htm` | 102 KB | 16 | 16 | 218 | 742px | 80px | 16 bảng >= 3 cột; 218 style width px; 7 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 19 | critical | Văn bản pháp luật | `thong-tu-88-che-do-ke-toan-cho-ca-nhan-ho-kinh-doanh.htm` | 239 KB | 39 | 17 | 47 | 560px | 0px | 17 bảng >= 3 cột; 47 style width px; 37 width attr; 1 ảnh width cứng |
| 20 | critical | Mẫu biểu - Thủ tục | `mau-bieu-chung-tu-ke-toan-tien-luong-theo-thong-tu-99.htm` | 134 KB | 22 | 19 | 17 | 555px | 40px | 19 bảng >= 3 cột; 17 style width px; 22 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 21 | critical | Mẫu biểu - Thủ tục | `mau-04-dk-tct-to-khai-dang-ky-thue-nha-thau.htm` | 78 KB | 30 | 9 | 170 | 718px | 40px | 9 bảng >= 3 cột; 170 style width px; 5 width attr; 3 ảnh width cứng; margin-left tối đa… |
| 22 | critical | Mẫu biểu - Thủ tục | `mau-giay-uy-quyen-dang-ky-thue.htm` | 62 KB | 18 | 10 | 175 | 697px | 320px | 10 bảng >= 3 cột; 175 style width px; 14 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 23 | critical | Văn bản pháp luật | `nghi-dinh-47-2021-nd-cp-huong-dan-luat-doanh-nghiep.htm` | 225 KB | 25 | 15 | 60 | 560px | 0px | 15 bảng >= 3 cột; 60 style width px; 15 width attr; 1 ảnh width cứng |
| 24 | critical | Văn bản pháp luật | `nghi-dinh-188-2025-nd-cp-huong-dan-luat-bao-hiem-y-te.htm` | 423 KB | 31 | 14 | 51 | 555px | 40px | 14 bảng >= 3 cột; 51 style width px; 31 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 25 | critical | Phần mềm - Công cụ | `phan-mem-ho-tro-ke-khai-thue-htkk-4-2-0-moi-nhat-2019.htm` | 122 KB | 16 | 16 | 20 | 560px | 0px | 16 bảng >= 3 cột; 20 style width px; 16 width attr; 4 ảnh width cứng |
| 26 | critical | Mẫu biểu - Thủ tục | `to-khai-thue-thu-nhap-ca-nhan-theo-tt-119.htm` | 61 KB | 16 | 8 | 187 | 699px | 0px | 8 bảng >= 3 cột; 187 style width px; 17 width attr; 6 ảnh width cứng |
| 27 | critical | Mẫu biểu - Thủ tục | `mau-01-vtnn-to-khai-quyet-toan-thue-tndn-doi-voi-hang-van-…` | 85 KB | 17 | 9 | 302 | 1002px | 0px | 9 bảng >= 3 cột; 302 style width px; 8 width attr; 1 ảnh width cứng |
| 28 | critical | Mẫu biểu - Thủ tục | `mau-chung-tu-ke-toan-tscd-theo-thong-tu-99.htm` | 106 KB | 19 | 15 | 25 | 555px | 40px | 15 bảng >= 3 cột; 25 style width px; 14 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 29 | critical | Lao động - Bảo hiểm | `boi-thuong-tro-cap-tai-nan-lao-dong-benh-nghe-nghiep.htm` | 209 KB | 16 | 9 | 522 | 555px | 80px | 9 bảng >= 3 cột; 522 style width px; 4 width attr; 1 ảnh width cứng; margin-left tối đa… |
| 30 | critical | Văn bản pháp luật | `quyet-dinh-919-qd-bhxh-ngay-26-8-2015-cua-bhxh-viet-nam.htm` | 328 KB | 17 | 9 | 1354 | 550px | 0px | 9 bảng >= 3 cột; 1354 style width px; 1 ảnh width cứng |
| 31 | critical | Phần mềm - Công cụ | `phan-mem-ho-tro-ke-khai-thue-htkk-4-1-9-moi-nhat-2019.htm` | 70 KB | 9 | 9 | 202 | 560px | 0px | 9 bảng >= 3 cột; 202 style width px; 9 width attr; 4 ảnh width cứng |
| 32 | critical | Thuế - Hóa đơn | `cach-lap-hoa-don-dieu-chinh-tang-giam.htm` | 131 KB | 10 | 8 | 98 | 744px | 80px | 8 bảng >= 3 cột; 98 style width px; 8 width attr; 10 ảnh width cứng; margin-left tối đa… |
| 33 | critical | Văn bản pháp luật | `thong-tu-92-2015-quy-dinh-ve-thue-gtgt-tncn-doi-voi-ca-…` | 237 KB | 11 | 9 | 244 | 577px | 0px | 9 bảng >= 3 cột; 244 style width px; 1 width attr; 2 ảnh width cứng |
| 34 | critical | Thuế - Hóa đơn | `cach-lap-to-khai-thue-nha-thau-mau-01-ntnn.htm` | 55 KB | 11 | 10 | 107 | 636px | 0px | 10 bảng >= 3 cột; 107 style width px; 9 width attr; 4 ảnh width cứng |
| 35 | critical | Thuế - Hóa đơn | `cach-lap-hoa-don-chiet-khau-thuong-mai.htm` | 66 KB | 8 | 7 | 187 | 718px | 40px | 7 bảng >= 3 cột; 187 style width px; 5 width attr; 4 ảnh width cứng; margin-left tối đa… |
| 36 | critical | Phần mềm - Công cụ | `cach-hach-toan-ban-hang-co-khuyen-mai-tren-misa.htm` | 71 KB | 0 | 0 | 37 | 550px | 80px | 37 style width px; 37 ảnh width cứng; margin-left tối đa 80px |
| 37 | critical | Mẫu biểu - Thủ tục | `giay-de-nghi-cong-bo-noi-dung-dang-ky-doanh-nghiep.htm` | 69 KB | 14 | 10 | 121 | 555px | 40px | 10 bảng >= 3 cột; 121 style width px; 12 width attr; margin-left tối đa 40px |
| 38 | critical | Mẫu biểu - Thủ tục | `mau-c70a-hsb-danh-sach-giai-quyet-huong-che-do-thai-san-om-…` | 436 KB | 7 | 7 | 1904 | 1433px | 80px | 7 bảng >= 3 cột; 1904 style width px; 5 width attr; margin-left tối đa 80px |
| 39 | critical | Văn bản pháp luật | `thong-tu-18-vbhn-btc-van-ban-hop-nhat-ve-quan-ly-thue.htm` | 934 KB | 11 | 9 | 139 | 550px | 0px | 9 bảng >= 3 cột; 139 style width px; 1 width attr; 1 ảnh width cứng |
| 40 | critical | Mẫu biểu - Thủ tục | `mau-phu-luc-ii-1-thay-doi-noi-dung-dang-ky-doanh-nghiep.htm` | 65 KB | 13 | 7 | 176 | 624px | 0px | 7 bảng >= 3 cột; 176 style width px; 10 width attr; 1 ảnh width cứng |

## 7) Nhóm bài cần QA sớm nhất

### Critical
- **Mẫu thuyết minh Báo cáo tài chính theo Thông tư 99** (`mau-thuyet-minh-bao-cao-tai-chinh-theo-thong-tu-99.htm`) — critical, score 1569; bảng=110, bảng>=3 cột=110, width px=4, max width=555px, margin-left max=0px.
- **Thông tư 132/2018/TT-BTC Chế độ kế toán Doanh nghiệp siêu nhỏ** (`thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.htm`) — critical, score 1049; bảng=88, bảng>=3 cột=63, width px=2123, max width=871px, margin-left max=0px.
- **Các loại sổ Nhật ký - Chứng từ, Bảng kê theo hình thức Nhật ký - Chứng từ** (`cac-loai-so-nhat-ky-chung-tu-bang-ke-theo-thong-tu-99.htm`) — critical, score 970; bảng=67, bảng>=3 cột=65, width px=56, max width=560px, margin-left max=40px.
- **Cách kiểm tra đối chiếu chứng từ sổ sách kế toán trên Misa** (`cach-kiem-tra-doi-chieu-chung-tu-so-sach-ke-toan-tren-misa.htm`) — critical, score 604; bảng=0, bảng>=3 cột=0, width px=105, max width=550px, margin-left max=160px.
- **Phần mềm hỗ trợ kê khai thuế HTKK 4.2.2 mới nhất 2019** (`phan-mem-ho-tro-ke-khai-thue-htkk-4-2-2-moi-nhat-2019.htm`) — critical, score 593; bảng=34, bảng>=3 cột=34, width px=738, max width=560px, margin-left max=0px.
- **Mẫu Bản Thuyết minh Báo cáo tài chính theo QĐ 15 Excel** (`mau-ban-thuyet-minh-bao-cao-tai-chinh-theo-qd-15-excel.htm`) — critical, score 521; bảng=30, bảng>=3 cột=30, width px=686, max width=703px, margin-left max=0px.
- **Mẫu 01-ĐK-TCT Tờ khai đăng ký thuế theo thông tư 86/2024/TT-BTC** (`mau-to-khai-dang-ky-thu-01-dk-tct.htm`) — critical, score 460; bảng=32, bảng>=3 cột=22, width px=188, max width=631px, margin-left max=80px.
- **Thông tư 04/VBHN-BTC văn bản hợp nhất về thuế TNCN** (`thong-tu-04-vbhn-btc-van-ban-hop-nhat-ve-thue-tncn.htm`) — critical, score 448; bảng=29, bảng>=3 cột=24, width px=280, max width=550px, margin-left max=0px.
- **Thông tư 111/2013/TT-BTC hướng dẫn thi hành Luật thuế TNCN** (`thong-tu-111-2013-tt-btc-huong-dan-thi-ha-nh-luat-thue-tncn.htm`) — critical, score 443; bảng=29, bảng>=3 cột=24, width px=279, max width=550px, margin-left max=0px.
- **Thông tư 59/2015/TT-BLĐTBXH hướng dẫn Luật BHXH bắt buộc** (`thong-tu-59-2015-bldtbxh-huong-dan-luat-bhxh-bat-buoc.htm`) — critical, score 428; bảng=32, bảng>=3 cột=21, width px=187, max width=568px, margin-left max=0px.
- **Nghị định 145/2020/NĐ-CP hướng dẫn thi hành Luật lao động** (`nghi-dinh-145-2020-nd-cp-huong-dan-thi-hanh-luat-lao-dong.htm`) — critical, score 408; bảng=60, bảng>=3 cột=16, width px=153, max width=560px, margin-left max=0px.
- **Nghị định 70/2025/NĐ-CP sửa đổi Nghị định 123 về hóa đơn điện tử** (`nghi-dinh-70-2025-nd-cp-sua-doi-nghi-dinh-123-ve-hoa-don-dien-tu.htm`) — critical, score 405; bảng=53, bảng>=3 cột=21, width px=59, max width=555px, margin-left max=0px.
- **Mẫu biểu chứng từ kế toán hàng tồn kho theo Thông tư 99** (`mau-bieu-chung-tu-ke-toan-hang-ton-kho-theo-thong-tu-99.htm`) — critical, score 364; bảng=21, bảng>=3 cột=18, width px=453, max width=555px, margin-left max=40px.
- **Nghị định 122/2020/NĐ-CP phổi hợp thủ tục đăng ký thành lập doanh nghiệp** (`nghi-dinh-122-2020-nd-cp-phoi-hop-thu-tuc-thanh-lap-doanh-nghiep.htm`) — critical, score 354; bảng=30, bảng>=3 cột=16, width px=458, max width=560px, margin-left max=0px.
- **Mẫu Báo cáo tài chính giữa niên độ theo Thông tư 99** (`mau-bao-cao-tai-chinh-giua-nien-do-theo-thong-tu-99.htm`) — critical, score 353; bảng=25, bảng>=3 cột=23, width px=13, max width=555px, margin-left max=0px.

### High
- **Thông tư 26/2015/TT-BTC hướng dẫn về thuế GTGT và quản lý thuế** (`thong-tu-26-2015-tt-btc-huong-dan-thue-gtgt-va-quan-ly-thue.htm`) — high, score 95; bảng=6, bảng>=3 cột=4, width px=30, max width=576px, margin-left max=0px.
- **Cách chuyển lỗ khi tạm tính, quyết toán thuế TNDN 2026** (`cach-chuyen-lo-khi-tinh-quyet-toan-thue-tndn-sang-cac-nam-sau.htm`) — high, score 95; bảng=3, bảng>=3 cột=3, width px=53, max width=555px, margin-left max=80px.
- **Xử lý sự cố đối với hóa đơn điện tử có mã của cơ quan thuế** (`xu-ly-su-co-doi-voi-hoa-don-dien-tu-co-ma-cua-co-quan-thue.htm`) — high, score 95; bảng=4, bảng>=3 cột=2, width px=93, max width=559px, margin-left max=0px.
- **Cách hạch toán mua CCDC về nhập kho sau đó xuất kho ra sử dụng trên Misa** (`cach-hach-toan-mua-ccdc-ve-nhap-kho-sau-do-xuat-kho-su-dung.htm`) — high, score 95; bảng=0, bảng>=3 cột=0, width px=13, max width=550px, margin-left max=80px.
- **Cách hạch toán mua hàng nhập khẩu nhập kho trên Misa** (`cach-hach-toan-mua-hang-nhap-khau-nhap-kho-tren-misa.htm`) — high, score 95; bảng=0, bảng>=3 cột=0, width px=13, max width=550px, margin-left max=80px.
- **Cách lập báo cáo tài chính theo thông tư 133 trên Misa** (`cach-lap-bao-cao-tai-chinh-theo-thong-tu-133-tren-misa.htm`) — high, score 95; bảng=0, bảng>=3 cột=0, width px=13, max width=550px, margin-left max=80px.
- **Bài tập kế toán thuế tiêu thụ đặc biệt có lời giải** (`bai-tap-ke-toan-thue-tieu-thu-dac-biet-co-loi-giai.htm`) — high, score 94; bảng=5, bảng>=3 cột=5, width px=7, max width=550px, margin-left max=0px.
- **Thông tư 20/2026/TT-BTC hướng dẫn về Thuế TNDN** (`thong-tu-20-2026-tt-btc-huong-dan-luat-thue-tndn.htm`) — high, score 94; bảng=9, bảng>=3 cột=4, width px=7, max width=555px, margin-left max=40px.
- **Nghi định 115/2015/NĐ-CP Quy định Luật Bảo hiểm xã hội** (`nghi-dinh-115-2015-nd-cp-quy-dinh-luat-bao-hiem-xa-hoi.htm`) — high, score 94; bảng=6, bảng>=3 cột=3, width px=56, max width=550px, margin-left max=0px.
- **Cách tính giá xuất kho theo phương pháp thực tế đích danh 2026** (`cach-tinh-gia-xuat-kho-theo-phuong-phap-dich-danh.htm`) — high, score 93; bảng=4, bảng>=3 cột=4, width px=18, max width=555px, margin-left max=80px.
- **Cách tính thuế TNDN chuyển nhượng BĐS - Quyền sử dụng đất** (`cach-tinh-thue-tndn-tu-chuyen-nhuong-bat-dong-san.htm`) — high, score 93; bảng=4, bảng>=3 cột=4, width px=13, max width=560px, margin-left max=0px.
- **Mẫu giấy nộp tiền vào ngân sách nhà nước 2026 Excel, Word** (`giay-nop-tien-vao-ngan-sach-nha-nuoc-mau-c1-02-ns-theo-tt-119.htm`) — high, score 93; bảng=5, bảng>=3 cột=4, width px=9, max width=576px, margin-left max=40px.
- **Mẫu 06/ĐN-PSĐT đơn đề nghị cấp hóa đơn điện tử theo Nghị định 70/2025** (`mau-06-theo-nd-119-de-nghi-cap-hoa-don-dien-tu-ban-le.htm`) — high, score 93; bảng=7, bảng>=3 cột=3, width px=28, max width=559px, margin-left max=40px.
- **Cách viết giấy nộp tiền vào ngân sách nhà nước mẫu C1-02/NS** (`cach-viet-giay-nop-tien-vao-ngan-sach-nha-nuoc-mau-c1-02-ns.htm`) — high, score 92; bảng=5, bảng>=3 cột=4, width px=8, max width=576px, margin-left max=40px.
- **Cách ghi sổ theo hình thức Nhật ký chung theo Thông tư 99** (`cach-ghi-so-theo-hinh-thuc-nhat-ky-chung-theo-thong-tu-99.htm`) — high, score 92; bảng=1, bảng>=3 cột=1, width px=117, max width=555px, margin-left max=40px.

## 8) Nhóm bài nhiều bảng phức tạp nhất

### Bài có nhiều bảng >= 3 cột
- **Mẫu thuyết minh Báo cáo tài chính theo Thông tư 99** (`mau-thuyet-minh-bao-cao-tai-chinh-theo-thong-tu-99.htm`) — critical, score 1569; bảng=110, bảng>=3 cột=110, width px=4, max width=555px, margin-left max=0px.
- **Thông tư 132/2018/TT-BTC Chế độ kế toán Doanh nghiệp siêu nhỏ** (`thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.htm`) — critical, score 1049; bảng=88, bảng>=3 cột=63, width px=2123, max width=871px, margin-left max=0px.
- **Các loại sổ Nhật ký - Chứng từ, Bảng kê theo hình thức Nhật ký - Chứng từ** (`cac-loai-so-nhat-ky-chung-tu-bang-ke-theo-thong-tu-99.htm`) — critical, score 970; bảng=67, bảng>=3 cột=65, width px=56, max width=560px, margin-left max=40px.
- **Phần mềm hỗ trợ kê khai thuế HTKK 4.2.2 mới nhất 2019** (`phan-mem-ho-tro-ke-khai-thue-htkk-4-2-2-moi-nhat-2019.htm`) — critical, score 593; bảng=34, bảng>=3 cột=34, width px=738, max width=560px, margin-left max=0px.
- **Mẫu Bản Thuyết minh Báo cáo tài chính theo QĐ 15 Excel** (`mau-ban-thuyet-minh-bao-cao-tai-chinh-theo-qd-15-excel.htm`) — critical, score 521; bảng=30, bảng>=3 cột=30, width px=686, max width=703px, margin-left max=0px.
- **Mẫu 01-ĐK-TCT Tờ khai đăng ký thuế theo thông tư 86/2024/TT-BTC** (`mau-to-khai-dang-ky-thu-01-dk-tct.htm`) — critical, score 460; bảng=32, bảng>=3 cột=22, width px=188, max width=631px, margin-left max=80px.
- **Thông tư 04/VBHN-BTC văn bản hợp nhất về thuế TNCN** (`thong-tu-04-vbhn-btc-van-ban-hop-nhat-ve-thue-tncn.htm`) — critical, score 448; bảng=29, bảng>=3 cột=24, width px=280, max width=550px, margin-left max=0px.
- **Thông tư 111/2013/TT-BTC hướng dẫn thi hành Luật thuế TNCN** (`thong-tu-111-2013-tt-btc-huong-dan-thi-ha-nh-luat-thue-tncn.htm`) — critical, score 443; bảng=29, bảng>=3 cột=24, width px=279, max width=550px, margin-left max=0px.
- **Thông tư 59/2015/TT-BLĐTBXH hướng dẫn Luật BHXH bắt buộc** (`thong-tu-59-2015-bldtbxh-huong-dan-luat-bhxh-bat-buoc.htm`) — critical, score 428; bảng=32, bảng>=3 cột=21, width px=187, max width=568px, margin-left max=0px.
- **Nghị định 145/2020/NĐ-CP hướng dẫn thi hành Luật lao động** (`nghi-dinh-145-2020-nd-cp-huong-dan-thi-hanh-luat-lao-dong.htm`) — critical, score 408; bảng=60, bảng>=3 cột=16, width px=153, max width=560px, margin-left max=0px.
- **Nghị định 70/2025/NĐ-CP sửa đổi Nghị định 123 về hóa đơn điện tử** (`nghi-dinh-70-2025-nd-cp-sua-doi-nghi-dinh-123-ve-hoa-don-dien-tu.htm`) — critical, score 405; bảng=53, bảng>=3 cột=21, width px=59, max width=555px, margin-left max=0px.
- **Mẫu biểu chứng từ kế toán hàng tồn kho theo Thông tư 99** (`mau-bieu-chung-tu-ke-toan-hang-ton-kho-theo-thong-tu-99.htm`) — critical, score 364; bảng=21, bảng>=3 cột=18, width px=453, max width=555px, margin-left max=40px.
- **Nghị định 122/2020/NĐ-CP phổi hợp thủ tục đăng ký thành lập doanh nghiệp** (`nghi-dinh-122-2020-nd-cp-phoi-hop-thu-tuc-thanh-lap-doanh-nghiep.htm`) — critical, score 354; bảng=30, bảng>=3 cột=16, width px=458, max width=560px, margin-left max=0px.
- **Mẫu Báo cáo tài chính giữa niên độ theo Thông tư 99** (`mau-bao-cao-tai-chinh-giua-nien-do-theo-thong-tu-99.htm`) — critical, score 353; bảng=25, bảng>=3 cột=23, width px=13, max width=555px, margin-left max=0px.
- **Nghị định 92/2021/NĐ-CP về việc giảm thuế TNDN, GTGT năm 2021** (`nghi-dinh-92-2021-nd-cp-giam-thue-tndn-gtgt-nam-2021.htm`) — critical, score 342; bảng=33, bảng>=3 cột=14, width px=181, max width=560px, margin-left max=0px.

## 9) Nhóm bài nhiều width px inline nhất

### Bài có nhiều width px inline
- **Biểu thuế xuất khẩu năm 2017 theo Thông tư 182** (`bieu-thue-xuat-khau-moi-nhat-hien-nay.htm`) — critical, score 125; bảng=2, bảng>=3 cột=1, width px=4721, max width=605px, margin-left max=0px.
- **Mẫu Thuyết minh Báo cáo tài chính Excel theo TT 200 và 133** (`mau-ban-thuyet-minh-bao-cao-tai-chinh-excel-theo-qd-48.htm`) — critical, score 133; bảng=2, bảng>=3 cột=2, width px=2386, max width=660px, margin-left max=0px.
- **Thông tư 132/2018/TT-BTC Chế độ kế toán Doanh nghiệp siêu nhỏ** (`thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.htm`) — critical, score 1049; bảng=88, bảng>=3 cột=63, width px=2123, max width=871px, margin-left max=0px.
- **Mẫu C70a-HSB Danh sách giải quyết hưởng chế độ thai sản, ốm đau, DSPHSK** (`mau-c70a-hsb-danh-sach-giai-quyet-huong-che-do-thai-san-om-dau.htm`) — critical, score 221; bảng=7, bảng>=3 cột=7, width px=1904, max width=1433px, margin-left max=80px.
- **Mẫu bảng cân đối số phát sinh tài khoản theo QĐ 15 trên Exccel** (`mau-bang-can-doi-so-phat-sinh-tai-khoan-theo-qd-15-tren-exccel.htm`) — critical, score 111; bảng=1, bảng>=3 cột=1, width px=1836, max width=550px, margin-left max=0px.
- **So sánh tài khoản kế toán Thông tư 200 và Thông tư 99** (`so-sanh-tai-khoan-ke-toan-thong-tu-200-va-thong-tu-99.htm`) — critical, score 152; bảng=2, bảng>=3 cột=2, width px=1748, max width=1514px, margin-left max=40px.
- **Mẫu 01B-HSB theo Quyết định 2222/QĐ-BHXH mới nhất 2025** (`mau-01b-hsb-theo-quyet-dinh-166-excel-moi-nhat.htm`) — critical, score 185; bảng=5, bảng>=3 cột=5, width px=1664, max width=1031px, margin-left max=0px.
- **Quyết định 919/QĐ-BHXH Sửa đổi bổ sung QĐ 01/QĐ-BHXH** (`quyet-dinh-919-qd-bhxh-ngay-26-8-2015-cua-bhxh-viet-nam.htm`) — critical, score 244; bảng=17, bảng>=3 cột=9, width px=1354, max width=550px, margin-left max=0px.
- **Thông tư 200/2014/TT-BTC của Bộ tài chính (PDF - WORD)** (`thong-tu-200-2014-tt-btc-che-do-ke-toan-doanh-nghiep.htm`) — critical, score 151; bảng=3, bảng>=3 cột=3, width px=1222, max width=664px, margin-left max=40px.
- **Mẫu bảng cân đối số phát sinh tài khoản Excel theo QĐ 48** (`mau-bang-can-doi-so-phat-sinh-tai-khoan-excel-theo-qd-48.htm`) — critical, score 113; bảng=2, bảng>=3 cột=1, width px=1205, max width=560px, margin-left max=0px.
- **Hệ thống tài khoản kế toán theo Thông tư 200 Excel** (`he-thong-tai-khoan-ke-toan-theo-thong-tu-200-moi-nhat.htm`) — critical, score 148; bảng=2, bảng>=3 cột=2, width px=942, max width=690px, margin-left max=40px.
- **Hệ thống tài khoản kế toán theo quyết định 15 mới nhất năm 2014** (`he-thong-tai-khoan-ke-toan-theo-quyet-dinh-15-moi-nhat.htm`) — critical, score 111; bảng=1, bảng>=3 cột=1, width px=922, max width=550px, margin-left max=0px.
- **Phần mềm hỗ trợ kê khai thuế HTKK 4.2.2 mới nhất 2019** (`phan-mem-ho-tro-ke-khai-thue-htkk-4-2-2-moi-nhat-2019.htm`) — critical, score 593; bảng=34, bảng>=3 cột=34, width px=738, max width=560px, margin-left max=0px.
- **Hệ thống tài khoản kế toán theo quyết định 48 mới nhất năm 2016 đã được sử đổi** (`he-thong-tai-khoan-ke-toan-theo-quyet-dinh-48-moi-nhat.htm`) — critical, score 111; bảng=1, bảng>=3 cột=1, width px=709, max width=550px, margin-left max=0px.
- **Mẫu Bản Thuyết minh Báo cáo tài chính theo QĐ 15 Excel** (`mau-ban-thuyet-minh-bao-cao-tai-chinh-theo-qd-15-excel.htm`) — critical, score 521; bảng=30, bảng>=3 cột=30, width px=686, max width=703px, margin-left max=0px.

## 10) Nhóm bài thụt lề legacy mạnh nhất

### Bài có margin-left lớn
- **Mẫu giấy ủy quyền đăng ký thuế 2026 - Mẫu số: 41/UQ-ĐKT** (`mau-giay-uy-quyen-dang-ky-thue.htm`) — critical, score 280; bảng=18, bảng>=3 cột=10, width px=175, max width=697px, margin-left max=320px.
- **Mẫu giấy ủy quyền đăng ký người phụ thuộc 2026 mới nhất** (`mau-giay-uy-quyen-dang-ky-nguoi-phu-thuoc-giam-tru-gia-canh.htm`) — critical, score 112; bảng=8, bảng>=3 cột=4, width px=15, max width=555px, margin-left max=320px.
- **Mẫu giấy ủy quyền đăng ký mã số thuế cá nhân mới nhất năm 2026** (`mau-giau-uy-quyen-dang-ky-ma-so-thue-tncn.htm`) — critical, score 112; bảng=8, bảng>=3 cột=4, width px=15, max width=555px, margin-left max=320px.
- **Thủ tục cắt giảm người phụ thuộc 2025 trên phần mềm HTKK** (`thu-tuc-cat-giam-nguoi-phu-thuoc.htm`) — high, score 87; bảng=4, bảng>=3 cột=2, width px=9, max width=555px, margin-left max=280px.
- **Cách kiểm tra đối chiếu chứng từ sổ sách kế toán trên Misa** (`cach-kiem-tra-doi-chieu-chung-tu-so-sach-ke-toan-tren-misa.htm`) — critical, score 604; bảng=0, bảng>=3 cột=0, width px=105, max width=550px, margin-left max=160px.
- **Cách hạch toán TK 711 - Thu nhập khác theo Thông tư 99** (`cach-hach-toan-tk-711-theo-thong-tu-99.htm`) — high, score 40; bảng=1, bảng>=3 cột=0, width px=2, max width=555px, margin-left max=160px.
- **Cách hạch toán TK 821 - Chi phí thuế TNDN theo Thông tư 99** (`cach-hach-toan-tk-821-theo-thong-tu-99.htm`) — high, score 69; bảng=3, bảng>=3 cột=2, width px=4, max width=555px, margin-left max=160px.
- **Cách làm tờ khai quyết toán thuế TNCN Mẫu 05/QTT-TNCN trên HTKK** (`cach-lap-to-khai-quyet-toan-thue-tncn-05-kk-tncn.htm`) — high, score 85; bảng=1, bảng>=3 cột=1, width px=9, max width=555px, margin-left max=160px.
- **Đăng ký thuế lần đầu của cá nhân, hộ kinh doanh mới nhất năm 2025** (`dang-ky-thue-lan-dau-cua-ca-nhan-ho-kinh-doanh-2025-theo-tt86.htm`) — high, score 38; bảng=1, bảng>=3 cột=0, width px=2, max width=500px, margin-left max=160px.
- **Nộp danh sách chi tiết số tiền thuế TNCN đã nộp thay cho từng cá nhân** (`nop-danh-sach-chi-tiet-so-tien-thue-tncn-da-nop-thay-cho-tung-ca-nhan.htm`) — critical, score 127; bảng=1, bảng>=3 cột=0, width px=19, max width=555px, margin-left max=160px.
- **Cách tính thuế TNCN hợp đồng thử việc 2026** (`cach-tinh-thue-tncn-hop-dong-thu-viec-cua-nhan-vien-thu-viec.htm`) — critical, score 132; bảng=9, bảng>=3 cột=5, width px=39, max width=558px, margin-left max=160px.
- **Cách xử lý chứng từ khấu trừ thuế TNCN điện tử theo NĐ 70/2025 bị sai** (`cach-xu-ly-chung-tu-khau-tru-thue-tncn-dien-tu-bi-sai.htm`) — high, score 37; bảng=0, bảng>=3 cột=0, width px=1, max width=555px, margin-left max=160px.
- **Cách hạch toán TTK 511 - Doanh thu theo Thông tư 99** (`cach-hach-toan-tk-511-doanh-thu-theo-thong-tu-99.htm`) — high, score 36; bảng=1, bảng>=3 cột=0, width px=2, max width=555px, margin-left max=120px.
- **Cách hạch toán Tài Sản Cố Định - TK 211 theo Thông tư 99** (`cach-hach-toan-tai-san-co-dinh-tk-211-theo-thong-tu-99.htm`) — high, score 36; bảng=1, bảng>=3 cột=0, width px=2, max width=555px, margin-left max=120px.
- **Cách hạch toán Tài khoản 343 - Trái phiếu phát hành theo Thông tư 99** (`cach-hach-toan-tk-343-theo-thong-tu-99.htm`) — high, score 91; bảng=4, bảng>=3 cột=4, width px=5, max width=555px, margin-left max=120px.

## 11) Kết luận vận hành

- Không nên sửa tay từng bài.
- Cần giữ chiến lược **pipeline hóa**:
  - scan trước
  - sanitize khi import
  - runtime safety net
  - QA theo risk bucket
- Report này là danh sách ưu tiên để khi nhập tiếp hơn 2000 bài, đội triển khai biết **bài nào phải xem kỹ trước**.
