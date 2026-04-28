#!/usr/bin/env python3
"""Rà soát và phân loại lại cụm Bản tin / Ưu đãi học phí."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "ban-tin-uu-dai-hoc-phi-reclassify.json"
OUT_MD = ROOT / "docs" / "ban-tin-uu-dai-hoc-phi-reclassify.md"

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


# href -> decision
# action: keep-bantin | move-thuvien
DECISIONS: Dict[str, Dict[str, str]] = {
    # Giữ ở Bản tin (tin ưu đãi đúng ngữ cảnh)
    "ban-tin/chuong-trinh-giam-gia-khoa-hoc-ke-toan-thuc-hanh.html": {
        "action": "keep-bantin",
        "reason": "tin-khuyen-mai-hoc-phi",
    },
    "ban-tin/chuong-trinh-khuyen-mai-hoc-phi-khoa-hoc-ke-toan-online-moi-nhat.html": {
        "action": "keep-bantin",
        "reason": "tin-uu-dai-hoc-phi",
    },
    # Chuyển sang Thư viện / Hướng dẫn
    "ban-tin/cach-hach-toan-cac-khoan-giam-tru-doanh-thu-tai-khoan-521.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "doanh-thu-chi-phi-kqkd",
        "reason": "nghiep-vu-hach-toan",
    },
    "ban-tin/cach-hach-toan-chiet-khau-thuong-mai.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "doanh-thu-chi-phi-kqkd",
        "reason": "nghiep-vu-hach-toan",
    },
    "ban-tin/cach-hach-toan-gia-von-hang-ban-tai-khoan-632.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "hang-ton-kho-gia-thanh",
        "reason": "nghiep-vu-gia-von",
    },
    "ban-tin/cach-hach-toan-hao-mon-tai-san-co-dinh-tai-khoan-214.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "tai-khoan-hach-toan",
        "lv3": "tscd-ccdc-khau-hao",
        "reason": "nghiep-vu-tscd",
    },
    "ban-tin/cach-viet-hoa-don-gtgt-chiet-khau-thuong-mai-giam-gia-ban.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "lap-xu-ly-hoa-don",
        "reason": "huong-dan-hoa-don",
    },
    "ban-tin/cach-xac-dinh-gia-tinh-thue-gia-tri-gia-tang.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "thue-suat-doi-tuong",
        "reason": "huong-dan-thue-gtgt",
    },
    "ban-tin/nhan-tien-ho-tro-co-phai-xuat-hoa-don.html": {
        "action": "move-thuvien",
        "kind": "huong-dan",
        "lv1": "thue",
        "lv2": "gtgt-hoa-don",
        "lv3": "lap-xu-ly-hoa-don",
        "reason": "nghiep-vu-hoa-don",
    },
    # Chuyển sang Thư viện / Biểu mẫu
    "ban-tin/mau-01-thong-bao-thuc-hien-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/mau-02-dang-ky-thuc-hien-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/mau-03-the-le-chuong-trinh-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/mau-06-thong-bao-sua-doi-bo-sung-noi-dung-chuong-trinh-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/mau-06a-dang-ky-sua-doi-bo-sung-noi-dung-chuong-trinh-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/mau-07-bao-cao-thuc-hien-khuyen-mai.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "doanh-nghiep-thu-tuc",
        "lv2": "mau-bieu-doanh-nghiep-thu-tuc",
        "lv3": "mau-khuyen-mai-thuong-mai",
        "reason": "mau-khuyen-mai",
    },
    "ban-tin/phu-luc-bao-cao-trich-su-dung-quy-khoa-hoc-cong-nghe-mau-so-03-6-tndn.html": {
        "action": "move-thuvien",
        "kind": "bieu-mau",
        "lv1": "thue",
        "lv2": "mau-bieu-thue",
        "lv3": "mau-thue-tndn",
        "reason": "phu-luc-to-khai-thue-tndn",
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_reclass_uu_dai", module_path)
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

    # sectionKey/topicLabel là field legacy nhưng vẫn được article-layout dùng fallback.
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

    source_hrefs = {href for href in DECISIONS.keys() if href in article_map}
    source_rows = [article_map[href] for href in sorted(source_hrefs)]
    missing_decisions = sorted(source_hrefs - set(DECISIONS.keys()))
    extra_decisions = sorted(set(DECISIONS.keys()) - source_hrefs)

    applied = []
    skipped = []

    for href in sorted(source_hrefs):
        decision = DECISIONS.get(href)
        if not decision:
            skipped.append({"href": href, "reason": "missing-decision"})
            continue
        article = article_map[href]
        before = {
            "section": article.get("section") or "",
            "libraryKindKey": article.get("libraryKindKey") or "",
            "topicLv1Key": article.get("topicLv1Key") or "",
            "topicLv2Key": article.get("topicLv2Key") or "",
            "topicLv3Key": article.get("topicLv3Key") or "",
        }

        action = decision["action"]
        if action == "keep-bantin":
            article["section"] = "ban-tin"
            article["sectionLabel"] = "Bản tin"
            article["sectionHref"] = "ban-tin.html"
            article["libraryKindKey"] = ""
            article["libraryKindLabel"] = ""
            article["topicLv1Key"] = "uu-dai-hoc-phi"
            article["topicLv1Label"] = lv1_labels.get("uu-dai-hoc-phi", "Ưu đãi học phí")
            article["topicLv2Key"] = "chuong-trinh-uu-dai-hoc-phi"
            article["topicLv2Label"] = "Chương trình ưu đãi học phí"
            article["topicLv3Key"] = "khuyen-mai-giam-gia-khoa-hoc"
            article["topicLv3Label"] = "Khuyến mãi giảm giá khóa học"
            article["cardTopicLabel"] = article["topicLv2Label"]
        elif action == "move-thuvien":
            kind = decision["kind"]
            lv1 = decision["lv1"]
            lv2 = decision["lv2"]
            lv3 = decision["lv3"]
            if kind not in LIBRARY_KIND_LABELS or lv1 not in lv1_labels or lv2 not in lv2_labels or lv3 not in lv3_labels:
                skipped.append({"href": href, "reason": "invalid-target-taxonomy", "decision": decision})
                continue
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
        else:
            skipped.append({"href": href, "reason": f"unknown-action:{action}"})
            continue

        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["banTinUuDaiHocPhiReview"] = decision.get("reason") or action

        ok, reason = update_meta(ROOT / href, article)
        if not ok:
            skipped.append({"href": href, "reason": reason})
            continue

        applied.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "action": action,
                "before": before,
                "after": {
                    "section": article.get("section") or "",
                    "libraryKindKey": article.get("libraryKindKey") or "",
                    "topicLv1Key": article.get("topicLv1Key") or "",
                    "topicLv2Key": article.get("topicLv2Key") or "",
                    "topicLv3Key": article.get("topicLv3Key") or "",
                },
                "reason": decision.get("reason") or "",
            }
        )

    importer.write_json(data_articles_path, data_articles)
    counts = rebuild(importer, data_articles)

    after_ban_tin_uu_dai = [
        a for a in data_articles if a.get("section") == "ban-tin" and a.get("topicLv1Key") == "uu-dai-hoc-phi"
    ]
    moved_to_thu_vien = [
        a for a in data_articles if a.get("href") in source_hrefs and a.get("section") == "thu-vien"
    ]

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "sourceCount": len(source_rows),
        "missingDecisions": missing_decisions,
        "extraDecisions": extra_decisions,
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {
            "banTinUuDaiCount": len(after_ban_tin_uu_dai),
            "movedToThuVienCount": len(moved_to_thu_vien),
            "countsAfterRebuild": counts,
        },
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Rà soát Bản tin / Ưu đãi học phí - Apply",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Tổng bài nguồn: **{len(source_rows)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Còn lại ở Bản tin/Ưu đãi học phí: **{len(after_ban_tin_uu_dai)}**",
        f"- Chuyển sang Thư viện: **{len(moved_to_thu_vien)}**",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Chi tiết áp dụng",
        "",
        "| # | href | action | after(section/kind/lv1/lv2/lv3) |",
        "|---:|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        a = row["after"]
        lines.append(
            f"| {i} | `{row['href']}` | `{row['action']}` | `{a['section']} / {a['libraryKindKey']} / {a['topicLv1Key']} / {a['topicLv2Key']} / {a['topicLv3Key']}` |"
        )
    if skipped:
        lines += ["", "## Skipped", ""]
        for row in skipped:
            lines.append(f"- `{row.get('href')}`: {row.get('reason')}")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "sourceCount": len(source_rows),
                "applied": len(applied),
                "skipped": len(skipped),
                "banTinUuDaiAfter": len(after_ban_tin_uu_dai),
                "movedToThuVien": len(moved_to_thu_vien),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
