#!/usr/bin/env python3
"""Phase 30: batch 50 - chuẩn hóa phụ lục/bảng kê thuế + tinh chỉnh thông tư và mẫu hợp đồng."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase30-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase30-batch50.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)

LIBRARY_KIND_LABELS = {
    "huong-dan": "Hướng dẫn",
    "bieu-mau": "Biểu mẫu",
    "cong-cu": "Công cụ",
    "van-ban": "Văn bản",
}

GROUP_C_FIXED = [
    "thu-vien/bien-ban-thanh-ly-nghiem-thu-hop-dong-giao-khoan-mau-09-ldtl.html",
    "thu-vien/mau-bien-ban-thanh-ly-hop-dong-giao-khoan-theo-thong-tu-99.html",
    "thu-vien/hop-dong-giao-khoan-mau-so-08-ldtl.html",
    "thu-vien/mau-hop-dong-giao-khoan-theo-thong-tu-99.html",
]

GROUP_D_FIXED = "thu-vien/hop-dong-thu-viec-co-phai-dong-bhxh-khong.html"


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase30_batch50", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


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
        "section",
        "sectionLabel",
        "sectionHref",
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
    meta["sectionKey"] = article.get("section") or ""
    meta["topicLabel"] = article.get("topicLv2Label") or article.get("topicLv1Label") or ""

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


def exp_tt(title: str) -> str:
    s = title.lower()
    if any(k in s for k in ["tncn", "thu nhập cá nhân"]):
        return "thong-tu-tncn"
    if any(k in s for k in ["tndn", "thu nhập doanh nghiệp"]):
        return "thong-tu-tndn"
    if any(k in s for k in ["gtgt", "hóa đơn", "hoá đơn"]):
        return "thong-tu-gtgt-hoa-don"
    if any(k in s for k in ["hộ kinh doanh", "cá nhân kinh doanh", "cnkd"]):
        return "thong-tu-ho-ca-nhan-kinh-doanh"
    if any(k in s for k in ["đăng ký thuế", "mst", "mã số thuế"]):
        return "thong-tu-mst-dang-ky-thue"
    if any(k in s for k in ["kê khai", "quản lý thuế"]):
        return "thong-tu-ke-khai-quan-ly-thue"
    return "thong-tu-chinh-sach-chung"


def build_decisions(data_articles: List[Dict]) -> Dict[str, Dict[str, str]]:
    decisions: Dict[str, Dict[str, str]] = {}

    # A) 37 bài biểu mẫu thuế chứa bảng kê/phụ lục/khbs -> mau-bang-ke-phu-luc-ho-so
    rows = [
        a for a in data_articles
        if a.get("section") == "thu-vien"
        and a.get("libraryKindKey") == "bieu-mau"
        and a.get("topicLv1Key") == "thue"
        and a.get("topicLv2Key") == "mau-bieu-thue"
    ]
    pattern = re.compile(r"bảng kê|phụ lục|khbs", re.IGNORECASE)
    cand_a = sorted(
        [a for a in rows if pattern.search(a["title"]) and (a.get("topicLv3Key") or "") != "mau-bang-ke-phu-luc-ho-so"],
        key=lambda x: x["href"],
    )
    for a in cand_a:
        decisions[a["href"]] = {
            "kind": "bieu-mau",
            "lv1": "thue",
            "lv2": "mau-bieu-thue",
            "lv3": "mau-bang-ke-phu-luc-ho-so",
            "reason": "phase30-thue-bangke-phuluc-normalize",
        }

    # B) 8 bài thông tư thuế còn lệch lv3
    tt_rows = [
        a for a in data_articles
        if a.get("section") == "thu-vien"
        and a.get("libraryKindKey") == "van-ban"
        and a.get("topicLv1Key") == "thue"
        and a.get("topicLv2Key") == "thong-tu"
    ]
    mis_tt = sorted(
        [a for a in tt_rows if exp_tt(a["title"]) != (a.get("topicLv3Key") or "")],
        key=lambda x: x["href"],
    )
    for a in mis_tt:
        decisions[a["href"]] = {
            "kind": "van-ban",
            "lv1": "thue",
            "lv2": "thong-tu",
            "lv3": exp_tt(a["title"]),
            "reason": "phase30-thong-tu-lv3-normalize",
        }

    # C) 4 biểu mẫu kế toán hợp đồng giao khoán đang sai lv3
    for href in GROUP_C_FIXED:
        decisions[href] = {
            "kind": "bieu-mau",
            "lv1": "ke-toan",
            "lv2": "mau-bieu-ke-toan",
            "lv3": "mau-chung-tu-mua-ban-hop-dong",
            "reason": "phase30-ke-toan-contract-form",
        }

    # D) 1 bài lao động top-up
    decisions[GROUP_D_FIXED] = {
        "kind": "bieu-mau",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "mau-bieu-lao-dong-bao-hiem",
        "lv3": "mau-hop-dong-lao-dong",
        "reason": "phase30-laodong-contract-topup",
    }

    if len(decisions) != 50:
        raise RuntimeError(f"Phase30 cần đúng 50 bài, hiện build được {len(decisions)}")
    return decisions


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)
    decisions = build_decisions(data_articles)

    applied = []
    skipped = []
    for href, decision in decisions.items():
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-article"})
            continue

        kind = decision["kind"]
        lv1 = decision["lv1"]
        lv2 = decision["lv2"]
        lv3 = decision["lv3"]
        if (
            kind not in LIBRARY_KIND_LABELS
            or lv1 not in lv1_labels
            or lv2 not in lv2_labels
            or lv3 not in lv3_labels
        ):
            skipped.append({"href": href, "reason": "missing-target-label", "decision": decision})
            continue

        before = {
            "kind": article.get("libraryKindKey") or "",
            "lv1": article.get("topicLv1Key") or "",
            "lv2": article.get("topicLv2Key") or "",
            "lv3": article.get("topicLv3Key") or "",
        }

        article["section"] = "thu-vien"
        article["sectionLabel"] = "Thư viện"
        article["sectionHref"] = "thu-vien.html"
        article["libraryKindKey"] = kind
        article["libraryKindLabel"] = LIBRARY_KIND_LABELS[kind]
        article["cardBadgeLabel"] = LIBRARY_KIND_LABELS[kind]
        article["topicLv1Key"] = lv1
        article["topicLv1Label"] = lv1_labels[lv1]
        article["topicLv2Key"] = lv2
        article["topicLv2Label"] = lv2_labels[lv2]
        article["topicLv3Key"] = lv3
        article["topicLv3Label"] = lv3_labels[lv3]
        article["cardTopicLabel"] = article["topicLv2Label"]
        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["phase30Batch50"] = decision["reason"]

        ok, reason = update_meta(ROOT / href, article)
        if not ok:
            skipped.append({"href": href, "reason": reason})
            continue

        applied.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "reason": decision["reason"],
                "before": before,
                "after": {
                    "kind": article.get("libraryKindKey") or "",
                    "lv1": article.get("topicLv1Key") or "",
                    "lv2": article.get("topicLv2Key") or "",
                    "lv3": article.get("topicLv3Key") or "",
                },
            }
        )

    importer.write_json(data_articles_path, data_articles)
    counts = rebuild(importer, data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "phase": "phase30-batch50",
        "plannedCount": 50,
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 30 - Batch 50",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        "- Planned: **50**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Các bài đã chỉnh",
        "",
        "| # | href | before | after | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        b = row["before"]
        a = row["after"]
        lines.append(
            f"| {i} | `{row['href']}` | `{b['kind']} / {b['lv1']} / {b['lv2']} / {b['lv3']}` | `{a['kind']} / {a['lv1']} / {a['lv2']} / {a['lv3']}` | `{row['reason']}` |"
        )
    if skipped:
        lines += ["", "## Skipped", ""]
        for row in skipped:
            lines.append(f"- `{row['href']}`: {row['reason']}")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "planned": 50,
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
