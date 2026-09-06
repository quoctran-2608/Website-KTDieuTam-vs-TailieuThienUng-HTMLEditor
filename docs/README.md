# Chỉ mục tài liệu

> **Canonical documentation index.** Current code is authoritative.
> Precedence: **current code → canonical docs → legacy/historical docs**.

## AI quick path: hiểu repository trong 5 phút

1. Đọc [`../AGENTS.md`](../AGENTS.md).
2. Đọc [`../README.md`](../README.md).
3. Đọc [ARCHITECTURE.md](ARCHITECTURE.md).
4. Nếu chạm Editorial V2, đọc [`../editorial/README.md`](../editorial/README.md).
5. Đọc tài liệu chuyên sâu phù hợp: Editorial, Publish/Handoff hoặc Operations.
6. Mở module mã tương ứng trước khi sửa; tài liệu không thay thế code.

## Canonical — current

| Tài liệu | Dùng khi |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Cần hiểu boundary giữa public site, data, Editorial, legacy Admin và tools. |
| [EDITORIAL_V2.md](EDITORIAL_V2.md) | Cần sửa workflow, ownership, draft, revision, stage, review hoặc module Editorial. |
| [PUBLISH_AND_HANDOFF.md](PUBLISH_AND_HANDOFF.md) | Cần sửa Publish, ảnh, rebuild public, marker hoặc Google Drive/Sheet. |
| [OPERATIONS.md](OPERATIONS.md) | Cần triển khai, cấu hình, cấp quyền filesystem hoặc xử lý sự cố runtime. |
| [`../editorial/README.md`](../editorial/README.md) | Đang làm việc trực tiếp trong thư mục `editorial/`. |
| [`../README.md`](../README.md) | Cần entrypoint tổng quan repository. |
| [`../AGENTS.md`](../AGENTS.md) | AI/agent cần guardrail trước khi sửa code. |

## Legacy / fallback

| Tài liệu | Trạng thái |
|---|---|
| [`../admin/README.md`](../admin/README.md) | Chỉ mô tả `/admin` legacy/fallback. Không dùng làm authority cho Editorial V2. |

## Historical / design archive

| Khu vực | Trạng thái |
|---|---|
| [editor-php/](editor-php/) | Tài liệu thiết kế, MVP và lịch sử editor PHP. Có thể giúp hiểu thuật ngữ hoặc quyết định cũ, nhưng không mô tả kiến trúc Editorial V2 hiện tại. |
| `editorial-v2/` | Tài liệu hướng dẫn/recovery cũ hơn. Chỉ dùng như tham khảo sau khi đối chiếu code và canonical docs. |
| Các báo cáo migration/audit trong `docs/` | Artifact lịch sử vận hành hoặc dữ liệu; không phải tài liệu kiến trúc canonical. |

## Quy tắc bảo trì tài liệu

Khi code thay đổi bất kỳ invariant kiến trúc nào, phải cập nhật canonical docs
trong **cùng commit**. Tối thiểu gồm:

- source of truth;
- ownership/assignment;
- status transition;
- revision/stage;
- Publish;
- public rebuild/public-ready;
- Handoff;
- media path;
- taxonomy authority.

Không đưa secret, token, API key, mật khẩu hoặc ID private không cần thiết vào
tài liệu.
