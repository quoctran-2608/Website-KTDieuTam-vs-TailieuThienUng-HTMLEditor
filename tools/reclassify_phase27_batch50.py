#!/usr/bin/env python3
"""Phase 27: batch 50 - dọn nốt mẫu thuế + chuẩn hóa lv2/lv3 văn bản/mẫu DN."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "thu-vien-phase27-batch50.json"
OUT_MD = ROOT / "docs" / "thu-vien-phase27-batch50.md"

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
    # A) 13 bài đuôi mismatch mẫu thuế
    "thu-vien/phu-luc-bang-ke-thu-nhap-chiu-thue-va-thue-tncn-da-khau-tru-mau-so-05-2-bk-tncn.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-thue-tncn", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/phu-luc-bang-ke-thu-nhap-chiu-thue-va-thue-tncn-mau-so-05-1-bk-tncn.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-thue-tncn", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/phu-luc-chi-tiet-giam-tru-gia-canh-cho-nguoi-phu-thuoc-mau-so-01-1-thkh.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dang-ky-nguoi-phu-thuoc-giam-tru-gia-canh-mau-so-16-dk-tncn.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dang-ky-thue-cho-dn-duoc-co-quan-thue-uy-nhiem-thu.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dang-ky-thue-dung-cho-ca-nhan-khong-kinh-doanh.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dang-ky-thue-dung-cho-co-quan-dang-ky-ca-nhan-co-uy-quyen.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dang-ky-thue-dung-cho-khau-tru-nop-thue-thay-cho-nha-thau-nuoc-ngoai.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-thue-nha-thau", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-dieu-chinh-bo-sung-thong-tin-dang-ky-thue-mau-so-08-mst-theo-thong-tu-so-156-2013-tt-btc.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/to-khai-thue-khoan-mau-so-01-thkh.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-thue-ho-ca-nhan-mon-bai", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/van-ban-de-nghi-cham-dut-hieu-luc-ma-so-thue.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/van-ban-de-nghi-gia-han-nop-tien-thue-tien-phat-mau-01-ghan.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},
    "thu-vien/van-ban-de-nghi-khoi-phuc-ma-so-thue.html": {"kind": "bieu-mau", "lv1": "thue", "lv2": "mau-bieu-thue", "lv3": "mau-bang-ke-phu-luc-ho-so", "reason": "phase27-thue-form-lv3-tail"},

    # B) 28 bài chuẩn hóa lv2/lv3 theo tiền tố văn bản pháp lý
    "thu-vien/cac-khoan-phu-cap-tro-cap-de-xac-dinh-thu-nhap-chiu-thue-tncn.html": {"kind": "van-ban", "lv1": "thue", "lv2": "cong-van", "lv3": "cong-van-tncn", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/cong-van-12568-btc-cdkt-giai-thich-noi-dung-thong-tu-200.html": {"kind": "van-ban", "lv1": "ke-toan", "lv2": "cong-van", "lv3": "cong-van-chinh-sach-chung", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/huong-dan-trich-nop-kinh-phi-cong-doan-moi-nhat.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-quyet-quyet-dinh", "lv3": "qd-cong-doan-doan-phi", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/luat-106-2016-qh13-sua-doi-luat-thue-gtgt-ttdb-quan-ly-thue.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/luat-107-2016-qh13-luat-thue-xuat-nhap-khau.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/luat-thue-gtgt-so-48-2024-qh15-moi-nhat-hien-nay.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-thue-gtgt", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/luat-thue-thu-nhap-doanh-nghiep-so-67-2025-qh15.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/luat-thue-tncn-so-109-2025-qh15.html": {"kind": "van-ban", "lv1": "thue", "lv2": "luat-bo-luat", "lv3": "luat-quan-ly-thue", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-121-2018-nd-cp-huong-dan-thang-bang-luong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-121-2018-nd-cp-quy-dinh-ve-thang-bang-luong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-145-2020-nd-cp-huong-dan-bo-luat-lao-dong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-145-2020-nd-cp-huong-dan-thi-hanh-luat-lao-dong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-153-2016-nd-cp-quy-dinh-luong-toi-thieu-vung.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-157-muc-luong-toi-thieu-vung-nam-2019.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-174-2016-nd-cp-quy-dinh-ve-luat-ke-toan.html": {"kind": "van-ban", "lv1": "ke-toan", "lv2": "nghi-dinh", "lv3": "nghi-dinh-quan-ly-xu-phat", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-191-huong-dan-ve-kinh-phi-cong-doan.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-318-2025-nd-cp-huong-dan-luat-viec-lam.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-337-2025-nd-cp-hop-dong-lao-dong-dien-tu.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-38-2022-nd-cp-muc-luong-toi-thieu-vung-nam-2022.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-41-2018-nd-cp-quy-dinh-muc-phat-vu-pham-ke-toan.html": {"kind": "van-ban", "lv1": "ke-toan", "lv2": "nghi-dinh", "lv3": "nghi-dinh-quan-ly-xu-phat", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-47-2021-nd-cp-huong-dan-luat-doanh-nghiep.html": {"kind": "van-ban", "lv1": "doanh-nghiep-thu-tuc", "lv2": "nghi-dinh", "lv3": "nd-dang-ky-doanh-nghiep", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nghi-dinh-90-muc-luong-toi-thieu-vung-nam-2020.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-dinh", "lv3": "nd-bhxh", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/nhung-diem-moi-cua-thong-tu-96-ve-thue-thu-nhap-doanh-nghiep.html": {"kind": "van-ban", "lv1": "thue", "lv2": "cong-van", "lv3": "cong-van-tndn", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/quyet-dinh-ban-hanh-he-thong-thang-bang-luong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "nghi-quyet-quyet-dinh", "lv3": "qd-bhxh-bhyt-bhtn", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/thong-tu-09-2015-tt-btc-dn-gop-von-phai-chuyen-khoan.html": {"kind": "van-ban", "lv1": "doanh-nghiep-thu-tuc", "lv2": "thong-tu", "lv3": "thong-tu-chinh-sach-chung", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/thong-tu-133-2016-che-do-ke-toan-doanh-nghiep-vua-va-nho.html": {"kind": "van-ban", "lv1": "doanh-nghiep-thu-tuc", "lv2": "thong-tu", "lv3": "thong-tu-chinh-sach-chung", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/thong-tu-23-2014-tt-bldtbxh-quy-dinh-ve-tuyen-dung-va-bao-cao-su-dung-lao-dong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "thong-tu", "lv3": "thong-tu-chinh-sach-chung", "reason": "phase27-vanban-prefix-lv2"},
    "thu-vien/thong-tu-23-2015-tt-bldtbxh-quy-dinh-ve-tien-luong.html": {"kind": "van-ban", "lv1": "lao-dong-bao-hiem", "lv2": "thong-tu", "lv3": "thong-tu-chinh-sach-chung", "reason": "phase27-vanban-prefix-lv2"},

    # C) 9 bài chuẩn hóa lv3 biểu mẫu DN-thủ tục
    "thu-vien/danh-sach-chu-so-huu-huong-loi-cua-doanh-nghiep.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-bieu-quan-tri-noi-bo", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-cho-thue-nha-mat-dat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-cho-thue-quyen-su-dung-dat-da-co-ha-tang-ky-thuat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-bieu-bat-dong-san", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-cho-vay-tien-cua-ca-nhan-doanh-nghiep.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-chuyen-nhuong-mua-ban-cho-thue-nha-o-cong-trinh.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-cong-trinh-xay-dung-giao-duc-y-te-the-thao.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-bieu-bat-dong-san", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-cong-trinh-xay-dung.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-bieu-bat-dong-san", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-nha-mat-dat.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-hop-dong-thuong-mai", "reason": "phase27-dn-form-lv3-normalize"},
    "thu-vien/mau-hop-dong-mua-ban-cho-thue-phan-dien-tich-san-xay-dung.html": {"kind": "bieu-mau", "lv1": "doanh-nghiep-thu-tuc", "lv2": "mau-bieu-doanh-nghiep-thu-tuc", "lv3": "mau-bieu-bat-dong-san", "reason": "phase27-dn-form-lv3-normalize"},
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_phase27_batch50", module_path)
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
        raise RuntimeError(f"Phase27 cần đúng 50 bài, hiện khai báo {len(DECISIONS)}")

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
            article["classificationReasons"]["phase27Batch50"] = decision["reason"]

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
        "phase": "phase27-batch50",
        "plannedCount": len(DECISIONS),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "applied": applied,
        "skipped": skipped,
        "after": {"countsAfterRebuild": counts},
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Phase 27 - Batch 50",
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
