#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SITE_ROOT = REPO_ROOT / "ketoandieutam.vn"
DOCS_DIR = SITE_ROOT / "docs"
CATALOG_FILE = REPO_ROOT / "TailieuKeToanThienUng" / "index.html"
REPORT_PATH = DOCS_DIR / "content-classification-audit.md"
BUILDER_PATH = REPO_ROOT / ".m" / "build_sample_sections.py"


def load_builder_module():
    spec = importlib.util.spec_from_file_location("build_sample_sections", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không thể load module từ {BUILDER_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_catalog() -> dict:
    raw = CATALOG_FILE.read_text(encoding="utf-8", errors="ignore")
    match = re.search(r'<script id="catalog-data" type="application/json">(.*?)</script>', raw, re.S)
    if not match:
        raise RuntimeError("Không tìm thấy catalog-data trong nguồn")
    return json.loads(match.group(1))


def build_report(builder, catalog: dict) -> str:
    legacy_counts = Counter()
    top_counts = Counter()
    library_kind_counts = Counter()
    changed_pairs = Counter()
    changed_examples: list[dict] = []
    overlap_examples: list[dict] = []
    kind_examples: dict[str, list[str]] = defaultdict(list)

    for article in catalog["articles"]:
        if builder.is_paged_variant(article["file"]):
            continue

        legacy = builder.classify_sections(article)
        modern = builder.classify_article(article)

        legacy_primary = legacy["primary"]
        top_primary = modern["primary"]
        legacy_counts[legacy_primary] += 1
        top_counts[top_primary] += 1

        if top_primary == "thu-vien":
            library_kind_counts[modern["library_kind_key"]] += 1
            if len(kind_examples[modern["library_kind_key"]]) < 12:
                kind_examples[modern["library_kind_key"]].append(article["title"])

        if top_primary == "ban-tin" and modern["secondary"]:
            if len(overlap_examples) < 24:
                overlap_examples.append(
                    {
                        "title": article["title"],
                        "source_group": article["topic_lv1_label"],
                        "secondary": ", ".join(modern["secondary"]),
                    }
                )

        pair = (legacy_primary, top_primary)
        changed_pairs[pair] += 1
        if legacy_primary != top_primary and len(changed_examples) < 40:
            changed_examples.append(
                {
                    "legacy": legacy_primary,
                    "top": top_primary,
                    "source_group": article["topic_lv1_label"],
                    "topic": article["topic_lv2_label"],
                    "title": article["title"],
                    "secondary": ", ".join(modern["secondary"]) if modern["secondary"] else "-",
                }
            )

    total = sum(top_counts.values())

    def label_for_top(key: str) -> str:
        return builder.SECTION_CONFIG[key]["label"]

    def label_for_legacy(key: str) -> str:
        return {
            "kien-thuc": "Kiến thức",
            "tai-lieu": "Tài liệu",
            "ban-tin": "Bản tin",
        }.get(key, key)

    lines: list[str] = [
        "# Audit phân loại nội dung theo IA mới",
        "",
        "- Nguồn: `TailieuKeToanThienUng/index.html`",
        "- Rule chính lấy từ: `.m/build_sample_sections.py`",
        "- Taxonomy public mới: **Thư viện | Bản tin**",
        f"- Tổng bài đã scan: **{total}**",
        "",
        "## 1) Phân bổ top-level mới",
        "",
        "| Menu lớn | Số bài |",
        "|---|---:|",
    ]
    for section in builder.SECTION_CONFIG:
        lines.append(f"| {label_for_top(section)} | {top_counts[section]} |")

    lines.extend(
        [
            "",
            "## 2) Phân bổ 3 nhóm cũ so với IA mới",
            "",
            "> Mục đích: để thấy rõ `Kiến thức + Tài liệu` đang được gom về **Thư viện**, còn `Bản tin` giữ vai trò cập nhật/chính sách.",
            "",
            "| Từ logic cũ | Sang menu mới | Số bài |",
            "|---|---|---:|",
        ]
    )
    for (legacy_primary, top_primary), count in sorted(changed_pairs.items(), key=lambda item: (-item[1], item[0][0], item[0][1])):
        lines.append(f"| {label_for_legacy(legacy_primary)} | {label_for_top(top_primary)} | {count} |")

    lines.extend(
        [
            "",
            "## 3) Phân loại nội dung bên trong Thư viện",
            "",
            "| Loại nội dung | Số bài |",
            "|---|---:|",
        ]
    )
    for kind_key, kind_label in builder.LIBRARY_KIND_LABELS.items():
        lines.append(f"| {kind_label} | {library_kind_counts[kind_key]} |")

    for kind_key, kind_label in builder.LIBRARY_KIND_LABELS.items():
        lines.extend(
            [
                "",
                f"### Ví dụ `Thư viện > {kind_label}`",
                "",
            ]
        )
        for title in kind_examples.get(kind_key, [])[:10]:
            lines.append(f"- {title}")

    lines.extend(
        [
            "",
            "## 4) Ví dụ bài cần chú ý khi đổi menu",
            "",
            "| Logic cũ | Menu mới | Nhóm nguồn | Nhóm con | Secondary | Tiêu đề |",
            "|---|---|---|---|---|---|",
        ]
    )
    for item in changed_examples:
        lines.append(
            f"| {label_for_legacy(item['legacy'])} | {label_for_top(item['top'])} | {item['source_group']} | {item['topic']} | {item['secondary']} | {item['title'].replace('|', ' ')} |"
        )

    lines.extend(
        [
            "",
            "## 5) Bài thuộc `Bản tin` nhưng vẫn có secondary `Thư viện`",
            "",
            "> Đây là nhóm bài vừa có tính cập nhật chính sách, vừa có giá trị tra cứu/ứng dụng. Không nhân bản URL, chỉ giữ secondary để hỗ trợ filter hoặc related content về sau.",
            "",
            "| Nguồn | Secondary | Tiêu đề |",
            "|---|---|---|",
        ]
    )
    for item in overlap_examples:
        secondary_labels = ", ".join(builder.SECTION_CONFIG[key]["label"] for key in item["secondary"].split(", ") if key in builder.SECTION_CONFIG)
        lines.append(f"| {item['source_group']} | {secondary_labels} | {item['title'].replace('|', ' ')} |")

    lines.extend(
        [
            "",
            "## 6) Rule vận hành ngắn gọn",
            "",
            "### Đưa vào `Thư viện` khi",
            "",
            "- mục tiêu chính là **tra cứu, học, làm theo hoặc dùng lại lâu dài**",
            "- đó là **hướng dẫn**, **biểu mẫu**, **công cụ**, hoặc nội dung chuyên môn evergreen",
            "",
            "### Đưa vào `Bản tin` khi",
            "",
            "- mục tiêu chính là **nắm thay đổi mới / điểm mới / công văn / chính sách / văn bản đáng chú ý**",
            "- bài có tính thời điểm cao hơn tính lưu trữ",
            "",
            "## 7) Chạy lại lần sau",
            "",
            "```bash",
            "python3 ketoandieutam.vn/tools/audit_content_classification.py",
            "```",
            "",
            "Đọc kèm:",
            "",
            "- `docs/content-classification-policy.md`",
            "- `docs/legacy-import-pipeline.md`",
        ]
    )

    return "\n".join(lines) + "\n"


def main() -> None:
    builder = load_builder_module()
    catalog = load_catalog()
    DOCS_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(build_report(builder, catalog), encoding="utf-8")
    print(str(REPORT_PATH.relative_to(REPO_ROOT)))
    print(f"Scanned: {len(catalog['articles'])} catalog rows")


if __name__ == "__main__":
    main()
