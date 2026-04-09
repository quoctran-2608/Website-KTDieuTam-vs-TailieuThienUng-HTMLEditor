# Spec queue brief tuyển dụng cho editor PHP

## Mục tiêu

Spec này dành cho luồng **nhà tuyển dụng gửi brief trước khi có tin public**.

Khác với:
- `editor-php-tuyen-dung-form-spec.md` → quản job public

File này dùng cho:
- hàng chờ brief nội bộ
- moderation trước khi tạo tin public

---

## 1) Nguồn dữ liệu hiện tại

Nguồn brief hiện tại đi từ:
- `dang-tin-tuyen-dung.html`

Browser tạo:
- file `.json` brief

Sau đó nội bộ ingest qua:
- `tools/ingest_employer_request.py`

Kết quả ghi vào:
- `data/employer-requests.json`
- `data/feeds/employer-requests-queue.json`
- `docs/nghien-cuu-tuyen-dung/hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md`

---

## 2) Field queue tối thiểu

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `id` | string | Có | format `lead/<request-id>` |
| `requestId` | string | Có | id ngắn phía browser |
| `companyName` | string | Có | tên công ty |
| `contactName` | string | Có | người liên hệ |
| `contactPhone` | string | Có | phone / Zalo |
| `contactEmail` | string | Không | email |
| `jobTitle` | string | Có | vị trí cần tuyển |
| `jobLocation` | string | Có | khu vực làm việc |
| `jobQuantity` | number | Không | mặc định 1 |
| `jobDeadline` | string | Không | hạn nhận hồ sơ |
| `employmentType` | string | Không | hình thức |
| `workMode` | string | Không | onsite/hybrid/remote dạng human-readable |
| `salaryLabel` | string | Không | lương text |
| `experienceLevel` | string | Không | kinh nghiệm text |
| `jobNotes` | text | Không | mô tả thêm |
| `createdAt` | string | Có | format `YYYY-MM-DDTHH:MM:SS` |
| `sourcePage` | string | Có | mặc định `dang-tin-tuyen-dung.html` |
| `sourceChannel` | string | Có | mặc định `website-brief-json` |
| `status` | enum | Có | `new / reviewing / approved / rejected` |
| `ingestedAt` | string | Có | lúc vào queue |
| `reviewNotes` | array | Không | ghi chú xử lý |
| `jobDraftPath` | string | Không | path file public nếu đã duyệt |
| `jobPublicHref` | string | Không | href public sau khi duyệt |
| `publishedAt` | string | Không | thời điểm brief được public |

---

## 3) Luồng moderation đề xuất

### Bước 1 — New
- brief mới vào queue
- trạng thái: `new`

### Bước 2 — Reviewing
- xác minh lại thông tin liên hệ
- rà mô tả công việc
- chuẩn hóa lại wording
- trạng thái: `reviewing`

### Bước 3 — Approved
- brief đủ dữ liệu
- tạo job source `.md`
- chạy build jobs
- điền `jobDraftPath`
- điền `jobPublicHref`
- điền `publishedAt`
- trạng thái: `approved`

### Bước 4 — Rejected
- spam
- thiếu dữ liệu
- không rõ đầu mối liên hệ
- job không phù hợp phạm vi site

---

## 4) Hành vi editor PHP nên có

Editor PHP sau này nên có 1 màn riêng:
- danh sách brief mới
- lọc theo status
- xem chi tiết brief
- đổi status
- thêm `reviewNotes`
- bấm nút “Tạo job từ brief”

### Nút “Tạo job từ brief”
Khi bấm:
1. map dữ liệu brief sang form job
2. cho editor chỉnh lại metadata + body
3. save ra `content/tuyen-dung/<slug>.md`
4. trigger build jobs
5. update `jobDraftPath`
6. set brief status = `approved`

---

## 5) Tool nội bộ hiện có

- ingest:
  - `python3 tools/ingest_employer_request.py <file.json>`
- tạo job draft từ brief:
  - `python3 tools/create_job_draft_from_request.py <request-id>`
- duyệt / từ chối brief:
  - `python3 tools/moderate_employer_request.py <request-id> --action approve|reject|reviewing`
- audit:
  - `python3 tools/audit_employer_requests.py`

---

## 6) Quyết định chốt

Luồng brief tuyển dụng nên là:

**brief queue trước → moderation → tạo job public sau**

Không cho brief của nhà tuyển dụng đi thẳng thành tin public nếu chưa có bước rà soát.
