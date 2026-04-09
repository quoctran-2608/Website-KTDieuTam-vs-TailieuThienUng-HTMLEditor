# Schema brief nhu cầu tuyển dụng

## Mục tiêu

Tài liệu này chốt schema cho brief tuyển dụng mà doanh nghiệp gửi từ:

- `dang-tin-tuyen-dung.html`

Schema này dùng cho:
- export `.json` từ browser
- ingest nội bộ qua CLI
- moderation queue
- bridge sang PHP editor về sau

---

## 1) Field bắt buộc

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `companyName` | string | Có | tên công ty |
| `contactName` | string | Có | người liên hệ |
| `contactPhone` | string | Có | số điện thoại / Zalo |
| `jobTitle` | string | Có | vị trí cần tuyển |
| `jobLocation` | string | Có | khu vực làm việc |
| `createdAt` | string | Có | format `YYYY-MM-DDTHH:MM:SS` |

---

## 2) Field nên có

| Field | Type | Required | Ghi chú |
|---|---|---:|---|
| `contactEmail` | string | Không | email liên hệ |
| `jobQuantity` | number | Không | mặc định `1` |
| `jobDeadline` | string | Không | date `YYYY-MM-DD` |
| `employmentType` | string | Không | ví dụ `Toàn thời gian` |
| `workMode` | string | Không | ví dụ `Hybrid` |
| `salaryLabel` | string | Không | text lương |
| `experienceLevel` | string | Không | text kinh nghiệm |
| `jobNotes` | text | Không | mô tả ngắn / yêu cầu nổi bật |
| `sourcePage` | string | Không | mặc định `dang-tin-tuyen-dung.html` |
| `sourceChannel` | string | Không | mặc định `website-brief-json` |

---

## 3) Field hệ thống khi ingest

Các field này không bắt doanh nghiệp nhập tay:

| Field | Ai sinh | Ghi chú |
|---|---|---|
| `id` | CLI ingest | `lead/<slug>` |
| `requestId` | browser hoặc CLI | id ngắn của brief |
| `status` | CLI ingest | `new / reviewing / approved / rejected` |
| `ingestedAt` | CLI ingest | thời điểm ghi queue |
| `reviewNotes` | nội bộ | mảng note xử lý |
| `jobDraftPath` | nội bộ | đường dẫn job public nếu đã duyệt |
| `jobPublicHref` | nội bộ | href public sau khi duyệt |
| `publishedAt` | nội bộ | thời điểm brief được public |

---

## 4) Trạng thái moderation

| Status | Ý nghĩa |
|---|---|
| `new` | brief mới nhận |
| `reviewing` | đang rà thông tin |
| `approved` | đã duyệt để lên public |
| `rejected` | từ chối / spam / thiếu dữ liệu |

---

## 5) File đích nội bộ

Sau khi ingest, dữ liệu được ghi vào:

- `data/employer-requests.json`
- `data/feeds/employer-requests-queue.json`
- `docs/nghien-cuu-tuyen-dung/hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md`

---

## 6) Ghi chú kỹ thuật

Website tĩnh hiện tại **không thể tự ghi file vào repo từ browser**.  
Vì vậy flow đúng ở phase này là:

1. browser tạo brief `.json`
2. người phụ trách nội bộ ingest file đó bằng tool CLI
3. queue nội bộ được cập nhật
4. sau này PHP editor sẽ thay thế bước ingest thủ công này
