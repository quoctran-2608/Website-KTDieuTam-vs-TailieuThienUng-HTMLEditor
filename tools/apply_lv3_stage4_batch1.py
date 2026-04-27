#!/usr/bin/env python3
"""Chặng 4 - apply thêm một batch nhỏ lv3 từ queue medium/low (siêu bảo thủ)."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
MEDIUM_LOW_QUEUE_PATH = ROOT / "docs" / "lv3-review-queue-medium-low.json"
APPLY_JSON_PATH = ROOT / "docs" / "lv3-stage4-batch1-apply.json"
APPLY_MD_PATH = ROOT / "docs" / "lv3-stage4-batch1-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage4", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def load_taxonomy_label_map() -> Dict[str, str]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    out: Dict[str, str] = {}
    stack = list(data["roots"])
    while stack:
        node = stack.pop()
        key = node.get("key")
        label = node.get("label")
        if key and label:
            out[key] = label
        for c in node.get("children") or []:
            stack.append(c)
    return out


def update_article_meta_file(article_path: Path, lv3_key: str, lv3_label: str) -> Tuple[bool, str]:
    if not article_path.exists():
        return False, "missing-file"
    html = article_path.read_text(encoding="utf-8", errors="ignore")
    match = META_RE.search(html)
    if not match:
        return False, "missing-article-meta"
    try:
        meta = json.loads(match.group(2))
    except json.JSONDecodeError:
        return False, "invalid-article-meta-json"
    meta["topicLv3Key"] = lv3_key
    meta["topicLv3Label"] = lv3_label
    replaced = html[: match.start(2)] + json.dumps(meta, ensure_ascii=False) + html[match.end(2) :]
    article_path.write_text(replaced, encoding="utf-8")
    return True, "updated"


def rebuild_data_artifacts(importer, data_articles: List[Dict]) -> Dict[str, int]:
    records_by_section: Dict[str, List[Dict]] = {"thu-vien": [], "ban-tin": []}
    for idx, item in enumerate(data_articles):
        rec = {
            "file": Path(item["href"]).name.replace(".html", ".htm"),
            "target_root": item["href"],
            "section": item["section"],
            "title": item["title"],
            "excerpt": item.get("excerpt") or "",
            "content_html": "",
            "topic_lv1_key": item.get("topicLv1Key") or "",
            "topic_lv1_label": item.get("topicLv1Label") or "",
            "topic_lv2_key": item.get("topicLv2Key") or "",
            "topic_lv2_label": item.get("topicLv2Label") or "",
            "topic_lv3_key": item.get("topicLv3Key") or "",
            "topic_lv3_label": item.get("topicLv3Label") or "",
            "tags": item.get("tags") or [],
            "display_badge": item.get("cardBadgeLabel") or "",
            "display_topic": item.get("cardTopicLabel") or "",
            "library_kind_key": item.get("libraryKindKey") or "",
            "library_kind_label": item.get("libraryKindLabel") or "",
            "tool_lv3_key": item.get("toolLv3Key") or "",
            "tool_lv3_label": item.get("toolLv3Label") or "",
            "publish_date": item.get("publishDate") or "",
            "modified_date": item.get("modifiedDate"),
            "author_name": item.get("authorName") or "Kế Toán Diệu Tâm",
            "author_type": item.get("authorType") or "Organization",
            "hub_image": item.get("image") or importer.FEATURE_IMAGE_PATH,
            "catalog_index": idx,
        }
        records_by_section[item["section"]].append(rec)

    for section in ("thu-vien", "ban-tin"):
        records_by_section[section].sort(key=lambda r: importer.fold(r["title"]))
        for idx, rec in enumerate(records_by_section[section]):
            rec["catalog_index"] = idx

    page_maps = {}
    for section in ("thu-vien", "ban-tin"):
        total_pages = max(1, (len(records_by_section[section]) + importer.PAGE_SIZE - 1) // importer.PAGE_SIZE)
        page_maps[section] = importer.build_page_map(section, total_pages)
    importer.rebuild_hub_pages(records_by_section, page_maps)

    index_data = importer.build_content_index(records_by_section)
    importer.write_content_index(index_data)
    importer.write_data_artifacts(records_by_section, index_data, page_maps)
    importer.write_taxonomy_data(records_by_section)
    importer.write_sitemap(index_data, page_maps)

    return {
        "thu_vien_count": len(records_by_section["thu-vien"]),
        "ban_tin_count": len(records_by_section["ban-tin"]),
        "thu_vien_pages": len(page_maps["thu-vien"]),
        "ban_tin_pages": len(page_maps["ban-tin"]),
    }


def main() -> None:
    importer = load_importer_module()
    taxonomy_labels = load_taxonomy_label_map()
    queue = json.loads(MEDIUM_LOW_QUEUE_PATH.read_text(encoding="utf-8")).get("records", [])

    # Siêu bảo thủ cho batch nhỏ đầu tiên:
    # - Chỉ lấy bài lv2=bao-cao-thuc-tap
    # - score >= 4
    # - confidence=medium
    candidates = [
        row
        for row in queue
        if row.get("topicLv2Key") == "bao-cao-thuc-tap"
        and row.get("confidence") == "medium"
        and int(row.get("score", 0)) >= 4
        and (row.get("suggestedTopicLv3Key") or "")
    ]

    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}

    updated_rows = []
    skipped_rows = []
    for row in candidates:
        href = row["href"]
        item = article_map.get(href)
        if not item:
            skipped_rows.append({"href": href, "reason": "missing-in-data-articles"})
            continue
        if item.get("section") != "thu-vien":
            skipped_rows.append({"href": href, "reason": "not-thu-vien"})
            continue
        lv3_key = row["suggestedTopicLv3Key"]
        lv3_label = taxonomy_labels.get(lv3_key) or row.get("suggestedTopicLv3Label") or ""
        old_key = (item.get("topicLv3Key") or "").strip()
        if old_key and old_key != lv3_key:
            skipped_rows.append({"href": href, "reason": f"conflict-existing-lv3:{old_key}"})
            continue
        item["topicLv3Key"] = lv3_key
        item["topicLv3Label"] = lv3_label
        updated_rows.append(
            {
                "href": href,
                "title": item.get("title") or "",
                "topicLv2Key": item.get("topicLv2Key") or "",
                "topicLv3Key": lv3_key,
                "topicLv3Label": lv3_label,
                "confidence": row.get("confidence"),
                "score": row.get("score", 0),
                "rationale": row.get("rationale", ""),
            }
        )

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for row in updated_rows:
        ok, reason = update_article_meta_file(ROOT / row["href"], row["topicLv3Key"], row["topicLv3Label"])
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": row["href"], "reason": reason})

    counts = rebuild_data_artifacts(importer, data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "policy": "stage4-batch1-conservative",
        "candidateCount": len(candidates),
        "appliedCount": len(updated_rows),
        "skippedCount": len(skipped_rows),
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "appliedRows": updated_rows,
        "skippedRows": skipped_rows,
    }
    APPLY_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 4 - Batch 1 apply từ medium/low (siêu bảo thủ)",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        "- Rule: `lv2=bao-cao-thuc-tap`, `confidence=medium`, `score>=4`.",
        f"- Candidate: **{len(candidates)}**",
        f"- Applied: **{len(updated_rows)}**",
        f"- Skipped: **{len(skipped_rows)}**",
        f"- Cập nhật article-meta HTML: **{meta_updated}**",
        "",
        "## Quy mô sau rebuild",
        "",
        f"- Thư viện: {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang",
        f"- Bản tin: {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách applied",
        "",
        "| # | href | lv2 | lv3 | score | rationale |",
        "|---:|---|---|---|---:|---|",
    ]
    for idx, row in enumerate(updated_rows, 1):
        lines.append(
            f"| {idx} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` | "
            f"{row['score']} | {row['rationale']} |"
        )
    APPLY_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "candidateCount": len(candidates),
                "appliedCount": len(updated_rows),
                "articleMetaUpdated": meta_updated,
                "countsAfterRebuild": counts,
                "applyLog": str(APPLY_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
