#!/usr/bin/env python3
"""Chặng 24 - Apply theo queue review medium của chặng 23."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
IN_REVIEW_PATH = ROOT / "docs" / "lv3-stage23-manual-review.json"
OUT_JSON_PATH = ROOT / "docs" / "lv3-stage24-apply.json"
OUT_MD_PATH = ROOT / "docs" / "lv3-stage24-apply.md"
OUT_LEFT_JSON_PATH = ROOT / "docs" / "lv3-stage24-manual-review-left.json"
OUT_LEFT_MD_PATH = ROOT / "docs" / "lv3-stage24-manual-review-left.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage24", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def build_taxonomy_maps() -> Tuple[Dict[str, str], Dict[str, str], List[Dict]]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    labels: Dict[str, str] = {}
    parent: Dict[str, str] = {}
    roots = data.get("roots", [])
    stack: List[Tuple[Dict, str]] = [(node, "") for node in roots]
    while stack:
        node, pkey = stack.pop()
        key = node.get("key") or ""
        label = node.get("label") or ""
        if key:
            labels[key] = label
            if pkey:
                parent[key] = pkey
        for child in node.get("children") or []:
            stack.append((child, key))
    return labels, parent, roots


def lv3_belongs_to_lv2(roots: List[Dict], lv2_key: str, lv3_key: str) -> bool:
    for r in roots:
        stack = [r]
        while stack:
            n = stack.pop()
            if n.get("key") == lv2_key:
                child_stack = list(n.get("children") or [])
                while child_stack:
                    c = child_stack.pop()
                    if c.get("key") == lv3_key:
                        return True
                    child_stack.extend(c.get("children") or [])
            stack.extend(n.get("children") or [])
    return False


def update_meta(
    path: Path,
    lv1_key: str,
    lv1_label: str,
    lv2_key: str,
    lv2_label: str,
    lv3_key: str,
    lv3_label: str,
) -> Tuple[bool, str]:
    if not path.exists():
        return False, "missing-file"
    html = path.read_text(encoding="utf-8", errors="ignore")
    m = META_RE.search(html)
    if not m:
        return False, "missing-article-meta"
    try:
        meta = json.loads(m.group(2))
    except json.JSONDecodeError:
        return False, "invalid-article-meta-json"

    meta["topicLv1Key"] = lv1_key
    meta["topicLv1Label"] = lv1_label
    meta["topicLv2Key"] = lv2_key
    meta["topicLv2Label"] = lv2_label
    meta["topicLv3Key"] = lv3_key
    meta["topicLv3Label"] = lv3_label
    meta["cardTopicLabel"] = lv2_label

    replaced = html[: m.start(2)] + json.dumps(meta, ensure_ascii=False) + html[m.end(2) :]
    path.write_text(replaced, encoding="utf-8")
    return True, "updated"


def rebuild(importer, data_articles: List[Dict]) -> Dict[str, int]:
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

    for sec in ("thu-vien", "ban-tin"):
        records_by_section[sec].sort(key=lambda r: importer.fold(r["title"]))
        for i, r in enumerate(records_by_section[sec]):
            r["catalog_index"] = i

    page_maps = {}
    for sec in ("thu-vien", "ban-tin"):
        total_pages = max(1, (len(records_by_section[sec]) + importer.PAGE_SIZE - 1) // importer.PAGE_SIZE)
        page_maps[sec] = importer.build_page_map(sec, total_pages)

    importer.rebuild_hub_pages(records_by_section, page_maps)
    idx_data = importer.build_content_index(records_by_section)
    importer.write_content_index(idx_data)
    importer.write_data_artifacts(records_by_section, idx_data, page_maps)
    importer.write_taxonomy_data(records_by_section)
    importer.write_sitemap(idx_data, page_maps)
    return {
        "thu_vien_count": len(records_by_section["thu-vien"]),
        "ban_tin_count": len(records_by_section["ban-tin"]),
        "thu_vien_pages": len(page_maps["thu-vien"]),
        "ban_tin_pages": len(page_maps["ban-tin"]),
    }


def write_final_summary(data_articles: List[Dict]) -> None:
    final_md = ROOT / "docs" / "lv3-final-summary.md"
    final_json = ROOT / "docs" / "lv3-final-summary.json"
    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}
    imported_rows = [a for a in data_articles if a.get("href") in imported_hrefs]

    total = len(imported_rows)
    non_empty = sum(1 for a in imported_rows if (a.get("topicLv3Key") or "").strip())
    empty = total - non_empty
    coverage = round((non_empty / total) * 100, 2) if total else 0.0

    from collections import Counter

    remaining = [a for a in imported_rows if not (a.get("topicLv3Key") or "").strip() and a.get("section") == "thu-vien"]
    by_lv2 = Counter((a.get("topicLv2Key") or "") for a in remaining)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "imported745": {"total": total, "topicLv3NonEmpty": non_empty, "topicLv3Empty": empty, "coveragePercent": coverage},
        "remainingThuVienByLv2": dict(by_lv2),
    }
    final_json.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Tổng kết tiến độ gán LV3 cho 745 bài import",
        "",
        f"- Thời gian cập nhật: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Tổng bài import: **{total}**",
        f"- Đã có topicLv3: **{non_empty}**",
        f"- Còn trống topicLv3: **{empty}**",
        f"- Coverage: **{coverage}%**",
        "",
        "## Còn trống theo LV2 (Thu viện)",
        "",
    ]
    for k, v in by_lv2.most_common():
        lines.append(f"- `{k}`: {v}")
    final_md.write_text("\n".join(lines) + "\n", encoding="utf-8")


def write_left_review(left_rows: List[Dict]) -> None:
    summary = {"medium": 0, "low": 0}
    for r in left_rows:
        conf = r.get("confidence") or "low"
        summary[conf] = summary.get(conf, 0) + 1
    payload = {
        "generatedAt": datetime.now().isoformat(),
        "count": len(left_rows),
        "summary": summary,
        "records": left_rows,
    }
    OUT_LEFT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 24 - Queue review tay còn lại",
        "",
        f"- Tổng dòng còn lại: **{len(left_rows)}**",
        f"- Medium: **{summary.get('medium', 0)}**",
        f"- Low: **{summary.get('low', 0)}**",
        "",
        "| # | href | lv2 hiện tại | lv2 đề xuất | lv3 đề xuất | confidence | note |",
        "|---:|---|---|---|---|---|---|",
    ]
    for i, r in enumerate(left_rows, 1):
        lines.append(
            f"| {i} | `{r.get('href','')}` | `{r.get('currentTopicLv2Key','')}` | `{r.get('suggestedTopicLv2Key','')}` | `{r.get('suggestedTopicLv3Key','')}` | `{r.get('confidence','')}` | {r.get('note','')} |"
        )
    OUT_LEFT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    importer = load_importer_module()
    labels, parent, roots = build_taxonomy_maps()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}

    review = json.loads(IN_REVIEW_PATH.read_text(encoding="utf-8"))
    records = review.get("records", [])
    medium_rows = [r for r in records if r.get("confidence") == "medium"]
    non_medium_rows = [r for r in records if r.get("confidence") != "medium"]

    applied = []
    skipped = []
    for row in medium_rows:
        href = row.get("href") or ""
        lv2 = row.get("suggestedTopicLv2Key") or ""
        lv3 = row.get("suggestedTopicLv3Key") or ""
        a = article_map.get(href)
        if not a:
            skipped.append({**row, "skipReason": "missing-article"})
            continue
        if not lv2 or not lv3:
            skipped.append({**row, "skipReason": "missing-suggestion-keys"})
            continue
        if lv2 not in labels or lv3 not in labels:
            skipped.append({**row, "skipReason": "invalid-taxonomy-key"})
            continue
        if not lv3_belongs_to_lv2(roots, lv2, lv3):
            skipped.append({**row, "skipReason": "lv3-not-under-lv2"})
            continue

        lv1 = parent.get(lv2) or ""
        if not lv1 or lv1 not in labels:
            skipped.append({**row, "skipReason": "cannot-resolve-lv1"})
            continue

        a["topicLv1Key"] = lv1
        a["topicLv1Label"] = labels.get(lv1) or ""
        a["topicLv2Key"] = lv2
        a["topicLv2Label"] = labels.get(lv2) or ""
        a["topicLv3Key"] = lv3
        a["topicLv3Label"] = labels.get(lv3) or ""
        a["cardTopicLabel"] = a["topicLv2Label"]

        applied.append(
            {
                "href": href,
                "topicLv1Key": a["topicLv1Key"],
                "topicLv2Key": a["topicLv2Key"],
                "topicLv3Key": a["topicLv3Key"],
                "reason": row.get("note") or "manual-medium-approved",
            }
        )

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for row in applied:
        art = article_map[row["href"]]
        ok, reason = update_meta(
            ROOT / row["href"],
            art.get("topicLv1Key") or "",
            art.get("topicLv1Label") or "",
            art.get("topicLv2Key") or "",
            art.get("topicLv2Label") or "",
            art.get("topicLv3Key") or "",
            art.get("topicLv3Label") or "",
        )
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": row["href"], "reason": reason})

    counts = rebuild(importer, data_articles)
    write_final_summary(data_articles)

    left_rows = non_medium_rows + skipped
    write_left_review(left_rows)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "scope": {"inputMedium": len(medium_rows), "applied": len(applied), "skipped": len(skipped)},
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "skippedRows": skipped,
        "leftReviewPack": {
            "json": str(OUT_LEFT_JSON_PATH.relative_to(ROOT)),
            "md": str(OUT_LEFT_MD_PATH.relative_to(ROOT)),
            "count": len(left_rows),
        },
    }
    OUT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 24 - Apply queue medium từ review tay",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Input medium rows: **{len(medium_rows)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Cập nhật article-meta HTML: **{meta_updated}**",
        f"- Queue review còn lại: `{OUT_LEFT_MD_PATH.relative_to(ROOT)}` (**{len(left_rows)}** dòng)",
        "",
        "## Quy mô sau rebuild",
        "",
        f"- Thư viện: {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang",
        f"- Bản tin: {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách applied",
        "",
        "| # | href | lv1 | lv2 | lv3 |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv1Key']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` |"
        )
    lines += ["", "## Danh sách skipped", "", "| # | href | skipReason |", "|---:|---|---|"]
    for i, row in enumerate(skipped, 1):
        lines.append(f"| {i} | `{row.get('href','')}` | `{row.get('skipReason','')}` |")
    OUT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "inputMedium": len(medium_rows),
                "applied": len(applied),
                "skipped": len(skipped),
                "articleMetaUpdated": meta_updated,
                "countsAfterRebuild": counts,
                "applyLog": str(OUT_JSON_PATH.relative_to(ROOT)),
                "leftReviewPack": str(OUT_LEFT_MD_PATH.relative_to(ROOT)),
                "finalSummary": "docs/lv3-final-summary.md",
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
