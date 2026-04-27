#!/usr/bin/env python3
"""Chặng 25 - Chốt nốt dòng review tay cuối của nhóm import 745."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
TARGET_HREF = "thu-vien/muc-phat-cham-nop-ho-so-khai-thue-gtgt-tncn-tndn.html"
TARGET_LV3_KEY = "htkk-guide-import-bang-ke"

OUT_JSON_PATH = ROOT / "docs" / "lv3-stage25-apply.json"
OUT_MD_PATH = ROOT / "docs" / "lv3-stage25-apply.md"
OUT_LEFT_JSON_PATH = ROOT / "docs" / "lv3-stage25-manual-review-left.json"
OUT_LEFT_MD_PATH = ROOT / "docs" / "lv3-stage25-manual-review-left.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage25", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def taxonomy_labels() -> Dict[str, str]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    labels: Dict[str, str] = {}
    stack = list(data.get("roots", []))
    while stack:
        node = stack.pop()
        k, lb = node.get("key"), node.get("label")
        if k and lb:
            labels[k] = lb
        stack.extend(node.get("children") or [])
    return labels


def update_meta(path: Path, lv3_key: str, lv3_label: str) -> Tuple[bool, str]:
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
    meta["topicLv3Key"] = lv3_key
    meta["topicLv3Label"] = lv3_label
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


def write_left_review_pack(left_rows: List[Dict]) -> None:
    payload = {
        "generatedAt": datetime.now().isoformat(),
        "count": len(left_rows),
        "summary": {"medium": 0, "low": len(left_rows)},
        "records": left_rows,
    }
    OUT_LEFT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 25 - Queue review tay còn lại",
        "",
        f"- Tổng dòng còn lại: **{len(left_rows)}**",
        "",
        "| # | href | lv2 | title |",
        "|---:|---|---|---|",
    ]
    for i, r in enumerate(left_rows, 1):
        lines.append(f"| {i} | `{r.get('href','')}` | `{r.get('topicLv2Key','')}` | {r.get('title','')} |")
    OUT_LEFT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    importer = load_importer_module()
    labels = taxonomy_labels()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)

    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}

    target = next((a for a in data_articles if a.get("href") == TARGET_HREF), None)
    applied = []
    skipped = []

    if not target:
        skipped.append({"href": TARGET_HREF, "reason": "missing-article"})
    elif target.get("href") not in imported_hrefs:
        skipped.append({"href": TARGET_HREF, "reason": "not-in-imported-745"})
    elif target.get("section") != "thu-vien":
        skipped.append({"href": TARGET_HREF, "reason": "not-thu-vien"})
    elif (target.get("topicLv3Key") or "").strip():
        skipped.append({"href": TARGET_HREF, "reason": "already-has-lv3"})
    elif TARGET_LV3_KEY not in labels:
        skipped.append({"href": TARGET_HREF, "reason": "invalid-target-lv3"})
    else:
        target["topicLv3Key"] = TARGET_LV3_KEY
        target["topicLv3Label"] = labels[TARGET_LV3_KEY]
        applied.append(
            {
                "href": target["href"],
                "topicLv2Key": target.get("topicLv2Key") or "",
                "topicLv3Key": target.get("topicLv3Key") or "",
                "topicLv3Label": target.get("topicLv3Label") or "",
                "reason": "stage25-finalize-last-row",
            }
        )

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for row in applied:
        ok, reason = update_meta(ROOT / row["href"], row["topicLv3Key"], row["topicLv3Label"])
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": row["href"], "reason": reason})

    counts = rebuild(importer, data_articles)
    write_final_summary(data_articles)

    remaining = [
        a
        for a in data_articles
        if a.get("href") in imported_hrefs
        and a.get("section") == "thu-vien"
        and not (a.get("topicLv3Key") or "").strip()
    ]
    write_left_review_pack(remaining)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "scope": {"targetHref": TARGET_HREF, "applied": len(applied), "skipped": len(skipped)},
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "skippedRows": skipped,
        "leftReviewPack": {"json": str(OUT_LEFT_JSON_PATH.relative_to(ROOT)), "md": str(OUT_LEFT_MD_PATH.relative_to(ROOT)), "count": len(remaining)},
    }
    OUT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 25 - Finalize dòng review cuối",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Cập nhật article-meta HTML: **{meta_updated}**",
        f"- Queue review còn lại: `{OUT_LEFT_MD_PATH.relative_to(ROOT)}` (**{len(remaining)}** dòng)",
        "",
        "| # | href | lv2 | lv3 | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` | `{row['reason']}` |"
        )
    lines += ["", "## Skipped", "", "| # | href | reason |", "|---:|---|---|"]
    for i, row in enumerate(skipped, 1):
        lines.append(f"| {i} | `{row['href']}` | `{row['reason']}` |")
    OUT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
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
