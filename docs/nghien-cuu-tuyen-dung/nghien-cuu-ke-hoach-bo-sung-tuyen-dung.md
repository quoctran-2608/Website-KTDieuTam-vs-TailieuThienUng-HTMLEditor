# Nghiên cứu & kế hoạch bổ sung góc Tuyển dụng cho KetoanDieuTam.com

## 1) Bài toán
- Mục tiêu: bổ sung một góc **Tuyển dụng kế toán / việc làm kế toán** cho website hiện tại.
- Câu hỏi cần chốt:
  1. Có nên đưa vào **trang chủ `index.html`** không?
  2. Có cần tạo **trang riêng** không?
  3. Có nên thêm **menu “Tuyển dụng”** trên thanh điều hướng chính không?

---

## 2) Kết luận ngắn gọn

### Khuyến nghị chính
**Không nên chỉ nhét Tuyển dụng vào `index.html`.**  
Nên làm theo mô hình:

1. **Tạo 1 trang riêng**: `tuyen-dung.html`  
2. **Đặt 1 block teaser trên trang chủ** `index.html`  
3. **Chưa cần thêm menu top-nav ngay ở giai đoạn đầu**, trừ khi đã có đủ dữ liệu tuyển dụng thật

### Kết luận cụ thể
- **Có nên bổ sung vào trang chủ không?**  
  **Có**, nhưng **chỉ ở dạng block giới thiệu / teaser / danh sách ngắn**, không phải full job board.

- **Có phải chỉ làm ở `index.html` không?**  
  **Không.** Trang chủ chỉ nên là điểm dẫn vào. Phần chính phải nằm ở **trang riêng**.

- **Có nên thêm menu “Tuyển dụng” không?**  
  **Có, nhưng theo giai đoạn.**
  - **Giai đoạn MVP:** chưa cần đưa lên top-nav chính
  - **Giai đoạn 2:** khi đã có đủ tin tuyển dụng thật, nên đưa thành menu riêng

---

## 3) Nghiên cứu tham chiếu từ sanketoan.vn

### 3.1. Điều sanketoan.vn đang làm tốt
Qua quan sát:
- Trang chủ có **nhóm điều hướng việc làm riêng**: Việc làm kế toán, Tìm hồ sơ, Gói đăng tuyển miễn phí
- Có **trang listing tuyển dụng riêng**, có danh sách việc làm mới, việc làm tuyển gấp, lọc theo vị trí và địa phương
- Có **phễu riêng cho nhà tuyển dụng**: đăng tin miễn phí, gói có phí, FAQ, lợi ích, hướng dẫn sử dụng

### 3.2. Ý nghĩa đối với KetoanDieuTam
Mô hình này cho thấy:
- Tuyển dụng là **một trục sản phẩm riêng**
- Nếu làm nghiêm túc, nó không chỉ là một box nhỏ trên homepage
- Nó cần:
  - trang listing
  - form đăng tuyển / thu lead
  - logic lọc
  - quy trình duyệt tin

### 3.3. Điều không nên bê nguyên
KetoanDieuTam hiện là website thiên về:
- giới thiệu thương hiệu
- giải pháp
- đào tạo
- thư viện nội dung
- bản tin

Nên **không nên bê nguyên mô hình job board nặng kiểu sanketoan** lên trang chủ. Nếu làm như vậy:
- vỡ định vị site hiện tại
- làm loãng trải nghiệm người đọc nội dung
- tăng chi phí vận hành dữ liệu tuyển dụng quá sớm

### Nguồn tham chiếu
- Trang chủ Sàn Kế Toán: https://sanketoan.vn/
- Gói đăng tuyển miễn phí: https://sanketoan.vn/bang-gia/dich-vu-mien-phi

---

## 4) Đối chiếu với website hiện tại

### 4.1. Điều hướng hiện tại
Menu hiện tại đang là:
- Trang Chủ
- Giới Thiệu
- Giải Pháp
- Đào Tạo
- Thư Viện
- Bản Tin
- Liên Hệ

=> Đây là **menu thiên về nội dung và dịch vụ**, chưa phải menu sản phẩm tuyển dụng.

