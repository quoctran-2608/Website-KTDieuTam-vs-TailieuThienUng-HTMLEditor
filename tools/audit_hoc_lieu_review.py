from __future__ import annotations

import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SITE = ROOT / "ketoandieutam.vn"
ARTICLES_PATH = SITE / "data" / "articles.json"
REPORT_PATH = SITE / "docs" / "editor-php" / "hoc-lieu-review-queue.md"


def suggest_target(title: str) -> str:
    text = title.lower()
    if re.search(r"gtgt|giá trị gia tăng|hóa đơn|hoa don|thanh toán không dùng tiền mặt|thanh toan khong dung tien mat|thuế điện tử|thue dien tu|mã chương|ma chuong|tiểu mục|tieu muc|báo cáo thuế|bao cao thue", text):
        return "Thuế - Hóa đơn"
    if re.search(r"tndn|thuế thu nhập doanh nghiệp", text):
        return "Thuế TNDN"
    if re.search(r"tncn|thuế thu nhập cá nhân|người phụ thuộc|nguoi phu thuoc", text):
        return "Thuế TNCN"
    if re.search(r"môn bài|lệ phí môn bài|hộ kinh doanh|nhà thầu|mã số thuế|mst", text):
        return "Môn bài - MST"
    if re.search(r"bhxh|bhyt|bhtn|bảo hiểm", text):
        return "BHXH - BHYT"
    if re.search(r"lao động|lao dong|tiền lương|tien luong|lương", text):
        return "Tiền lương"
    if re.search(r"doanh nghiệp vừa và nhỏ|doanh nghiep vua va nho|thành lập doanh nghiệp|thanh lap doanh nghiep|công ty mới thành lập|cong ty moi thanh lap", text):
        return "DN - Thủ tục"
    if re.search(r"bctc|báo cáo tài chính|bao cao tai chinh", text):
        return "Báo cáo tài chính"
    if re.search(r"chuẩn mực|chuan muc|chế độ|che do|nguyên tắc|nguyen tac|hình thức ghi sổ|hinh thuc ghi so", text):
        return "Chuẩn mực - Chế độ"
    if re.search(r"chứng từ|chung tu|sổ kế toán|so ke toan|nhật ký|nhat ky", text):
        return "Chứng từ - Sổ sách"
    if re.search(r"tscđ|tscd|khấu hao|khau hao|ccdc|công cụ dụng cụ|kho|hàng tồn kho|hang ton kho", text):
        return "Tài sản / Kho"
    if re.search(r"hạch toán|hach toan|tài khoản|tai khoan|định khoản|dinh khoan", text):
        return "Hạch toán"
    return "Cần review tay"


def confidence(title: str, suggestion: str) -> str:
    if suggestion == "Cần review tay":
        return "thấp"
    if suggestion in {"Thuế - Hóa đơn", "Thuế TNDN", "Thuế TNCN", "Môn bài - MST", "BHXH - BHYT", "Tiền lương", "Báo cáo tài chính", "Chứng từ - Sổ sách", "Tài sản / Kho", "Hạch toán"}:
        return "cao"
    return "trung bình"


def main() -> None:
    articles = json.loads(ARTICLES_PATH.read_text(encoding="utf-8"))
    rows = []
    for article in articles:
        if article.get("section") != "thu-vien":
            continue
        if article.get("cardTopicLabel") != "Học liệu":
            continue
        target = suggest_target(article["title"])
        rows.append(
            {
                "title": article["title"],
                "kind": article.get("libraryKindLabel", ""),
                "current_topic": article.get("cardTopicLabel", ""),
                "target": target,
                "confidence": confidence(article["title"], target),
                "href": article["href"],
            }
        )

    rows.sort(key=lambda r: ({"cao": 0, "trung bình": 1, "thấp": 2}[r["confidence"]], r["target"], r["title"]))

    lines = [
        "# Queue rà soát bài đang hiển thị topic `Học liệu` trong Thư viện",
        "",
        f"- Tổng số bài cần rà: **{len(rows)}**",
        "- Mục tiêu: dọn các bài đang hiển thị topic `Học liệu` nhưng bản chất thuộc chủ đề chuyên môn khác rõ ràng hơn.",
        "- Ưu tiên xử lý các bài có độ tin cậy **cao** trước.",
        "",
        "## Bảng đề xuất",
        "",
        "| Độ tin cậy | Mục hiện tại | Topic hiện tại | Topic đề xuất | Tiêu đề | URL |",
        "|---|---|---|---|---|---|",
    ]
    for row in rows:
        lines.append(
            f"| {row['confidence']} | {row['kind']} | {row['current_topic']} | {row['target']} | {row['title']} | `{row['href']}` |"
        )

    REPORT_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(REPORT_PATH)
    print(f"Rows: {len(rows)}")


if __name__ == "__main__":
    main()
