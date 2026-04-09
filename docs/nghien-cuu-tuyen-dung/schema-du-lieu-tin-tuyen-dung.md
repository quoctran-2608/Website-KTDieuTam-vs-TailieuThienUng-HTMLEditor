# Schema dữ liệu chuẩn cho 1 tin tuyển dụng

## 1) Mục đích

Tài liệu này chốt schema dữ liệu chuẩn cho tính năng **Tuyển dụng** tại KetoanDieuTam.com.

Schema này là nền cho:
- file source `.md`
- build HTML public
- build `data/jobs.json`
- editor PHP sau này

---

## 2) Quyết định kỹ thuật

- **Source of truth:** file Markdown (`.md`)
- **Metadata:** YAML front matter
- **Output public:** HTML
- **Output dữ liệu:** JSON

---

## 3) Cấu trúc file chuẩn

```md
---
metadata ở đây
---

nội dung body markdown ở đây
```

---

## 4) Field bắt buộc

Các field dưới đây là **bắt buộc** cho mọi tin tuyển dụng:

| Field | Kiểu | Ý nghĩa |
|---|---|---|
| `id` | string | ID duy nhất của job |
| `slug` | string | slug URL |
| `title` | string | tiêu đề tin tuyển dụng |
| `companyName` | string | tên công ty |
| `location` | string | nơi làm việc |
| `employmentType` | string | loại hình làm việc |
| `workMode` | string | onsite/hybrid/remote |
| `deadline` | date | hạn nộp hồ sơ |
| `publishDate` | date | ngày đăng |
| `status` | string | active/expired/closed/draft |
| `summary` | string | mô tả ngắn để render card/list |

---

## 5) Field khuyến nghị rất nên có

| Field | Kiểu | Ý nghĩa |
|---|---|---|
| `companySlug` | string | slug công ty |
| `salaryMin` | number | lương thấp nhất |
| `salaryMax` | number | lương cao nhất |
| `salaryLabel` | string | text hiển thị mức lương |
| `experienceLevel` | string | mức kinh nghiệm |
| `featured` | boolean | job nổi bật |
| `urgent` | boolean | job tuyển gấp |
| `contactName` | string | đầu mối liên hệ |
| `contactPhone` | string | số điện thoại |
| `contactEmail` | string | email ứng tuyển |
| `applyUrl` | string | link ứng tuyển |
| `tags` | array | tag hỗ trợ filter/search |
| `sourceSite` | string | nguồn dữ liệu seed |
| `sourceUrl` | string | URL nguồn gốc |
| `lastReviewedDate` | date | ngày rà soát gần nhất |

---

## 6) Enum đề xuất

## 6.1. `status`

Giá trị hợp lệ:
- `draft`
- `active`
- `expired`
- `closed`

### Ý nghĩa
- `draft`: chưa public
- `active`: đang tuyển
- `expired`: quá hạn
- `closed`: đã đóng tin thủ công

## 6.2. `employmentType`

Giá trị hợp lệ:
- `full-time`
- `part-time`
- `internship`
- `freelance`
- `contract`

## 6.3. `workMode`

Giá trị hợp lệ:
- `onsite`
- `hybrid`
- `remote`

## 6.4. `experienceLevel`

Giá trị hợp lệ gợi ý:
- `fresher`
- `junior`
- `1-nam`
- `2-nam`
- `3-nam`
- `senior`
- `manager`

---

## 7) Quy tắc định danh

## 7.1. `id`

Format đề xuất:

```text
job/<slug>
```

Ví dụ:

```text
job/ke-toan-tong-hop-cong-ty-abc
```

## 7.2. `slug`

Quy tắc:
- chỉ dùng chữ thường
- dùng `-` nối từ
- không dấu
- không trùng

Ví dụ:

```text
ke-toan-tong-hop-cong-ty-abc
```

---

## 8) Quy tắc ngày tháng

Format chuẩn:

```text
YYYY-MM-DD
```

Ví dụ:
- `2026-04-10`
- `2026-05-30`

---

## 9) Logic hiển thị list

## 9.1. Job được đưa vào list public khi
- `status = active`
- chưa quá `deadline`

## 9.2. Job hết hạn
- nếu `deadline < hôm nay` thì có thể:
  - tự động đổi thành `expired`
  - hoặc ẩn khỏi list active

## 9.3. Job nổi bật
- `featured = true`
- có thể được ghim lên đầu list

## 9.4. Job tuyển gấp
- `urgent = true`
- hiển thị badge riêng

---

## 10) Rule body markdown

Body nên chia theo các heading rõ ràng:

```md
## Mô tả công việc
## Yêu cầu
## Quyền lợi
## Thời gian và địa điểm làm việc
## Cách ứng tuyển
```

Không nên viết body như một khối text dài không cấu trúc.

---

## 11) Rule seed data từ Sanketoan

Khi dùng dữ liệu từ `sanketoan.vn` làm seed:

- bắt buộc lưu:
  - `sourceSite: sanketoan.vn`
  - `sourceUrl`
- nên viết lại `summary` cho đồng đều
- nên chuẩn hóa field lương / địa điểm / deadline
- không nên để body thô không kiểm tra

---

## 12) Mapping sang output

## 12.1. HTML detail page
- title
- meta description
- header job
- summary
- metadata card
- body mô tả công việc

## 12.2. JSON list item
Mỗi record trong `data/jobs.json` tối thiểu nên có:

```json
{
  "id": "job/ke-toan-tong-hop-cong-ty-abc",
  "slug": "ke-toan-tong-hop-cong-ty-abc",
  "title": "Tuyển Kế toán tổng hợp",
  "companyName": "Công ty ABC",
  "location": "TP.HCM",
  "salaryLabel": "12 - 18 triệu",
  "employmentType": "full-time",
  "workMode": "onsite",
  "deadline": "2026-05-30",
  "publishDate": "2026-04-10",
  "status": "active",
  "featured": true,
  "urgent": false,
  "summary": "Công việc phù hợp với ứng viên đã có kinh nghiệm lập báo cáo thuế và BCTC.",
  "href": "tuyen-dung/ke-toan-tong-hop-cong-ty-abc.html"
}
```

---

## 13) Điều kiện schema được coi là chốt

Schema Phase 0 được coi là hoàn tất khi:
- đã có file mẫu `.md`
- đã có folder source tuyển dụng
- mọi field bắt buộc đã được chốt
- có thể dùng schema này để tạo seed data thật
