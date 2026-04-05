#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SITE_ROOT = ROOT / "Ketoandieutam.com"
DOCS_DIR = SITE_ROOT / "docs"
REPORT_PATH = DOCS_DIR / "editorial-review-queue.md"


def load_builder():
    spec = importlib.util.spec_from_file_location("builder", ROOT / ".m" / "build_sample_sections.py")
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def main() -> None:
    b = load_builder()
    catalog = b.load_catalog()["articles"]

    rows = []
    for article in catalog:
        if b.is_paged_variant(article["file"]):
            continue

        classification = b.classify_article(article)
        legacy_scores = classification.get("legacy_scores", {})
        ranked = sorted(legacy_scores.items(), key=lambda item: (-item[1], item[0]))
        top_name, top_score = ranked[0]
        second_name, second_score = ranked[1]
        margin = top_score - second_score

        title = article["title"]
        flags = []
        if b.DOC_LIKE_RE.search(title) and b.GUIDE_LIKE_RE.search(title):
            flags.append("doc+guide")
        if b.DOC_LIKE_RE.search(title) and (b.NEWS_LIKE_RE.search(title) or b.NEWS_UPDATE_RE.search(title)):
            flags.append("doc+news")
        if b.GUIDE_LIKE_RE.search(title) and (b.NEWS_LIKE_RE.search(title) or b.NEWS_UPDATE_RE.search(title)):
            flags.append("guide+news")
        if classification["primary"] == "ban-tin" and not re.search(
            r"(luật|bộ luật|nghị định|thông tư|công văn|nghị quyết|quyết định|điểm mới|cập nhật|bãi bỏ)",
            title,
            re.IGNORECASE,
        ):
            flags.append("ban-tin weak wording")
        if classification["primary"] == "thu-vien" and classification.get("library_kind_key") == "bieu-mau" and b.GUIDE_LIKE_RE.search(title):
            flags.append("bieu-mau guide-like")
        if classification["primary"] == "thu-vien" and classification.get("library_kind_key") == "cong-cu" and b.DOC_LIKE_RE.search(title):
            flags.append("cong-cu doc-like")
        if article.get("topic_lv1_key") == "phan-mem-cong-cu" and classification.get("library_kind_key") != "cong-cu":
            flags.append("tool-source mapped away")
        if article.get("topic_lv1_key") == "van-ban-phap-luat" and classification["primary"] != "ban-tin":
            flags.append("legal-source mapped to library")

        uncertainty = max(0, 100 - min(margin, 100))
        uncertainty += len(flags) * 18
        if top_score < 100:
            uncertainty += 12

        rows.append(
            {
                "title": title,
                "file": article["file"],
                "topic_lv1": article.get("topic_lv1_label") or "",
                "topic_lv2": article.get("topic_lv2_label") or "",
                "primary": classification["primary"],
                "library_kind": classification.get("library_kind_label") or "",
                "legacy_top": f"{top_name}:{top_score}",
                "legacy_second": f"{second_name}:{second_score}",
                "margin": margin,
                "uncertainty": uncertainty,
                "flags": flags,
            }
        )

    rows.sort(key=lambda item: (-item["uncertainty"], item["margin"], item["title"].lower()))
    top = rows[:50]

    lines = [
        "# Editorial review queue — 50 bài cần rà tay",
        "",
        "- Nguồn: `TailieuKeToanThienUng/index.html`",
        "- Logic hiện tại: `.m/build_sample_sections.py`",
        "- Mục tiêu: chọn ra **50 bài biên giới nhất** để biên tập viên rà tay trước/hoặc sau import full",
        "",
        "## Cách hiểu cột",
        "",
        "- `Primary`: menu public hiện tại (`Thư viện` hoặc `Bản tin`)",
        "- `Library kind`: nhóm con nếu thuộc `Thư viện`",
        "- `Legacy top / second`: điểm cao nhất và nhì của classifier 3 ý định cũ",
        "- `Margin`: khoảng cách điểm giữa 2 intent cao nhất; càng thấp càng dễ nhập nhằng",
        "- `Flags`: pattern xung đột hoặc ca đáng nghi",
        "",
        "## Top 50 bài cần rà",
        "",
        "| # | Primary | Kind | Margin | Flags | LV1 | LV2 | Tiêu đề | File |",
        "|---:|---|---|---:|---|---|---|---|---|",
    ]

    for idx, row in enumerate(top, 1):
        primary_label = "Bản tin" if row["primary"] == "ban-tin" else "Thư viện"
        flag_text = ", ".join(row["flags"]) if row["flags"] else "-"
        lines.append(
            f"| {idx} | {primary_label} | {row['library_kind'] or '-'} | {row['margin']} | "
            f"{flag_text} | {row['topic_lv1']} | {row['topic_lv2']} | {row['title']} | `{row['file']}` |"
        )

    lines += [
        "",
        "## Gợi ý xử lý tay",
        "",
        "1. Ưu tiên rà các bài có `Margin <= 20`",
        "2. Sau đó rà các bài có cờ `doc+guide`, `doc+news`, `guide+news`",
        "3. Nếu tiêu đề là mẫu biểu nhưng user sẽ vào để **làm theo**, cân nhắc chuyển `Library kind` từ `Biểu mẫu` sang `Hướng dẫn`",
        "4. Nếu là văn bản cập nhật nhưng có giá trị tra cứu lâu dài, vẫn giữ `Bản tin` nếu tính thời điểm là trọng tâm",
        "",
        "## Chạy lại",
        "",
        "```bash",
        "python3 Ketoandieutam.com/tools/audit_editorial_review_queue.py",
        "```",
        "",
    ]

    REPORT_PATH.write_text("\n".join(lines), encoding="utf-8")
    print(REPORT_PATH)
    print(f"Scanned: {len(rows)} articles")


if __name__ == "__main__":
    main()
