#!/usr/bin/env python3
"""Dry-run còn lại cho lv3 sau các batch apply Stage 3/4."""

from __future__ import annotations

import json
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
MEDIUM_LOW_QUEUE = ROOT / "docs" / "lv3-review-queue-medium-low.json"
TAX_GAP_QUEUE = ROOT / "docs" / "lv3-review-queue-taxonomy-gap.json"
OUT_JSON = ROOT / "docs" / "lv3-stage5-remaining-summary.json"
OUT_MD = ROOT / "docs" / "lv3-stage5-remaining-summary.md"


def main() -> None:
    articles = json.loads((ROOT / "data" / "articles.json").read_text(encoding="utf-8"))
    log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {it["target_path"] for b in log.get("batches", []) for it in b.get("imported", [])}
    imported_map = {a["href"]: a for a in articles if a["href"] in imported_hrefs}

    medium_low = json.loads(MEDIUM_LOW_QUEUE.read_text(encoding="utf-8")).get("records", [])
    tax_gap = json.loads(TAX_GAP_QUEUE.read_text(encoding="utf-8")).get("records", [])

    medium_low_remaining = [
        r
        for r in medium_low
        if r["href"] in imported_map and not (imported_map[r["href"]].get("topicLv3Key") or "").strip()
    ]
    tax_gap_remaining = [
        r
        for r in tax_gap
        if r["href"] in imported_map and not (imported_map[r["href"]].get("topicLv3Key") or "").strip()
    ]

    imported_rows = [a for a in imported_map.values()]
    imported_non_empty = sum(1 for a in imported_rows if (a.get("topicLv3Key") or "").strip())
    imported_empty = len(imported_rows) - imported_non_empty

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "imported745": {
            "total": len(imported_rows),
            "topicLv3NonEmpty": imported_non_empty,
            "topicLv3Empty": imported_empty,
        },
        "remainingQueues": {
            "mediumLowTotal": len(medium_low),
            "mediumLowRemainingWithoutLv3": len(medium_low_remaining),
            "taxonomyGapTotal": len(tax_gap),
            "taxonomyGapRemainingWithoutLv3": len(tax_gap_remaining),
        },
        "remainingByLv2": {
            "mediumLow": dict(Counter(r.get("topicLv2Key") or "" for r in medium_low_remaining)),
            "taxonomyGap": dict(Counter(r.get("topicLv2Key") or "" for r in tax_gap_remaining)),
        },
        "sampleMediumLow": medium_low_remaining[:40],
        "sampleTaxonomyGap": tax_gap_remaining[:40],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 5 - Tóm tắt phần LV3 còn lại sau Stage 3/4",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        "",
        "## Coverage 745 bài import mới",
        "",
        f"- Tổng: **{len(imported_rows)}**",
        f"- Đã có topicLv3: **{imported_non_empty}**",
        f"- Còn trống topicLv3: **{imported_empty}**",
        "",
        "## Queue còn lại",
        "",
        f"- Medium/Low tổng: **{len(medium_low)}**, còn trống: **{len(medium_low_remaining)}**",
        f"- Taxonomy-gap tổng: **{len(tax_gap)}**, còn trống: **{len(tax_gap_remaining)}**",
        "",
        "## Medium/Low còn lại theo LV2",
        "",
    ]
    for key, count in Counter(r.get("topicLv2Key") or "" for r in medium_low_remaining).most_common():
        lines.append(f"- `{key}`: {count}")
    lines += ["", "## Taxonomy-gap còn lại theo LV2", ""]
    for key, count in Counter(r.get("topicLv2Key") or "" for r in tax_gap_remaining).most_common():
        lines.append(f"- `{key}`: {count}")

    lines += [
        "",
        "## 30 dòng medium/low còn lại (mẫu)",
        "",
        "| # | href | lv2 | đề xuất lv3 | confidence | score |",
        "|---:|---|---|---|---|---:|",
    ]
    for i, row in enumerate(medium_low_remaining[:30], 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row.get('topicLv2Key','')}` | "
            f"`{row.get('suggestedTopicLv3Key','')}` | `{row.get('confidence','')}` | {row.get('score',0)} |"
        )

    lines += [
        "",
        "## 30 dòng taxonomy-gap còn lại (mẫu)",
        "",
        "| # | href | lv2 |",
        "|---:|---|---|",
    ]
    for i, row in enumerate(tax_gap_remaining[:30], 1):
        lines.append(f"| {i} | `{row['href']}` | `{row.get('topicLv2Key','')}` |")

    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "imported745": {
                    "total": len(imported_rows),
                    "topicLv3NonEmpty": imported_non_empty,
                    "topicLv3Empty": imported_empty,
                },
                "remaining": {
                    "mediumLow": len(medium_low_remaining),
                    "taxonomyGap": len(tax_gap_remaining),
                },
                "summaryJson": str(OUT_JSON.relative_to(ROOT)),
                "summaryMd": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
