# LV3 dry-run cho 745 bài import mới (chặng 2)

- Phạm vi dry-run: **682** bài Thư viện trong 745 bài import mới (Bản tin không có nhánh lv3 tương ứng trong taxonomy hiện tại).
- Auto gán được ngay: **194**
- Cần review: **488**

## Phân bố độ tin cậy

- `high`: **43**
- `no-child-lv2`: **246**
- `auto-single-child`: **151**
- `medium`: **225**
- `low`: **17**

## Top LV2 cần review nhiều

- `htkk-etax-thue-dien-tu`: 62
- `kinh-nghiem-hoi-dap-nghe-nghiep`: 57
- `gtgt-hoa-don`: 55
- `thu-tuc-doanh-nghiep`: 50
- `thong-tu`: 46
- `cong-van`: 43
- `lao-dong-tien-luong`: 28
- `bao-hiem`: 28
- `tai-khoan-hach-toan`: 26
- `mau-bieu-doanh-nghiep-thu-tuc`: 25
- `excel-va-cong-cu-khac`: 22
- `luat-bo-luat`: 12
- `bao-cao-thuc-tap`: 8
- `chung-tu-so-sach`: 7
- `mau-bieu-ke-toan`: 7
- `nghi-quyet-quyet-dinh`: 4
- `bao-cao-tai-chinh`: 3
- `ma-so-thue-dang-ky-thue`: 3
- `bai-tap-thuc-hanh`: 1
- `ho-ca-nhan-kinh-doanh`: 1

## Mẫu 30 dòng cần review

