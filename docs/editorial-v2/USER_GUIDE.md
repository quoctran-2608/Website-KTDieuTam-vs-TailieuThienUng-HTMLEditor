# Hướng dẫn dùng Editorial V2

Editorial V2 là công cụ tạm thời để nhóm cùng biên tập bài viết. HTML public
gốc vẫn là nguồn nội dung chính.

## Editor

1. Đăng nhập tại `/editorial/`.
2. Vào **Bài viết**, lọc **Chưa có người nhận** và chọn **Nhận biên tập**.
3. Mở **Công việc của tôi**, chọn bài cần xử lý.
4. Sửa nội dung, rồi **Lưu nháp**.
5. Chọn **Tạo phiên bản** sau khi nháp đã sẵn sàng.
6. Chọn **Gửi duyệt**.
7. Nếu bài bị trả lại, đọc ghi chú trong **Công việc của tôi**, sửa và gửi duyệt lại.

## Admin

1. Đăng nhập tại `/editorial/`.
2. Vào **Duyệt bài** để xem bài chờ duyệt.
3. So sánh phiên bản nếu cần, rồi **Phê duyệt** hoặc **Yêu cầu chỉnh lại**.
4. Với bài đã duyệt, chọn **Chuẩn bị Publish**, kiểm tra preflight và Publish.
5. Nếu có cảnh báo public rebuild, mở bài đã published và chọn **Thử rebuild lại dữ liệu public**.
6. Vào **Toàn vẹn hệ thống** để xem trạng thái, và chỉ dọn các khóa đã hết hạn khi cần.

## Khi cần dừng

Không tiếp tục Publish hoặc retry rebuild khi hệ thống báo live hash không khớp
hoặc `recovery_required`. Giữ nguyên dữ liệu hiện tại và kiểm tra thủ công trước.
