#!/usr/bin/env python3
"""
Audit 2066 bài nguồn trong TailieuKeToanThienUng để phát hiện rủi ro mobile overflow
trước khi nhập vào ketoandieutam.vn.

Output:
- ketoandieutam.vn/docs/legacy-mobile-import-audit.md
"""

from __future__ import annotations

import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path
from textwrap import shorten


REPO_ROOT = Path(__file__).resolve().parents[2]
SITE_ROOT = REPO_ROOT / "ketoandieutam.vn"
DOCS_DIR = SITE_ROOT / "docs"
SOURCE_ROOT = REPO_ROOT / "TailieuKeToanThienUng"
CATALOG_FILE = SOURCE_ROOT / "index.html"
REPORT_PATH = DOCS_DIR / "legacy-mobile-import-audit.md"

CATALOG_RE = re.compile(r'<script id="catalog-data" type="application/json">(.*?)</script>', re.S)
TABLE_BLOCK_RE = re.compile(r"<table\b[^>]*>.*?</table>", re.I | re.S)
TR_RE = re.compile(r"<tr\b[^>]*>(.*?)</tr>", re.I | re.S)
CELL_TAG_RE = re.compile(r"<t[dh]\b[^>]*>", re.I)
IMG_TAG_RE = re.compile(r"<img\b[^>]*>", re.I)
STYLE_WIDTH_RE = re.compile(r"\b(?:width|min-width|max-width)\s*:\s*(\d+(?:\.\d+)?)px", re.I)
STYLE_MARGIN_LEFT_RE = re.compile(r"\bmargin-left\s*:\s*(\d+(?:\.\d+)?)px", re.I)
STYLE_MARGIN_RIGHT_RE = re.compile(r"\bmargin-right\s*:\s*(\d+(?:\.\d+)?)px", re.I)
WIDTH_ATTR_RE = re.compile(r"\bwidth\s*=\s*([\"']?)(\d+(?:\.\d+)?)(?:px|%)?\1", re.I)
STYLE_ATTR_RE = re.compile(r'style\s*=\s*(["\'])(.*?)\1', re.I | re.S)


def load_catalog() -> dict:
    raw = CATALOG_FILE.read_text(encoding="utf-8", errors="ignore")
    match = CATALOG_RE.search(raw)
    if not match:
        raise RuntimeError("Không tìm thấy catalog-data trong TailieuKeToanThienUng/index.html")
    return json.loads(match.group(1))


def to_float(value: str | None) -> float:
    try:
        return float(value) if value is not None else 0.0
    except (TypeError, ValueError):
        return 0.0


def text_width(value: str) -> int:
    return len(re.sub(r"<[^>]+>", "", value or "").strip())


def analyze_table(block: str) -> dict:
    open_tag = block.split(">", 1)[0] + ">"
    rows = TR_RE.findall(block)
    col_counts = [len(CELL_TAG_RE.findall(row)) for row in rows]
    max_cols = max(col_counts) if col_counts else 0
    table_widths = STYLE_WIDTH_RE.findall(open_tag)
    width_attrs = WIDTH_ATTR_RE.findall(open_tag)
    borderless = 'border="0"' in open_tag.lower() or "border: none" in block.lower()
    return {
        "max_cols": max_cols,
        "row_count": len(rows),
        "width_px_max": max((to_float(v) for v in table_widths), default=0.0),
        "width_attr_max": max((to_float(v) for _, v in width_attrs), default=0.0),
        "borderless": borderless,
    }


def analyze_image(tag: str) -> dict:
    style_widths = STYLE_WIDTH_RE.findall(tag)
    width_attrs = WIDTH_ATTR_RE.findall(tag)
    return {
        "has_fixed_width": bool(style_widths or width_attrs),
        "width_px_max": max((to_float(v) for v in style_widths), default=0.0),
        "width_attr_max": max((to_float(v) for _, v in width_attrs), default=0.0),
    }


def classify_risk(stats: dict) -> tuple[str, int]:
    score = 0
    score += min(stats["fixed_width_style_count"], 180) * 0.45
    score += stats["complex_table_count"] * 12
    score += stats["table_count"] * 2
    score += stats["fixed_image_count"] * 5
    score += min(stats["max_width_px"], 1200) / 35
    score += min(stats["max_margin_left_px"], 200) / 10
    if stats["size_bytes"] >= 2_000_000:
        score += 18
    elif stats["size_bytes"] >= 1_000_000:
        score += 12
    elif stats["size_bytes"] >= 500_000:
        score += 6

    if (
        stats["complex_table_count"] >= 8
        or stats["fixed_width_style_count"] >= 140
        or stats["max_width_px"] >= 800
        or stats["size_bytes"] >= 2_000_000
        or score >= 95
    ):
        return "critical", round(score)
    if (
        stats["complex_table_count"] >= 3
        or stats["fixed_width_style_count"] >= 40
        or stats["max_margin_left_px"] >= 80
        or stats["size_bytes"] >= 800_000
        or score >= 40
    ):
        return "high", round(score)
    if (
        stats["table_count"] > 0
        or stats["fixed_width_style_count"] >= 10
        or stats["margin_left_count"] >= 5
        or stats["fixed_image_count"] > 0
        or score >= 15
    ):
        return "medium", round(score)
    return "low", round(score)


