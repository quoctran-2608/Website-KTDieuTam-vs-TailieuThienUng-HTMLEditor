# Phase 2 Quickstart — Article List & Filter

## Mục tiêu phase

Mở module danh sách bài trong admin để:

- tìm nhanh theo `title/id/href`
- lọc theo section/taxonomy/date
- sắp xếp + phân trang
- mở trang chi tiết bài (entry cho Phase 3)

---

## Chạy local

```bash
php -S 127.0.0.1:8080 -t .
```

Mở:

- `http://127.0.0.1:8080/admin/`
- vào menu **Bài viết**

---

## Healthcheck

```bash
php admin/includes/healthcheck.php
```

Kỳ vọng có các dòng:

- `articles source exists`
- `articles index cache ready`

---

## Luồng test thủ công đề xuất

1. Login bằng `admin / admin123`
2. Vào `/admin/articles.php`
3. Tìm từ khóa bất kỳ (ví dụ `thuế`)
4. Lọc `section=thu-vien`
5. Lọc thêm `library kind`
6. Đổi sort + per page
7. Chuyển trang pagination
8. Click `Xem` để mở bài public
9. Click `Sửa` để vào entry chi tiết (`/admin/article.php?id=...`)

---

## Ghi chú kỹ thuật

- Index cache dùng file: `admin/storage/articles-index.json`
- Source đọc từ: `data/articles.json`
- Mỗi request bootstrap sẽ đồng bộ cache khi source đổi mtime
- `admin/article.php` hiện là placeholder có dữ liệu tóm tắt, parser-safe đầy đủ sẽ nằm ở Phase 3