| # | href | lv2 | đề xuất lv3 | confidence | score | ghi chú |
|---:|---|---|---|---|---:|---|
| 1 | `thu-vien/don-de-nghi-mau-d01-ts.html` | `excel-va-cong-cu-khac` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 2 | `thu-vien/bien-ban-xac-minh-tinh-trang-hoat-dong.html` | `kinh-nghiem-hoi-dap-nghe-nghiep` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 3 | `thu-vien/bieu-thue-suat-thue-tieu-thu-dac-biet.html` | `gtgt-hoa-don` | `lap-xu-ly-hoa-don ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 4 | `thu-vien/bai-tap-tinh-thue-thu-nhap-ca-nhan-co-loi-giai-nhap-moi.html` | `bai-tap-thuc-hanh` | `bai-tap-thue-ttdb ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 5 | `thu-vien/ban-hang-tai-kho-ngoai-tinh-co-phai-nop-thue-vang-lai.html` | `gtgt-hoa-don` | `khau-tru-hoan-thue ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 6 | `thu-vien/bao-cao-hoan-thien-ke-toan-tien-luong-tai-cong-ty-dien-luc-hoan-kiem.html` | `bao-cao-thuc-tap` | `thuc-tap-tien-luong ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 7 | `thu-vien/bao-cao-ke-toan-ban-hang-tai-cong-ty-tnhh-xay-dung-cong-tien.html` | `bao-cao-thuc-tap` | `thuc-tap-ban-hang-doanh-thu-kqkd ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 8 | `thu-vien/bao-cao-ke-toan-chi-phi-doanh-thu-tai-cong-ty-cpkdptndt-ha-noi.html` | `bao-cao-thuc-tap` | `thuc-tap-ban-hang-doanh-thu-kqkd ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 9 | `thu-vien/bao-cao-ke-toan-doanh-thu-tai-cong-ty-tnhh-tin-nghia.html` | `bao-cao-thuc-tap` | `thuc-tap-ban-hang-doanh-thu-kqkd ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 10 | `thu-vien/bao-cao-ke-toan-nguyen-vat-lieu-tai-cong-ty-cpxd-giao-thong-quang-nam.html` | `bao-cao-thuc-tap` | `thuc-tap-ke-toan-tong-hop-to-chuc ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 11 | `thu-vien/bao-cao-ke-toan-tien-luong-tai-cong-ty-cp-dau-tu-va-phat-trien-nha-ha-noi.html` | `bao-cao-thuc-tap` | `thuc-tap-tien-luong ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 12 | `thu-vien/bao-cao-ke-toan-von-bang-tien-tai-cong-ty-tnhh-mtv-hop-quoc.html` | `bao-cao-thuc-tap` | `thuc-tap-von-bang-tien ` | `medium` | 5 | match 5 token; cần rà soát nhẹ |
| 13 | `thu-vien/bao-cao-tai-san-co-dinh-tai-cong-ty-cpdt-va-tm-bach-gia.html` | `bao-cao-thuc-tap` | `thuc-tap-chi-phi-gia-thanh ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 14 | `thu-vien/bao-cao-to-chuc-cong-tac-ke-toan-nguyen-vat-lieu-tai-cong-ty-cp-hoang-lam.html` | `gtgt-hoa-don` | `lap-xu-ly-hoa-don ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 15 | `thu-vien/ban-giai-trinh-khai-bo-sung-dieu-chinh-mau-so-01-khbs.html` | `kinh-nghiem-hoi-dap-nghe-nghiep` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 16 | `thu-vien/danh-muc-he-thong-tai-khoan-ke-toan-theo-thong-tu-133.html` | `excel-va-cong-cu-khac` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 17 | `thu-vien/danh-muc-he-thong-tai-khoan-ke-toan-theo-thong-tu-200.html` | `excel-va-cong-cu-khac` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 18 | `thu-vien/bang-quy-dinh-tieu-chuan-va-dieu-kien-ap-dung-chuc-danh.html` | `lao-dong-tien-luong` | `tien-luong-thoi-gio-lam-viec ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 19 | `thu-vien/bang-thue-xuat-khau-moi-nhat.html` | `gtgt-hoa-don` | `lap-xu-ly-hoa-don ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 20 | `thu-vien/bang-tinh-lai-truy-thu-bhxh-bhyt-bhtn-mau-d02b-ts.html` | `bao-hiem` | `che-do-muc-huong ` | `low` | 1 | match yếu (1 token) |
| 21 | `thu-vien/bo-luat-45-2019-qh14-bo-luat-lao-dong.html` | `luat-bo-luat` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 22 | `thu-vien/huong-dan-viet-giay-dieu-chinh-thu-ngan-sach-nha-nuoc.html` | `kinh-nghiem-hoi-dap-nghe-nghiep` | `- ` | `no-child-lv2` | 0 | lv2 không có nhánh con lv3 trong taxonomy |
| 23 | `thu-vien/cach-hach-toan-hang-gui-dai-ly-ban-dung-gia-huong-hoa-hong.html` | `tai-khoan-hach-toan` | `hach-toan-dac-thu ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 24 | `thu-vien/cach-lap-so-chi-tiet-cac-tai-khoan-theo-thong-tu-200-va-133.html` | `tai-khoan-hach-toan` | `hach-toan-dac-thu ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 25 | `thu-vien/cach-lap-so-chi-tiet-thanh-toan-voi-nguoi-mua-ban.html` | `tai-khoan-hach-toan` | `hach-toan-dac-thu ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 26 | `thu-vien/cach-lap-so-chi-tiet-tien-vay-theo-thong-tu-200-va-133.html` | `tai-khoan-hach-toan` | `tien-quy-ngan-hang ` | `medium` | 3 | match 3 token; cần rà soát nhẹ |
| 27 | `thu-vien/cach-lap-so-chi-tiet-vat-tu-hang-hoa-theo-thong-tu-200-va-133.html` | `lao-dong-tien-luong` | `tien-luong-thoi-gio-lam-viec ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 28 | `thu-vien/cach-lap-so-ke-toan-chi-tiet-quy-tien-mat.html` | `chung-tu-so-sach` | `so-sach-tien-kho-chi-tiet ` | `medium` | 4 | match 4 token; cần rà soát nhẹ |
| 29 | `thu-vien/cach-lap-so-tien-gui-ngan-hang-theo-thong-tu-200-va-133.html` | `thu-tuc-doanh-nghiep` | `dn-thu-tuc-khuyen-mai-thuong-mai ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |
| 30 | `thu-vien/cach-lap-the-kho-so-kho-theo-thong-tu-200-va-133.html` | `lao-dong-tien-luong` | `tien-luong-thoi-gio-lam-viec ` | `medium` | 2 | match 2 token; cần rà soát nhẹ |

## Mẫu 20 dòng auto

