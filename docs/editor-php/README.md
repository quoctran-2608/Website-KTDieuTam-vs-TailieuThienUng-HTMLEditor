# Tài liệu editor PHP lịch sử

> ## STATUS: HISTORICAL DESIGN / LEGACY EDITOR DOCUMENTATION
>
> Thư mục này hữu ích để tra lịch sử, thuật ngữ và các ý tưởng của legacy
> editor/Admin. Nó **không phải** authority cho Editorial V2 hiện tại. Một số
> tuyên bố kiến trúc ở đây đã bị thay thế.

Để hiểu hệ thống hiện tại, đọc:

- [../ARCHITECTURE.md](../ARCHITECTURE.md)
- [../EDITORIAL_V2.md](../EDITORIAL_V2.md)
- [../PUBLISH_AND_HANDOFF.md](../PUBLISH_AND_HANDOFF.md)
- [../../editorial/README.md](../../editorial/README.md)

Đặc biệt, không áp dụng mặc định các mô tả cũ như “DB là source of truth” hoặc
“static HTML chỉ là build artifact” cho Editorial V2 hiện tại. Public article
content hiện vẫn nằm trong file HTML live gốc; SQLite Editorial là workflow/state
metadata và snapshot evidence.

Không xóa các file bên dưới chỉ vì chúng cũ. Khi dùng chúng, đối chiếu mã hiện
tại và canonical docs trước.