def collect_reasons(stats: dict) -> list[str]:
    reasons: list[str] = []
    if stats["complex_table_count"]:
        reasons.append(f"{stats['complex_table_count']} bảng >= 3 cột")
    if stats["fixed_width_style_count"]:
        reasons.append(f"{stats['fixed_width_style_count']} style width px")
    if stats["fixed_width_attr_count"]:
        reasons.append(f"{stats['fixed_width_attr_count']} width attr")
    if stats["fixed_image_count"]:
        reasons.append(f"{stats['fixed_image_count']} ảnh width cứng")
    if stats["max_margin_left_px"] >= 40:
        reasons.append(f"margin-left tối đa {int(stats['max_margin_left_px'])}px")
    if stats["size_bytes"] >= 1_000_000:
        reasons.append(f"file {stats['size_bytes'] // 1024} KB")
    return reasons or ["Nội dung tương đối an toàn"]


def analyze_article(article: dict) -> dict | None:
    source_path = SOURCE_ROOT / article["file"]
    if not source_path.exists():
        return None

    html = source_path.read_text(encoding="utf-8", errors="ignore")
    style_values = [style for _, style in STYLE_ATTR_RE.findall(html)]
    width_values = [to_float(v) for style in style_values for v in STYLE_WIDTH_RE.findall(style)]
    margin_left_values = [to_float(v) for style in style_values for v in STYLE_MARGIN_LEFT_RE.findall(style)]
    margin_right_values = [to_float(v) for style in style_values for v in STYLE_MARGIN_RIGHT_RE.findall(style)]
    width_attrs = WIDTH_ATTR_RE.findall(html)

    tables = [analyze_table(block) for block in TABLE_BLOCK_RE.findall(html)]
    images = [analyze_image(tag) for tag in IMG_TAG_RE.findall(html)]
    max_table_cols = max((table["max_cols"] for table in tables), default=0)

    stats = {
        "file": article["file"],
        "title": article["title"],
        "topic_lv1_label": article.get("topic_lv1_label") or "",
        "topic_lv2_label": article.get("topic_lv2_label") or "",
        "size_bytes": int(article.get("size_bytes") or source_path.stat().st_size),
        "table_count": len(tables),
        "complex_table_count": sum(1 for table in tables if table["max_cols"] >= 3),
        "two_col_table_count": sum(1 for table in tables if table["max_cols"] == 2),
        "legal_header_table_count": sum(1 for table in tables if table["max_cols"] == 2 and table["borderless"]),
        "max_table_cols": max_table_cols,
        "fixed_width_style_count": len(width_values),
        "fixed_width_attr_count": len(width_attrs),
        "fixed_image_count": sum(1 for image in images if image["has_fixed_width"]),
        "max_width_px": max(
            [*width_values,
             *(to_float(v) for _, v in width_attrs),
             *(table["width_px_max"] for table in tables),
             *(table["width_attr_max"] for table in tables),
             *(image["width_px_max"] for image in images),
             *(image["width_attr_max"] for image in images)],
            default=0.0,
        ),
        "margin_left_count": len(margin_left_values),
        "max_margin_left_px": max(margin_left_values, default=0.0),
        "margin_right_count": len(margin_right_values),
        "max_margin_right_px": max(margin_right_values, default=0.0),
    }
    stats["risk"], stats["score"] = classify_risk(stats)
    stats["reasons"] = collect_reasons(stats)
    return stats


def kb(value: int) -> str:
    return f"{round(value / 1024):,} KB".replace(",", ".")


def md(text: str) -> str:
    return str(text).replace("|", "\\|").replace("\n", " ").strip()


def render_metric_table(rows: list[tuple[str, str]]) -> str:
    lines = ["| Chỉ số | Giá trị |", "|---|---:|"]
    lines.extend(f"| {md(label)} | {md(value)} |" for label, value in rows)
    return "\n".join(lines)


