#!/usr/bin/env python3
"""Phase 16: batch 50 - cân bằng lại cụm Văn bản/Kế toán và nghị định lạc bucket."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase16-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase16-batch50.md"

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

# Loại trừ các bài pháp lý thuần văn bản đang để đúng dạng "Văn bản"
EXCLUDE_VAN_BAN_KE_TOAN = {
    "thu-vien/cong-van-12568-btc-cdkt-giai-thich-noi-dung-thong-tu-200.html",
    "thu-vien/nghi-dinh-174-2016-nd-cp-quy-dinh-ve-luat-ke-toan.html",
    "thu-vien/nghi-dinh-41-2018-nd-cp-quy-dinh-muc-phat-vu-pham-ke-toan.html",
    "thu-vien/luat-ke-toan-luat-so-88-2015-qh13.html",
    "thu-vien/luat-so-88-2015-qh13-luat-ke-toan-moi-nhat.html",
    "thu-vien/thong-tu-08-2013-tt-btc-thuc-hien-ke-toan-nha-nuoc.html",
    "thu-vien/thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.html",
    "thu-vien/thong-tu-200-2014-tt-btc-che-do-ke-toan-doanh-nghiep.html",
    "thu-vien/thong-tu-202-cach-lap-va-trinh-bay-lap-bctc-hop-nhat.html",
    "thu-vien/thong-tu-45-2013-tt-btc-quan-ly-su-dung-va-trich-hao-tai-san-co-dinh.html",
    "thu-vien/thong-tu-75-2015-tt-btc-sua-doi-bo-sung-dieu-128-tt-200.html",
    "thu-vien/thong-tu-99-2025-tt-btc-huong-dan-che-do-ke-toan-dn.html",
}

# Override cho các bài cần đổi lv1/lv2/lv3 khi chuyển về Hướng dẫn
OVERRIDES: Dict[str, Dict[str, str]] = {
    "thu-vien/cv-2270-tct-cs-tien-thue-nha-cua-ca-nhan-la-chi-phi-hop-ly.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "chi-phi-duoc-tru-khong-duoc-tru",
        "reason": "phase16-vb-ke-toan-to-thue-tndn",
    },
    "thu-vien/ho-tro-chi-phi-mai-tang.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "chi-phi-duoc-tru-khong-duoc-tru",
        "reason": "phase16-vb-ke-toan-to-thue-tndn",
    },
    "thu-vien/nguyen-tac-lap-va-trinh-bay-bao-cao-tai-chinh-theo-thong-tu-99.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "bao-cao-tai-chinh",
        "lv3": "chuan-muc-trinh-bay-bctc",
        "reason": "phase16-vb-ke-toan-bctc-lv3-normalize",
    },
    "thu-vien/yeu-cau-doi-voi-thong-tin-trinh-bay-tren-bctc-theo-thong-tu-99.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "bao-cao-tai-chinh",
        "lv3": "chuan-muc-trinh-bay-bctc",
        "reason": "phase16-vb-ke-toan-bctc-lv3-normalize",
    },
}

# 4 bài nghị định lạc bucket: đưa về Văn bản/Doanh nghiệp/Nghị định
EXTRA_MOVES: Dict[str, Dict[str, str]] = {
    "thu-vien/nghi-dinh-122-2020-nd-cp-lien-thong-thu-tuc-thanh-lap-doanh-nghiep.html": {
        "kind": "van-ban",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "nghi-dinh",
        "lv3": "nd-dang-ky-doanh-nghiep",
        "reason": "phase16-legal-decree-to-van-ban",
    },
    "thu-vien/nghi-dinh-39-2018-nd-cp-huong-dan-ho-tro-doanh-nghiep-nho-va-vua.html": {
        "kind": "van-ban",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "nghi-dinh",
        "lv3": "nd-dang-ky-doanh-nghiep",
        "reason": "phase16-legal-decree-to-van-ban",
    },
    "thu-vien/nghi-dinh-47-2021-nd-cp-quy-dinh-chi-tiet-luat-doanh-nghiep.html": {
        "kind": "van-ban",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "nghi-dinh",
        "lv3": "nd-dang-ky-doanh-nghiep",
        "reason": "phase16-legal-decree-to-van-ban",
    },
    "thu-vien/nghi-dinh-80-2021-nd-cp-quy-dinh-luat-ho-tro-dn-vua-va-nho.html": {
        "kind": "van-ban",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "nghi-dinh",
        "lv3": "nd-dang-ky-doanh-nghiep",
        "reason": "phase16-legal-decree-to-van-ban",
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase16_batch50", module_path)
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


def build_decisions(data_articles: List[Dict]) -> Dict[str, Dict[str, str]]:
    decisions: Dict[str, Dict[str, str]] = {}

    # 46 bài: từ van-ban/ke-toan sang huong-dan (giữ lv2/lv3, trừ override)
    base_rows = [
        a for a in data_articles
        if a.get("section") == "thu-vien"
        and a.get("libraryKindKey") == "van-ban"
        and a.get("topicLv1Key") == "ke-toan"
        and a["href"] not in EXCLUDE_VAN_BAN_KE_TOAN
    ]
    for a in sorted(base_rows, key=lambda x: x["href"]):
        href = a["href"]
        if href in OVERRIDES:
            decisions[href] = dict(OVERRIDES[href])
            continue
        decisions[href] = {
            "kind": "huong-dan",
            "lv1": "ke-toan",
            "lv2": a.get("topicLv2Key") or "",
            "lv3": a.get("topicLv3Key") or "",
            "reason": "phase16-vb-ke-toan-to-huong-dan",
        }

    # +4 bài nghị định lạc bucket
    for href, decision in EXTRA_MOVES.items():
        decisions[href] = dict(decision)

    return decisions


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)
    decisions = build_decisions(data_articles)

    if len(decisions) != 50:
        raise RuntimeError(f"Phase16 cần đúng 50 bài, hiện build được {len(decisions)}")

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
            article["classificationReasons"]["phase16Batch50"] = decision["reason"]

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
        "phase": "phase16-batch50",
        "plannedCount": len(decisions),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 16 - Batch 50",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Planned: **{len(decisions)}**",
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
                "planned": len(decisions),
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
