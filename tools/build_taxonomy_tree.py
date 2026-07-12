#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Build full taxonomy tree for KT Diệu Tâm website
Theo tài liệu: Phân loại tài liệu KT Diệu Tâm.md
"""

import json
import re
import unicodedata
import os
import shutil
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MASTER_PATH = os.path.join(ROOT, 'data', 'taxonomy-master.json')
BACKUP_DIR = os.path.join(ROOT, '.m', 'taxonomy-admin')

def slugify(text):
    text = text.strip().lower()
    text = text.replace('đ', 'd').replace('Đ', 'd')
    text = unicodedata.normalize('NFD', text)
    text = ''.join(c for c in text if unicodedata.category(c) != 'Mn')
    text = re.sub(r'[^a-z0-9]+', '-', text)
    text = text.strip('-')
    return text or 'category'

def make_node(label, key=None, children=None, icon=None, description=None):
    k = key or slugify(label)
    node = {'key': k, 'label': label}
    if icon:
        node['icon'] = icon
    if description:
        node['description'] = description
    if children:
        node['children'] = children
    return node

# ── CÂY THƯ VIỆN - "PHÂN LOẠI MỚI" ──────────────────────────────────────────
# Đây là cây con bên trong node "phan-loai-moi" của thu-vien
# Mỗi node level 2 sẽ là con trực tiếp của "phan-loai-moi"

THU_VIEN_PHAN_LOAI_MOI_CHILDREN = [
    make_node("Tải về", "tai-ve", icon="fa-download", description="Tài liệu, biểu mẫu, file tải về", children=[
        make_node("Tài liệu kế toán"),
        make_node("Biểu mẫu kế toán"),
        make_node("Mẫu thuế"),
        make_node("Mẫu hóa đơn – chứng từ", "mau-hoa-don-chung-tu"),
        make_node("Mẫu lao động – BHXH", "mau-lao-dong-bhxh"),
        make_node("File Excel kế toán", "file-excel-ke-toan"),
        make_node("File Word / PDF", "file-word-pdf"),
        make_node("Checklist kế toán – thuế", "checklist-ke-toan-thue"),
        make_node("Phần mềm / ứng dụng hỗ trợ", "phan-mem-ung-dung"),
    ]),
    make_node("Văn bản pháp luật", "van-ban-phap-luat", icon="fa-scale-balanced", description="Luật, nghị định, thông tư, công văn và văn bản pháp lý", children=[
        make_node("Luật"),
        make_node("Nghị định", "nghi-dinh"),
        make_node("Thông tư", "thong-tu"),
        make_node("Công văn", "cong-van"),
        make_node("Nghị quyết – Quyết định", "nghi-quyet-quyet-dinh"),
        make_node("Văn bản còn hiệu lực / hết hiệu lực", "van-ban-hieu-luc"),
    ]),
    make_node("Tài liệu thuế", "tai-lieu-thue", icon="fa-receipt", description="Tài liệu tra cứu và hướng dẫn về các sắc thuế", children=[
        make_node("Thuế GTGT", "thue-gtgt"),
        make_node("Thuế TNDN", "thue-tndn"),
        make_node("Thuế TNCN", "thue-tncn"),
        make_node("Lệ phí môn bài", "le-phi-mon-bai"),
        make_node("Thuế nhà thầu", "thue-nha-thau"),
        make_node("Thuế xuất nhập khẩu", "thue-xuat-nhap-khau"),
        make_node("Đăng ký thuế – Mã số thuế", "dang-ky-thue-ma-so-thue"),
        make_node("Hoàn thuế", "hoan-thue"),
        make_node("Xử phạt thuế", "xu-phat-thue"),
    ]),
    make_node("Tài liệu kế toán", "tai-lieu-ke-toan", icon="fa-book-open", description="Chế độ kế toán, chuẩn mực, hạch toán, sổ sách", children=[
        make_node("Chế độ kế toán", "che-do-ke-toan"),
        make_node("Chuẩn mực kế toán", "chuan-muc-ke-toan"),
        make_node("Hệ thống tài khoản", "he-thong-tai-khoan"),
        make_node("Định khoản – hạch toán", "dinh-khoan-hach-toan"),
        make_node("Sổ sách kế toán", "so-sach-ke-toan"),
        make_node("Báo cáo tài chính", "bao-cao-tai-chinh"),
        make_node("Kế toán theo phần hành", "ke-toan-theo-phan-hanh"),
    ]),
    make_node("Hóa đơn – Chứng từ", "hoa-don-chung-tu", icon="fa-file-invoice", description="Hóa đơn điện tử, lập xuất hóa đơn, xử lý sai sót", children=[
        make_node("Hóa đơn điện tử", "hoa-don-dien-tu"),
        make_node("Hóa đơn máy tính tiền", "hoa-don-may-tinh-tien"),
        make_node("Lập / xuất hóa đơn", "lap-xuat-hoa-don"),
        make_node("Xử lý sai sót hóa đơn", "xu-ly-sai-sot-hoa-don"),
        make_node("Hóa đơn đầu vào / đầu ra", "hoa-don-dau-vao-dau-ra"),
        make_node("Chứng từ kế toán", "chung-tu-ke-toan"),
        make_node("Lưu trữ hồ sơ", "luu-tru-ho-so"),
    ]),
    make_node("Lao động – BHXH", "lao-dong-bhxh", icon="fa-users", description="Hợp đồng, tiền lương, bảo hiểm xã hội", children=[
        make_node("Hợp đồng lao động", "hop-dong-lao-dong"),
        make_node("Tiền lương – bảng lương", "tien-luong-bang-luong"),
        make_node("Chấm công", "cham-cong"),
        make_node("BHXH – BHYT – BHTN", "bhxh-bhyt-bhtn"),
        make_node("Công đoàn", "cong-doan"),
        make_node("Thuế TNCN tiền lương", "thue-tncn-tien-luong"),
        make_node("Hồ sơ nhân sự", "ho-so-nhan-su"),
    ]),
    make_node("Hộ kinh doanh", "ho-kinh-doanh", icon="fa-store", description="Thuế, hóa đơn, sổ sách cho hộ kinh doanh", children=[
        make_node("Thuế hộ kinh doanh", "thue-ho-kinh-doanh"),
        make_node("Hóa đơn hộ kinh doanh", "hoa-don-ho-kinh-doanh"),
        make_node("Sổ sách hộ kinh doanh", "so-sach-ho-kinh-doanh"),
        make_node("Kê khai – nộp thuế", "ke-khai-nop-thue"),
        make_node("Chứng từ hộ kinh doanh", "chung-tu-ho-kinh-doanh"),
        make_node("Chuyển đổi lên doanh nghiệp", "chuyen-doi-len-doanh-nghiep"),
    ]),
    make_node("Doanh nghiệp – Thủ tục", "doanh-nghiep-thu-tuc", icon="fa-building", description="Thành lập, thay đổi, tạm ngừng, giải thể doanh nghiệp", children=[
        make_node("Thành lập doanh nghiệp", "thanh-lap-doanh-nghiep"),
        make_node("Thay đổi đăng ký kinh doanh", "thay-doi-dang-ky-kinh-doanh"),
        make_node("Đăng ký thuế ban đầu", "dang-ky-thue-ban-dau"),
        make_node("Tạm ngừng kinh doanh", "tam-ngung-kinh-doanh"),
        make_node("Giải thể doanh nghiệp", "giai-the-doanh-nghiep"),
        make_node("Thủ tục khuyến mại – thương mại", "thu-tuc-khuyen-mai-thuong-mai"),
        make_node("Thủ tục tài chính – vay vốn", "thu-tuc-tai-chinh-vay-von"),
        make_node("Mẫu biểu doanh nghiệp", "mau-bieu-doanh-nghiep"),
    ]),
    make_node("Tra cứu", "tra-cuu", icon="fa-magnifying-glass", description="Tra cứu nhanh tài khoản, biểu thuế, lương, mức phạt", children=[
        make_node("Hệ thống tài khoản kế toán", "he-thong-tai-khoan-ke-toan"),
        make_node("Biểu thuế TNCN", "bieu-thue-tncn"),
        make_node("Mức lương tối thiểu vùng", "muc-luong-toi-thieu-vung"),
        make_node("Mức phạt thuế – hóa đơn", "muc-phat-thue-hoa-don"),
        make_node("Thời hạn nộp tờ khai", "thoi-han-nop-to-khai"),
        make_node("Mã ngành nghề kinh doanh", "ma-nganh-nghe-kinh-doanh"),
        make_node("Mã số thuế", "ma-so-thue"),
        make_node("Văn bản còn hiệu lực / hết hiệu lực", "van-ban-con-hieu-luc"),
    ]),
    make_node("Công cụ – Tiện ích", "cong-cu-tien-ich", icon="fa-calculator", description="Các công cụ tính thuế, lương, khấu hao trực tuyến", children=[
        make_node("Tính thuế TNCN", "tinh-thue-tncn"),
        make_node("Tính lương Gross sang Net", "tinh-luong-gross-sang-net"),
        make_node("Tính BHXH bắt buộc", "tinh-bhxh-bat-buoc"),
        make_node("Tính thuế GTGT", "tinh-thue-gtgt"),
        make_node("Tính tiền chậm nộp thuế", "tinh-tien-cham-nop-thue"),
        make_node("Tính khấu hao TSCĐ", "tinh-khau-hao-tscd"),
        make_node("Tính phân bổ công cụ dụng cụ", "tinh-phan-bo-cong-cu-dung-cu"),
    ]),
    make_node("Phần mềm – Công cụ nghiệp vụ", "phan-mem-cong-cu-nghiep-vu", icon="fa-laptop-code", description="HTKK, eTax, MISA, FAST, Excel kế toán", children=[
        make_node("HTKK", "htkk"),
        make_node("Thuế điện tử / eTax", "thue-dien-tu-etax"),
        make_node("Hóa đơn điện tử", "hoa-don-dien-tu-pm"),
        make_node("MISA", "misa"),
        make_node("FAST", "fast"),
        make_node("Excel kế toán", "excel-ke-toan"),
        make_node("Công cụ hỗ trợ kê khai", "cong-cu-ho-tro-ke-khai"),
    ]),
]

# ── CÂY BẢN TIN ──────────────────────────────────────────────────────────────
BAN_TIN_CHILDREN = [
    make_node("Chính sách mới", "chinh-sach-moi", children=[
        make_node("Tin thuế mới", "tin-thue-moi"),
        make_node("Tin hóa đơn mới", "tin-hoa-don-moi"),
        make_node("Tin kế toán mới", "tin-ke-toan-moi"),
        make_node("Tin BHXH – lao động mới", "tin-bhxh-lao-dong-moi"),
        make_node("Văn bản mới cần biết", "van-ban-moi-can-biet"),
        make_node("Chính sách sắp có hiệu lực", "chinh-sach-sap-co-hieu-luc"),
    ]),
    make_node("Kiến thức thuế", "kien-thuc-thue", children=[
        make_node("Thuế GTGT", "thue-gtgt"),
        make_node("Thuế TNDN", "thue-tndn"),
        make_node("Thuế TNCN", "thue-tncn"),
        make_node("Lệ phí môn bài", "le-phi-mon-bai"),
        make_node("Thuế nhà thầu", "thue-nha-thau"),
        make_node("Hoàn thuế", "hoan-thue"),
        make_node("Xử phạt thuế", "xu-phat-thue"),
        make_node("Quản lý thuế", "quan-ly-thue"),
    ]),
    make_node("Kiến thức kế toán", "kien-thuc-ke-toan", children=[
        make_node("Hạch toán kế toán", "hach-toan-ke-toan"),
        make_node("Sổ sách kế toán", "so-sach-ke-toan"),
        make_node("Báo cáo tài chính", "bao-cao-tai-chinh"),
        make_node("Chi phí – doanh thu", "chi-phi-doanh-thu"),
        make_node("Công nợ", "cong-no"),
        make_node("Hàng tồn kho", "hang-ton-kho"),
        make_node("Tài sản cố định", "tai-san-co-dinh"),
        make_node("Giá thành", "gia-thanh"),
    ]),
    make_node("Hướng dẫn thực hành", "huong-dan-thuc-hanh", children=[
        make_node("Hướng dẫn kê khai thuế", "huong-dan-ke-khai-thue"),
        make_node("Hướng dẫn xuất hóa đơn", "huong-dan-xuat-hoa-don"),
        make_node("Hướng dẫn xử lý sai sót hóa đơn", "huong-dan-xu-ly-sai-sot-hoa-don"),
        make_node("Hướng dẫn lập bảng lương", "huong-dan-lap-bang-luong"),
        make_node("Hướng dẫn làm báo cáo tài chính", "huong-dan-lam-bao-cao-tai-chinh"),
        make_node("Hướng dẫn quyết toán thuế", "huong-dan-quyet-toan-thue"),
        make_node("Hướng dẫn sử dụng phần mềm", "huong-dan-su-dung-phan-mem"),
    ]),
    make_node("Kinh nghiệm làm nghề", "kinh-nghiem-lam-nghe", children=[
        make_node("Kinh nghiệm cho kế toán mới", "kinh-nghiem-ke-toan-moi"),
        make_node("Kinh nghiệm quyết toán thuế", "kinh-nghiem-quyet-toan-thue"),
        make_node("Kinh nghiệm xử lý hồ sơ", "kinh-nghiem-xu-ly-ho-so"),
        make_node("Kinh nghiệm làm việc với cơ quan thuế", "kinh-nghiem-lam-viec-co-quan-thue"),
        make_node("Kinh nghiệm phỏng vấn kế toán", "kinh-nghiem-phong-van-ke-toan"),
        make_node("Mô tả công việc kế toán", "mo-ta-cong-viec-ke-toan"),
        make_node("Lỗi kế toán thường gặp", "loi-ke-toan-thuong-gap"),
        make_node("Kỹ năng nghề kế toán", "ky-nang-nghe-ke-toan"),
    ]),
    make_node("Tình huống thực tế", "tinh-huong-thuc-te", children=[
        make_node("Tình huống về hóa đơn", "tinh-huong-hoa-don"),
        make_node("Tình huống về chi phí", "tinh-huong-chi-phi"),
        make_node("Tình huống về thuế", "tinh-huong-thue"),
        make_node("Tình huống về lương – BHXH", "tinh-huong-luong-bhxh"),
        make_node("Tình huống về công nợ", "tinh-huong-cong-no"),
        make_node("Tình huống thanh tra – kiểm tra", "tinh-huong-thanh-tra-kiem-tra"),
    ]),
    make_node("Góc chủ doanh nghiệp", "goc-chu-doanh-nghiep", children=[
        make_node("Hiểu doanh thu – lợi nhuận – dòng tiền", "hieu-doanh-thu-loi-nhuan-dong-tien"),
        make_node("Kiểm soát chi phí", "kiem-soat-chi-phi"),
        make_node("Quản lý công nợ", "quan-ly-cong-no"),
        make_node("Rủi ro thuế thường gặp", "rui-ro-thue-thuong-gap"),
        make_node("Đọc báo cáo tài chính", "doc-bao-cao-tai-chinh"),
        make_node("Quản trị tài chính doanh nghiệp nhỏ", "quan-tri-tai-chinh-doanh-nghiep-nho"),
    ]),
    make_node("Hỏi đáp – Giải đáp", "hoi-dap-giai-dap", children=[
        make_node("Hỏi đáp thuế", "hoi-dap-thue"),
        make_node("Hỏi đáp kế toán", "hoi-dap-ke-toan"),
        make_node("Hỏi đáp hóa đơn", "hoi-dap-hoa-don"),
        make_node("Hỏi đáp BHXH – lao động", "hoi-dap-bhxh-lao-dong"),
        make_node("Hỏi đáp hộ kinh doanh", "hoi-dap-ho-kinh-doanh"),
        make_node("Hỏi đáp doanh nghiệp mới thành lập", "hoi-dap-doanh-nghiep-moi"),
    ]),
    make_node("Học liệu – Thực hành", "hoc-lieu-thuc-hanh", children=[
        make_node("Bài tập kế toán", "bai-tap-ke-toan"),
        make_node("Bài tập thuế", "bai-tap-thue"),
        make_node("Bài tập hạch toán", "bai-tap-hach-toan"),
        make_node("Báo cáo thực tập kế toán", "bao-cao-thuc-tap-ke-toan"),
        make_node("Tự học kế toán thực tế", "tu-hoc-ke-toan-thuc-te"),
        make_node("Tài liệu ôn tập nghề kế toán", "tai-lieu-on-tap-nghe-ke-toan"),
    ]),
]


def backup():
    os.makedirs(BACKUP_DIR, exist_ok=True)
    ts = datetime.now().strftime('%Y%m%d-%H%M%S')
    if os.path.exists(MASTER_PATH):
        shutil.copy2(MASTER_PATH, os.path.join(BACKUP_DIR, f'taxonomy-master-{ts}.json'))
    print(f'✅ Backup saved: taxonomy-master-{ts}.json')


def main():
    with open(MASTER_PATH, 'r', encoding='utf-8') as f:
        master = json.load(f)

    roots = master.get('roots', [])

    # 1. Tìm node "thu-vien" → "phan-loai-moi" và thay thế/thêm children
    for root in roots:
        if root.get('key') == 'thu-vien':
            children = root.get('children', [])
            for child in children:
                if child.get('key') == 'phan-loai-moi':
                    # Thêm tất cả các node phân loại mới vào đây
                    existing_keys = {c.get('key') for c in child.get('children', [])}
                    added = 0
                    for new_child in THU_VIEN_PHAN_LOAI_MOI_CHILDREN:
                        if new_child['key'] not in existing_keys:
                            if 'children' not in child:
                                child['children'] = []
                            child['children'].append(new_child)
                            added += 1
                            print(f'  ✅ Thêm vào phan-loai-moi: {new_child["key"]} - {new_child["label"]}')
                        else:
                            print(f'  ⏭️  Đã tồn tại: {new_child["key"]}')
                    print(f'\n✅ Đã thêm {added} nodes vào thu-vien/phan-loai-moi')
                    break
            break

    # 2. Thêm các nodes vào "ban-tin" nếu chưa có
    for root in roots:
        if root.get('key') == 'ban-tin':
            existing_keys = {c.get('key') for c in root.get('children', [])}
            added = 0
            for new_child in BAN_TIN_CHILDREN:
                if new_child['key'] not in existing_keys:
                    if 'children' not in root:
                        root['children'] = []
                    root['children'].append(new_child)
                    added += 1
                    print(f'  ✅ Thêm vào ban-tin: {new_child["key"]} - {new_child["label"]}')
                else:
                    print(f'  ⏭️  Đã tồn tại trong ban-tin: {new_child["key"]}')
            print(f'\n✅ Đã thêm {added} nodes vào ban-tin')
            break

    master['roots'] = roots

    # Save
    with open(MASTER_PATH, 'w', encoding='utf-8') as f:
        json.dump(master, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print(f'\n✅ Đã ghi taxonomy-master.json')


if __name__ == '__main__':
    backup()
    main()
    print('\n🎉 Hoàn tất! Chạy sync để cập nhật taxonomy.json')