def render_top_table(items: list[dict], limit: int = 30) -> str:
    lines = [
        "| # | Mức | Chuyên mục gốc | File nguồn | Kích thước | Bảng | Bảng >= 3 cột | Width px | Max width | Margin-left max | Ghi chú |",
        "|---:|---|---|---|---:|---:|---:|---:|---:|---:|---|",
    ]
    for index, item in enumerate(items[:limit], start=1):
        lines.append(
            "| {idx} | {risk} | {lv1} | `{file}` | {size} | {tables} | {complex_tables} | {widths} | {max_width}px | {max_margin}px | {notes} |".format(
                idx=index,
                risk=item["risk"],
                lv1=md(item["topic_lv1_label"]),
                file=md(shorten(item["file"], width=60, placeholder="…")),
                size=kb(item["size_bytes"]),
                tables=item["table_count"],
                complex_tables=item["complex_table_count"],
                widths=item["fixed_width_style_count"],
                max_width=int(item["max_width_px"]),
                max_margin=int(item["max_margin_left_px"]),
                notes=md(shorten("; ".join(item["reasons"]), width=90, placeholder="…")),
            )
        )
    return "\n".join(lines)


def render_case_list(items: list[dict], heading: str, limit: int = 12) -> str:
    lines = [f"### {heading}"]
    if not items:
        lines.append("- Không có bài nào trong nhóm này.")
        return "\n".join(lines)
    for item in items[:limit]:
        lines.append(
            "- **{title}** (`{file}`) — {risk}, score {score}; bảng={tables}, bảng>=3 cột={complex_tables}, width px={widths}, max width={max_width}px, margin-left max={max_margin}px.".format(
                title=md(item["title"]),
                file=md(item["file"]),
                risk=item["risk"],
                score=item["score"],
                tables=item["table_count"],
                complex_tables=item["complex_table_count"],
                widths=item["fixed_width_style_count"],
                max_width=int(item["max_width_px"]),
                max_margin=int(item["max_margin_left_px"]),
            )
        )
    return "\n".join(lines)


