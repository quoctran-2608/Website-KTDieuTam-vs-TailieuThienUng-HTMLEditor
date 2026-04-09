# Spec form và workflow cho editor PHP — Tuyển dụng

## Mục tiêu

Tài liệu này chốt contract cho phần **Tuyển dụng** khi tích hợp vào editor PHP sau này.

Mục tiêu:
- thêm tin tuyển dụng
- sửa tin tuyển dụng
- đóng tin / hết hạn / nháp
- không sửa HTML raw
- ghi dữ liệu về đúng source `.md`
- trigger build để sinh lại:
  - `tuyen-dung.html`
  - `tuyen-dung/<slug>.html`
  - `data/jobs.json`
  - `data/feeds/tuyen-dung.json`
  - `sitemap-jobs.xml`

---

## 1) Nguồn dữ liệu editor phải thao tác

### Editor PHP không edit HTML public

Editor phải thao tác trên:
- `content/tuyen-dung/*.md`

### Mỗi tin tuyển dụng gồm 2 phần
1. **front matter metadata**
2. **body markdown**

---

## 2) Field form bắt buộc

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `title` | string | Có | tiêu đề job |
| `slug` | string | Có | duy nhất |
| `companyName` | string | Có | tên công ty |
| `location` | string | Có | địa điểm làm việc |
| `employmentType` | enum | Có | `full-time / part-time / internship / freelance / contract` |
| `workMode` | enum | Có | `onsite / hybrid / remote` |
| `deadline` | date | Có | hạn nộp hồ sơ |
| `publishDate` | date | Có | ngày đăng |
| `status` | enum | Có | `draft / active / expired / closed` |
| `summary` | text | Có | text cho card/list |

---

## 3) Field nên có trong form

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `salaryMin` | number | Không | có thể để trống |
| `salaryMax` | number | Không | có thể để trống |
| `salaryLabel` | string | Không | text hiển thị |
| `experienceLevel` | enum/string | Không | `fresher / 1-nam / 2-nam / 3-nam / senior ...` |
| `featured` | boolean | Có | checkbox |
| `urgent` | boolean | Có | checkbox |
| `contactName` | string | Không | đầu mối liên hệ |
| `contactPhone` | string | Không | số điện thoại |
| `contactEmail` | string | Không | email |
| `applyUrl` | string | Không | link nộp hồ sơ |
| `tags` | array | Không | tag hỗ trợ filter/search |
| `sourceSite` | string | Không | nếu là seed/import |
| `sourceUrl` | string | Không | link nguồn |
| `lastReviewedDate` | date | Không | ngày rà soát gần nhất |

---

## 4) Field hệ thống tự sinh

Editor không bắt user nhập tay các field sau:

| Field | Cách sinh |
|---|---|
| `id` | `job/<slug>` |
| `companySlug` | slugify từ `companyName` |
| `href` | builder tự sinh |

---

## 5) Body editor

Body nên edit bằng markdown, chia section rõ:

```md
## Mô tả công việc
## Yêu cầu
## Quyền lợi
## Thời gian và địa điểm làm việc
## Cách ứng tuyển
```

### Không cho edit raw HTML
Lý do:
- dễ hỏng layout
- khó sanitize
- khó maintain
- không phù hợp với static build pipeline hiện tại

---

## 6) Validation bắt buộc

## 6.1. Slug
- không rỗng
- URL-safe
- không trùng với file khác

## 6.2. Date
- `publishDate` đúng format `YYYY-MM-DD`
- `deadline` đúng format `YYYY-MM-DD`
- `deadline >= publishDate`

## 6.3. Status
Chỉ chấp nhận:
- `draft`
- `active`
- `expired`
- `closed`

## 6.4. Summary
- không để trống
- không quá dài
- khuyến nghị 140–220 ký tự

## 6.5. Nguồn
Nếu tin được import từ nguồn ngoài:
- nên có `sourceSite`
- nên có `sourceUrl`

---

## 7) Hành vi CRUD

## 7.1. Create
Khi tạo mới:
1. nhập form
2. validate
3. sinh front matter
4. sinh file:
   - `content/tuyen-dung/<slug>.md`
5. trigger build jobs

## 7.2. Update
Khi sửa:
1. load file `.md`
2. map metadata vào form
3. map body markdown vào editor
4. save lại file cũ
5. trigger build jobs

## 7.3. Delete
Khuyến nghị:
- **không xóa cứng ngay**

Nên có 2 mức:
1. đổi `status = closed`
2. chỉ xóa file thật khi admin xác nhận mức cao hơn

---

## 8) Trigger build sau khi save

Sau mỗi lần create/update/delete, editor nên gọi:

```bash
python3 tools/build_jobs.py
python3 tools/audit_jobs_data.py
```

## Kết quả kỳ vọng
- HTML public cập nhật
- JSON cập nhật
- sitemap jobs cập nhật
- audit report cập nhật

---

## 9) Side effects editor cần biết

Sau khi save 1 job:
- `content/tuyen-dung/*.md` thay đổi
- `tuyen-dung.html` thay đổi
- `tuyen-dung/<slug>.html` thay đổi
- `data/jobs.json` thay đổi
- `data/feeds/tuyen-dung.json` thay đổi
- `sitemap-jobs.xml` thay đổi
- `robots.txt` có thể được bổ sung sitemap jobs nếu chưa có

---

## 10) Quyền truy cập tối thiểu

Editor PHP cần:
- quyền đọc:
  - `content/tuyen-dung/`
  - `data/`
- quyền ghi:
  - `content/tuyen-dung/`
  - `data/`
  - `tuyen-dung/`
  - `tuyen-dung.html`
  - `sitemap-jobs.xml`
  - `docs/nghien-cuu-tuyen-dung/bao-cao-audit-du-lieu-tuyen-dung.md`
- quyền thực thi:
  - `python3 tools/build_jobs.py`
  - `python3 tools/audit_jobs_data.py`

---

## 11) Preview nên có trong editor

Form editor nên preview được:
- URL public
- title card
- summary card
- badges `featured / urgent`
- trạng thái hiển thị
- cảnh báo nếu job đã quá hạn nhưng vẫn đang `active`

---

## 12) Quyết định chốt

### PHP editor cho Tuyển dụng phải:
- edit `.md + metadata`
- không edit HTML raw
- luôn validate schema trước khi save
- luôn trigger build sau khi save

Đây là cách ít technical debt nhất và khớp với kiến trúc static hiện tại của site.
