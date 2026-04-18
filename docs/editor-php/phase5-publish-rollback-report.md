# Phase 5 Publish & Rollback Report

## Mục tiêu đã hoàn thành

- Backup file thật trước khi publish
- Ghi lại đúng vùng `.article-prose`
- Ghi lại đúng `script#article-meta`
- Cập nhật metadata index (`data/articles.json`) cho field cần thiết
- Rollback về backup gần nhất bằng 1 thao tác
- Ghi audit log + publish history

## Module mới

- `admin/includes/article_publish.php`
  - bootstrap publish storage
  - publish draft -> file thật
  - rollback từ backup gần nhất
  - append publish/rollback record
  - sync 1 entry trong `data/articles.json`

## Artifact mới

- `admin/storage/backups/` (file backup HTML)
- `admin/storage/publish-history.json`

## Luồng publish

1. Editor lưu draft
2. Click `Publish`
3. Hệ thống:
   - parse lại vùng ghi
   - backup file hiện tại
   - ghi prose + article-meta + title + description + summary
   - append publish record
   - sync `data/articles.json` cho bài đó

## Luồng rollback

1. Click `Rollback gần nhất`
2. Hệ thống:
   - lấy publish record mới nhất
   - backup trạng thái hiện tại trước khi restore
   - copy backup cũ về target
   - append rollback record

## Ghi chú

Phase 5 đã có khả năng ghi file thật.  
Phase 6 tập trung vào hardening, smoke/regression và runbook vận hành.