def build_report(stats: list[dict]) -> str:
    total = len(stats)
    risk_counter = Counter(item["risk"] for item in stats)
    with_tables = sum(1 for item in stats if item["table_count"] > 0)
    with_complex_tables = sum(1 for item in stats if item["complex_table_count"] > 0)
    with_fixed_widths = sum(1 for item in stats if item["fixed_width_style_count"] > 0)
    with_margin_left = sum(1 for item in stats if item["margin_left_count"] > 0)
    with_fixed_images = sum(1 for item in stats if item["fixed_image_count"] > 0)

    top_risk = sorted(
        stats,
        key=lambda item: (
            {"critical": 3, "high": 2, "medium": 1, "low": 0}[item["risk"]],
            item["score"],
            item["complex_table_count"],
            item["fixed_width_style_count"],
            item["size_bytes"],
        ),
        reverse=True,
    )
    complex_heavy = [item for item in top_risk if item["complex_table_count"] > 0]
    width_heavy = sorted(stats, key=lambda item: (item["fixed_width_style_count"], item["max_width_px"], item["size_bytes"]), reverse=True)
    indent_heavy = sorted(stats, key=lambda item: (item["max_margin_left_px"], item["margin_left_count"], item["size_bytes"]), reverse=True)

    metric_rows = [
        ("Tổng bài scan theo catalog", f"{total:,}".replace(",", ".")),
        ("Bài có bảng", f"{with_tables:,}".replace(",", ".")),
        ("Bài có bảng >= 3 cột", f"{with_complex_tables:,}".replace(",", ".")),
        ("Bài có width px inline", f"{with_fixed_widths:,}".replace(",", ".")),
        ("Bài có margin-left inline", f"{with_margin_left:,}".replace(",", ".")),
        ("Bài có ảnh width cứng", f"{with_fixed_images:,}".replace(",", ".")),
        ("Critical", f"{risk_counter['critical']:,}".replace(",", ".")),
        ("High", f"{risk_counter['high']:,}".replace(",", ".")),
        ("Medium", f"{risk_counter['medium']:,}".replace(",", ".")),
        ("Low", f"{risk_counter['low']:,}".replace(",", ".")),
    ]

    level_rows = {
        "critical": [item for item in top_risk if item["risk"] == "critical"],
        "high": [item for item in top_risk if item["risk"] == "high"],
        "medium": [item for item in top_risk if item["risk"] == "medium"],
        "low": [item for item in top_risk if item["risk"] == "low"],
    }

    report = f"""# Báo cáo audit mobile overflow cho kho bài legacy

- Thời điểm tạo: {datetime.now().strftime("%Y-%m-%d %H:%M:%S")}
- Nguồn scan: `TailieuKeToanThienUng/index.html` (`catalog-data`)
- Script tạo báo cáo: `tools/audit_legacy_mobile_readiness.py`
- File report: `docs/legacy-mobile-import-audit.md`

## 1) Mục đích

Scan toàn bộ **2066 bài nguồn** trước khi nhập vào `ketoandieutam.vn`, để:

1. nhận diện sớm bài có nguy cơ **tràn ngang trên mobile**
2. gom bài theo mức độ rủi ro để **ưu tiên QA**
3. chốt **quy trình kỹ thuật chuẩn** cho lần import tiếp theo

## 2) Cách chạy lại

```bash
python3 ketoandieutam.vn/tools/audit_legacy_mobile_readiness.py
```

## 3) Snapshot tổng quan

{render_metric_table(metric_rows)}

## 4) Cách đọc mức độ rủi ro

- **critical**: bài rất nặng hoặc có nhiều bảng phức tạp / width px cứng; cần QA tay sau import
- **high**: có nhiều width px, nhiều bảng >= 3 cột, margin-left lớn; cần test mobile sớm
- **medium**: có dấu hiệu mobile risk nhưng thường xử lý được bằng pipeline chuẩn
- **low**: gần như không có dấu hiệu overflow lớn

## 5) Giải pháp kỹ thuật chuẩn khi import

### 5.1 Build-time sanitizer là lớp xử lý chính

Khi nhập bài vào `ketoandieutam.vn`, nên chạy sanitizer **ngay lúc build** thay vì chờ runtime:

1. **Bỏ width cứng**
   - xóa `width="..."`
   - xóa hoặc rewrite `width/min-width/max-width: ...px`
   - áp dụng cho `table`, `td`, `th`, `img`, `iframe`, `embed`, `object`, `div`, `span`

2. **Chuẩn hóa ảnh**
   - `max-width: 100%`
   - `height: auto`
   - bỏ `height="..."` cứng nếu có

3. **Hạ indent legacy**
   - các `margin-left: 40px`, `80px`... nên clamp về mức nhỏ trên mobile
   - khuyến nghị: mobile không quá `16px`

4. **Phân loại bảng**
   - bảng **2 cột đơn giản**: giữ dạng table
   - bảng **>= 3 cột**: sinh thêm bản mobile stack/card
   - bảng header pháp lý kiểu “Quốc hội / Cộng hòa...” thường là 2 cột borderless, không cần card hóa

5. **Wrap text trong ô**
   - `white-space: normal`
   - `word-break: break-word`
   - `overflow-wrap: anywhere`

### 5.2 Runtime safety net là lớp chặn cuối

Hiện đã có lớp runtime trong:

- `article-layout.js`
- `assets/css/content-hub.css`

Các lớp này đang làm:

- normalize width cứng của HTML legacy
- clamp margin-left trên mobile
- ép bảng wrap cell
- tự dựng **mobile card view** cho bảng phức tạp

### 5.3 Quy trình rollout khuyến nghị

1. import bài bằng build-time sanitizer
2. để runtime safety net tiếp tục bảo vệ
3. test thủ công theo thứ tự:
   - critical
   - high
   - medium có nhiều bảng

## 6) Danh sách ưu tiên xử lý cao nhất

{render_top_table(top_risk, limit=40)}

## 7) Nhóm bài cần QA sớm nhất

{render_case_list(level_rows["critical"], "Critical", limit=15)}

{render_case_list(level_rows["high"], "High", limit=15)}

## 8) Nhóm bài nhiều bảng phức tạp nhất

{render_case_list(complex_heavy, "Bài có nhiều bảng >= 3 cột", limit=15)}

## 9) Nhóm bài nhiều width px inline nhất

{render_case_list(width_heavy, "Bài có nhiều width px inline", limit=15)}

## 10) Nhóm bài thụt lề legacy mạnh nhất

{render_case_list(indent_heavy, "Bài có margin-left lớn", limit=15)}

## 11) Kết luận vận hành

- Không nên sửa tay từng bài.
- Cần giữ chiến lược **pipeline hóa**:
  - scan trước
  - sanitize khi import
  - runtime safety net
  - QA theo risk bucket
- Report này là danh sách ưu tiên để khi nhập tiếp hơn 2000 bài, đội triển khai biết **bài nào phải xem kỹ trước**.
"""
    return report


def collect_audit_stats(catalog: dict | None = None) -> list[dict]:
    catalog = catalog or load_catalog()
    stats: list[dict] = []
    for article in catalog["articles"]:
        result = analyze_article(article)
        if result:
            stats.append(result)
    return stats


def write_audit_report(stats: list[dict], report_path: Path = REPORT_PATH) -> Path:
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(build_report(stats), encoding="utf-8")
    return report_path


def run_audit(report_path: Path = REPORT_PATH, catalog: dict | None = None) -> tuple[list[dict], Path]:
    stats = collect_audit_stats(catalog=catalog)
    path = write_audit_report(stats, report_path=report_path)
    return stats, path


def main() -> None:
    stats, path = run_audit()
    print(str(path.relative_to(REPO_ROOT)))
    print(f"Scanned: {len(stats)} articles")


if __name__ == "__main__":
    main()