### 4.2. Cấu trúc trang chủ hiện tại
Thứ tự section ở `index.html` hiện nay:
1. Hero
2. About
3. Philosophy
4. Solutions
5. Personas
6. Insights
7. Why choose
8. Testimonials
9. Founder
10. Connect
11. FAQ
12. CTA

=> Vị trí hợp lý nhất để chèn teaser Tuyển dụng là:
**sau section `personas` và trước `insights`**

### Vì sao?
Vì đây là điểm giao giữa:
- nhóm người dùng mà site phục vụ
- và nhóm tài nguyên / cơ hội tiếp theo mà họ quan tâm

Nói cách khác:
- `personas` trả lời: **ai là người mà Diệu Tâm đồng hành**
- block `tuyển dụng` sẽ trả lời: **cơ hội nghề nghiệp / nhu cầu tuyển người nằm ở đâu**
- rồi `insights` tiếp tục dẫn sang kho nội dung

---

## 5) Đề xuất kiến trúc thông tin

## 5.1. Giai đoạn 1 (MVP)

### A. Tạo trang riêng
**Trang mới đề xuất:** `tuyen-dung.html`

Vai trò:
- landing page tuyển dụng
- gom cả 2 nhu cầu:
  1. người tìm việc
  2. doanh nghiệp cần đăng tuyển

### B. Thêm teaser trên homepage
**Vị trí đề xuất:**  
Trong `index.html`, đặt **1 section mới sau `#personas` và trước `#insights`**

Tên section gợi ý:
- `Cơ hội nghề nghiệp & tuyển dụng`
hoặc
- `Tuyển dụng kế toán & cơ hội nghề nghiệp`

### C. Chưa thêm top-nav ngay
Ở MVP, chỉ cần:
- teaser trên homepage
- link ở footer
- CTA trong trang Đào tạo hoặc Kết nối

**Chưa bắt buộc** thêm menu top-nav “Tuyển dụng” ngay, vì:
- nếu tin còn ít thì menu sẽ dẫn đến trang mỏng
- nhìn như một sản phẩm chưa hoàn thiện

---

## 5.2. Giai đoạn 2 (khi dữ liệu đủ mạnh)

Khi đạt một trong các điều kiện sau:
- có tối thiểu **10–20 tin tuyển dụng thật**
- có quy trình đăng tuyển rõ ràng
- có nhu cầu truy cập lặp lại từ ứng viên / nhà tuyển dụng

=> khi đó **nên thêm menu top-nav “Tuyển dụng”**

### Vị trí menu đề xuất
Đặt sau:
- **Đào Tạo**

Lý do:
- tuyển dụng gắn trực tiếp với đầu ra nghề nghiệp
- phù hợp hành trình người dùng:
  - học -> nâng năng lực -> tìm việc / tuyển người

### Thứ tự menu gợi ý khi lên phase 2
- Trang Chủ
- Giới Thiệu
- Giải Pháp
- Đào Tạo
- **Tuyển Dụng**
- Thư Viện
- Bản Tin
- Liên Hệ

---

## 6) Đề xuất nội dung cho `tuyen-dung.html`

## 6.1. Hero đầu trang
- Tiêu đề: Tuyển dụng kế toán & cơ hội nghề nghiệp
- Subtext: nơi kết nối doanh nghiệp nhỏ và vừa với cộng đồng kế toán thực chiến
- 2 CTA:
  - **Tìm việc ngay**
  - **Đăng nhu cầu tuyển dụng**

## 6.2. Khối cho ứng viên
- Việc làm mới nhất
- Việc làm nổi bật
- Lọc nhanh theo:
  - vị trí
  - địa điểm
  - mức lương
  - kinh nghiệm
  - loại hình làm việc

## 6.3. Khối cho doanh nghiệp
- Vì sao nên đăng tuyển tại đây
- Form để lại nhu cầu tuyển dụng
- CTA Zalo / hotline

## 6.4. Khối niềm tin
- tin tuyển được kiểm duyệt
- tập trung đúng ngành kế toán – HCNS – tài chính
- kết nối với cộng đồng học viên / người làm nghề sẵn có

---

## 7) Đề xuất section teaser trên `index.html`

## 7.1. Không làm kiểu listing dài
Homepage chỉ nên có:
- 1 intro ngắn
- 3–4 job card mới nhất
- 2 CTA

