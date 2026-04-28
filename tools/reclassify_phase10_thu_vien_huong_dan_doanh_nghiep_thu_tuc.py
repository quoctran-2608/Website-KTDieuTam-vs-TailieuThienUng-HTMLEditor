#!/usr/bin/env python3
"""Phase 10: rà và sửa nhóm Hướng dẫn > Doanh nghiệp - Thủ tục."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase10-huong-dan-doanh-nghiep-thu-tuc.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase10-huong-dan-doanh-nghiep-thu-tuc.md"

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
    # Nhóm kế toán/chứng từ/sổ sách lạc vào doanh nghiệp-thủ tục
    "thu-vien/cach-lap-so-tien-gui-ngan-hang-theo-thong-tu-200-va-133.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chung-tu-so-sach",
        "lv3": "so-sach-tien-kho-chi-tiet",
        "reason": "so-tien-gui-ngan-hang",
    },
    "thu-vien/dinh-khoan-hach-toan-tai-san-co-dinh.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "tscd-ccdc-khau-hao",
        "reason": "tk211-tscd",
    },
    "thu-vien/cach-trich-khau-hao-tai-san-co-dinh-theo-duong-thang.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "tscd-ccdc-khau-hao",
        "reason": "khau-hao-tscd",
    },
    "thu-vien/cach-xac-dinh-nguyen-gia-tai-san-co-dinh-huu-hinh.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "tscd-ccdc-khau-hao",
        "reason": "nguyen-gia-tscd",
    },
    "thu-vien/quy-dinh-ve-to-chuc-bo-may-ke-toan.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chuan-muc-che-do-nguyen-tac",
        "lv3": "nguyen-tac-ke-toan",
        "reason": "to-chuc-bo-may-ke-toan",
    },
    "thu-vien/quy-dinh-ve-don-vi-tien-te-trong-ke-toan.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chuan-muc-che-do-nguyen-tac",
        "lv3": "che-do-ke-toan-va-thong-tu",
        "reason": "don-vi-tien-te-ke-toan",
    },
    "thu-vien/tieu-chuan-va-dieu-kien-de-bo-nhiem-lam-ke-toan-truong.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chuan-muc-che-do-nguyen-tac",
        "lv3": "nguyen-tac-ke-toan",
        "reason": "bo-nhiem-ke-toan-truong",
    },
    # Nhóm thuế TNDN/GTGT/lệ phí môn bài lạc vào doanh nghiệp-thủ tục
    "thu-vien/cac-khoan-chi-phi-duoc-tru-khi-tinh-thue-thu-nhap-doanh-nghiep.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "chi-phi-duoc-tru-khong-duoc-tru",
        "reason": "chi-phi-duoc-tru-tndn",
    },
    "thu-vien/cac-khoan-thu-nhap-duoc-mien-thue-thu-nhap-doanh-nghiep.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "uu-dai-mien-giam-chuyen-lo",
        "reason": "mien-thue-tndn",
    },
    "thu-vien/cach-hach-toan-chi-phi-thue-thu-nhap-doanh-nghiep-tai-khoan-821.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "hach-toan-tndn",
        "reason": "hach-toan-tk821",
    },
    "thu-vien/cach-tinh-thue-thu-nhap-doanh-nghiep-moi-nhat.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "thue-suat-phuong-phap",
        "reason": "tinh-thue-tndn",
    },
    "thu-vien/giai-dap-quyet-toan-thue-thu-nhap-doanh-nghiep.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "ke-khai-tam-nop-quyet-toan",
        "reason": "hoi-dap-quyet-toan-tndn",
    },
    "thu-vien/hoi-dap-quyet-toan-thue-thu-nhap-doanh-nghiep-2020-phan-2.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "ke-khai-tam-nop-quyet-toan",
        "reason": "hoi-dap-quyet-toan-tndn",
    },
    "thu-vien/nguyen-tac-ke-khai-thue-thu-nhap-doanh-nghiep-moi-nhat.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "ke-khai-tam-nop-quyet-toan",
        "reason": "nguyen-tac-ke-khai-tndn",
    },
    "thu-vien/thue-suat-thue-thu-nhap-doanh-nghiep-nam-2014.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "thue-suat-phuong-phap",
        "reason": "thue-suat-tndn",
    },
    "thu-vien/doanh-thu-de-tinh-thu-nhap-chiu-thue-thu-nhap-doanh-nghiep.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "tndn",
        "lv3": "doanh-thu-thu-nhap-tinh-thue",
        "reason": "doanh-thu-tinh-thue-tndn",
    },
    "thu-vien/cac-bac-thue-mon-bai-moi-nhat-nam.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "le-phi-mon-bai",
        "lv3": "mon-bai-muc-thu-doi-tuong",
        "reason": "muc-thu-mon-bai",
    },
    "thu-vien/cac-hanh-vi-bi-nghiem-cam-trong-khau-tru-va-hoan-thue-gtgt.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "khau-tru-hoan-thue",
        "reason": "hanh-vi-nghiem-cam-khau-tru-hoan-thue",
    },
    "thu-vien/quy-dinh-ve-dat-in-va-thong-bao-phat-hanh-hoa-don.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "lap-xu-ly-hoa-don",
        "reason": "phat-hanh-hoa-don",
    },
    "thu-vien/xu-ly-hoa-don-khi-chuyen-dia-diem-kinh-doanh.html": {
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "lap-xu-ly-hoa-don",
        "reason": "xu-ly-hoa-don-khi-thay-doi-dia-diem",
    },
    # Nhóm văn bản nghị định
    "thu-vien/nghi-dinh-01-2021-nd-cp-ve-dang-ky-doanh-nghiep.html": {
        "kind": "van-ban",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "nghi-dinh",
        "lv3": "nd-dang-ky-doanh-nghiep",
        "reason": "nghi-dinh-dang-ky-doanh-nghiep",
    },
    "thu-vien/nghi-dinh-114-2020-nd-cp-giam-thue-thu-nhap-doanh-nghiep-nam-2020.html": {
        "kind": "van-ban",
        "lv1": "thue",
        "lv2": "nghi-dinh",
        "lv3": "nghi-dinh-tndn",
        "reason": "nghi-dinh-giam-thue-tndn",
    },
    "thu-vien/nghi-dinh-22-2020-nd-cp-quy-dinh-ve-le-phi-mon-bai.html": {
        "kind": "van-ban",
        "lv1": "thue",
        "lv2": "nghi-dinh",
        "lv3": "nghi-dinh-gtgt-hoa-don",
        "reason": "nghi-dinh-le-phi-mon-bai",
    },
    "thu-vien/nghi-dinh-34-2022-nd-cp-gia-han-thoi-han-nop-thue-tndn-gtgt-2022.html": {
        "kind": "van-ban",
        "lv1": "thue",
        "lv2": "nghi-dinh",
        "lv3": "nghi-dinh-gtgt-hoa-don",
        "reason": "nghi-dinh-gia-han-thue",
    },
    "thu-vien/nghi-dinh-92-2021-nd-cp-quy-dinh-giam-thue-gtgt-tndn-2021.html": {
        "kind": "van-ban",
        "lv1": "thue",
        "lv2": "nghi-dinh",
        "lv3": "nghi-dinh-gtgt-hoa-don",
        "reason": "nghi-dinh-giam-thue-gtgt-tndn",
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase10_huongdan_dn_thutuc", module_path)
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
            article["classificationReasons"]["phase10ThuVienDoanhNghiepThuTuc"] = decision["reason"]

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
        and a.get("topicLv1Key") == "doanh-nghiep-thu-tuc"
    ]

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "phase": "phase10-huong-dan-doanh-nghiep-thu-tuc",
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
                "href": "thu-vien/nghi-dinh-47-2021-nd-cp-quy-dinh-chi-tiet-luat-doanh-nghiep.html",
                "reason": "van-ban-doanh-nghiep-vs-thu-tuc-huong-dan-boundary",
            }
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 10 - Rà soát Hướng dẫn / Doanh nghiệp - Thủ tục",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Còn lại trong bucket sau phase 10: **{len(phase_bucket_after)}**",
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
        "- `thu-vien/nghi-dinh-47-2021-nd-cp-quy-dinh-chi-tiet-luat-doanh-nghiep.html` (ranh giới văn bản doanh nghiệp và bài thủ tục hướng dẫn)",
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
