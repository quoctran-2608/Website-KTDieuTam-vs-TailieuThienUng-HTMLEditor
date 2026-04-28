#!/usr/bin/env python3
"""Phase 3: rà và sửa nhóm Hướng dẫn > Kế toán > Chuẩn mực - Chế độ - Nguyên tắc."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase3-huong-dan-ke-toan-chuan-muc.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase3-huong-dan-ke-toan-chuan-muc.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)
TITLE_RE = re.compile(r"(<title>)(.*?)(</title>)", re.IGNORECASE | re.DOTALL)


LIBRARY_KIND_LABELS = {
    "huong-dan": "Hướng dẫn",
    "bieu-mau": "Biểu mẫu",
    "cong-cu": "Công cụ",
    "van-ban": "Văn bản",
}


# Tin tuyển dụng công ty bị lạc trong Thư viện -> đưa về Bản tin / Thông báo tuyển dụng.
JOB_HREFS = [
    "thu-vien/cong-ty-co-phan-asia-pacific-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-co-phan-co-kim-khi-viet-my-tuyen-ke-toan.html",
    "thu-vien/cong-ty-co-phan-dau-tu-everland-tuyen-ke-toan-ban-hang.html",
    "thu-vien/cong-ty-co-phan-dich-vu-va-tu-van-phat-trien-nguon-nhan-luc-eq-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-co-phan-ninza-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-co-phan-thuong-mai-va-dich-vu-viet-huong-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-co-phan-thuong-mai-va-xuat-nhap-tvh-tuyen-ke-toan-kho.html",
    "thu-vien/cong-ty-co-phan-tm-dtpt-echo-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-cp-dich-vu-va-van-tai-bao-chau-tuyen-ke-toan-nha-hang.html",
    "thu-vien/cong-ty-cp-tam-hoang-viet-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-cp-xnk-thu-cong-my-nghe-viet-nhat-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-cp-xnk-thuong-mai-va-xay-dung-ngoc-khanh-tuyen-ke-toan.html",
    "thu-vien/cong-ty-eurodoor-vietnam-tuyen-ke-toan-ban-hang.html",
    "thu-vien/cong-ty-ke-toan-dieu-tam-tuyen-ke-toan-giang-day.html",
    "thu-vien/cong-ty-thanh-lich-tuyen-nhan-vien-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-tl-logistics-tuyen-ke-toan-vien.html",
    "thu-vien/cong-ty-tm-va-dv-rong-viet-dvc-pharma-tuyen-ke-toan-cong-no.html",
    "thu-vien/cong-ty-tnhh-hbc-viet-nam-tuyen-ke-toan-kho.html",
    "thu-vien/cong-ty-tnhh-mtv-toyota-my-dinh-tuyen-nhan-vien-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-tnhh-phan-phoi-san-pham-cao-cap-lpd-tuyen-ke-toan-thue.html",
    "thu-vien/cong-ty-tnhh-pttm-va-sx-thanh-long-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-tnhh-tham-my-quoc-te-nha-khoa-lien-thanh-tuyen-ke-toan.html",
    "thu-vien/cong-ty-tnhh-thanh-cong-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-tnhh-thuong-mai-huong-thuy-tuyen-ke-toan-trung-tam-bao-hanh.html",
    "thu-vien/cong-ty-tnhh-thuong-mai-tvahat-quoc-te-tuyen-ke-toan-ban-hang-ke-toan-kho.html",
    "thu-vien/cong-ty-tnhh-thuong-mai-va-dau-tu-kleve-tuyen-ke-toan-thue.html",
    "thu-vien/cong-ty-tnhh-thuong-mai-va-dich-vu-quan-phong-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-tnhh-thuong-mai-va-du-lich-mc-viet-nam-tuyen-ke-toan-kho.html",
    "thu-vien/cong-ty-tnhh-tqt-thuong-mai-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-tnhh-tu-van-luat-bravo-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-tnhh-tu-van-tham-dinh-va-dau-tu-cong-nghe-gia-loc-tuyen-ke-toan-vat-tu.html",
    "thu-vien/cong-ty-tnhh-xay-dung-halo-tuyen-ke-toan-tong-hop.html",
    "thu-vien/cong-ty-tu-van-va-kinh-doanh-vietbay-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/cong-ty-viet-nhat-handicraft-jsc-tuyen-ke-toan-tong-hop.html",
    "thu-vien/http-ketoandieutam-net.html",
    "thu-vien/hyundai-my-dinh-tuyen-dung-ke-toan-tong-hop.html",
    "thu-vien/nha-may-sx-viname-tuyen-ke-toan-tong-hop.html",
    "thu-vien/tap-doan-tai-chinh-fb-viet-nam-tuyen-nhan-vien-ke-toan-tai-dong-anh-ha-noi.html",
    "thu-vien/tap-doan-tan-a-dai-thanh-tuyen-ke-toan-chi-nhanh.html",
    "thu-vien/thoi-trang-cong-so-emspo-tuyen-nhan-vien-ke-toan.html",
    "thu-vien/truong-mam-non-thanh-dong-tuyen-ke-toan-vien.html",
]


# Mapping rõ ràng khác trong phase 3 (không suy luận mơ hồ).
MANUAL_DECISIONS: Dict[str, Dict[str, str]] = {
    "thu-vien/cach-viet-don-xin-viec-ke-toan-viet-tay-hay-nhat.html": {
        "kind": "huong-dan",
        "lv1": "tham-khao-hoc-lieu",
        "lv2": "kinh-nghiem-hoi-dap-nghe-nghiep",
        "lv3": "kinh-nghiem-phong-van-xin-viec",
        "reason": "huong-dan-xin-viec-nghe-nghiep",
    },
    "thu-vien/tai-lieu-on-thi-chung-chi-hanh-nghe-ke-toan-kiem-toan-2014.html": {
        "kind": "huong-dan",
        "lv1": "tham-khao-hoc-lieu",
        "lv2": "kinh-nghiem-hoi-dap-nghe-nghiep",
        "lv3": "hoc-va-dao-tao-ke-toan",
        "reason": "tai-lieu-on-thi-hoc-dao-tao",
    },
    "thu-vien/cach-ghi-so-theo-hinh-thuc-ke-toan-tren-may-vi-tinh.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chung-tu-so-sach",
        "lv3": "hinh-thuc-ghi-so-ke-toan",
        "reason": "hinh-thuc-ghi-so-khong-phai-tuyen-dung",
    },
    "thu-vien/ghi-so-theo-hinh-thuc-ke-toan-tren-may-vi-tinh-theo-thong-tu-99.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chung-tu-so-sach",
        "lv3": "hinh-thuc-ghi-so-ke-toan",
        "reason": "ghi-so-tren-may-vi-tinh",
    },
    "thu-vien/cach-lap-so-chi-tiet-ban-hang-theo-thong-tu-200-va-133.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chung-tu-so-sach",
        "lv3": "so-sach-tien-kho-chi-tiet",
        "reason": "mau-so-chi-tiet-ban-hang",
    },
    "thu-vien/cach-lap-so-quy-tien-mat-theo-thong-tu-200-va-133.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chung-tu-so-sach",
        "lv3": "so-sach-tien-kho-chi-tiet",
        "reason": "mau-so-quy-tien-mat",
    },
    "thu-vien/muc-phat-vi-pham-ve-bo-tri-nguoi-lam-ke-toan-sai-quy-dinh.html": {
        "kind": "huong-dan",
        "lv1": "ke-toan",
        "lv2": "chuan-muc-che-do-nguyen-tac",
        "lv3": "nguyen-tac-ke-toan",
        "reason": "xu-phat-vi-pham-ke-toan",
    },
    "thu-vien/ho-so-thu-tuc-huong-che-do-tu-tuat.html": {
        "kind": "huong-dan",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "bao-hiem",
        "lv3": "ho-so-thu-tuc",
        "reason": "che-do-tu-tuat-thuoc-bao-hiem",
    },
    "thu-vien/thoi-gian-nghi-viec-huong-che-do-khi-thuc-hien-cac-bien-phap-tranh-thai.html": {
        "kind": "huong-dan",
        "lv1": "lao-dong-bao-hiem",
        "lv2": "bao-hiem",
        "lv3": "che-do-muc-huong",
        "reason": "che-do-bhxh-thai-san",
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase3_hdketoan_chuanmuc", module_path)
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


def update_meta_and_title(path: Path, article: Dict) -> Tuple[bool, str]:
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

    page_title = f"{article.get('title') or ''} | {article.get('sectionLabel') or 'Thư viện'} | Kế Toán Diệu Tâm"
    if TITLE_RE.search(replaced):
        replaced = TITLE_RE.sub(rf"\1{page_title}\3", replaced, count=1)

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


def apply_move_to_ban_tin(article: Dict, lv1_labels: Dict[str, str]) -> None:
    article["section"] = "ban-tin"
    article["sectionLabel"] = "Bản tin"
    article["sectionHref"] = "ban-tin.html"
    article["libraryKindKey"] = ""
    article["libraryKindLabel"] = ""
    article["topicLv1Key"] = "thong-bao-tuyen-dung"
    article["topicLv1Label"] = lv1_labels.get("thong-bao-tuyen-dung", "Thông báo tuyển dụng")
    article["topicLv2Key"] = ""
    article["topicLv2Label"] = "Thông báo tuyển dụng"
    article["topicLv3Key"] = ""
    article["topicLv3Label"] = ""
    article["cardBadgeLabel"] = "Cập nhật"
    article["cardTopicLabel"] = article["topicLv2Label"]


def apply_move_in_thu_vien(article: Dict, decision: Dict[str, str], lv1_labels: Dict[str, str], lv2_labels: Dict[str, str], lv3_labels: Dict[str, str]) -> None:
    kind = decision["kind"]
    lv1 = decision["lv1"]
    lv2 = decision["lv2"]
    lv3 = decision["lv3"]

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


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)

    applied = []
    skipped = []

    # 1) Move tuyển dụng từ Thư viện -> Bản tin
    for href in JOB_HREFS:
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-article"})
            continue
        before = {
            "section": article.get("section") or "",
            "kind": article.get("libraryKindKey") or "",
            "lv1": article.get("topicLv1Key") or "",
            "lv2": article.get("topicLv2Key") or "",
            "lv3": article.get("topicLv3Key") or "",
        }
        apply_move_to_ban_tin(article, lv1_labels)
        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["phase3ThuVienChuanMuc"] = "job-post-should-be-ban-tin"
        ok, reason = update_meta_and_title(ROOT / href, article)
        if not ok:
            skipped.append({"href": href, "reason": reason})
            continue
        applied.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "reason": "job-post-should-be-ban-tin",
                "before": before,
                "after": {
                    "section": article.get("section") or "",
                    "kind": article.get("libraryKindKey") or "",
                    "lv1": article.get("topicLv1Key") or "",
                    "lv2": article.get("topicLv2Key") or "",
                    "lv3": article.get("topicLv3Key") or "",
                },
            }
        )

    # 2) Move rõ ràng khác trong Thư viện
    for href, decision in MANUAL_DECISIONS.items():
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
            "section": article.get("section") or "",
            "kind": article.get("libraryKindKey") or "",
            "lv1": article.get("topicLv1Key") or "",
            "lv2": article.get("topicLv2Key") or "",
            "lv3": article.get("topicLv3Key") or "",
        }
        apply_move_in_thu_vien(article, decision, lv1_labels, lv2_labels, lv3_labels)
        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["phase3ThuVienChuanMuc"] = decision["reason"]
        ok, reason = update_meta_and_title(ROOT / href, article)
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
                    "section": article.get("section") or "",
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
        and a.get("topicLv1Key") == "ke-toan"
        and a.get("topicLv2Key") == "chuan-muc-che-do-nguyen-tac"
    ]
    job_left = [
        a for a in phase_bucket_after
        if a.get("topicLv3Key") == "tuyen-dung-nghe-nghiep-ke-toan"
    ]

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "phase": "phase3-huong-dan-ke-toan-chuan-muc-che-do-nguyen-tac",
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {
            "remainingInBucket": len(phase_bucket_after),
            "remainingJobLv3InBucket": len(job_left),
            "countsAfterRebuild": counts,
        },
        "deferredManualReview": [
            {
                "href": "thu-vien/cong-ty-lam-dich-vu-ke-toan-thue-tron-goi-uy-tin-tai-ha-noi.html",
                "reason": "service-marketing-article-no-clear-taxonomy-destination",
            }
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 3 - Rà soát Hướng dẫn / Kế toán / Chuẩn mực - Chế độ - Nguyên tắc",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Còn lại trong bucket sau phase 3: **{len(phase_bucket_after)}**",
        f"- Còn lại bài lv3 tuyển dụng trong bucket: **{len(job_left)}**",
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
            f"| {i} | `{row['href']}` | `{b['section']} / {b['kind']} / {b['lv1']} / {b['lv2']} / {b['lv3']}` | `{a['section']} / {a['kind']} / {a['lv1']} / {a['lv2']} / {a['lv3']}` | `{row['reason']}` |"
        )
    if skipped:
        lines += ["", "## Skipped", ""]
        for row in skipped:
            lines.append(f"- `{row.get('href')}`: {row.get('reason')}")
    lines += [
        "",
        "## Deferred manual review",
        "",
        "- `thu-vien/cong-ty-lam-dich-vu-ke-toan-thue-tron-goi-uy-tin-tai-ha-noi.html` (bài dịch vụ/marketing, chưa có bucket đích rõ trong taxonomy hiện tại)",
    ]
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "applied": len(applied),
                "skipped": len(skipped),
                "remainingInBucket": len(phase_bucket_after),
                "remainingJobLv3InBucket": len(job_left),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