## 7.2. Bố cục gợi ý
### Cột trái
- Tiêu đề:
  - `Tuyển dụng kế toán & cơ hội nghề nghiệp`
- Mô tả:
  - kết nối doanh nghiệp nhỏ và vừa (SME) với cộng đồng kế toán thực chiến
- CTA:
  - Xem việc làm
  - Đăng nhu cầu tuyển dụng

### Cột phải
- 3 hoặc 4 card việc làm
  - Tên vị trí
  - Tên công ty
  - Địa điểm
  - Mức lương
  - Ngày đăng

## 7.3. Vị trí chính xác nên chèn
**Ngay sau section `Giải pháp phù hợp cho từng nhóm khách hàng` (`#personas`)**

Không nên đặt:
- quá cao gần hero: sẽ làm lệch định vị thương hiệu
- quá thấp dưới FAQ/CTA: sẽ ít người nhìn thấy

---

## 8) Có nên làm thêm trang “Đăng tuyển” riêng không?

### Khuyến nghị
**Chưa cần ở giai đoạn đầu**

Ban đầu có thể xử lý bằng:
- section trong `tuyen-dung.html`
- hoặc form/CTA đẩy sang Zalo / Google Form / form nội bộ

### Khi nào nên tách?
Khi đã có:
- chính sách đăng tuyển
- quy trình duyệt
- gói miễn phí / gói ưu tiên

Khi đó có thể tạo:
- `dang-tin-tuyen-dung.html`

---

## 9) Đề xuất chức năng MVP

## 9.1. Phần front-end
- Trang `tuyen-dung.html`
- Section teaser trên `index.html`
- Card việc làm
- CTA nhà tuyển dụng

## 9.2. Dữ liệu tối thiểu cho 1 tin tuyển dụng
- tiêu đề
- công ty
- địa điểm
- mức lương
- kinh nghiệm
- loại hình làm việc
- mô tả ngắn
- ngày đăng
- link ứng tuyển / liên hệ
- trạng thái hiển thị

## 9.3. Quy trình nội dung
- nhà tuyển dụng gửi form
- admin duyệt
- mới lên site

=> rất quan trọng để tránh:
- tin rác
- link xấu
- job hết hạn vẫn còn hiển thị

---

## 10) Gợi ý roadmap triển khai

### Phase 1 — Validate nhu cầu
- Tạo `tuyen-dung.html`
- Tạo block teaser trên `index.html`
- Dùng form/Zalo để nhận nhu cầu đăng tuyển
- Đăng 5–10 tin mẫu đã kiểm duyệt

### Phase 2 — Chuẩn hóa vận hành
- thêm bộ lọc
- thêm chi tiết việc làm
- thêm trạng thái hết hạn / còn tuyển
- thêm rule moderation

### Phase 3 — Nâng thành sản phẩm rõ ràng
- thêm menu “Tuyển dụng”
- thêm landing cho nhà tuyển dụng
- thêm gói miễn phí / ưu tiên / featured jobs

---

## 11) Khuyến nghị cuối cùng

### Nên làm ngay
- **Có**
  - tạo trang `tuyen-dung.html`
  - thêm block teaser trong `index.html`

### Chưa nên làm ngay
- **Chưa**
  - chưa nên biến homepage thành job board đầy đủ
  - chưa nên thêm top-nav “Tuyển dụng” nếu chưa có đủ dữ liệu thật

### Khi nào nên thêm menu “Tuyển dụng”
- khi đã có:
  - lượng tin đủ dày
  - quy trình duyệt
  - giá trị truy cập lặp lại

---

## 12) Quyết định đề xuất để triển khai

### Phương án tôi khuyến nghị
1. **Trang chính:** tạo `tuyen-dung.html`
2. **Vị trí trên trang chủ:** chèn block teaser **sau `#personas`, trước `#insights`**
3. **Menu “Tuyển dụng”:**
   - **MVP:** chưa đưa lên top-nav
   - **Phase 2:** thêm top-nav, đặt sau `Đào Tạo`

Đây là phương án cân bằng nhất giữa:
- nhu cầu mở thêm trục tuyển dụng
- định vị hiện tại của website
- chi phí vận hành nội dung
- khả năng mở rộng về sau
