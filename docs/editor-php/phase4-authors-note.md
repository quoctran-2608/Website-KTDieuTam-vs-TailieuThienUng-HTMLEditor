# Phase 4 — Notes cho reviewer

## Vì sao chưa có nút Publish ở phase này

Phase 4 chỉ mở **draft + preview** để giảm rủi ro ghi nhầm file HTML thật.

Các hành động có side effect mạnh:

- ghi lại `.article-prose`
- ghi lại `article-meta`
- backup/rollback

sẽ được triển khai trong Phase 5 để tách biệt kiểm thử.

## Giới hạn hiện tại của form draft

- form chỉ chỉnh subset field theo contract v1
- chưa chạm vào `href`, `canonical`, taxonomy key
- draft lưu riêng ở `admin/storage/article-drafts.json`

## Lý do thiết kế preview live theo textarea

- editor nội bộ cần nhìn nhanh hiệu ứng bố cục sau mỗi sửa
- live preview ở client giúp giảm thao tác submit vòng lặp
- server vẫn là nơi validate chính khi lưu draft

