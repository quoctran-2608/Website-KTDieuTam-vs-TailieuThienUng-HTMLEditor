#!/usr/bin/env python3
"""Phase 15: batch lớn 50 bài cho nhóm Hướng dẫn/Văn bản trong Thư viện."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase15-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase15-batch50.md"

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
    # 16 bài chi phí được trừ/không được trừ (đang lạc ở kế toán > nghiệp vụ)
    "thu-vien/cac-khoan-chi-phi-khong-tuong-ung-voi-doanh-thu-tinh-thue.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-chi-cho-sep-di-tiep-khach-xu-ly-nhu-the-nao.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-mua-nha-chung-cu-cho-nhan-vien-giam-doc-o.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-tien-thu-nha-cho-nguoi-nuoc-ngoai-hop-ly.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/hoa-don-tien-dien-nuoc-khong-mang-ten-cong-ty.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-trang-phuc-cho-nhan-vien.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/quy-dinh-ve-thanh-toan-uy-quyen-qua-ben-thu-3.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-tai-tro-lam-nha-tinh-nghia-cho-nguoi-ngheo-nha-dai-doan-ket.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-ton-that-do-thien-tai-dich-benh-hoa-hoan.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/quy-dinh-ve-chi-phi-cho-nhan-vien-di-nghi-mat-di-lai-ngay-le-tet.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/cac-khoan-chi-phi-bi-khong-che-muc-duoc-tru-2026-moi-nhat.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-quang-cao-tren-mang-facebook-google-hop-ly-thi-can-gi.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/cach-dua-tien-thue-nha-vao-chi-phi-hop-ly.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/chi-phi-ve-may-bay-di-cong-tac-hop-ly.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/tien-phat-vi-pham-hop-dong-kinh-te-co-duoc-cho-vao-chi-phi.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },
    "thu-vien/xu-ly-khoan-chi-phi-to-chuc-hoi-thao-quang-ba-khuyen-mai.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "chi-phi-duoc-tru-khong-duoc-tru", "reason": "batch50-tndn-costs"
    },

    # 1 bài sổ mẫu S36-DN
    "thu-vien/cach-lap-so-chi-phi-san-xuat-kinh-doanh-mau-s36-dn.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "chung-tu-so-sach", "lv3": "so-sach-tien-kho-chi-tiet", "reason": "batch50-ledger-form"
    },

    # 13 bài lạc rõ từ doanh-nghiep-thu-tuc
    "thu-vien/phuong-phap-tinh-thue-nha-thau-nuoc-ngoai-moi-nhat.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "thue-nha-thau", "lv3": "nha-thau-ke-khai-to-khai", "reason": "batch50-dn-thue-nha-thau"
    },
    "thu-vien/tai-khoan-ngan-hang-khong-dang-ky-voi-co-quan-thue.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "gtgt-hoa-don", "lv3": "khau-tru-hoan-thue", "reason": "batch50-dn-gtgt-khau-tru"
    },
    "thu-vien/nghi-dinh-139-2016-nd-cp-thue-mon-bai-tu-nam-2017.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-gtgt-hoa-don", "reason": "batch50-dn-nd-mon-bai"
    },
    "thu-vien/cac-phuong-phap-phan-loai-tai-san-co-dinh-cua-doanh-nghiep.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-san-kho-ccdc", "lv3": "tscd-nguyen-gia-khau-hao", "reason": "batch50-dn-tscd"
    },
    "thu-vien/cac-quy-tac-trich-khau-hao-tai-san-co-dinh.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-san-kho-ccdc", "lv3": "tscd-nguyen-gia-khau-hao", "reason": "batch50-dn-tscd"
    },
    "thu-vien/cach-hach-toan-cong-cu-dung-cu-tai-khoan-153.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-khoan-hach-toan", "lv3": "hang-ton-kho-gia-thanh", "reason": "batch50-dn-tk153"
    },
    "thu-vien/cach-hach-toan-chi-phi-quan-ly-doanh-nghiep-tai-khoan-642.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-khoan-hach-toan", "lv3": "doanh-thu-chi-phi-kqkd", "reason": "batch50-dn-tk642"
    },
    "thu-vien/huong-dan-hach-toan-chi-phi-lai-vay.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-khoan-hach-toan", "lv3": "cong-no-thanh-toan", "reason": "batch50-dn-lai-vay"
    },
    "thu-vien/he-thong-so-sach-ke-toan-theo-quyet-dinh-48-2006-qd-btc.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "chung-tu-so-sach", "lv3": "he-thong-chung-tu-mau-bieu", "reason": "batch50-dn-he-thong-so-sach"
    },
    "thu-vien/nguyen-tac-ke-toan-cac-khoan-chi-phi.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "chuan-muc-che-do-nguyen-tac", "lv3": "nguyen-tac-ke-toan", "reason": "batch50-dn-nguyen-tac-ke-toan"
    },
    "thu-vien/nguyen-tac-ke-toan-cac-khoan-doanh-thu.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "chuan-muc-che-do-nguyen-tac", "lv3": "nguyen-tac-ke-toan", "reason": "batch50-dn-nguyen-tac-ke-toan"
    },
    "thu-vien/nguyen-tac-ke-toan-von-chu-so-huu.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "chuan-muc-che-do-nguyen-tac", "lv3": "nguyen-tac-ke-toan", "reason": "batch50-dn-nguyen-tac-ke-toan"
    },
    "thu-vien/quy-dinh-ve-cong-khai-bao-cao-tai-chinh-tai-doanh-nghiep.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "bao-cao-tai-chinh", "lv3": "tong-quan-quy-dinh-nop-bctc", "reason": "batch50-dn-bctc"
    },

    # 14 bài lạc từ phần mềm/công cụ (bản chất thuế/kế toán)
    "thu-vien/cac-but-toan-ket-chuyen-cuoi-ky-ke-toan-tren-excel.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "tai-khoan-hach-toan", "lv3": "doanh-thu-chi-phi-kqkd", "reason": "batch50-phanmem-ket-chuyen"
    },
    "thu-vien/huong-dan-ke-khai-thue-gtgt-theo-phuong-phap-khau-tru.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "gtgt-hoa-don", "lv3": "ke-khai-gtgt", "reason": "batch50-phanmem-gtgt"
    },
    "thu-vien/cach-lap-to-khai-thue-thu-nhap-ca-nhan-mau-so-02kk-tncn.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/huong-dan-lap-to-khai-quyet-toan-thue-tncn-05kk-tncn.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/huong-dan-lap-to-khai-quyet-toan-thue-tndn-cuoi-nam-mau-03-tndn.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "ke-khai-tam-nop-quyet-toan", "reason": "batch50-phanmem-tndn"
    },
    "thu-vien/huong-dan-quyet-toan-thue-tncn-moi-nhat.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/huong-dan-cach-lap-to-khai-thue-gtgt-mau-01-gtgt.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "gtgt-hoa-don", "lv3": "ke-khai-gtgt", "reason": "batch50-phanmem-gtgt"
    },
    "thu-vien/cach-ke-khai-thue-mon-bai-qua-mang-truc-tuyen.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "le-phi-mon-bai", "lv3": "mon-bai-ke-khai-to-khai", "reason": "batch50-phanmem-monbai"
    },
    "thu-vien/huong-dan-cach-ke-khai-thue-thu-nhap-ca-nhan-theo-thang-quy.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/cach-ke-khai-thue-tncn-tu-chuyen-nhuong-von-nhap-moi.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/huong-dan-lap-bang-ke-hang-hoa-dich-vu-mua-vao-pl-01-2-gtgt.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "gtgt-hoa-don", "lv3": "bao-cao-bang-ke", "reason": "batch50-phanmem-gtgt-bang-ke"
    },
    "thu-vien/cach-lap-to-khai-thue-tndn-tam-tinh-quy-mau-01a-tndn.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tndn", "lv3": "ke-khai-tam-nop-quyet-toan", "reason": "batch50-phanmem-tndn"
    },
    "thu-vien/thue-tncn-khong-phat-sinh-co-phai-nop-to-khai-khong.html": {
        "kind": "huong-dan", "lv1": "thue", "lv2": "tncn", "lv3": "ke-khai-quyet-toan", "reason": "batch50-phanmem-tncn"
    },
    "thu-vien/gia-han-nop-bao-cao-tai-chinh-theo-thong-tu-133.html": {
        "kind": "huong-dan", "lv1": "ke-toan", "lv2": "bao-cao-tai-chinh", "lv3": "tong-quan-quy-dinh-nop-bctc", "reason": "batch50-phanmem-bctc"
    },

    # 6 bài văn bản nghị định lạc ở tham khảo học liệu
    "thu-vien/nghi-dinh-126-2020-nd-cp-quy-dinh-chi-tiet-luat-quan-ly-thue.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
    "thu-vien/nghi-dinh-91-2014-nd-cp-sua-doi-bo-sung-quy-dinh-ve-thue.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
    "thu-vien/nghi-dinh-so-117-quy-dinh-quan-ly-thue-kinh-doanh-thuong-mai-dien-tu.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
    "thu-vien/nghi-dinh-so-175-quy-dinh-ve-le-phi-truoc-ba.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
    "thu-vien/cong-dien-so-102-ve-thuc-hien-nghi-dinh-175.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
    "thu-vien/nghi-dinh-41-2018-nd-cp-xu-phat-vi-pham-ke-toan-kiem-toan.html": {
        "kind": "van-ban", "lv1": "thue", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "batch50-thamkhao-nd"
    },
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase15_batch50", module_path)
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
            article["classificationReasons"]["phase15Batch50"] = decision["reason"]

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
        "phase": "phase15-batch50",
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 15 - Batch 50",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
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
                "planned": len(DECISIONS),
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
