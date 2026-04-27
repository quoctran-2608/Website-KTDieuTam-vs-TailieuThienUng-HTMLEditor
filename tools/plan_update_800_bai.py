#!/usr/bin/env python3
from __future__ import annotations

import html
import json
import re
import unicodedata
from collections import Counter
from pathlib import Path
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = Path("/mnt/d/WORKING/KetoanThienUng/TailieuKeToanThienUng/bai-moi-cap-nhat")
SOURCE_MANIFEST = SOURCE_DIR / "manifest.json"
REPORT_MD = ROOT / "docs" / "update-800-bai.md"
REPORT_JSON = ROOT / "docs" / "update-800-bai-manifest.json"
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg"}
ATTACH_EXTS = {".pdf", ".doc", ".docx", ".xls", ".xlsx", ".zip", ".rar", ".7z"}
ASSET_EXTS = IMAGE_EXTS | ATTACH_EXTS


LV2_LABELS = {
    "ke-toan": "Kế toán",
    "thue": "Thuế - Hóa đơn",
    "lao-dong-bao-hiem": "Lao động - Bảo hiểm",
    "phan-mem-cong-cu": "Phần mềm - Công cụ",
    "mau-file-ke-khai": "Mẫu file & kê khai",
    "tham-khao-hoc-lieu": "Học liệu - Tham khảo",
    "doanh-nghiep-thu-tuc": "Doanh nghiệp - Thủ tục",
}

LV3_LABELS = {
    "tai-khoan-hach-toan": "Tài khoản - Hạch toán",
    "chuan-muc-che-do-nguyen-tac": "Chuẩn mực - Chế độ - Nguyên tắc",
    "tai-san-kho-ccdc": "Tài sản - Kho - CCDC",
    "chung-tu-so-sach": "Chứng từ - Sổ sách",
    "nghiep-vu-theo-phan-hanh": "Nghiệp vụ theo phần hành",
    "bao-cao-tai-chinh": "Báo cáo tài chính",
    "mau-bieu-ke-toan": "Mẫu biểu kế toán",
    "excel-va-cong-cu-khac": "Excel - Công cụ khác",
    "gtgt-hoa-don": "GTGT - Hóa đơn",
    "tncn": "Thuế thu nhập cá nhân",
    "tndn": "Thuế thu nhập doanh nghiệp",
    "le-phi-mon-bai": "Lệ phí môn bài",
    "ho-ca-nhan-kinh-doanh": "Hộ/Cá nhân kinh doanh",
    "ma-so-thue-dang-ky-thue": "Mã số thuế - Đăng ký thuế",
    "mau-bieu-thue": "Mẫu biểu thuế - hóa đơn",
    "thue-nha-thau": "Thuế nhà thầu",
    "cong-van": "Công văn",
    "bao-hiem": "BHXH - BHYT - BHTN",
    "lao-dong-tien-luong": "Lao động - Tiền lương",
    "cong-doan": "Công đoàn",
    "nghi-quyet-quyet-dinh": "Nghị quyết - Quyết định",
    "nghi-dinh": "Nghị định",
    "thong-tu": "Thông tư",
    "luat-bo-luat": "Luật - Bộ luật",
    "misa": "MISA",
    "htkk-etax-thue-dien-tu": "HTKK - eTax - Thuế điện tử",
    "fast": "FAST",
    "kinh-nghiem-hoi-dap-nghe-nghiep": "Kinh nghiệm - Hỏi đáp - Nghề nghiệp",
    "bai-tap-thuc-hanh": "Bài tập - Thực hành",
    "bao-cao-thuc-tap": "Báo cáo thực tập",
    "thu-tuc-doanh-nghiep": "DN - Thủ tục",
    "mau-bieu-doanh-nghiep-thu-tuc": "Mẫu biểu doanh nghiệp - thủ tục",
    "mau-bieu-lao-dong-bao-hiem": "Mẫu biểu lao động - bảo hiểm",
    "bao-cao": "Báo cáo",
    "tai-ve": "Tải về",
    "cai-dat": "Cài đặt",
    "nang-cap": "Nâng cấp",
    "ke-khai": "Kê khai",
    "nop-to-khai": "Nộp tờ khai",
    "quyet-toan": "Quyết toán",
    "dang-ky-thue": "Đăng ký thuế",
    "hoan-thue": "Hoàn thuế",
    "loi-thuong-gap": "Lỗi thường gặp",
    "ham-excel": "Hàm Excel",
    "mau-file": "Mẫu file",
    "thuc-hanh": "Thực hành",
    "tscd-ccdc": "TSCĐ / CCDC",
    "tien-luong": "Tiền lương",
    "su-dung": "Sử dụng",
}

