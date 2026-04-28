#!/usr/bin/env python3
"""Phase 28: batch 50 - normalize biểu mẫu lao động/DN + cân bằng lv1 văn bản."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase28-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase28-batch50.md"

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
    # A) 12 biểu mẫu lao động: chuẩn hóa lv3
    "thu-vien/danh-sach-che-do-thai-san-mau-c70a-hd-theo-qd-919.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-bang-phan-bo-tien-luong-va-bao-hiem-xa-hoi-11-ldtl.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-bang-phan-bo-tien-luong-va-bao-hiem-xa-hoi-theo-qd-48-va-15.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-de-nghi-giai-quyet-huong-che-do-om-dau-thai-san-duong-suc.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-giay-uy-quyen-thuc-hien-cac-thu-tuc-bao-hiem.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-lao-dong-nam-moi-nhat.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-hop-dong-lao-dong", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-so-a01-ts-to-khai-tham-gia-bao-hiem-xa-hoi-bao-hiem-y-te.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-thong-bao-cham-dut-hop-dong-lao-dong.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-hop-dong-lao-dong", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-tk1-ts-to-khai-tham-gia-bhxh-bhyt-bhtn.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/mau-tk3-ts-to-khai-don-vi-tham-gia-bhxh-bhyt-bhtn.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/quyet-dinh-1040-qd-bhxh-mau-bao-cao-su-dung-lao-dong-tham-gia-bhxh.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},
    "thu-vien/thong-bao-ket-qua-dong-bhxh-bhyt-mau-c12-ts.html": {"kind": "bieu-mau", "lv1": "lao-dong-bao-hiem", "lv2": "mau-bieu-lao-dong-bao-hiem", "lv3": "mau-bhxh-che-do", "reason": "phase28-laodong-form-lv3-normalize"},

    # B) 13 biểu mẫu DN-thủ tục: chuẩn hóa lv3
    "thu-vien/mau-hop-dong-cho-thue-quyen-su-dung-dat-da-co-ha-tang-ky-thuat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-chuyen-nhuong-mot-phan-du-an-bat-dong-san.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-chuyen-nhuong-quyen-su-dung-dat-da-co-ha-tang-ky-thuat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-chuyen-nhuong-toan-bo-du-an-bat-dong-san.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-cong-trinh-xay-dung-giao-duc-y-te-the-thao.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-cong-trinh-xay-dung.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-phan-dien-tich-san-xay-dung.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-san-xay-dung-cong-trinh-giao-duc-y-te-the-thao.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-nha-o-chung-cu-moi-nhat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-thue-cong-trinh-xay-dung-phan-dien-tich-san.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-so-01-ht-giay-de-nghi-hoan-tra-khoan-thu-ngan-sach-nha-nuoc.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-dang-ky-thue-mst", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/mau-thong-bao-tam-ngung-kinh-doanh-moi-nhat-mau-23.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-dang-ky-thue-mst", "reason": "phase28-dn-form-lv3-normalize"},
    "thu-vien/thong-bao-ve-viec-cham-dut-hoat-dong-dia-diem-kinh-doanh-cn-vpdd.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-dang-ky-thay-doi-doanh-nghiep", "reason": "phase28-dn-form-lv3-normalize"},

    # C) 22 văn bản: cân bằng lv1 theo domain thuế/lao động
    "thu-vien/cong-van-897-tct-qln-gia-han-nop-thue-do-dich-benh-covid-19.html": {"kind": "van-ban", "lv1": "thue", "lv2": "cong-van", "lv3": "cong-van-ke-khai-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/cong-van-so-2541.html": {"kind": "van-ban", "lv1": "thue", "lv2": "cong-van", "lv3": "cong-van-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-106-2016-qh13-bo-sung-luat-thue-gtgt-quan-ly-thue-ttdb.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-quan-ly-thue-so-108-2025-qh15-hieu-luc-tu-01-07-2026.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-quan-ly-thue-so-38-2019-moi-nhat.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-so-31-2013-qh13-sua-doi-bo-sung-luat-thue-gtgt.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-thue-gtgt", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-so-38-2019-luat-quan-ly-thue.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-thue-gia-tri-gia-tang-hop-nhat.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-thue-gtgt", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-thue-gia-tri-gia-tang-sua-doi-so-149-2025-qh15.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-thue-gtgt", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/luat-thue-gtgt-moi-nhat.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-thue-gtgt", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-122-2015-quy-dinh-muc-luong-toi-thieu-vung-nam-2016.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-153-2016-nd-cp-muc-luong-toi-thieu-vung-2017.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-157-quy-dinh-muc-luong-toi-thieu-vung-2019.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-38-2022-nd-cp-quy-dinh-muc-luong-toi-thieu-vung.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-74-2024-nd-cp-quy-dinh-muc-luong-toi-thieu-vung.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-dinh-90-quy-dinh-muc-luong-toi-thieu-vung-2020.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nghi-dinh-chinh-sach-chung", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-quyet-116-2020-qh14-giam-thue-tndn-phai-nop-nam-2020.html": {"kind": "van-ban", "lv1": "thue", "lv2": "nghi-quyet-quyet-dinh", "lv3": "nq-thue-tndn-giam-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/nghi-quyet-406-nq-ubtvqh15-giam-thue-tndn-va-gtgt-nam-2021.html": {"kind": "van-ban", "lv1": "thue", "lv2": "nghi-quyet-quyet-dinh", "lv3": "nq-thue-tndn-giam-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/thong-tu-119-2014-tt-btc-thu-tuc-hanh-chinh-ve-thue.html": {"kind": "van-ban", "lv1": "thue", "lv2": "thong-tu", "lv3": "thong-tu-ke-khai-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/thong-tu-18-vbhn-btc-huong-dan-luat-quan-ly-thue.html": {"kind": "van-ban", "lv1": "thue", "lv2": "thong-tu", "lv3": "thong-tu-ke-khai-quan-ly-thue", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/thong-tu-78-2014-tt-btc-thi-hanh-luat-thue-thu-nhap-doanh-nghiep.html": {"kind": "van-ban", "lv1": "thue", "lv2": "thong-tu", "lv3": "thong-tu-tndn", "reason": "phase28-vanban-lv1-normalize"},
    "thu-vien/thong-tu-96-2015-quy-dinh-ve-thue-thu-nhap-doanh-nghiep.html": {"kind": "van-ban", "lv1": "thue", "lv2": "thong-tu", "lv3": "thong-tu-tndn", "reason": "phase28-vanban-lv1-normalize"},

    # D) 3 biểu mẫu kế toán nằm trong nhánh DN-thủ tục
    "thu-vien/mau-bien-ban-giao-nhan-tscd-theo-qd-48-va-15.html": {"kind": "bieu-mau", "lv1": "ke-toan", "lv2": "mau-bieu-ke-toan", "lv3": "mau-tscd-khau-hao", "reason": "phase28-dn-to-ke-toan-form"},
    "thu-vien/mau-bien-lai-thu-tien-theo-qd-48-va-15.html": {"kind": "bieu-mau", "lv1": "ke-toan", "lv2": "mau-bieu-ke-toan", "lv3": "mau-phieu-thu-phieu-chi", "reason": "phase28-dn-to-ke-toan-form"},
    "thu-vien/mau-so-dang-ky-chung-tu-ghi-so-theo-thong-tu-99.html": {"kind": "bieu-mau", "lv1": "ke-toan", "lv2": "mau-bieu-ke-toan", "lv3": "mau-so-sach-ke-toan", "reason": "phase28-dn-to-ke-toan-form"},
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase28_batch50", module_path)
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

    if len(DECISIONS) != 50:
        raise RuntimeError(f"Phase28 cần đúng 50 bài, hiện khai báo {len(DECISIONS)}")

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
            article["classificationReasons"]["phase28Batch50"] = decision["reason"]

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
        "phase": "phase28-batch50",
        "plannedCount": len(DECISIONS),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 28 - Batch 50",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Planned: **{len(DECISIONS)}**",
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
