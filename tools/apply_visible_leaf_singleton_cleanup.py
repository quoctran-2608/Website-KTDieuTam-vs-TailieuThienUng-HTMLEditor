#!/usr/bin/env python3
"""Dọn singleton theo leaf hiển thị trên UI: libraryKind + topicLv1 + topicLv2."""

from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "visible-leaf-singleton-cleanup-apply.json"
OUT_MD = ROOT / "docs" / "visible-leaf-singleton-cleanup-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


# href -> (libraryKindKey, topicLv1Key, topicLv2Key, topicLv3Key, reason)
MOVES: Dict[str, Tuple[str, str, str, str, str]] = {
    "thu-vien/so-chi-tiet-thanh-toan-voi-nguoi-mua-nguoi-ban.html": ("bieu-mau", "ke-toan", "mau-bieu-ke-toan", "mau-so-sach-ke-toan", "so-chi-tiet-to-mau-so-sach"),
    "thu-vien/quy-che-dan-chu-o-co-so-tai-noi-lam-viec-theo-nghi-dinh-145.html": ("bieu-mau", "lao-dong-bao-hiem", "mau-bieu-lao-dong-bao-hiem", "mau-noi-quy-thoa-uoc-lao-dong", "quy-che-to-mau-noi-quy"),
    "thu-vien/quyet-dinh-1040-qd-bhxh-mau-bao-cao-su-dung-lao-dong-tham-gia-bhxh.html": ("bieu-mau", "lao-dong-bao-hiem", "mau-bieu-lao-dong-bao-hiem", "mau-bao-cao-dang-ky-lao-dong", "qd1040-to-mau-bao-cao-lao-dong"),
    "thu-vien/tai-phan-mem-ke-toan-fast-accounting-mien-phi-dung-thu.html": ("huong-dan", "phan-mem-cong-cu", "fast", "fast-huong-dan-su-dung", "fast-download-to-guide-fast"),
    "thu-vien/danh-sach-ma-thu-tuc-hanh-chinh-khi-nop-to-khai-thue.html": ("bieu-mau", "thue", "mau-bieu-thue", "mau-bang-ke-phu-luc-ho-so", "ma-thu-tuc-to-tax-appendix"),
    "thu-vien/phan-mem-chiu-thue-suat-bao-nhieu-0-hay-khong-chiu-thue.html": ("huong-dan", "thue", "gtgt-hoa-don", "thue-suat-doi-tuong", "software-tax-rate-to-gtgt-guide"),
    "thu-vien/nghi-dinh-115-2015-nd-cp-quy-dinh-luat-bao-hiem-xa-hoi.html": ("van-ban", "lao-dong-bao-hiem", "nghi-dinh", "nd-bhxh", "nd115-to-van-ban-nghi-dinh"),
    "thu-vien/quyet-dinh-61-qd-tld-ve-dieu-chinh-giam-muc-dong-doan-phi-cong-doan.html": ("van-ban", "lao-dong-bao-hiem", "nghi-quyet-quyet-dinh", "qd-cong-doan-doan-phi", "qd61-to-van-ban-quyet-dinh"),
    "thu-vien/cong-van-845-tct-nang-cap-htkk-bctc-theo-thong-tu-133.html": ("van-ban", "thue", "cong-van", "cong-van-ke-khai-quan-ly-thue", "cv845-to-tax-cong-van"),
    "thu-vien/bai-bo-le-phi-mon-bai-tu-nam-2026.html": ("van-ban", "thue", "nghi-quyet-quyet-dinh", "nq-thue-tndn-giam-thue", "mon-bai-nghi-quyet-to-tax-resolution"),
}


