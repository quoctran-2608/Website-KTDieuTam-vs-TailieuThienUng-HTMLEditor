#!/usr/bin/env python3
"""Normalize taxonomy-facing tags for articles moved from ban-tin path to thu-vien.

Problem:
- Some moved articles still carry legacy campaign tags like "Ưu đãi học phí",
  causing UI perception that classification is still from Bản tin.

Fix:
- For moved set (from docs/path-mismatch-fix-thu-vien.json), rewrite tags to
  taxonomy-derived tags: [topicLv2Label, topicLv1Label, libraryKindLabel].
- Sync article-meta in HTML.
- Rebuild generated data/artifacts.
"""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List

ROOT = Path(__file__).resolve().parent.parent
DATA_ARTICLES = ROOT / "data" / "articles.json"
MOVED_REPORT = ROOT / "docs" / "path-mismatch-fix-thu-vien.json"
OUT_JSON = ROOT / "docs" / "moved-bantin-thuvien-taxonomy-tag-fix.json"
OUT_MD = ROOT / "docs" / "moved-bantin-thuvien-taxonomy-tag-fix.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_fix_moved_tags", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Cannot import module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def update_meta_tags(path: Path, tags: List[str]) -> tuple[bool, str]:
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
    meta["tags"] = tags
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


def normalized_tags(article: Dict) -> List[str]:
    parts = [
        article.get("topicLv2Label") or "",
        article.get("topicLv1Label") or "",
        article.get("libraryKindLabel") or "",
    ]
    out: List[str] = []
    seen = set()
    for p in parts:
        p = str(p).strip()
        if not p:
            continue
        if p in seen:
            continue
        seen.add(p)
        out.append(p)
    return out or ["Thông tin"]


def main() -> None:
    importer = load_importer_module()
    articles: List[Dict] = json.loads(DATA_ARTICLES.read_text(encoding="utf-8"))
    amap = {a["href"]: a for a in articles}
    moved = json.loads(MOVED_REPORT.read_text(encoding="utf-8"))
    targets = [row["newHref"] for row in moved.get("applied", []) if row.get("newHref")]
    targets = sorted(set(targets))

    applied = []
    skipped = []
    for href in targets:
        a = amap.get(href)
        if not a:
            skipped.append({"href": href, "reason": "missing-article-record"})
            continue
        if a.get("section") != "thu-vien":
            skipped.append({"href": href, "reason": "not-thu-vien-section"})
            continue

        old_tags = list(a.get("tags") or [])
        new_tags = normalized_tags(a)
        if old_tags == new_tags:
            skipped.append({"href": href, "reason": "already-normalized"})
            continue

        a["tags"] = new_tags
        if isinstance(a.get("classificationReasons"), dict):
            a["classificationReasons"]["fixMovedBanTinThuVienTags"] = "normalized-to-taxonomy-derived-tags"

        ok, reason = update_meta_tags(ROOT / href, new_tags)
        if not ok:
            skipped.append({"href": href, "reason": reason})
            continue

        applied.append(
            {
                "href": href,
                "oldTags": old_tags,
                "newTags": new_tags,
            }
        )

    # save + rebuild
    DATA_ARTICLES.write_text(json.dumps(articles, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    counts = rebuild(importer, articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "targetCount": len(targets),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Normalize taxonomy tags for moved ban-tin -> thu-vien articles",
        "",
        f"- Generated: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Targets: **{len(targets)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Applied",
        "",
        "| # | href | old tags | new tags |",
        "|---:|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['oldTags']}` | `{row['newTags']}` |"
        )
    if skipped:
        lines += ["", "## Skipped", ""]
        for row in skipped:
            lines.append(f"- `{row['href']}`: {row['reason']}")

    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "targets": len(targets),
                "applied": len(applied),
                "skipped": len(skipped),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
