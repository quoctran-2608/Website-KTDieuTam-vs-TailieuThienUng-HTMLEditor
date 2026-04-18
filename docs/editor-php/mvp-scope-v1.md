# MVP Scope v1 — Admin Editor PHP (Thư viện / Bản tin)

## 1) Mục tiêu v1

Xây phiên bản đầu tiên đủ dùng cho vận hành nội bộ:

- admin đăng nhập được
- tìm bài nhanh theo trải nghiệm giống hub `Thư viện / Bản tin`
- mở và sửa **từng bài một**
- có luồng an toàn: `Draft -> Preview -> Publish -> Rollback`

Mục tiêu v1 là vận hành ổn định, chưa tối ưu mọi tính năng nâng cao.

---

## 2) In-scope (làm trong v1)

### 2.1 Auth & phân quyền
- đăng nhập/đăng xuất
- role `admin`, `editor`
- session timeout + CSRF

### 2.2 Danh sách bài trực quan (article list)
- search theo `title`, `id`, `href`
- filter theo:
  - `section` (`thu-vien`, `ban-tin`)
  - `libraryKindKey` (khi `section=thu-vien`)
  - `topicLv1Key`, `topicLv2Key`
  - `publishDate` range
- sort + pagination
- mở trang sửa từ từng item

### 2.3 Sửa bài đơn lẻ
- cho sửa:
  - `title`
  - `excerpt/summary`
  - `publishDate`
  - `modifiedDate`
  - `tags`
  - khối nội dung trong `.article-prose`
  - payload `article-meta` (những field nằm trong phạm vi v1)

### 2.4 Luồng publish an toàn
- lưu draft
- preview before/after
- publish ghi file bài
- backup file trước publish
- rollback về backup gần nhất
- ghi audit log (ai sửa gì, lúc nào)

---

## 3) Out-of-scope (không làm trong v1)

- đổi `slug`, `href`, `canonical` hàng loạt
- đổi taxonomy sâu làm thay URL/breadcrumb toàn site
- media manager upload phức tạp
- queue worker phân tán
- bulk publish

---

## 4) Contract field v1

## 4.1 Editable

| Field | Cho sửa v1 | Ghi chú |
|---|---:|---|
| `title` | Có | cập nhật title hiển thị |
| `excerpt` | Có | mô tả ngắn |
| `publishDate` | Có | giữ định dạng `YYYY-MM-DD` |
| `modifiedDate` | Có | auto set nếu editor không nhập |
| `tags[]` | Có | dedupe + trim |
| `article-prose` | Có | nội dung chính của bài |
| `article-meta` subset | Có | cập nhật đồng bộ metadata v1 |

## 4.2 Read-only trong v1

| Field | Read-only v1 | Lý do |
|---|---:|---|
| `id` | Có | khóa định danh bài |
| `section` | Có | tránh lệch taxonomy |
| `libraryKindKey` | Có | tránh đụng URL/filter |
| `href` | Có | tránh gãy link nội bộ |
| `canonical` | Có | tránh SEO drift |
| `topicLv1Key/topicLv2Key` | Có | dời qua phase sau |

---

## 5) Ràng buộc kỹ thuật v1

- Public site vẫn là static HTML.
- Không cho editor sửa trực tiếp file ngoài phạm vi bài mục tiêu.
- Publish phải tạo backup trước khi ghi.
- Rollback không được xóa backup cũ.
- Mọi thao tác publish/rollback phải có audit log.

---

## 6) Yêu cầu UX v1 (bắt buộc)

- list/filter nhanh, không rối
- filter chip rõ trạng thái đang áp dụng
- có empty state rõ ràng
- form edit chia khối:
  - Metadata
  - Nội dung
  - Preview diff
  - Publish controls
- thao tác nguy hiểm có confirm

---

## 7) Definition of Done (MVP)

MVP v1 đạt khi:

1. admin đăng nhập và vào list bài được
2. filter/search trả đúng kết quả
3. mở sửa 1 bài, lưu draft, preview diff
4. publish cập nhật bài thật + có backup
5. rollback khôi phục được bản trước
6. audit log truy vết đầy đủ