LIBRARY_KIND_LABELS = {
    "huong-dan": "Hướng dẫn",
    "bieu-mau": "Biểu mẫu",
    "cong-cu": "Công cụ",
    "van-ban": "Văn bản",
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_visible_leaf_cleanup", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def visible_leaf(article: Dict) -> Tuple[str, str, str, str]:
    if article.get("section") == "thu-vien":
        return (
            article.get("libraryKindKey") or "",
            article.get("topicLv1Key") or "",
            article.get("topicLv2Key") or "",
            article.get("topicLv3Key") or "",
        )
    return (
        "",
        article.get("topicLv1Key") or "",
        article.get("topicLv2Key") or "",
        article.get("topicLv3Key") or "",
    )


def visible_leaf_for_count(article: Dict) -> Tuple[str, str, str]:
    if article.get("section") == "thu-vien":
        return (
            article.get("libraryKindKey") or "",
            article.get("topicLv1Key") or "",
            article.get("topicLv2Key") or "",
        )
    return (
        "",
        article.get("topicLv1Key") or "",
        article.get("topicLv2Key") or "",
    )


def build_label_maps(articles: List[Dict]) -> Tuple[Dict[str, str], Dict[str, str], Dict[str, str]]:
    lv1: Dict[str, str] = {}
    lv2: Dict[str, str] = {}
    lv3: Dict[str, str] = {}
    for a in articles:
        if a.get("topicLv1Key"):
            lv1.setdefault(a["topicLv1Key"], a.get("topicLv1Label") or a["topicLv1Key"])
        if a.get("topicLv2Key"):
            lv2.setdefault(a["topicLv2Key"], a.get("topicLv2Label") or a["topicLv2Key"])
        if a.get("topicLv3Key"):
            lv3.setdefault(a["topicLv3Key"], a.get("topicLv3Label") or a["topicLv3Key"])
    return lv1, lv2, lv3


def singleton_stats(articles: List[Dict]) -> Tuple[Dict[Tuple[str, str, str], List[Dict]], Counter]:
    groups: Dict[Tuple[str, str, str], List[Dict]] = defaultdict(list)
    for a in articles:
        if a.get("section") in {"thu-vien", "ban-tin"}:
            groups[visible_leaf_for_count(a)].append(a)
    singles = {k: v for k, v in groups.items() if len(v) == 1}
    return singles, Counter(k[0] for k in singles)


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
        "libraryKindKey",
        "libraryKindLabel",
        "cardBadgeLabel",
        "topicLv1Key",
        "topicLv1Label",
        "topicLv2Key",
        "topicLv2Label",
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


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)
    before_singletons, before_by_kind = singleton_stats(data_articles)

    applied = []
    skipped = []
    for href, (kind, lv1, lv2, lv3, reason) in MOVES.items():
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-article"})
            continue
        old_leaf = visible_leaf(article)
        if kind not in LIBRARY_KIND_LABELS:
            skipped.append({"href": href, "reason": "invalid-kind", "kind": kind})
            continue
        if lv1 not in lv1_labels or lv2 not in lv2_labels or (lv3 and lv3 not in lv3_labels):
            skipped.append({"href": href, "reason": "missing-target-label", "target": [kind, lv1, lv2, lv3]})
            continue

        article["libraryKindKey"] = kind
        article["libraryKindLabel"] = LIBRARY_KIND_LABELS[kind]
        article["cardBadgeLabel"] = LIBRARY_KIND_LABELS[kind]
        article["topicLv1Key"] = lv1
        article["topicLv1Label"] = lv1_labels[lv1]
        article["topicLv2Key"] = lv2
        article["topicLv2Label"] = lv2_labels[lv2]
        article["topicLv3Key"] = lv3
        article["topicLv3Label"] = lv3_labels.get(lv3, "")
        article["cardTopicLabel"] = article["topicLv2Label"]

        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["visibleLeafSingletonCleanup"] = reason

        ok, meta_reason = update_meta(ROOT / href, article)
        if not ok:
            skipped.append({"href": href, "reason": meta_reason})
            continue

        applied.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "oldLeaf": old_leaf,
                "newLeaf": visible_leaf(article),
                "reason": reason,
            }
        )

    importer.write_json(data_articles_path, data_articles)
    counts = rebuild(importer, data_articles)
    after_singletons, after_by_kind = singleton_stats(data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "before": {"singletonCount": len(before_singletons), "byKind": dict(before_by_kind)},
        "after": {"singletonCount": len(after_singletons), "byKind": dict(after_by_kind)},
        "movesPlanned": len(MOVES),
        "applied": len(applied),
        "skipped": skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "remainingSingletons": [
            {"leaf": list(leaf), "href": rows[0].get("href"), "title": rows[0].get("title")}
            for leaf, rows in sorted(after_singletons.items(), key=lambda kv: kv[0])
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Dọn visible leaf singleton - Apply",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Visible singleton trước: **{len(before_singletons)}** ({dict(before_by_kind)})",
        f"- Moves planned: **{len(MOVES)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Visible singleton sau: **{len(after_singletons)}** ({dict(after_by_kind)})",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách move",
        "",
        "| # | href | old visible leaf | new visible leaf | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['oldLeaf']}` | `{row['newLeaf']}` | `{row['reason']}` |"
        )
    lines += ["", "## Singleton còn lại", ""]
    if after_singletons:
        for leaf, rows in sorted(after_singletons.items(), key=lambda kv: kv[0]):
            lines.append(f"- `{leaf}`: `{rows[0].get('href')}` — {rows[0].get('title')}")
    else:
        lines.append("- Không còn visible leaf nào chỉ có 1 bài.")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(json.dumps({
        "beforeSingletons": len(before_singletons),
        "applied": len(applied),
        "skipped": len(skipped),
        "afterSingletons": len(after_singletons),
        "report": str(OUT_MD.relative_to(ROOT)),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