| # | href | lv2 | lv3 | confidence | score |
|---:|---|---|---|---|---:|
| 1 | `thu-vien/bao-cao-ke-toan-chi-phi-san-xuat-va-gia-thanh-san-pham-tai-cong-ty-tvxd-truong-son.html` | `bao-cao-thuc-tap` | `thuc-tap-chi-phi-gia-thanh` | `high` | 6 |
| 2 | `thu-vien/bien-ban-thong-qua-he-thong-thang-bang-luong-cua-dn.html` | `mau-bieu-lao-dong-bao-hiem` | `mau-tien-luong-phu-cap` | `auto-single-child` | 999 |
| 3 | `thu-vien/bai-tap-dinh-khoan-nguyen-ly-ke-toan-co-loi-giai.html` | `bai-tap-thuc-hanh` | `bai-tap-dinh-khoan-hach-toan` | `high` | 4 |
| 4 | `thu-vien/bao-cao-ke-toan-ban-hang-tai-cong-ty-cptm-va-xl-an-phu.html` | `bao-cao-thuc-tap` | `thuc-tap-ban-hang-doanh-thu-kqkd` | `high` | 5 |
| 5 | `thu-vien/bao-cao-thuc-tap-ke-toan-von-bang-tien-tai-doanh-nghiep-tien.html` | `bao-cao-thuc-tap` | `thuc-tap-von-bang-tien` | `high` | 5 |
| 6 | `thu-vien/bao-cao-thuc-tap-ve-he-thong-ke-toan.html` | `bao-cao-thuc-tap` | `thuc-tap-chi-phi-gia-thanh` | `high` | 6 |
| 7 | `thu-vien/bang-ke-thu-mua-hang-hoa-dich-vu-khong-co-hoa-don-mau-01.html` | `mau-bieu-thue` | `mau-thue-gtgt-hoa-don` | `auto-single-child` | 999 |
| 8 | `thu-vien/mau-so-01-3-ttdb-bang-phan-bo-thue-tieu-thu-dac-biet.html` | `mau-bieu-thue` | `mau-thue-gtgt-hoa-don` | `auto-single-child` | 999 |
| 9 | `thu-vien/cach-lam-bao-cao-quyet-toan-thue-tncn-cuoi-nam.html` | `tncn` | `tinh-thue-bieu-thue` | `auto-single-child` | 999 |
| 10 | `thu-vien/cach-lap-bang-tong-hop-chi-tiet-vat-tu-hang-hoa.html` | `gtgt-hoa-don` | `lap-xu-ly-hoa-don` | `high` | 4 |
| 11 | `thu-vien/cach-lap-so-cai-nhat-ky-chung-theo-thong-tu-200-va-133.html` | `chung-tu-so-sach` | `hinh-thuc-ghi-so-ke-toan` | `high` | 5 |
| 12 | `thu-vien/cach-lap-so-chi-phi-san-xuat-kinh-doanh.html` | `tai-khoan-hach-toan` | `doanh-thu-chi-phi-kqkd` | `high` | 4 |
| 13 | `thu-vien/cach-lap-so-chi-tiet-ban-hang-theo-thong-tu-200-va-133.html` | `chuan-muc-che-do-nguyen-tac` | `che-do-ke-toan-va-thong-tu` | `auto-single-child` | 999 |
| 14 | `thu-vien/cach-lap-so-nhat-ky-chung-theo-thong-tu-200-va-133.html` | `chung-tu-so-sach` | `hinh-thuc-ghi-so-ke-toan` | `high` | 4 |
| 15 | `thu-vien/cach-lap-so-quy-tien-mat-theo-thong-tu-200-va-133.html` | `chuan-muc-che-do-nguyen-tac` | `che-do-ke-toan-va-thong-tu` | `auto-single-child` | 999 |
| 16 | `thu-vien/cach-lap-so-tai-san-co-dinh-theo-thong-tu-200-va-133.html` | `tai-san-kho-ccdc` | `hang-ton-kho-gia-xuat` | `auto-single-child` | 999 |
| 17 | `thu-vien/cach-lap-so-theo-doi-tai-san-co-dinh-cong-cu-dung-cu.html` | `tai-san-kho-ccdc` | `hang-ton-kho-gia-xuat` | `auto-single-child` | 999 |
| 18 | `thu-vien/cach-lap-the-tai-san-co-dinh-theo-thong-tu-200-va-133.html` | `tai-san-kho-ccdc` | `hang-ton-kho-gia-xuat` | `auto-single-child` | 999 |
| 19 | `thu-vien/cach-viet-hoa-don-kem-theo-bang-ke-co-mau-bang-ke-kem-theo.html` | `gtgt-hoa-don` | `lap-xu-ly-hoa-don` | `high` | 4 |
| 20 | `thu-vien/cach-tinh-khau-hao-tai-san-co-dinh-da-qua-su-dung.html` | `tai-san-kho-ccdc` | `hang-ton-kho-gia-xuat` | `auto-single-child` | 999 |