NEWS_LV1 = {
    "khoa-hoc-dao-tao": "Khóa học & đào tạo",
    "co-so-dia-diem": "Cơ sở & địa điểm",
    "gioi-thieu-thuong-hieu": "Giới thiệu & thương hiệu",
    "uu-dai-thong-bao": "Ưu đãi & thông báo",
}

NEWS_LV2 = {
    "khoa-hoc-ke-toan": "Khóa học kế toán",
    "khoa-hoc-excel": "Khóa học Excel",
    "khoa-hoc-misa": "Khóa học MISA",
    "khoa-hoc-thue": "Khóa học thuế",
    "co-so-dao-tao": "Cơ sở đào tạo",
    "gioi-thieu": "Giới thiệu",
    "uu-dai-hoc-phi": "Ưu đãi học phí",
    # Temporary planning bucket; flagged for taxonomy review before import.
    "thong-bao-tuyen-dung": "Thông báo tuyển dụng",
}


def fold(value: str) -> str:
    value = html.unescape(value or "").lower()
    value = "".join(ch for ch in unicodedata.normalize("NFD", value) if unicodedata.category(ch) != "Mn")
    return re.sub(r"[^a-z0-9]+", " ", value).strip()


def slugify(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", fold(value)).strip("-")
    return slug or "bai-viet"


def first_match(pattern: str, text: str, flags: int = re.I | re.S) -> str:
    match = re.search(pattern, text, flags)
    if not match:
        return ""
    return html.unescape(next((g for g in match.groups() if g), "")).strip()


def extract_meta(doc: str, fallback_title: str) -> dict[str, str]:
    title = first_match(r"<title[^>]*>(.*?)</title>", doc) or fallback_title
    description = first_match(r'<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']', doc)
    keywords = first_match(r'<meta[^>]+name=["\']keywords["\'][^>]+content=["\'](.*?)["\']', doc)
    og_image = first_match(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']', doc)
    return {
        "title": re.sub(r"\s+", " ", title).strip(),
        "description": re.sub(r"\s+", " ", description).strip(),
        "keywords": re.sub(r"\s+", " ", keywords).strip(),
        "og_image": og_image,
    }


def resolve_source_asset(url: str, source_file: Path) -> Path | None:
    if not url or url.startswith(("data:", "mailto:", "tel:", "#", "javascript:")):
        return None
    parsed = urlsplit(html.unescape(url))
    path = unquote(parsed.path or url)
    if parsed.netloc and "ketoandieutam" not in parsed.netloc and "ketoanthienung" not in parsed.netloc:
        return None
    rel = path.lstrip("/")
    if rel.startswith("bai-moi-cap-nhat/"):
        rel = rel.removeprefix("bai-moi-cap-nhat/")
    if rel.startswith("assets/"):
        return SOURCE_DIR / rel
    if Path(rel).suffix.lower() in ASSET_EXTS:
        return (source_file.parent / rel).resolve()
    return None


def collect_assets(doc: str, source_file: Path) -> tuple[list[str], list[str]]:
    urls = []
    urls.extend(re.findall(r'\b(?:src|href)\s*=\s*["\'](.*?)["\']', doc, flags=re.I | re.S))
    urls.extend(re.findall(r"url\([\"']?([^)'\" ]+)[\"']?\)", doc, flags=re.I))
    found: list[str] = []
    missing: list[str] = []
    seen = set()
    for url in urls:
        asset = resolve_source_asset(url, source_file)
        if not asset or asset.suffix.lower() not in ASSET_EXTS:
            continue
        key = str(asset)
        if key in seen:
            continue
        seen.add(key)
        rel = str(asset.relative_to(SOURCE_DIR)) if asset.exists() and SOURCE_DIR in asset.parents else key
        if asset.exists():
            found.append(rel)
        else:
            missing.append(rel)
    return sorted(found), sorted(missing)


def classify_news(text: str) -> tuple[str, str, list[str]]:
    flags: list[str] = []
    f = fold(text)
    if re.search(r"\b(giam gia|uu dai|khuyen mai|hoc phi|mien phi)\b", f):
        return "uu-dai-thong-bao", "uu-dai-hoc-phi", flags
    if re.search(r"\b(thanh xuan|cau giay|ha dong|hoang mai|long bien|thu duc|tp hcm|sai gon|co so|dia chi|tai ha noi|tai hcm)\b", f):
        return "co-so-dia-diem", "co-so-dao-tao", flags
    if re.search(r"\b(gioi thieu|dieu tam|trung tam|cong ty tnhh tu van dao tao dieu tam)\b", f):
        return "gioi-thieu-thuong-hieu", "gioi-thieu", flags
    if "excel" in f:
        return "khoa-hoc-dao-tao", "khoa-hoc-excel", flags
    if "misa" in f:
        return "khoa-hoc-dao-tao", "khoa-hoc-misa", flags
    if "thue" in f:
        return "khoa-hoc-dao-tao", "khoa-hoc-thue", flags
    if re.search(r"\b(tuyen|tuyen dung|viec lam|nhan vien ke toan|thuc tap ke toan)\b", f):
        flags.append("needs-taxonomy-review: tin tuyển dụng chưa có LV2 chính thức trong Bản tin")
        return "uu-dai-thong-bao", "thong-bao-tuyen-dung", flags
    return "khoa-hoc-dao-tao", "khoa-hoc-ke-toan", flags


def is_news(text: str) -> bool:
    f = fold(text)
    return bool(re.search(r"\b(khoa hoc|hoc ke toan|hoc thuc hanh|dieu tam|giam gia|khuyen mai|uu dai|tuyen .*ke toan|viec lam ke toan|dia chi hoc|trung tam dao tao)\b", f))


def library_kind(text: str) -> str:
    f = fold(text)
    if re.match(r"^(bai tap|bai thuc hanh|cach|huong dan|tu hoc|thuc hanh|hach toan|ke khai|dang ky|nop|lap|tinh|xu ly)", f):
        return "huong-dan"
    if re.match(r"^(mau|bang ke|bang cham cong|bang thanh toan|bien ban|giay|phieu|phu luc|to khai|hop dong|don|danh sach|so|the|quy che|thong bao|van ban de nghi)", f):
        return "bieu-mau"
    if re.search(r"\b(mau so|file word|file excel|bieu mau)\b", f):
        return "bieu-mau"
    if re.match(r"^(luat|bo luat|nghi dinh|thong tu|cong van|nghi quyet|quyet dinh|cong dien)\b", f):
        return "van-ban"
    if re.search(r"\b(phan mem|htkk|etax|misa|fast|excel|ham |phim tat|tai file|download)\b", f):
        return "cong-cu"
    return "huong-dan"


def classify_library(text: str, kind: str) -> tuple[str, str, list[str]]:
    f = fold(text)
    flags: list[str] = []

    if re.search(r"\b(bai tap|bai thuc hanh|nguyen ly ke toan)\b", f):
        return "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", flags
    if re.search(r"\b(bao cao thuc tap|bao cao ke toan .*tai cong ty|bao cao hoan thien)\b", f):
        return "tham-khao-hoc-lieu", "bao-cao-thuc-tap", flags
    if re.search(r"\b(xin viec|phong van|kinh nghiem|cong viec cua|nghe ke toan|thuc tap ke toan)\b", f):
        return "tham-khao-hoc-lieu", "kinh-nghiem-hoi-dap-nghe-nghiep", flags

    if re.search(r"\b(htkk|etax|nop to khai|ke khai qua mang|chu ky so|thue dien tu)\b", f):
        return "phan-mem-cong-cu" if kind != "bieu-mau" else "mau-file-ke-khai", "htkk-etax-thue-dien-tu", flags
    if "misa" in f:
        return "phan-mem-cong-cu" if kind != "bieu-mau" else "mau-file-ke-khai", "misa", flags
    if "fast" in f:
        return "phan-mem-cong-cu" if kind != "bieu-mau" else "mau-file-ke-khai", "fast", flags
    if re.search(r"\b(excel|ham excel|subtotal|vlookup|file excel)\b", f):
        return "phan-mem-cong-cu" if kind != "bieu-mau" else "mau-file-ke-khai", "excel-va-cong-cu-khac", flags

    if re.search(r"\b(bhxh|bhyt|bhtn|bao hiem|thai san|om dau|duong suc)\b", f):
        return "lao-dong-bao-hiem", "bao-hiem" if kind != "bieu-mau" else "mau-bieu-lao-dong-bao-hiem", flags
    if re.search(r"\b(tien luong|luong|lao dong|hop dong lao dong|nghi huu|tro cap|cham cong|lam them gio|thang bang luong)\b", f):
        return "lao-dong-bao-hiem", "lao-dong-tien-luong" if kind != "bieu-mau" else "mau-bieu-lao-dong-bao-hiem", flags
    if "cong doan" in f or "kinh phi cong doan" in f:
        return "lao-dong-bao-hiem", "cong-doan", flags

    if re.search(r"\b(hop dong|dang ky doanh nghiep|thanh lap|giai the|tam ngung|chi nhanh|van phong dai dien|dia diem kinh doanh|gop von|khuyen mai|doanh nghiep)\b", f):
        return "doanh-nghiep-thu-tuc", "mau-bieu-doanh-nghiep-thu-tuc" if kind == "bieu-mau" else "thu-tuc-doanh-nghiep", flags

    if re.search(r"\b(tncn|thu nhap ca nhan|nguoi phu thuoc|giam tru gia canh|quyet toan thue tncn)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "tncn", flags
    if re.search(r"\b(tndn|thu nhap doanh nghiep|chi phi duoc tru|chuyen lo|tam tinh quy)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "tndn", flags
    if re.search(r"\b(mon bai|le phi mon bai)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "le-phi-mon-bai", flags
    if re.search(r"\b(ho kinh doanh|ca nhan kinh doanh|thue khoan)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "ho-ca-nhan-kinh-doanh", flags
    if re.search(r"\b(ma so thue|mst|dang ky thue|khoi phuc ma so thue|cham dut hieu luc ma so thue)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "ma-so-thue-dang-ky-thue", flags
    if "nha thau" in f:
        return "thue", "thue-nha-thau", flags
    if re.search(r"\b(gtgt|gia tri gia tang|hoa don|vat|khau tru|hoan thue|bao cao thue|thue suat|tieu thu dac biet|xuat khau)\b", f):
        return "thue", "mau-bieu-thue" if kind == "bieu-mau" else "gtgt-hoa-don", flags

    if re.search(r"\b(bctc|bao cao tai chinh|thuyet minh bao cao|can doi tai khoan|tinh hinh tai chinh)\b", f):
        return "ke-toan", "bao-cao-tai-chinh", flags
    if re.search(r"\b(tscd|tai san co dinh|khau hao|ccdc|cong cu dung cu|kho|hang ton kho|nguyen vat lieu|vat tu|thanh pham|the kho)\b", f):
        return "ke-toan", "tai-san-kho-ccdc", flags
    if re.search(r"\b(chung tu|so ke toan|nhat ky|so cai|phieu thu|phieu chi|uy nhiem chi|hoa don chung tu)\b", f):
        return "ke-toan", "mau-bieu-ke-toan" if kind == "bieu-mau" else "chung-tu-so-sach", flags
    if re.search(r"\b(hach toan|tai khoan|dinh khoan|ket chuyen|doanh thu|chi phi|gia von|cong no|thanh toan)\b", f):
        return "ke-toan", "tai-khoan-hach-toan", flags
    if re.search(r"\b(chuan muc|che do|nguyen tac|thong tu 200|thong tu 133|thong tu 99|hinh thuc ghi so|don vi tien te|bo may ke toan)\b", f):
        return "ke-toan", "chuan-muc-che-do-nguyen-tac", flags

    flags.append("needs-review: chưa khớp rule chuyên đề rõ ràng")
    return "tham-khao-hoc-lieu", "kinh-nghiem-hoi-dap-nghe-nghiep", flags


def refine_doc_type(kind: str, text: str, lv3: str) -> str:
    if kind != "van-ban":
        return lv3
    f = fold(text)
    if "cong van" in f or re.search(r"\bcv\b", f):
        return "cong-van"
    if "nghi dinh" in f:
        return "nghi-dinh"
    if "thong tu" in f:
        return "thong-tu"
    if "luat" in f or "bo luat" in f:
        return "luat-bo-luat"
    if "nghi quyet" in f or "quyet dinh" in f:
        return "nghi-quyet-quyet-dinh"
    return lv3


def classify(row: dict, meta: dict) -> dict:
    text = " ".join([row.get("title", ""), meta.get("title", ""), meta.get("description", ""), meta.get("keywords", ""), row.get("file", "")])
    flags: list[str] = []
    if is_news(text):
        lv1, lv2, news_flags = classify_news(text)
        flags.extend(news_flags)
        return {
            "section": "ban-tin",
            "section_label": "Bản tin",
            "lv1_key": lv1,
            "lv1_label": NEWS_LV1[lv1],
            "lv2_key": lv2,
            "lv2_label": NEWS_LV2[lv2],
            "lv3_key": "",
            "lv3_label": "",
            "library_kind": "",
            "confidence": "medium" if flags else "high",
            "flags": flags,
        }
    kind = library_kind(" ".join([row.get("title", ""), row.get("file", "")]))
    lv2, lv3, flags = classify_library(text, kind)
    lv3 = refine_doc_type(kind, text, lv3)
    return {
        "section": "thu-vien",
        "section_label": "Thư viện",
        "lv1_key": kind,
        "lv1_label": {"huong-dan": "Hướng dẫn", "bieu-mau": "Biểu mẫu", "cong-cu": "Công cụ", "van-ban": "Văn bản"}[kind],
        "lv2_key": lv2,
        "lv2_label": LV2_LABELS.get(lv2, lv2),
        "lv3_key": lv3,
        "lv3_label": LV3_LABELS.get(lv3, lv3),
        "library_kind": kind,
        "confidence": "medium" if flags else "high",
        "flags": flags,
    }


def current_site_titles() -> dict[str, list[str]]:
    titles: dict[str, list[str]] = {}
    for root in [ROOT / "thu-vien", ROOT / "ban-tin"]:
        for path in root.glob("*.html"):
            text = path.read_text(encoding="utf-8", errors="ignore")
            title = first_match(r'<h1[^>]*class=["\']article-title["\'][^>]*>(.*?)</h1>', text) or first_match(r"<h1[^>]*>(.*?)</h1>", text)
            title = re.sub(r"<.*?>", "", title)
            if title:
                titles.setdefault(fold(title), []).append(str(path.relative_to(ROOT)))
    return titles


def current_site_stems() -> set[str]:
    return {p.stem for root in [ROOT / "thu-vien", ROOT / "ban-tin"] for p in root.glob("*.html")}


def unique_target(section: str, source_file: str, existing_stems: set[str]) -> tuple[str, str]:
    base = slugify(Path(source_file).stem)
    candidate = base
    status = "ok"
    if candidate in existing_stems:
        candidate = f"{base}-nhap-moi"
        status = "slug-duplicate"
    return f"{section}/{candidate}.html", status


def source_html_path(source_name: str, title: str = "") -> Path:
    direct = SOURCE_DIR / source_name
    if direct.exists():
        return direct
    slug_candidate = SOURCE_DIR / f"{slugify(Path(source_name).stem)}{Path(source_name).suffix.lower() or '.htm'}"
    if slug_candidate.exists():
        return slug_candidate
    folded_title = fold(title)
    if folded_title:
        for candidate in SOURCE_DIR.glob("*.htm"):
            doc = candidate.read_text(encoding="utf-8", errors="ignore")
            candidate_title = extract_meta(doc, candidate.stem).get("title", "")
            if fold(candidate_title) == folded_title:
                return candidate
    raise FileNotFoundError(f"Không tìm thấy file nguồn: {source_name}")


def md_escape(value: str) -> str:
    return (value or "").replace("|", " ").replace("\n", " ").strip()


def build_report(manifest: dict, records: list[dict]) -> str:
    section_counts = Counter(r["section_label"] for r in records)
    kind_counts = Counter(r["lv1_label"] for r in records if r["section"] == "thu-vien")
    news_counts = Counter(r["lv1_label"] for r in records if r["section"] == "ban-tin")
    lv2_counts = Counter(f"{r['section_label']} > {r['lv1_label']} > {r['lv2_label']}" for r in records)
    dup_counts = Counter(r["duplicate_status"] for r in records)
    review_count = sum(1 for r in records if r["flags"])
    asset_total = sum(r["asset_count"] for r in records)
    asset_missing = sum(r["asset_missing_count"] for r in records)
    first_batch = [r for r in records if r["duplicate_status"] == "ok" and not r["flags"]][:20]

    lines = [
        "# Update 800 bài — manifest nhập liệu",
        "",
        "## Trạng thái chặng 0",
        "",
        "- Chặng hiện tại: **0 — kiểm kê, phân loại sơ bộ, chưa copy bài/ảnh vào site**.",
        f"- Nguồn: `{SOURCE_DIR}`",
        f"- Tổng HTML theo manifest nguồn: **{manifest.get('total_html')}**",
        f"- Bài chi tiết đã chuẩn hóa ở nguồn: **{manifest.get('processed_count')}**",
        f"- File nguồn bị bỏ qua từ lần chuẩn hóa trước: **{manifest.get('skipped_count')}**",
        f"- Lỗi nguồn: **{manifest.get('failed_count')}**",
        f"- Bài sẽ xét import trong các chặng sau: **{len(records)}**",
        f"- Asset được bài nguồn tham chiếu trong 745 bài: **{asset_total}**",
        f"- Asset thiếu khi kiểm kê hiện tại: **{asset_missing}**",
        f"- Bài cần review tay trước import: **{review_count}**",
        "",
        "## Phân bổ section sơ bộ",
        "",
        "| Section | Số bài |",
        "|---|---:|",
    ]
    for label, count in section_counts.most_common():
        lines.append(f"| {label} | {count} |")

    lines += ["", "## Phân bổ Thư viện theo LV1", "", "| LV1 | Số bài |", "|---|---:|"]
    for label, count in kind_counts.most_common():
        lines.append(f"| {label} | {count} |")

    lines += ["", "## Phân bổ Bản tin theo LV1", "", "| LV1 | Số bài |", "|---|---:|"]
    for label, count in news_counts.most_common():
        lines.append(f"| {label} | {count} |")

    lines += ["", "## Top nhánh LV2 đông nhất", "", "| Nhánh | Số bài |", "|---|---:|"]
    for label, count in lv2_counts.most_common(30):
        lines.append(f"| {md_escape(label)} | {count} |")

    lines += ["", "## Kiểm tra trùng lặp sơ bộ", "", "| Trạng thái | Số bài |", "|---|---:|"]
    for label, count in dup_counts.most_common():
        lines.append(f"| {label} | {count} |")

    lines += [
        "",
        "## Chặng 1 đề xuất — 20 bài import thử",
        "",
        "| STT | File nguồn | Tiêu đề | Đích dự kiến | Phân loại |",
        "|---:|---|---|---|---|",
    ]
    for i, r in enumerate(first_batch, 1):
        path = " > ".join(filter(None, [r["section_label"], r["lv1_label"], r["lv2_label"], r["lv3_label"]]))
        lines.append(f"| {i} | `{r['source_file']}` | {md_escape(r['title'])} | `{r['target_path']}` | {md_escape(path)} |")

    lines += [
        "",
        "## Manifest đầy đủ 745 bài",
        "",
        "| STT | File nguồn | Tiêu đề | Đích dự kiến | Section | LV1 | LV2 | LV3 | Asset | Trùng/Review |",
        "|---:|---|---|---|---|---|---|---|---:|---|",
    ]
    for i, r in enumerate(records, 1):
        review = r["duplicate_status"]
        if r["flags"]:
            review += "; " + "; ".join(r["flags"])
        lines.append(
            f"| {i} | `{r['source_file']}` | {md_escape(r['title'])} | `{r['target_path']}` | "
            f"{r['section_label']} | {r['lv1_label']} | {r['lv2_label']} | {r['lv3_label'] or '-'} | "
            f"{r['asset_count']} | {md_escape(review)} |"
        )

    lines += [
        "",
        "## 55 file nguồn bị bỏ qua từ manifest cũ",
        "",
        "> Nhóm này chưa nằm trong 745 bài import vì nguồn báo không có khung bài viết chi tiết `div.content > div.name + div.details`.",
        "",
        "| STT | File | Tiêu đề nguồn | Lý do |",
        "|---:|---|---|---|",
    ]
    for i, item in enumerate(manifest.get("skipped", []), 1):
        lines.append(f"| {i} | `{item.get('file','')}` | {md_escape(item.get('title',''))} | {md_escape(item.get('reason',''))} |")

    lines += [
        "",
        "## Quy tắc cho các chặng sau",
        "",
        "- Mỗi lần `ok continue`: xử lý một batch nhỏ, ưu tiên 20 bài import thử ở Chặng 1, sau đó 50–80 bài/chặng.",
        "- Khi import thật phải copy asset sang `assets/images/content/...` hoặc vùng content tương ứng, rồi rewrite `src`/`href` trong HTML.",
        "- Không ghi đè bài hiện có; các bài `slug-duplicate` hoặc `title-duplicate` phải review tay trước khi quyết định skip/merge/đổi slug.",
        "- Sau mỗi chặng phải cập nhật lại bảng manifest này từ trạng thái `planned` sang trạng thái đã import/skip/review.",
        "",
    ]
    return "\n".join(lines)


def main() -> None:
    manifest = json.loads(SOURCE_MANIFEST.read_text(encoding="utf-8"))
    existing_stems = current_site_stems()
    existing_titles = current_site_titles()
    records: list[dict] = []

    for row in manifest["processed"]:
        source_file = source_html_path(row["file"], row.get("title", ""))
        doc = source_file.read_text(encoding="utf-8", errors="ignore")
        meta = extract_meta(doc, row.get("title", ""))
        assets, missing_assets = collect_assets(doc, source_file)
        cls = classify(row, meta)
        target_path, duplicate_status = unique_target(cls["section"], row["file"], existing_stems)
        title_key = fold(row.get("title") or meta["title"])
        title_dups = existing_titles.get(title_key, [])
        if title_dups:
            duplicate_status = "title-duplicate" if duplicate_status == "ok" else f"{duplicate_status}+title-duplicate"

        records.append(
            {
                "source_file": row["file"],
                "title": row.get("title") or meta["title"],
                "meta_title": meta["title"],
                "description": meta["description"],
                "og_image": meta["og_image"],
                "target_path": target_path,
                "asset_count": len(assets),
                "asset_missing_count": len(missing_assets),
                "assets": assets,
                "missing_assets": missing_assets,
                "duplicate_status": duplicate_status,
                "duplicate_targets": title_dups,
                "planned_status": "planned",
                **cls,
            }
        )

    REPORT_MD.write_text(build_report(manifest, records), encoding="utf-8")
    REPORT_JSON.write_text(json.dumps({"source": str(SOURCE_DIR), "records": records}, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps({
        "report": str(REPORT_MD.relative_to(ROOT)),
        "json": str(REPORT_JSON.relative_to(ROOT)),
        "records": len(records),
        "sections": Counter(r["section_label"] for r in records),
        "review": sum(1 for r in records if r["flags"]),
        "asset_missing": sum(r["asset_missing_count"] for r in records),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
