# QA readiness audit cho HTML site hiện tại

## Tóm tắt

- Tổng article metadata trong `data/articles.json`: **2066**
- Tổng file article HTML thực tế: **2066**
- Thư viện: **1835**
- Bản tin: **231**
- Hướng dẫn: **1146**
- Biểu mẫu: **523**
- Công cụ: **166**

## Kiểm tra cấu trúc

- Article HTML có `publishDate + authorName`: **2066 / 2066**
- Missing/invalid article-meta: **0**
- Đã kiểm tra internal refs: **1063**
- Internal refs lỗi (mẫu kiểm): **0**

## Preview article-meta lỗi

- Không có

## Preview internal refs lỗi

- Không có trong mẫu kiểm

## Kết luận vận hành

Site HTML được xem là **sẵn sàng chuyển sang giai đoạn editor PHP** khi:

- article count giữa `data/articles.json` và file HTML khớp nhau
- article-meta của toàn bộ bài có `publishDate` và `authorName`
- không còn internal ref lỗi trong mẫu kiểm
- taxonomy public ổn định: `Thư viện / Bản tin`
- taxonomy nội bộ ổn định: `Hướng dẫn / Biểu mẫu / Công cụ` + level 2/3 phù hợp