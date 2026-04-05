# Editorial review queue — 50 bài cần rà tay

- Nguồn: `TailieuKeToanThienUng/index.html`
- Logic hiện tại: `.m/build_sample_sections.py`
- Mục tiêu: chọn ra **50 bài biên giới nhất** để biên tập viên rà tay trước/hoặc sau import full

## Cách hiểu cột

- `Primary`: menu public hiện tại (`Thư viện` hoặc `Bản tin`)
- `Library kind`: nhóm con nếu thuộc `Thư viện`
- `Legacy top / second`: điểm cao nhất và nhì của classifier 3 ý định cũ
- `Margin`: khoảng cách điểm giữa 2 intent cao nhất; càng thấp càng dễ nhập nhằng
- `Flags`: pattern xung đột hoặc ca đáng nghi

## Top 50 bài cần rà

| # | Primary | Kind | Margin | Flags | LV1 | LV2 | Tiêu đề | File |
|---:|---|---|---:|---|---|---|---|---|
| 1 | Bản tin | Hướng dẫn | 7 | ban-tin weak wording | Thuế - Hóa đơn | Thuế thu nhập doanh nghiệp | Các khoản thu nhập chịu thuế TNDN theo quy định mới nhất 2026 | `thu-nhap-chiu-thue-thu-nhap-doanh-nghiep.htm` |
| 2 | Thư viện | Công cụ | 0 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Các hàm Excel trong kế toán thường dùng | `cac-ham-thuong-dung-trong-ke-toan-excel-de-len-so-sach.htm` |
| 3 | Thư viện | Công cụ | 0 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Các phím tắt trong Excel kế toán hữu ích | `cac-phim-tat-trong-excel-ke-toan.htm` |
| 4 | Thư viện | Công cụ | 0 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Thuế suất thuế giá trị gia tăng đối với phần mềm như thế nào | `thue-suat-thue-gia-tri-gia-tang-doi-voi-phan-mem-nhu-the-nao.htm` |
| 5 | Thư viện | Công cụ | 0 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Thủ tục cắt giảm người phụ thuộc 2025 trên phần mềm HTKK | `thu-tuc-cat-giam-nguoi-phu-thuoc.htm` |
| 6 | Thư viện | Công cụ | 0 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Trọn bộ mẫu giấy ủy nhiệm chi của các ngân hàng | `mau-giay-uy-nhiem-chi-cua-cac-ngan-hang-bang-excel.htm` |
| 7 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Biểu thuế xuất khẩu năm 2017 theo Thông tư 182 | `bieu-thue-xuat-khau-moi-nhat-hien-nay.htm` |
| 8 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Các hình thức khuyến mại theo nghị định 81, Luật thương mại | `cac-hinh-thuc-khuyen-mai.htm` |
| 9 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Các phương pháp tính giá xuất kho theo thông tư 200 và 133 | `cac-phuong-phap-tinh-gia-xuat-kho.htm` |
| 10 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Các phương pháp tính giá xuất kho theo thông tư 99 | `phuong-phap-tinh-gia-xuat-kho-theo-thong-tu-99.htm` |
| 11 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Danh mục bệnh cần chữa trị dài ngày theo Thông tư 25/2025/TT-BYT | `danh-muc-benh-can-chua-tri-dai-ngay.htm` |
| 12 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Mức lương tối thiều vùng năm 2026 theo Nghị định 293/2025/NĐ-CP | `muc-luong-toi-thieu-vung-nam-moi-nhat-hien-nay.htm` |
| 13 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Quy định về thời hạn nộp thuế 2026 theo Luật quản lý thuế | `quy-dinh-ve-thoi-han-nop-thue.htm` |
| 14 | Thư viện | Hướng dẫn | 2 | - | Học liệu - Tham khảo | Khác | Thư tra soát C1-11/NS theo Thông tư 84 | `thu-tra-soat-c1-11ns-theo-thong-tu-84.htm` |
| 15 | Bản tin | Công cụ | 4 | - | Phần mềm - Công cụ | Excel - Công cụ khác | Quy định về sử dụng phần mềm kế toán theo Thông tư 99 | `quy-dinh-ve-su-dung-phan-mem-ke-toan-theo-thong-tu-99.htm` |
| 16 | Bản tin | Hướng dẫn | 7 | - | Thuế - Hóa đơn | GTGT - Hóa đơn | Các công văn ĐÁNG CHÚ Ý về hóa đơn điện tử mới nhất năm 2025 | `cac-cong-van-huong-xu-ly-hoa-don-dien-tu-moi-nhat-nam-2025.htm` |
| 17 | Bản tin | Hướng dẫn | 7 | - | Lao động - Bảo hiểm | BHXH - BHYT - BHTN | Các điểm mới về đối tượng đóng BHXH, BHYT bắt buộc từ ngày 1/7/2025 | `cac-diem-moi-ve-doi-tuong-dong-bhxh-bhyt-bat-buoc-ke-tu-1-7-2025.htm` |
| 18 | Thư viện | Hướng dẫn | 7 | - | Mẫu biểu - Thủ tục | Mẫu biểu kế toán | Cách làm báo cáo tài chính theo Thông tư 133 mới nhất | `mau-bao-cao-tai-chinh-theo-thong-tu-133-moi-nhat.htm` |
| 19 | Thư viện | Hướng dẫn | 7 | - | Mẫu biểu - Thủ tục | Mẫu biểu kế toán | Cách lập báo cáo tính tài chính Mẫu B01b-DNN theo TT 133 | `mau-bao-cao-tinh-hinh-tai-chinh-b01b-dnn-theo-thong-tu-133.htm` |
| 20 | Bản tin | Hướng dẫn | 23 | - | Thuế - Hóa đơn | Môn bài - Hộ kinh doanh - Nhà thầu - MST | Bãi bỏ lệ phí môn bài từ năm 2026 theo Nghị quyết 198/2025/QH15 | `bai-bo-le-phi-mon-bai-tu-nam-2026.htm` |
| 21 | Bản tin | Hướng dẫn | 23 | - | Kế toán | Nghiệp vụ theo phần hành | Công văn 2270/TCT-CS tiền thuê nhà của cá nhân là chi phí hợp lý | `cv-2270-tct-cs-tien-thue-nha-cua-ca-nhan-la-chi-phi-hop-ly.htm` |
| 22 | Bản tin | Hướng dẫn | 23 | - | Thuế - Hóa đơn | Thuế thu nhập doanh nghiệp | Công văn 2512/TCT-CS - Những nội dung mới của TT 96/2015/TT-BTC về thuế TNDN | `nhung-diem-moi-cua-thong-tu-96-ve-thue-thu-nhap-doanh-nghiep.htm` |
| 23 | Bản tin | Hướng dẫn | 23 | - | Thuế - Hóa đơn | Thuế thu nhập cá nhân | Công văn số 1381/TCT-TNCN Các khoản phụ cấp, trợ cấp để tính thuế TNCN | `cac-khoan-phu-cap-tro-cap-de-xac-dinh-thu-nhap-chiu-thue-tncn.htm` |
| 24 | Bản tin | Hướng dẫn | 23 | - | Thuế - Hóa đơn | Môn bài - Hộ kinh doanh - Nhà thầu - MST | Thông tư 152/2025/TT-BTC hướng dẫn kế toán cho hộ kinh doanh, cá nhân kinh doanh | `che-do-ke-toan-cho-ho-kinh-doanh-ca-nhan-kinh-doanh.htm` |
| 25 | Bản tin | Hướng dẫn | 23 | - | Thuế - Hóa đơn | Thuế thu nhập doanh nghiệp | Điểm mới cần chú ý của Thông tư 96/2015/TT-BTC về thuế TNDN | `diem-moi-can-chu-y-cua-thong-tu-96-2015-tt-btc-ve-thue-tndn.htm` |
| 26 | Thư viện | Biểu mẫu | 30 | legal-source mapped to library | Văn bản pháp luật | Nghị quyết - Quyết định | Quyết định 1408/QĐ-TLĐ Quy định về tài chính công đoàn | `quyet-dinh-1408-qd-tld-quy-dinh-ve-tai-chinh-cong-doan.htm` |
| 27 | Thư viện | Biểu mẫu | 30 | legal-source mapped to library | Văn bản pháp luật | Nghị quyết - Quyết định | Quyết định 8086/QĐ-TLĐ hướng dẫn thu đoàn phí công đoàn 2024 | `quyet-dinh-8086-qd-tld-huong-dan-thu-doan-phi-cong-doan.htm` |
| 28 | Thư viện | Biểu mẫu | 30 | legal-source mapped to library | Văn bản pháp luật | Nghị quyết - Quyết định | Quyết định ban hành hệ thống thang bảng lương 2025 | `quyet-dinh-ban-hanh-he-thong-thang-bang-luong.htm` |
| 29 | Thư viện | Biểu mẫu | 27 | - | Thuế - Hóa đơn | GTGT - Hóa đơn | Danh sách mã chương, mã tiểu mục nộp thuế mới nhất năm 2026 | `ma-chuong-ma-tieu-muc-nop-thue-tncn-thue-gtgt-thue-tndn.htm` |
| 30 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Tài khoản - Hạch toán | Mẫu bảng cân đối tài khoản theo Thông tư 133 – Cách lập | `cach-lap-bang-can-doi-tai-khoan-theo-thong-tu-133.htm` |
| 31 | Thư viện | Biểu mẫu | 27 | - | Lao động - Bảo hiểm | BHXH - BHYT - BHTN | Mẫu C70a-HD - Danh sách thanh toán chế độ thai sản, ốm đau | `danh-sach-thanh-toan-che-do-thai-san-mau-c70a-hd.htm` |
| 32 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Tài sản - Kho - CCDC | Mẫu Sổ theo dõi TSCĐ - CCDC tại nơi sử dụng theo TT 133 và 200 | `so-theo-doi-tscd-va-ccdc-tai-noi-su-dung-theo-tt-133-va-200.htm` |
| 33 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Báo cáo tài chính | Mẫu thuyết minh báo cáo tài chính theo Thông tư 133 – Cách lập | `cach-lap-thuyet-minh-bao-cao-tai-chinh-theo-thong-tu-133.htm` |
| 34 | Thư viện | Biểu mẫu | 27 | - | Thuế - Hóa đơn | GTGT - Hóa đơn | Quy định về ký hiệu mẫu số hóa đơn Giá trị gia tăng | `quy-dinh-ve-ky-hieu-mau-so-hoa-don-gia-tri-gia-tang.htm` |
| 35 | Thư viện | Biểu mẫu | 27 | - | Lao động - Bảo hiểm | BHXH - BHYT - BHTN | Sổ bảo hiểm xã hội | `so-bao-hiem-xa-hoi.htm` |
| 36 | Thư viện | Biểu mẫu | 27 | - | Thuế - Hóa đơn | GTGT - Hóa đơn | Sổ chi tiết thuế GTGT được hoàn lại theo Thông tư 99 | `so-chi-tiet-thue-gtgt-duoc-hoan-lai-theo-thong-tu-99.htm` |
| 37 | Thư viện | Biểu mẫu | 27 | - | Thuế - Hóa đơn | GTGT - Hóa đơn | Sổ chi tiết thuế GTGT được miễn giảm theo Thông tư 99 | `so-chi-tiet-thue-gtgt-duoc-mien-giam-theo-thong-tu-99.htm` |
| 38 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Chuẩn mực - Chế độ - Nguyên tắc | Sổ kế toán chi tiết theo dõi các khoản đầu tư vào công ty liên doanh | `so-ke-toan-chi-tiet-theo-doi-cac-khoan-dau-tu-vao-cong-ty-lien-doanh.htm` |
| 39 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Chuẩn mực - Chế độ - Nguyên tắc | Sổ kế toán chi tiết theo dõi các khoản đầu tư vào công ty liên kết | `so-ke-toan-chi-tiet-theo-doi-cac-khoan-dau-tu-vao-cong-ty-lien-ket.htm` |
| 40 | Thư viện | Biểu mẫu | 27 | - | Kế toán | Tài sản - Kho - CCDC | Sổ theo dõi TSCĐ và công cụ, dụng cụ tại nơi sử dụng | `so-theo-doi-tscd-va-cong-cu-dung-cu-tai-noi-su-dung-theo-tt99.htm` |
| 41 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Chính sách hỗ trợ Doanh nghiệp vừa và nhỏ của Chính phủ | `chuong-trinh-ho-tro-doanh-nghiep-vua-va-nho-cua-chinh-phu.htm` |
| 42 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các hành vi bị nghiêm cấm trong khấu trừ, hoàn thuế | `cac-hanh-vi-bi-nghiem-cam-trong-khau-tru-hoan-thue.htm` |
| 43 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các hành vi vi phạm hành chính về thuế theo TT 166 | `cac-hanh-vi-vi-pham-hanh-chinh-ve-thue-moi-nhat.htm` |
| 44 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các hình thức thanh toán không dùng tiền mặt 2026 mới nhất | `cac-hinh-thuc-thanh-toan-khong-dung-tien-mat.htm` |
| 45 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các loại báo cáo thuế phải nộp hàng quý - tháng cho cơ quan thuế | `cac-loai-bao-cao-thue-phai-nop-hang-thang-va-quy.htm` |
| 46 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các ngày nghỉ được hưởng nguyên lương năm 2026 | `cac-ngay-nghi-le-duoc-huong-luong-va-khong-huo-ng-luong.htm` |
| 47 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các phương pháp tính giá hàng nhập kho NVL - thành phẩm | `cac-phuong-phap-tinh-gia-hang-nhap-kho-nvl-thanh-pham.htm` |
| 48 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Các văn bản pháp luật thuế mới nhất năm 2015 | `cac-van-ban-phap-luat-thue-moi-nhat-nam-2015.htm` |
| 49 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Công ty mới thành lập cần làm những gì năm 2026 | `doanh-nghiep-moi-thanh-lap-can-lam-gi-nam-2026.htm` |
| 50 | Thư viện | Hướng dẫn | 28 | - | Học liệu - Tham khảo | Khác | Công ty Thiên Vũ Group tuyển Nhân viên Thủ kho - Thủ quỹ | `cong-ty-thien-vu-group-tuyen-nhan-vien-thu-kho-thu-quy.htm` |

## Gợi ý xử lý tay

1. Ưu tiên rà các bài có `Margin <= 20`
2. Sau đó rà các bài có cờ `doc+guide`, `doc+news`, `guide+news`
3. Nếu tiêu đề là mẫu biểu nhưng user sẽ vào để **làm theo**, cân nhắc chuyển `Library kind` từ `Biểu mẫu` sang `Hướng dẫn`
4. Nếu là văn bản cập nhật nhưng có giá trị tra cứu lâu dài, vẫn giữ `Bản tin` nếu tính thời điểm là trọng tâm

## Chạy lại

```bash
python3 Ketoandieutam.com/tools/audit_editorial_review_queue.py
```
