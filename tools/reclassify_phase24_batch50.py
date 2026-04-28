#!/usr/bin/env python3
"""Phase 24: batch 50 - normalize biểu mẫu kế toán + chuyển software guides + thuế forms."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase24-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase24-batch50.md"

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

# A) 29 bài: bieu-mau/ke-toan đang ở lv2 khác -> gom về mau-bieu-ke-toan
GROUP_A: Dict[str, str] = {
    "thu-vien/cach-lap-bang-can-doi-tai-khoan-theo-thong-tu-133.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/cach-lap-bao-cao-ket-qua-hoat-dong-kinh-doanh-theo-thong-tu-133.html": "mau-bao-cao-tai-chinh",
    "thu-vien/cach-lap-bao-cao-luu-chuyen-tien-te-theo-thong-tu-133.html": "mau-bao-cao-tai-chinh",
    "thu-vien/cach-lap-bao-cao-tinh-hinh-tai-chinh-theo-thong-tu-133.html": "mau-bao-cao-tai-chinh",
    "thu-vien/cach-lap-thuyet-minh-bao-cao-tai-chinh-theo-thong-tu-133.html": "mau-bao-cao-tai-chinh",
    "thu-vien/dang-ky-phuong-phap-trich-khau-hao-tscd.html": "mau-tscd-khau-hao",
    "thu-vien/giay-xac-nhan-chua-duoc-thanh-toan-mau-01-tcn.html": "mau-hanh-chinh-quan-tri-khac",
    "thu-vien/mau-01-bk-stk-theo-thong-tu-so-18-2026-tt-btc.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/mau-bang-ke-mua-lai-co-phieu-theo-qd-48-va-15.html": "mau-tai-chinh-von-ngoai-te",
    "thu-vien/mau-bang-ke-vang-bac-da-quy-theo-qd-48-va-15.html": "mau-hanh-chinh-quan-tri-khac",
    "thu-vien/mau-bang-kiem-ke-quy-theo-qd-48-va-15.html": "mau-hanh-chinh-quan-tri-khac",
    "thu-vien/mau-bang-thanh-toan-hang-dai-ly-ky-gui-theo-qd-48-va-15.html": "mau-chung-tu-tien-thanh-toan",
    "thu-vien/mau-bang-tinh-va-phan-bo-khau-hao-tscd-theo-qd-48-va-15.html": "mau-tscd-khau-hao",
    "thu-vien/mau-bang-tong-hop-doanh-thu.html": "mau-chung-tu-mua-ban-hop-dong",
    "thu-vien/mau-bao-cao-tinh-hinh-tai-chinh-theo-thong-tu-133.html": "mau-bao-cao-tai-chinh",
    "thu-vien/mau-bien-ban-ban-giao-tscd-sua-chua-theo-qd-48-va-15.html": "mau-tscd-khau-hao",
    "thu-vien/mau-bien-ban-danh-gia-lai-tai-san-co-dinh-theo-qd-48-va-15.html": "mau-tscd-khau-hao",
    "thu-vien/mau-bien-ban-thanh-ly-tscd-theo-qd-48-va-15.html": "mau-tscd-khau-hao",
    "thu-vien/mau-giay-di-duong-theo-qd-48-va-15.html": "mau-hanh-chinh-quan-tri-khac",
    "thu-vien/mau-phieu-xuat-kho-hang-gui-ban-dai-ly-mau-so-04hgdl.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/mau-phieu-xuat-kho-kiem-van-chuyen-noi-bo-mau-so-03xknb.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/so-chi-tiet-co-phieu-mua-lai-cua-chinh-minh-theo-thong-tu-99.html": "mau-tai-chinh-von-ngoai-te",
    "thu-vien/so-ke-toan-chi-tiet-theo-doi-cac-khoan-dau-tu-vao-cong-ty-lien-doanh.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/so-ke-toan-chi-tiet-theo-doi-cac-khoan-dau-tu-vao-cong-ty-lien-ket.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/so-theo-doi-chi-tiet-von-dau-tu-cua-chu-so-huu-theo-thong-tu-99.html": "mau-tai-chinh-von-ngoai-te",
    "thu-vien/so-theo-doi-phan-bo-cac-khoan-chenh-lech-phat-sinh-khi-mua-khoan-dau-tu-vao-cong-ty-lien-ket.html": "mau-kho-vat-tu-ccdc",
    "thu-vien/so-theo-doi-thanh-toan-bang-ngoai-te-theo-thong-tu-99.html": "mau-chung-tu-tien-thanh-toan",
    "thu-vien/so-theo-doi-tscd-va-ccdc-tai-noi-su-dung-theo-tt-133-va-200.html": "mau-tscd-khau-hao",
    "thu-vien/so-theo-doi-tscd-va-cong-cu-dung-cu-tai-noi-su-dung-theo-tt99.html": "mau-tscd-khau-hao",
}

# B) 8 bài hướng dẫn có tính tool/software rõ
GROUP_B: Dict[str, Tuple[str, str]] = {
    "thu-vien/cac-phim-tat-trong-excel-ke-toan.html": ("excel-va-cong-cu-khac", "excel-cong-cu-khac"),
    "thu-vien/cach-lap-bang-ke-hoa-don-hang-hoa-dich-vu-ban-ra.html": ("excel-va-cong-cu-khac", "excel-thue-hoa-don"),
    "thu-vien/cach-lap-to-khai-quyet-toan-thue-tncn-05-kk-tncn.html": ("htkk-etax-thue-dien-tu", "htkk-guide-tncn"),
    "thu-vien/cach-nop-thue-tndn-tam-tinh-qua-mang-tren-thue-dien-tu.html": ("htkk-etax-thue-dien-tu", "htkk-guide-tndn"),
    "thu-vien/huong-dan-cach-dang-ky-nop-thue-dien-tu.html": ("htkk-etax-thue-dien-tu", "htkk-guide-import-bang-ke"),
    "thu-vien/phan-mem-chiu-thue-suat-bao-nhieu-0-hay-khong-chiu-thue.html": ("excel-va-cong-cu-khac", "excel-cong-cu-khac"),
    "thu-vien/thu-tuc-cat-giam-nguoi-phu-thuoc.html": ("htkk-etax-thue-dien-tu", "htkk-guide-tncn"),
    "thu-vien/thue-suat-thue-gia-tri-gia-tang-doi-voi-phan-mem-nhu-the-nao.html": ("excel-va-cong-cu-khac", "excel-cong-cu-khac"),
}

# C) 13 bài biểu mẫu thuế đang lệch lv1
GROUP_C: Dict[str, str] = {
    "thu-vien/mau-bang-ke-cac-hop-dong-nha-thau-nuoc-ngoai.html": "mau-thue-nha-thau",
    "thu-vien/to-khai-dang-ky-su-dung-hoa-don-dien-tu-mau-01-nghi-dinh-119.html": "mau-thue-gtgt-hoa-don",
    "thu-vien/to-khai-thue-thu-nhap-doanh-nghiep-mau-08-tndn.html": "mau-thue-tndn",
    "thu-vien/thong-bao-ve-viec-nop-tien-vao-nsnn-mau-02-tcn.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/to-khai-quyet-toan-thue-tai-nguyen-mau-so-02-tain.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/to-khai-thue-bao-ve-moi-truong-mau-so-01-tbvmt.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/to-khai-thue-tai-nguyen-mau-so-01-tain.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/van-ban-de-nghi-thay-doi-ky-tinh-thue-tu-thang-sang-quy-mau-so-01-dk-tdktt.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/van-ban-de-nghi-xu-ly-so-tien-thue-nop-thua.html": "mau-bang-ke-phu-luc-ho-so",
    "thu-vien/to-khai-dang-ky-thue-dung-cho-co-quan-dang-ky-ca-nhan-co-uy-quyen.html": "mau-thue-tncn",
    "thu-vien/to-khai-khau-tru-thue-thu-nhap-ca-nhan-mau-so-02-kk-tncn.html": "mau-thue-tncn",
    "thu-vien/to-khai-quyet-toan-thue-thu-nhap-ca-nhan-mau-05-qtt-tncn.html": "mau-thue-tncn",
    "thu-vien/to-khai-quyet-toan-thue-thu-nhap-ca-nhan-mau-so-05-kk-tncn.html": "mau-thue-tncn",
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase24_batch50", module_path)
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


def build_decisions() -> Dict[str, Dict[str, str]]:
    decisions: Dict[str, Dict[str, str]] = {}

    for href, lv3 in GROUP_A.items():
        decisions[href] = {
            "kind": "bieu-mau",
            "lv1": "ke-toan",
            "lv2": "mau-bieu-ke-toan",
            "lv3": lv3,
            "reason": "phase24-ke-toan-form-normalize",
        }

    for href, (lv2, lv3) in GROUP_B.items():
        decisions[href] = {
            "kind": "cong-cu",
            "lv1": "phan-mem-cong-cu",
            "lv2": lv2,
            "lv3": lv3,
            "reason": "phase24-huong-dan-software-to-cong-cu",
        }

    for href, lv3 in GROUP_C.items():
        decisions[href] = {
            "kind": "bieu-mau",
            "lv1": "thue",
            "lv2": "mau-bieu-thue",
            "lv3": lv3,
            "reason": "phase24-tax-form-rebalance",
        }

    if len(decisions) != 50:
        raise RuntimeError(f"Phase24 cần đúng 50 bài, hiện build được {len(decisions)}")
    return decisions


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)
    decisions = build_decisions()

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
            article["classificationReasons"]["phase24Batch50"] = decision["reason"]

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
        "phase": "phase24-batch50",
        "plannedCount": len(decisions),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 24 - Batch 50",
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
