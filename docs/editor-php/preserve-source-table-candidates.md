# Danh sách bài đang bật preserve-source-width cho bảng biểu mẫu hẹp

## Mục đích

File này dùng để:

- theo dõi các bài hiện đang dùng lớp **preserve-source-width**
- QA nhanh sau mỗi lần chỉnh `article-layout.js`
- làm mẫu tham chiếu khi import tiếp hơn 2000 bài legacy

> Lưu ý: hiện tại **mọi bảng trên mobile đều fit-to-viewport**.  
> Danh sách dưới đây là các bài được cộng thêm lớp giữ width gốc của bảng/cột vì có tính chất **biểu mẫu hẹp nhiều cột nhỏ**.

---

## Rule preserve-source-width hiện tại

Một bài sẽ bật **article-level preserve mode** nếu trong bài có ít nhất 1 bảng thỏa:

- `maxCols >= 8`
- và:
  - `smallCount >= 8`
  - hoặc `smallCount >= 6` khi bảng có rất nhiều width inline (`>= 40`)

Trong đó:

- `smallCount` = số lượng width inline `<= 72px`
- width nhỏ thường gặp:
  - `31px`
  - `36px`
  - `48px`
  - `60px`
  - `72px`

### Cách xử lý

- giữ width gốc của `table`
- không xóa width cột nhỏ của `td/th`
- giữ font inline trong bảng
- `table-layout: auto`
- `word-break: normal`
- `overflow-wrap: normal`
- mobile:
  - **fit-to-viewport**
  - có **safe fix** chống cắt phần cuối sau khi scale

---

## Bài hiện đang bật preserve mode

| STT | File | Lý do mạnh nhất |
|---|---|---|
| 1 | `thu-vien/bang-bang-cham-cong-mau-so-01a-ldtl.html` | `maxCols=13`, `smallCount=43`, `widthCount=84` |
| 2 | `thu-vien/bang-cham-cong-lam-them-gio-mau-so-01b-ldtl.html` | `maxCols=10`, `smallCount=162`, `widthCount=184` |
| 3 | `thu-vien/bang-ke-giam-tru-gia-canh-cho-nguoi-phu-thuoc-mau-2-1-bk-qtt-tncn.html` | `maxCols=17`, `smallCount=41`, `widthCount=126` |
| 4 | `thu-vien/bang-ke-hang-hoa-dich-vu-ban-ra-01-1-gtgt-theo-tt-119.html` | `maxCols=15`, `smallCount=14`, `widthCount=16` |
| 5 | `thu-vien/bang-thanh-toan-tien-luong-mau-so-02-ldtl.html` | `maxCols=20`, `smallCount=149`, `widthCount=151` |
| 6 | `thu-vien/bao-cao-su-dung-chung-tu-khau-tru-thue-tncn-ctt25-ac.html` | `maxCols=8`, `smallCount=6`, `widthCount=64` |

---

## Checklist QA nhanh

1. mở 6 bài ở bảng trên
2. kiểm tra:
   - bảng không vỡ từng ký tự
   - mobile không có scroll ngang không cần thiết
   - bảng không bị cắt phần cuối sau khi scale
   - các row cuối vẫn hiện đầy đủ

---

## Quy trình áp dụng cho hơn 2000 bài import sau

1. import bài
2. scan heuristic bài-level cho bảng biểu mẫu hẹp
3. nếu trúng rule:
   - bật preserve-source-width cho cả bài
4. mobile:
   - fit-to-viewport cho mọi bảng
   - bật safe fix chống clip chiều cao
5. ghi bài mới vào file này nếu cần QA theo dõi

---

## File kỹ thuật liên quan

- `Ketoandieutam.com/article-layout.js`
- `Ketoandieutam.com/assets/css/content-hub.css`
- `legacy-import-pipeline.md`
- `legacy-mobile-import-audit.md`
