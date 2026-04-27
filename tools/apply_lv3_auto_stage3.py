#!/usr/bin/env python3
"""Áp dụng gán topicLv3 tự động (confidence cao) cho 745 bài import mới.

Chặng này chỉ ghi thật nhóm an toàn từ dry-run:
- auto-single-child
- high

Đồng thời xuất 2 queue còn lại để review:
- medium/low
- no-child-lv2 (taxonomy chưa có nhánh lv3)
"""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
DRYRUN_PATH = ROOT / "docs" / "lv3-dryrun-imported-745.json"
APPLY_JSON_PATH = ROOT / "docs" / "lv3-apply-stage3.json"
APPLY_MD_PATH = ROOT / "docs" / "lv3-apply-stage3.md"
QUEUE_MEDIUM_LOW_JSON = ROOT / "docs" / "lv3-review-queue-medium-low.json"
QUEUE_MEDIUM_LOW_MD = ROOT / "docs" / "lv3-review-queue-medium-low.md"
QUEUE_TAXONOMY_GAP_JSON = ROOT / "docs" / "lv3-review-queue-taxonomy-gap.json"
QUEUE_TAXONOMY_GAP_MD = ROOT / "docs" / "lv3-review-queue-taxonomy-gap.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


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
    new_json = json.dumps(meta, ensure_ascii=False)
    replaced = html[: match.start(2)] + new_json + html[match.end(2) :]
    article_path.write_text(replaced, encoding="utf-8")
    return True, "updated"


def write_queue_markdown(path: Path, title: str, rows: List[Dict], note: str) -> None:
    lines = [f"# {title}", "", note, "", f"- Tổng dòng: **{len(rows)}**", ""]
    lines += [
        "| # | href | lv2 | đề xuất lv3 | confidence | score | rationale |",
        "|---:|---|---|---|---|---:|---|",
    ]
    for i, row in enumerate(rows, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row.get('suggestedTopicLv3Key') or '-'}` | "
            f"`{row['confidence']}` | {row.get('score', 0)} | {row.get('rationale', '')} |"
        )
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


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
    dryrun = read_json(DRYRUN_PATH)
    records: List[Dict] = dryrun.get("records", [])

    auto_records = [
        r
        for r in records
        if r.get("confidence") in {"auto-single-child", "high"} and (r.get("suggestedTopicLv3Key") or "")
    ]
    medium_low_records = [r for r in records if r.get("confidence") in {"medium", "low"}]
    taxonomy_gap_records = [r for r in records if r.get("confidence") == "no-child-lv2"]

    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map: Dict[str, Dict] = {a["href"]: a for a in data_articles}

    updated_rows = []
    skipped_missing_href = []
    conflicts = []
    for row in auto_records:
        href = row["href"]
        article = article_map.get(href)
        if not article:
            skipped_missing_href.append(href)
            continue
        new_key = row["suggestedTopicLv3Key"]
        new_label = row["suggestedTopicLv3Label"]
        old_key = (article.get("topicLv3Key") or "").strip()
        old_label = (article.get("topicLv3Label") or "").strip()
        if old_key and old_key != new_key:
            conflicts.append(
                {
                    "href": href,
                    "old_key": old_key,
                    "new_key": new_key,
                    "old_label": old_label,
                    "new_label": new_label,
                }
            )
            continue
        if old_key == new_key and old_label == new_label:
            continue
        article["topicLv3Key"] = new_key
        article["topicLv3Label"] = new_label
        updated_rows.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "topicLv2Key": article.get("topicLv2Key") or "",
                "topicLv3Key": new_key,
                "topicLv3Label": new_label,
                "confidence": row.get("confidence"),
                "score": row.get("score", 0),
                "rationale": row.get("rationale", ""),
            }
        )

    importer.write_json(data_articles_path, data_articles)

    article_meta_updated = 0
    article_meta_skipped = []
    for row in updated_rows:
        ok, status = update_article_meta_file(ROOT / row["href"], row["topicLv3Key"], row["topicLv3Label"])
        if ok:
            article_meta_updated += 1
        else:
            article_meta_skipped.append({"href": row["href"], "reason": status})

    counts = rebuild_data_artifacts(importer, data_articles)

    write_json(QUEUE_MEDIUM_LOW_JSON, {"generatedAt": datetime.now().isoformat(), "records": medium_low_records})
    write_json(
        QUEUE_TAXONOMY_GAP_JSON,
        {"generatedAt": datetime.now().isoformat(), "records": taxonomy_gap_records},
    )
    write_queue_markdown(
        QUEUE_MEDIUM_LOW_MD,
        "LV3 review queue - medium/low",
        medium_low_records,
        "Danh sách cần review nhanh trước khi gán tự động.",
    )
    write_queue_markdown(
        QUEUE_TAXONOMY_GAP_MD,
        "LV3 review queue - taxonomy gap (no-child-lv2)",
        taxonomy_gap_records,
        "Các bài có lv2 chưa có nhánh lv3 trong taxonomy hiện tại.",
    )

    apply_payload = {
        "generatedAt": datetime.now().isoformat(),
        "sourceDryRun": str(DRYRUN_PATH.relative_to(ROOT)),
        "autoCandidateCount": len(auto_records),
        "appliedCount": len(updated_rows),
        "skippedMissingHref": skipped_missing_href,
        "conflicts": conflicts,
        "articleMetaUpdated": article_meta_updated,
        "articleMetaSkipped": article_meta_skipped,
        "queues": {
            "mediumLow": len(medium_low_records),
            "taxonomyGap": len(taxonomy_gap_records),
        },
        "countsAfterRebuild": counts,
        "appliedRows": updated_rows,
    }
    write_json(APPLY_JSON_PATH, apply_payload)

    lines = [
        "# Chặng 3 - Apply topicLv3 tự động (safe set)",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Dry-run nguồn: `{DRYRUN_PATH.relative_to(ROOT)}`",
        f"- Candidate auto (high + single-child): **{len(auto_records)}**",
        f"- Đã gán thật vào data/articles: **{len(updated_rows)}**",
        f"- Cập nhật article-meta trong file HTML: **{article_meta_updated}**",
        f"- Queue medium/low: **{len(medium_low_records)}** (`{QUEUE_MEDIUM_LOW_MD.relative_to(ROOT)}`)",
        f"- Queue taxonomy-gap: **{len(taxonomy_gap_records)}** (`{QUEUE_TAXONOMY_GAP_MD.relative_to(ROOT)}`)",
        "",
        "## Quy mô sau rebuild",
        "",
        f"- Thư viện: {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang",
        f"- Bản tin: {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## 30 dòng đã gán gần nhất",
        "",
        "| # | href | lv2 | lv3 | confidence | score |",
        "|---:|---|---|---|---|---:|",
    ]
    for i, row in enumerate(updated_rows[:30], 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` | "
            f"`{row['confidence']}` | {row['score']} |"
        )
    APPLY_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "autoCandidateCount": len(auto_records),
                "appliedCount": len(updated_rows),
                "articleMetaUpdated": article_meta_updated,
                "queueMediumLow": len(medium_low_records),
                "queueTaxonomyGap": len(taxonomy_gap_records),
                "countsAfterRebuild": counts,
                "applyLog": str(APPLY_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
