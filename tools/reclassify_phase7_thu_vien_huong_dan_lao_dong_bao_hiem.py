#!/usr/bin/env python3
"""Phase 7: rà và sửa nhóm Hướng dẫn > Lao động - Bảo hiểm."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase7-huong-dan-lao-dong-bao-hiem.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase7-huong-dan-lao-dong-bao-hiem.md"

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


DECISIONS: Dict[str, Dict[str, str]] = {
    # Nhóm TNCN lạc ở Lao động/BHXH
    "thu-vien/cac-khoan-giam-tru-thue-thu-nhap-ca-nhan.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tncn",
        "lv3": "giam-tru-mien-thue",
        "reason": "tncn-giam-tru-gia-canh",
    },
    "thu-vien/cac-khoan-thu-nhap-chiu-thue-thu-nhap-ca-nhan-nhap-moi.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tncn",
        "lv3": "doi-tuong-thu-nhap",
        "reason": "tncn-thu-nhap-chiu-thue",
    },
    "thu-vien/cach-tinh-tinh-thue-thu-nhap-ca-nhan-moi-nhat.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tncn",
        "lv3": "tinh-thue-bieu-thue",
        "reason": "tncn-tinh-thue",
    },
    "thu-vien/cach-xac-dinh-thu-nhap-chiu-thue-khi-tinh-thue-tncn.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tncn",
        "lv3": "doi-tuong-thu-nhap",
        "reason": "tncn-xac-dinh-thu-nhap-chiu-thue",
    },
    # Nhóm GTGT lạc ở Lao động/BHXH
    "thu-vien/cac-loai-hoa-don-theo-thong-tu-64-2013-tt-btc.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "lap-xu-ly-hoa-don",
        "reason": "hoa-don-gtgt",
    },
    "thu-vien/hoa-hong-dai-ly-bao-hiem-co-chiu-thue-gtgt.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "thue-suat-doi-tuong",
        "reason": "gtgt-hoa-hong-bao-hiem",
    },
    # Nhóm tài khoản kế toán lạc ở Lao động/BHXH
    "thu-vien/cach-hach-toan-cac-khoan-phai-tra-phai-nop-khac-tai-khoan-338.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "cong-no-thanh-toan",
        "reason": "tk338-cong-no-phai-tra",
    },
    "thu-vien/cach-hach-toan-khoan-phai-tra-cho-nguoi-lao-dong-tai-khoan-334.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "cong-no-thanh-toan",
        "reason": "tk334-cong-no-phai-tra",
    },
    "thu-vien/cach-hach-toan-phai-tra-nguoi-lao-dong-tk-334.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "cong-no-thanh-toan",
        "reason": "tk334-cong-no-phai-tra",
    },
    "thu-vien/cach-hach-toan-tk-334-theo-thong-tu-99.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "cong-no-thanh-toan",
        "reason": "tk334-cong-no-phai-tra",
    },
    "thu-vien/tai-khoan-334-phai-tra-nguoi-lao-dong-theo-tt-133.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "cong-no-thanh-toan",
        "reason": "tk334-cong-no-phai-tra",
    },
    # Nhóm văn bản pháp lý lao động/BHXH lạc ở Hướng dẫn
    "thu-vien/nghi-dinh-28-2020-nd-cp-quy-dinh-xu-phat-ve-lao-dong-bhxh.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "nghi-dinh",
        "lv3": "nd-bhxh",
        "reason": "nghi-dinh-xu-phat-lao-dong-bhxh",
    },
    "thu-vien/nghi-dinh-145-2020-nd-cp-huong-dan-bo-luat-lao-dong.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-huong-dan-bo-luat-lao-dong",
    },
    "thu-vien/nghi-dinh-121-2018-nd-cp-huong-dan-thang-bang-luong.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-thang-bang-luong",
    },
    "thu-vien/nghi-dinh-153-2016-nd-cp-quy-dinh-luong-toi-thieu-vung.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-luong-toi-thieu-vung",
    },
    "thu-vien/nghi-dinh-157-muc-luong-toi-thieu-vung-nam-2019.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-luong-toi-thieu-vung",
    },
    "thu-vien/nghi-dinh-38-2022-nd-cp-muc-luong-toi-thieu-vung-nam-2022.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-luong-toi-thieu-vung",
    },
    "thu-vien/nghi-dinh-90-muc-luong-toi-thieu-vung-nam-2020.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "lao-dong-tien-luong",
        "lv3": "tien-luong-thoi-gio-lam-viec",
        "reason": "nghi-dinh-luong-toi-thieu-vung",
    },
    "thu-vien/quyet-dinh-1040-qd-bhxh-bao-cao-su-dung-lao-dong-va-danh-sach-tham-gia-bhxh.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "nghi-quyet-quyet-dinh",
        "lv3": "qd-bhxh-bhyt-bhtn",
        "reason": "quyet-dinh-bhxh",
    },
    "thu-vien/quyet-dinh-595-qd-bhxh-quy-trinh-thu-bhxh-bhyt-bhtn.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "nghi-quyet-quyet-dinh",
        "lv3": "qd-bhxh-bhyt-bhtn",
        "reason": "quyet-dinh-bhxh",
    },
    "thu-vien/quyet-dinh-919-qd-bhxh-cua-bao-hiem-xa-hoi-viet-nam.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "nghi-quyet-quyet-dinh",
        "lv3": "qd-bhxh-bhyt-bhtn",
        "reason": "quyet-dinh-bhxh",
    },
    "thu-vien/quyet-dinh-959-qd-bhxh-quan-ly-thu-bhxh-bhyt-bhtn.html": {
        "kind": "van-ban",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "nghi-quyet-quyet-dinh",
        "lv3": "qd-bhxh-bhyt-bhtn",
        "reason": "quyet-dinh-bhxh",
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase7_huongdan_ldbh", module_path)
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


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)

    applied = []
    skipped = []
    for href, decision in DECISIONS.items():
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-article"})
            continue

        kind = decision["kind"]
        lv1 = decision["lv1"]
        lv2 = decision["lv2"]
        lv3 = decision["lv3"]
        if kind not in LIBRARY_KIND_LABELS or lv1 not in lv1_labels or lv2 not in lv2_labels or lv3 not in lv3_labels:
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
            article["classificationReasons"]["phase7ThuVienLaoDongBaoHiem"] = decision["reason"]

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

    phase_bucket_after = [
        a for a in data_articles
        if a.get("section") == "thu-vien"
        and a.get("libraryKindKey") == "huong-dan"
        and a.get("topicLv1Key") == "lao-dong-bao-hiem"
    ]

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "phase": "phase7-huong-dan-lao-dong-bao-hiem",
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {
            "remainingInBucket": len(phase_bucket_after),
            "countsAfterRebuild": counts,
        },
        "deferredManualReview": [
            {
                "href": "thu-vien/nghi-dinh-122-2020-nd-cp-lien-thong-thu-tuc-thanh-lap-doanh-nghiep.html",
                "reason": "cross-domain-doanh-nghiep-lao-dong-thue-hoa-don",
            }
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 7 - Rà soát Hướng dẫn / Lao động - Bảo hiểm",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Còn lại trong bucket sau phase 7: **{len(phase_bucket_after)}**",
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
    lines += [
        "",
        "## Deferred manual review",
        "",
        "- `thu-vien/nghi-dinh-122-2020-nd-cp-lien-thong-thu-tuc-thanh-lap-doanh-nghiep.html` (nội dung liên thông nhiều domain: doanh nghiệp + lao động + BHXH + hóa đơn)",
    ]
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "applied": len(applied),
                "skipped": len(skipped),
                "remainingInBucket": len(phase_bucket_after),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
