# QA readiness audit cho HTML site hiện tại

## Tóm tắt

- Tổng article metadata trong `data/articles.json`: **2064**
- Tổng file article HTML thực tế: **2064**
- Thư viện: **2039**
- Bản tin: **25**
- Hướng dẫn: **1064**
- Biểu mẫu: **669**
- Công cụ: **0**

## Kiểm tra cấu trúc

- Article HTML có `publishDate + authorName`: **2064 / 2064**
- Missing/invalid article-meta: **0**
- Đã kiểm tra internal refs: **1422**
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