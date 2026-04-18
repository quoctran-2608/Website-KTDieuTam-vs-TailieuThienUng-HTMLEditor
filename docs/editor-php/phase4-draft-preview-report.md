# Phase 4 Draft & Preview Report

## Mục tiêu đã hoàn thành

- Form edit theo contract v1 ngay trong `admin/article.php`
- Save draft vào storage riêng
- Hiển thị before/after diff
- Preview render nội dung prose
- Validation theo field, thông điệp rõ ràng

## Module mới

- `admin/includes/article_draft.php`
  - tạo file draft storage
  - đọc/ghi draft theo `article_id`
  - xóa draft
  - log sự kiện lưu/xóa draft

## Artifact mới

- `admin/storage/article-drafts.json`

## Trường dữ liệu đang hỗ trợ draft

- `title`
- `excerpt`
- `publish_date`
- `modified_date`
- `tags` (3-7)
- `prose_html`

## Luồng thao tác

1. Mở `/admin/article.php?id=...`
2. Kiểm tra parser-safe detail
3. Chỉnh form
4. `Lưu Draft` -> ghi vào `article-drafts.json`
5. Xem bảng diff + preview render
6. Reload trang -> draft vẫn còn (đáp ứng reopen draft)

## Ghi chú

Phase 4 chỉ dừng ở draft/preview.  
Publish ghi file thật sẽ triển khai ở Phase 5 để giữ an toàn vận hành.

