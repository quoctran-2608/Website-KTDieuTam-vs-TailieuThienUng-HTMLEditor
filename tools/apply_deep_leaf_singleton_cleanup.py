#!/usr/bin/env python3
"""Dọn singleton ở cấp sâu: libraryKind + lv1 + lv2 + lv3."""

from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "deep-leaf-singleton-cleanup-apply.json"
OUT_MD = ROOT / "docs" / "deep-leaf-singleton-cleanup-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_deep_leaf_cleanup", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def deep_bucket(article: Dict) -> Tuple[str, str, str, str]:
    return (
        article.get("section") or "",
        article.get("libraryKindKey") or "",
        article.get("topicLv1Key") or "",
        article.get("topicLv2Key") or "",
    )


def deep_leaf(article: Dict) -> Tuple[str, str, str, str, str]:
    b = deep_bucket(article)
    return b + ((article.get("topicLv3Key") or ""),)


def collect_deep_singletons(articles: List[Dict]) -> Dict[Tuple[str, str, str, str, str], List[Dict]]:
    groups: Dict[Tuple[str, str, str, str, str], List[Dict]] = defaultdict(list)
    for a in articles:
        if a.get("section") in {"thu-vien", "ban-tin"}:
            groups[deep_leaf(a)].append(a)
    return {k: v for k, v in groups.items() if len(v) == 1}


def update_meta(path: Path, article: Dict) -> Tuple[bool, str]:
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
    for key in (
        "topicLv3Key",
        "topicLv3Label",
        "cardTopicLabel",
    ):
        meta[key] = article.get(key) or ""
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


def choose_canonical(counter: Counter) -> str:
    # Ưu tiên nhóm có số lượng lớn hơn; nếu bằng nhau thì ưu tiên key không rỗng.
    ranked = sorted(
        counter.items(),
        key=lambda kv: (-kv[1], kv[0] == "", kv[0]),
    )
    return ranked[0][0]


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    before_singletons = collect_deep_singletons(data_articles)

    lv3_labels: Dict[str, str] = {}
    for a in data_articles:
        key = a.get("topicLv3Key") or ""
        if key and key not in lv3_labels:
            lv3_labels[key] = a.get("topicLv3Label") or key

    buckets: Dict[Tuple[str, str, str, str], List[Dict]] = defaultdict(list)
    for a in data_articles:
        if a.get("section") in {"thu-vien", "ban-tin"}:
            buckets[deep_bucket(a)].append(a)

    applied = []
    skipped = []
    bucket_decisions = []

    for bucket, rows in sorted(buckets.items(), key=lambda kv: kv[0]):
        lv3_counter = Counter((r.get("topicLv3Key") or "") for r in rows)
        singleton_keys = {k for k, c in lv3_counter.items() if c == 1}
        if not singleton_keys:
            continue
        if len(lv3_counter) == 1:
            continue

        canonical = choose_canonical(lv3_counter)
        moved_rows = 0
        for article in rows:
            old_key = article.get("topicLv3Key") or ""
            if old_key not in singleton_keys:
                continue
            if old_key == canonical:
                continue

            article["topicLv3Key"] = canonical
            article["topicLv3Label"] = lv3_labels.get(canonical, article.get("topicLv3Label") or "")
            article["cardTopicLabel"] = article.get("topicLv2Label") or article.get("topicLv1Label") or ""
            if isinstance(article.get("classificationReasons"), dict):
                article["classificationReasons"]["deepLeafSingletonCleanup"] = f"{old_key}->{canonical}"

            ok, reason = update_meta(ROOT / article["href"], article)
            if not ok:
                skipped.append({"href": article["href"], "reason": reason})
                continue

            moved_rows += 1
            applied.append(
                {
                    "href": article["href"],
                    "title": article.get("title") or "",
                    "bucket": bucket,
                    "oldLv3": old_key,
                    "newLv3": canonical,
                }
            )

        if moved_rows:
            bucket_decisions.append(
                {
                    "bucket": bucket,
                    "canonicalLv3": canonical,
                    "beforeCounts": dict(lv3_counter),
                    "movedRows": moved_rows,
                }
            )

    importer.write_json(data_articles_path, data_articles)
    counts = rebuild(importer, data_articles)
    after_singletons = collect_deep_singletons(data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "before": {"deepSingletonCount": len(before_singletons)},
        "after": {"deepSingletonCount": len(after_singletons)},
        "applied": len(applied),
        "skipped": skipped,
        "bucketDecisions": bucket_decisions,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "remainingDeepSingletons": [
            {"leaf": list(k), "href": rows[0].get("href"), "title": rows[0].get("title")}
            for k, rows in sorted(after_singletons.items(), key=lambda kv: kv[0])
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Dọn deep leaf singleton - Apply",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Deep singleton trước: **{len(before_singletons)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Deep singleton sau: **{len(after_singletons)}**",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Quyết định theo bucket",
        "",
        "| # | bucket | canonical lv3 | moved | before counts |",
        "|---:|---|---|---:|---|",
    ]
    for i, d in enumerate(bucket_decisions, 1):
        lines.append(
            f"| {i} | `{d['bucket']}` | `{d['canonicalLv3']}` | {d['movedRows']} | `{d['beforeCounts']}` |"
        )
    lines += ["", "## Singleton còn lại", ""]
    if after_singletons:
        for k, rows in sorted(after_singletons.items(), key=lambda kv: kv[0]):
            lines.append(f"- `{k}`: `{rows[0].get('href')}` — {rows[0].get('title')}")
    else:
        lines.append("- Không còn deep leaf singleton.")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "beforeDeepSingletons": len(before_singletons),
                "applied": len(applied),
                "skipped": len(skipped),
                "afterDeepSingletons": len(after_singletons),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
