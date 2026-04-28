#!/usr/bin/env python3
"""Fix articles moved to Thu vien taxonomy but still stored under ban-tin path.

Scope:
- data/articles.json records where section=thu-vien and href starts with ban-tin/
- move HTML file + article-view file to thu-vien path
- patch HTML meta/canonical/breadcrumb/data-nav/view-script reference
- patch record href/id/canonical
- rebuild derived artifacts
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
OUT_JSON = ROOT / "docs" / "path-mismatch-fix-thu-vien.json"
OUT_MD = ROOT / "docs" / "path-mismatch-fix-thu-vien.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_fix_mislocated_thuvien", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Cannot import module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def patch_article_html(path: Path, old_href: str, new_href: str) -> Dict[str, int]:
    html = path.read_text(encoding="utf-8", errors="ignore")
    stats = {
        "titlePatched": 0,
        "canonicalPatched": 0,
        "bodyNavPatched": 0,
        "breadcrumbPatched": 0,
        "viewScriptPatched": 0,
        "metaPatched": 0,
    }

    # title suffix
    before = html
    html = html.replace("| Bản tin |", "| Thư viện |")
    if html != before:
        stats["titlePatched"] = 1

    # canonical URL
    canonical_old = f"/{old_href}"
    canonical_new = f"/{new_href}"
    if canonical_old in html:
        html = html.replace(canonical_old, canonical_new)
        stats["canonicalPatched"] = 1

    # body nav
    html2 = re.sub(r'(<body[^>]*\bdata-nav=")ban-tin(")', r"\1thu-vien\2", html, flags=re.IGNORECASE)
    if html2 != html:
        stats["bodyNavPatched"] = 1
    html = html2

    # breadcrumb
    html2 = re.sub(
        r'(<a id="articleHubBreadcrumb"\s+href=")\.\./ban-tin\.html(">)(.*?)</a>',
        r'\1../thu-vien.html\2Thư viện</a>',
        html,
        flags=re.IGNORECASE,
    )
    if html2 != html:
        stats["breadcrumbPatched"] = 1
    html = html2

    # article-view script src
    old_view = f'../data/article-views/{old_href}.js'
    new_view = f'../data/article-views/{new_href}.js'
    if old_view in html:
        html = html.replace(old_view, new_view)
        stats["viewScriptPatched"] = 1

    # article-meta json
    m = META_RE.search(html)
    if m:
        try:
            meta = json.loads(m.group(2))
            meta["id"] = new_href
            meta["section"] = "thu-vien"
            meta["sectionKey"] = "thu-vien"
            meta["sectionLabel"] = "Thư viện"
            meta["sectionHref"] = "thu-vien.html"
            # keep taxonomy levels as-is
            new_meta = json.dumps(meta, ensure_ascii=False)
            html = html[: m.start(2)] + new_meta + html[m.end(2) :]
            stats["metaPatched"] = 1
        except json.JSONDecodeError:
            pass

    path.write_text(html, encoding="utf-8")
    return stats


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


def main() -> None:
    importer = load_importer_module()
    articles: List[Dict] = json.loads(DATA_ARTICLES.read_text(encoding="utf-8"))
    targets = [a for a in articles if a.get("section") == "thu-vien" and str(a.get("href", "")).startswith("ban-tin/")]
    targets = sorted(targets, key=lambda x: x["href"])

    applied = []
    skipped = []

    for a in targets:
        old_href = a["href"]
        slug = Path(old_href).name
        new_href = f"thu-vien/{slug}"
        old_html = ROOT / old_href
        new_html = ROOT / new_href
        old_view = ROOT / "data" / "article-views" / f"{old_href}.js"
        new_view = ROOT / "data" / "article-views" / f"{new_href}.js"

        if not old_html.exists():
            skipped.append({"href": old_href, "reason": "missing-old-html"})
            continue
        if new_html.exists():
            skipped.append({"href": old_href, "reason": "target-html-exists"})
            continue

        # move html
        new_html.parent.mkdir(parents=True, exist_ok=True)
        old_html.rename(new_html)

        # patch moved html
        patch_stats = patch_article_html(new_html, old_href=old_href, new_href=new_href)

        # move view file (if exists) and rewrite key/path
        view_moved = False
        if old_view.exists():
            new_view.parent.mkdir(parents=True, exist_ok=True)
            old_view.rename(new_view)
            txt = new_view.read_text(encoding="utf-8", errors="ignore")
            txt = txt.replace(old_href, new_href)
            txt = txt.replace(f"/{old_href}", f"/{new_href}")
            new_view.write_text(txt, encoding="utf-8")
            view_moved = True

        # update article record
        a["href"] = new_href
        a["id"] = new_href
        canonical = a.get("canonical") or ""
        if canonical:
            a["canonical"] = canonical.replace(f"/{old_href}", f"/{new_href}")
        if isinstance(a.get("classificationReasons"), dict):
            a["classificationReasons"]["fixPathMismatchThuVien"] = "moved-ban-tin-path-to-thu-vien"

        applied.append(
            {
                "oldHref": old_href,
                "newHref": new_href,
                "htmlMoved": True,
                "viewMoved": view_moved,
                "patchStats": patch_stats,
            }
        )

    # write updated articles
    DATA_ARTICLES.write_text(json.dumps(articles, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # rebuild generated artifacts
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
        "# Fix path mismatch: Thu vien records under ban-tin/ path",
        "",
        f"- Generated: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Targets: **{len(targets)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Applied",
        "",
        "| # | old href | new href | view moved |",
        "|---:|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(f"| {i} | `{row['oldHref']}` | `{row['newHref']}` | `{row['viewMoved']}` |")
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
