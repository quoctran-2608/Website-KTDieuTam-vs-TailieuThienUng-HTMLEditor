# Dọn deep leaf singleton - Apply

- Thời gian chạy: `2026-04-27 21:12:28`
- Deep singleton trước: **42**
- Applied: **36**
- Skipped: **0**
- Deep singleton sau: **0**
- Rebuild: Thư viện 2721 bài / 227 trang; Bản tin 88 bài / 8 trang

## Quyết định theo bucket

| # | bucket | canonical lv3 | moved | before counts |
|---:|---|---|---:|---|
| 1 | `('thu-vien', 'bieu-mau', 'doanh-nghiep-thu-tuc', 'thu-tuc-doanh-nghiep')` | `dn-thu-tuc-giai-the` | 1 | `{'dn-thu-tuc-quan-tri-ho-tro': 1, 'dn-thu-tuc-giai-the': 1}` |
| 2 | `('thu-vien', 'bieu-mau', 'ke-toan', 'tai-khoan-hach-toan')` | `cong-no-thanh-toan` | 3 | `{'cong-no-thanh-toan': 2, 'tien-quy-ngan-hang': 1, 'thue-nghia-vu': 1, 'doanh-thu-chi-phi-kqkd': 2, 'hach-toan-dac-thu': 2, 'hang-ton-kho-gia-thanh': 1, 'von-dau-tu': 2}` |
| 3 | `('thu-vien', 'bieu-mau', 'ke-toan', 'tai-san-kho-ccdc')` | `hang-ton-kho-gia-xuat` | 1 | `{'tscd-nguyen-gia-khau-hao': 2, 'hang-ton-kho-gia-xuat': 7, 'tai-san-kho-ccdc-khac': 1}` |
| 4 | `('thu-vien', 'bieu-mau', 'tham-khao-hoc-lieu', 'bao-cao-thuc-tap')` | `thuc-tap-ke-toan-tong-hop-to-chuc` | 2 | `{'thuc-tap-ke-toan-tong-hop-to-chuc': 5, 'thuc-tap-chi-phi-gia-thanh': 1, 'thuc-tap-ban-hang-doanh-thu-kqkd': 3, 'thuc-tap-nvl-ccdc': 4, 'thuc-tap-tien-luong': 1, 'thuc-tap-von-bang-tien': 2}` |
| 5 | `('thu-vien', 'bieu-mau', 'tham-khao-hoc-lieu', 'kinh-nghiem-hoi-dap-nghe-nghiep')` | `kinh-nghiem-quyet-toan-thue` | 2 | `{'kinh-nghiem-quyet-toan-thue': 17, 'kinh-nghiem-phong-van-xin-viec': 1, 'mo-ta-cong-viec-ke-toan': 1}` |
| 6 | `('thu-vien', 'bieu-mau', 'thue', 'cong-van')` | `cong-van-ke-khai-quan-ly-thue` | 2 | `{'cong-van-gtgt-hoa-don': 1, 'cong-van-chinh-sach-chung': 1, 'cong-van-ke-khai-quan-ly-thue': 2}` |
| 7 | `('thu-vien', 'bieu-mau', 'thue', 'mau-bieu-thue')` | `mau-thue-gtgt-hoa-don` | 2 | `{'mau-thue-tndn': 14, 'mau-thue-gtgt-hoa-don': 141, 'mau-thue-tncn': 35, 'mau-bang-ke-phu-luc-ho-so': 4, 'mau-khau-tru-hoan-mien-giam': 1, 'mau-thue-ho-ca-nhan-mon-bai': 8, 'mau-thue-nha-thau': 9, 'mau-dang-ky-thue-mst': 1}` |
| 8 | `('thu-vien', 'huong-dan', 'ke-toan', 'bao-cao-tai-chinh')` | `lap-cac-bao-cao-thanh-phan` | 1 | `{'tong-quan-quy-dinh-nop-bctc': 3, 'lap-cac-bao-cao-thanh-phan': 9, 'chuan-muc-trinh-bay-bctc': 3, 'vi-pham-dieu-chinh-bctc': 3, 'nguyen-tac-trinh-bay-bctc': 1}` |
| 9 | `('thu-vien', 'huong-dan', 'ke-toan', 'chuan-muc-che-do-nguyen-tac')` | `tuyen-dung-nghe-nghiep-ke-toan` | 1 | `{'che-do-ke-toan-va-thong-tu': 7, 'chuan-muc-ke-toan-vas': 16, 'tuyen-dung-nghe-nghiep-ke-toan': 43, 'chuan-muc-che-do-khac': 3, 'hinh-thuc-ghi-so-ke-toan': 1, 'nguyen-tac-ke-toan': 4}` |
| 10 | `('thu-vien', 'huong-dan', 'ke-toan', 'mau-bieu-ke-toan')` | `mau-bao-cao-tai-chinh` | 2 | `{'mau-hanh-chinh-quan-tri-khac': 1, 'mau-bao-cao-tai-chinh': 2, 'mau-chung-tu-tien-thanh-toan': 1}` |
| 11 | `('thu-vien', 'huong-dan', 'thue', 'cong-van')` | `cong-van-chinh-sach-chung` | 1 | `{'cong-van-chinh-sach-chung': 1, 'cong-van-ke-khai-quan-ly-thue': 1}` |
| 12 | `('thu-vien', 'huong-dan', 'thue', 'ma-so-thue-dang-ky-thue')` | `mst-thay-doi-va-xu-phat` | 1 | `{'mst-tra-cuu-cau-truc-trang-thai': 3, 'mst-thay-doi-va-xu-phat': 4, 'mst-thu-tuc-hanh-chinh-ho-so': 1, 'mst-dang-ky-lan-dau-doi-tuong': 3}` |
| 13 | `('thu-vien', 'huong-dan', 'thue', 'mau-bieu-thue')` | `mau-thue-tncn` | 3 | `{'mau-thue-gtgt-hoa-don': 1, 'mau-thue-tncn': 2, 'mau-dang-ky-thue-mst': 1, 'mau-khau-tru-hoan-mien-giam': 1}` |
| 14 | `('thu-vien', 'van-ban', 'doanh-nghiep-thu-tuc', 'thu-tuc-doanh-nghiep')` | `dn-thu-tuc-khuyen-mai-thuong-mai` | 2 | `{'dn-thu-tuc-khuyen-mai-thuong-mai': 1, 'dn-thu-tuc-thanh-lap-thay-doi': 1, 'dn-thu-tuc-quan-tri-ho-tro': 1}` |
| 15 | `('thu-vien', 'van-ban', 'ke-toan', 'bao-cao-tai-chinh')` | `tong-quan-quy-dinh-nop-bctc` | 1 | `{'tong-quan-quy-dinh-nop-bctc': 3, 'nguyen-tac-trinh-bay-bctc': 2, 'vi-pham-dieu-chinh-bctc': 1}` |
| 16 | `('thu-vien', 'van-ban', 'ke-toan', 'chung-tu-so-sach')` | `he-thong-chung-tu-mau-bieu` | 2 | `{'so-sach-tien-kho-chi-tiet': 3, 'he-thong-chung-tu-mau-bieu': 4, 'hinh-thuc-ghi-so-ke-toan': 1, 'chung-tu-cong-tac-thanh-toan': 1}` |
| 17 | `('thu-vien', 'van-ban', 'ke-toan', 'tai-khoan-hach-toan')` | `hach-toan-dac-thu` | 1 | `{'hach-toan-dac-thu': 5, 'tien-quy-ngan-hang': 2, 'doanh-thu-chi-phi-kqkd': 1}` |
| 18 | `('thu-vien', 'van-ban', 'lao-dong-bao-hiem', 'bao-hiem')` | `che-do-muc-huong` | 3 | `{'che-do-muc-huong': 1, 'doi-tuong-muc-dong': 1, 'van-ban-chinh-sach': 1, 'vi-pham-xu-phat': 1}` |
| 19 | `('thu-vien', 'van-ban', 'lao-dong-bao-hiem', 'lao-dong-tien-luong')` | `tien-luong-thoi-gio-lam-viec` | 1 | `{'tien-luong-thoi-gio-lam-viec': 4, 'van-ban-lao-dong': 1, 'ho-so-thu-tuc-lao-dong': 2, 'hop-dong-quan-he-lao-dong': 2}` |
| 20 | `('thu-vien', 'van-ban', 'thue', 'ho-ca-nhan-kinh-doanh')` | `hkd-che-do-ke-toan` | 1 | `{'hkd-ke-khai-ho-so-thoi-han': 1, 'hkd-che-do-ke-toan': 2}` |
| 21 | `('thu-vien', 'van-ban', 'thue', 'ma-so-thue-dang-ky-thue')` | `mst-dang-ky-lan-dau-doi-tuong` | 1 | `{'mst-tra-cuu-cau-truc-trang-thai': 1, 'mst-dang-ky-lan-dau-doi-tuong': 1}` |
| 22 | `('thu-vien', 'van-ban', 'thue', 'mau-bieu-thue')` | `mau-bang-ke-phu-luc-ho-so` | 1 | `{'mau-bang-ke-phu-luc-ho-so': 1, 'mau-thue-gtgt-hoa-don': 1}` |
| 23 | `('thu-vien', 'van-ban', 'thue', 'tndn')` | `van-ban-chinh-sach` | 1 | `{'doanh-thu-thu-nhap-tinh-thue': 1, 'van-ban-chinh-sach': 3}` |

## Singleton còn lại

- Không còn deep leaf singleton.
