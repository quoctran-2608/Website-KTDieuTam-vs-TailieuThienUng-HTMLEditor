#!/usr/bin/env python3
"""Dọn các leaf category chỉ có 1 bài bằng cách gom sang leaf phù hợp hơn."""

from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON = ROOT / "docs" / "leaf-singleton-cleanup-apply.json"
OUT_MD = ROOT / "docs" / "leaf-singleton-cleanup-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


# href -> (section, topicLv1Key, topicLv2Key, topicLv3Key, reason)
MOVES: Dict[str, Tuple[str, str, str, str, str]] = {
    # Bản tin
    "ban-tin/trung-tam-dao-tao-ke-toan-dieu-tam-thuc-te-tai-ha-noi.html": ("ban-tin", "co-so-dia-diem", "co-so-dao-tao", "", "intro-center-to-training-location"),
    "ban-tin/chuong-trinh-khuyen-mai-hoc-phi-khoa-hoc-ke-toan-online-moi-nhat.html": ("ban-tin", "uu-dai-hoc-phi", "", "", "promo-to-main-discount"),
    "ban-tin/phu-luc-bao-cao-trich-su-dung-quy-khoa-hoc-cong-nghe-mau-so-03-6-tndn.html": ("ban-tin", "uu-dai-hoc-phi", "", "", "legacy-news-tax-form-to-existing-update-bucket"),
    # Doanh nghiệp - thủ tục
    "thu-vien/cong-van-2512-tct-cs-nhung-noi-dung-moi-cua-thong-tu-96.html": ("thu-vien", "thue", "cong-van", "cong-van-ke-khai-quan-ly-thue", "cv-tax-to-main-tax-cv"),
    "thu-vien/mau-thong-bao-tam-ngung-kinh-doanh-moi-nhat-mau-23.html": ("thu-vien", "doanh-nghiep-thu-tuc", "mau-bieu-doanh-nghiep-thu-tuc", "mau-dang-ky-thay-doi-doanh-nghiep", "tam-ngung-to-dn-change-form"),
    "thu-vien/nghi-dinh-47-2021-nd-cp-huong-dan-luat-doanh-nghiep.html": ("thu-vien", "doanh-nghiep-thu-tuc", "thu-tuc-doanh-nghiep", "dn-thu-tuc-thanh-lap-thay-doi", "nd-law-dn-to-dn-change"),
    "thu-vien/quyet-dinh-giai-the-doanh-nghiep-cong-ty.html": ("thu-vien", "doanh-nghiep-thu-tuc", "thu-tuc-doanh-nghiep", "dn-thu-tuc-giai-the", "qd-giai-the-to-procedure"),
    "thu-vien/thong-tu-01-2021-tt-bkhdt-quy-dinh-ve-dang-ky-doanh-nghiep.html": ("thu-vien", "doanh-nghiep-thu-tuc", "thong-tu", "tt-dang-ky-doanh-nghiep", "merge-tt-dang-ky-dn"),
    "thu-vien/thong-tu-65-2020-tt-btc-huong-dan-ke-khai-le-phi-mon-bai.html": ("thu-vien", "thue", "thong-tu", "thong-tu-ke-khai-quan-ly-thue", "tt-tax-filing"),
    "thu-vien/thong-tu-95-2016-quy-dinh-ve-thu-tuc-dang-ky-thue.html": ("thu-vien", "thue", "thong-tu", "thong-tu-mst-dang-ky-thue", "tt-mst-dang-ky-thue"),
    "thu-vien/thong-tu-09-2015-tt-btc-giao-dich-tai-chinh-cua-doanh-nghiep.html": ("thu-vien", "doanh-nghiep-thu-tuc", "thong-tu", "thong-tu-chinh-sach-chung", "tt-finance-dn-to-policy"),
    # Kế toán
    "thu-vien/thu-thuat-doc-va-phan-tich-bao-cao-tai-chinh-doanh-nghiep.html": ("thu-vien", "ke-toan", "bao-cao-tai-chinh", "tong-quan-quy-dinh-nop-bctc", "bctc-analysis-to-overview"),
    "thu-vien/cong-ty-lam-dich-vu-ke-toan-thue-tron-goi-uy-tin-tai-ha-noi.html": ("thu-vien", "ke-toan", "chuan-muc-che-do-nguyen-tac", "chuan-muc-che-do-khac", "service-accounting-to-other"),
    "thu-vien/cong-van-4868-tct-cs-gioi-thieu-cac-noi-dung-moi-nghi-dinh-123.html": ("thu-vien", "thue", "cong-van", "cong-van-gtgt-hoa-don", "cv-nd123-invoice"),
    "thu-vien/cong-van-12568-btc-cdkt-giai-thich-noi-dung-thong-tu-200.html": ("thu-vien", "ke-toan", "chuan-muc-che-do-nguyen-tac", "che-do-ke-toan-va-thong-tu", "cv-tt200-to-chedo"),
    "thu-vien/mau-thong-bao-chung-tu-dien-tu-da-lap-sai.html": ("thu-vien", "thue", "mau-bieu-thue", "mau-thue-gtgt-hoa-don", "wrong-electronic-doc-to-tax-form"),
    "thu-vien/mau-bang-kiem-ke-quy-ngoai-te-theo-qd-48-va-15.html": ("thu-vien", "ke-toan", "mau-bieu-ke-toan", "mau-tai-chinh-von-ngoai-te", "foreign-currency-inventory"),
    "thu-vien/nghi-dinh-174-2016-nd-cp-quy-dinh-ve-luat-ke-toan.html": ("thu-vien", "ke-toan", "chuan-muc-che-do-nguyen-tac", "che-do-ke-toan-va-thong-tu", "nd-174-chedo"),
    "thu-vien/nghi-dinh-41-2018-nd-cp-quy-dinh-muc-phat-vu-pham-ke-toan.html": ("thu-vien", "ke-toan", "chuan-muc-che-do-nguyen-tac", "che-do-ke-toan-va-thong-tu", "nd-41-chedo-penalty"),
    "thu-vien/thoi-diem-ghi-nhan-doanh-thu-cung-cap-dich-vu.html": ("thu-vien", "ke-toan", "tai-khoan-hach-toan", "doanh-thu-chi-phi-kqkd", "revenue-recognition-to-tk"),
    "thu-vien/thu-tuc-dang-ky-chuong-trinh-ban-hang-khuyen-mai.html": ("thu-vien", "doanh-nghiep-thu-tuc", "thu-tuc-doanh-nghiep", "dn-thu-tuc-khuyen-mai-thuong-mai", "promotion-procedure"),
    "thu-vien/nhung-luu-y-khi-thanh-tra-thue-xuong-kiem-tra-dn-xay-dung.html": ("thu-vien", "tham-khao-hoc-lieu", "kinh-nghiem-hoi-dap-nghe-nghiep", "kinh-nghiem-quyet-toan-thue", "tax-inspection-experience"),
    "thu-vien/thong-tu-132-2018-tt-btc-che-do-ke-toan-doanh-nghiep-sieu-nho.html": ("thu-vien", "ke-toan", "thong-tu", "tt-che-do-ke-toan-doanh-nghiep", "tt132-chedo-dn"),
    "thu-vien/thong-tu-88-che-do-ke-toan-cho-ca-nhan-ho-kinh-doanh.html": ("thu-vien", "thue", "ho-ca-nhan-kinh-doanh", "hkd-che-do-ke-toan", "tt88-hkd-accounting"),
    # Lao động - bảo hiểm
    "thu-vien/huong-dan-so-32-hd-tld-tai-chinh-cong-doan-nam-2025.html": ("thu-vien", "lao-dong-bao-hiem", "cong-doan", "cong-doan-hach-toan-thue", "cong-doan-finance"),
    "thu-vien/muc-phat-khong-dong-kinh-phi-cong-doan.html": ("thu-vien", "lao-dong-bao-hiem", "cong-doan", "cong-doan-trich-nop-ty-le-muc-dong", "cong-doan-penalty-to-contribution"),
    "thu-vien/cong-van-5749-huong-dan-quyet-toan-thue-tncn.html": ("thu-vien", "thue", "cong-van", "cong-van-tncn", "cv-tncn-to-tax"),
    "thu-vien/cong-van-so-17940-sldtbxh-vl-quy-dinh-ve-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "cong-van", "cong-van-chinh-sach-chung", "cv-labor-policy"),
    "thu-vien/cong-van-xin-dang-ky-thang-bang-luong-gui-phong-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "cong-van", "cong-van-chinh-sach-chung", "cv-wage-policy"),
    "thu-vien/cong-van-11819-ct-ttht-chi-phi-co-tinh-chat-phuc-loi-cho-nguoi-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "thue-lien-quan-lao-dong", "benefit-tax-labor"),
    "thu-vien/cac-hanh-vi-bi-nghiem-cam-trong-linh-vuc-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "noi-quy-thoa-uoc-ky-luat", "labor-violation-to-discipline"),
    "thu-vien/luat-thue-tncn-so-109-2025-qh15.html": ("thu-vien", "thue", "tncn", "tinh-thue-bieu-thue", "tncn-law-to-tncn-tax"),
    "thu-vien/luat-107-2016-qh13-luat-thue-xuat-nhap-khau.html": ("thu-vien", "thue", "gtgt-hoa-don", "thue-suat-doi-tuong", "xnk-law-to-tax-rate-broad"),
    "thu-vien/nghi-dinh-191-huong-dan-ve-kinh-phi-cong-doan.html": ("thu-vien", "lao-dong-bao-hiem", "cong-doan", "cong-doan-trich-nop-ty-le-muc-dong", "nd-congdoan-contribution"),
    "thu-vien/nghi-dinh-337-2025-nd-cp-hop-dong-lao-dong-dien-tu.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "hop-dong-quan-he-lao-dong", "e-labor-contract"),
    "thu-vien/nghi-dinh-145-2020-nd-cp-huong-dan-thi-hanh-luat-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "van-ban-lao-dong", "nd-labor-law-to-vanban"),
    "thu-vien/nghi-dinh-121-2018-nd-cp-quy-dinh-ve-thang-bang-luong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "tien-luong-thoi-gio-lam-viec", "nd-wage"),
    "thu-vien/nghi-dinh-318-2025-nd-cp-huong-dan-luat-viec-lam.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "ho-so-thu-tuc-lao-dong", "nd-vieclam"),
    "thu-vien/quyet-dinh-cham-dut-hop-dong-lao-dong-moi-nhat.html": ("thu-vien", "lao-dong-bao-hiem", "mau-bieu-lao-dong-bao-hiem", "mau-hop-dong-lao-dong", "termination-decision-form"),
    "thu-vien/quyet-dinh-ban-hanh-he-thong-thang-bang-luong.html": ("thu-vien", "lao-dong-bao-hiem", "mau-bieu-lao-dong-bao-hiem", "mau-tien-luong-phu-cap", "salary-scale-decision-form"),
    "thu-vien/thong-tu-10-2020-tt-bldtbxh-huong-dan-luat-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "thong-tu", "thong-tu-chinh-sach-chung", "tt-labor-policy"),
    "thu-vien/thong-tu-23-2015-tt-bldtbxh-quy-dinh-ve-tien-luong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "tien-luong-thoi-gio-lam-viec", "tt-wage"),
    "thu-vien/thong-tu-23-2014-tt-bldtbxh-quy-dinh-ve-tuyen-dung-va-bao-cao-su-dung-lao-dong.html": ("thu-vien", "lao-dong-bao-hiem", "lao-dong-tien-luong", "ho-so-thu-tuc-lao-dong", "tt-recruitment-report"),
    # Phần mềm - công cụ
    "thu-vien/tai-phan-mem-ke-toan-fast-accounting-mien-phi-dung-thu.html": ("thu-vien", "phan-mem-cong-cu", "fast", "fast-huong-dan-su-dung", "fast-download-merge"),
    "thu-vien/cong-van-845-tct-nang-cap-htkk-bctc-theo-thong-tu-133.html": ("thu-vien", "phan-mem-cong-cu", "htkk-etax-thue-dien-tu", "htkk-guide-import-bang-ke", "htkk-cv-to-guide"),
    "thu-vien/cach-cai-dat-phan-mem-ke-toan-fast.html": ("thu-vien", "phan-mem-cong-cu", "fast", "fast-huong-dan-su-dung", "fast-install-merge"),
    # Học liệu - tham khảo
    "thu-vien/bai-tap-ke-toan-thue-gia-tri-gia-tang-co-loi-giai.html": ("thu-vien", "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", "bai-tap-dinh-khoan-hach-toan", "tax-exercise-broad"),
    "thu-vien/bai-tap-tinh-thue-thu-nhap-doanh-nghiep-co-loi-giai.html": ("thu-vien", "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", "bai-tap-dinh-khoan-hach-toan", "tax-exercise-broad"),
    "thu-vien/bai-tap-ke-toan-thue-tieu-thu-dac-biet-co-loi-giai.html": ("thu-vien", "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", "bai-tap-dinh-khoan-hach-toan", "tax-exercise-broad"),
    "thu-vien/bai-tap-ke-toan-thue-xuat-nhap-khau-co-loi-giai.html": ("thu-vien", "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", "bai-tap-dinh-khoan-hach-toan", "tax-exercise-broad"),
    "thu-vien/bai-tap-ke-toan-tai-san-co-dinh-co-dap-an.html": ("thu-vien", "tham-khao-hoc-lieu", "bai-tap-thuc-hanh", "bai-tap-dinh-khoan-hach-toan", "tscd-exercise-broad"),
    "thu-vien/bao-cao-thuc-tap-ke-toan-thanh-toan-thue-gtgt-va-thue-tndn.html": ("thu-vien", "tham-khao-hoc-lieu", "bao-cao-thuc-tap", "thuc-tap-ke-toan-tong-hop-to-chuc", "intern-tax-to-general"),
    "thu-vien/bao-cao-thuc-tap-ke-toan-tai-san-co-dinh-huu-hinh.html": ("thu-vien", "tham-khao-hoc-lieu", "bao-cao-thuc-tap", "thuc-tap-ke-toan-tong-hop-to-chuc", "intern-tscd-to-general"),
    "thu-vien/hoc-nghe-tap-nghe-de-lam-viec-cho-nguoi-su-dung-lao-dong.html": ("thu-vien", "tham-khao-hoc-lieu", "kinh-nghiem-hoi-dap-nghe-nghiep", "hoc-va-dao-tao-ke-toan", "hoc-nghe-to-dao-tao"),
    "thu-vien/thong-tu-151-2014-tt-btc-quy-dinh-ve-thue.html": ("thu-vien", "thue", "thong-tu", "thong-tu-chinh-sach-chung", "tt151-to-main-tax-tt"),
    # Thuế
    "thu-vien/thu-tra-soat-c1-11ns-theo-thong-tu-84.html": ("thu-vien", "thue", "mau-bieu-thue", "mau-bang-ke-phu-luc-ho-so", "c1-11ns-to-tax-form-appendix"),
    "thu-vien/cong-van-2065-ct-nvt-su-dung-so-dinh-danh-thay-ma-so-thue.html": ("thu-vien", "thue", "cong-van", "cong-van-ke-khai-quan-ly-thue", "mst-cv-to-ke-khai"),
    "thu-vien/thue-mon-bai-cua-ho-ca-nhan-kinh-doanh.html": ("thu-vien", "thue", "le-phi-mon-bai", "mon-bai-muc-thu-doi-tuong", "hkd-monbai-to-monbai"),
    "thu-vien/huong-dan-cach-ke-khai-thue-theo-phuong-phap-khoan.html": ("thu-vien", "thue", "ho-ca-nhan-kinh-doanh", "hkd-tinh-thue-va-nop-thay", "thue-khoan-to-hkd-tinh-thue"),
    "thu-vien/bao-cao-tinh-hinh-su-dung-bien-lai-dien-tu-thu-thue-phi-le-phi.html": ("thu-vien", "thue", "le-phi-mon-bai", "mon-bai-ke-khai-to-khai", "bien-lai-to-ke-khai"),
    "thu-vien/cach-hach-toan-tien-thue-mon-bai.html": ("thu-vien", "ke-toan", "tai-khoan-hach-toan", "thue-nghia-vu", "monbai-hachtoan-to-tax-obligation"),
    "thu-vien/luat-106-2016-qh13-sua-doi-luat-thue-gtgt-ttdb-quan-ly-thue.html": ("thu-vien", "thue", "gtgt-hoa-don", "thue-suat-doi-tuong", "gtgt-law-to-tax-rate"),
    "thu-vien/luat-thue-gtgt-so-48-2024-qh15-moi-nhat-hien-nay.html": ("thu-vien", "thue", "gtgt-hoa-don", "thue-suat-doi-tuong", "gtgt-law-to-tax-rate"),
    "thu-vien/luat-thue-thu-nhap-doanh-nghiep-so-67-2025-qh15.html": ("thu-vien", "thue", "tndn", "van-ban-chinh-sach", "tndn-law-to-policy"),
    "thu-vien/luat-107-2016-qh13-luat-thue-xuat-thue-nhap-khau.html": ("thu-vien", "thue", "gtgt-hoa-don", "thue-suat-doi-tuong", "xnk-law-to-tax-rate-broad"),
    "thu-vien/quyet-dinh-cua-giam-doc-ve-viec-di-nghi-mat-du-lich.html": ("thu-vien", "doanh-nghiep-thu-tuc", "mau-bieu-doanh-nghiep-thu-tuc", "mau-bieu-quan-tri-noi-bo", "internal-decision-form"),
    "thu-vien/thu-tuc-dang-ky-thue-nha-thau-nha-thau-phu-nuoc-ngoai.html": ("thu-vien", "thue", "thue-nha-thau", "nha-thau-ke-khai-to-khai", "contractor-registration-to-filing"),
    "thu-vien/cach-tinh-thue-nha-thau-nuoc-ngoai-moi-nhat-theo-tt-103.html": ("thu-vien", "thue", "thue-nha-thau", "nha-thau-ke-khai-to-khai", "contractor-calculation-to-filing"),
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_singleton_cleanup", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def article_leaf(article: Dict) -> Tuple[str, str, str, str]:
    return (
        article.get("section") or "",
        article.get("topicLv1Key") or "",
        article.get("topicLv2Key") or "",
        article.get("topicLv3Key") or "",
    )


def build_path_labels(articles: List[Dict]) -> Dict[Tuple[str, str, str, str], Tuple[str, str, str]]:
    labels: Dict[Tuple[str, str, str, str], Tuple[str, str, str]] = {}
    for a in articles:
        key = article_leaf(a)
        labels.setdefault(
            key,
            (
                a.get("topicLv1Label") or "",
                a.get("topicLv2Label") or "",
                a.get("topicLv3Label") or "",
            ),
        )
    return labels


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
    for key in ("section", "sectionLabel", "sectionHref", "topicLv1Key", "topicLv1Label", "topicLv2Key", "topicLv2Label", "topicLv3Key", "topicLv3Label", "cardTopicLabel"):
        meta[key] = article.get(key) or ""
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


def singleton_stats(articles: List[Dict]) -> Tuple[Dict[Tuple[str, str, str, str], List[Dict]], Counter]:
    groups: Dict[Tuple[str, str, str, str], List[Dict]] = defaultdict(list)
    for a in articles:
        if a.get("section") in {"thu-vien", "ban-tin"}:
            groups[article_leaf(a)].append(a)
    singletons = {k: v for k, v in groups.items() if len(v) == 1}
    return singletons, Counter(k[0] for k in singletons)


def main() -> None:
    importer = load_importer_module()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}
    labels_by_path = build_path_labels(data_articles)
    before_singletons, before_by_section = singleton_stats(data_articles)

    applied = []
    skipped = []
    for href, target in MOVES.items():
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-article"})
            continue
        section, lv1, lv2, lv3, reason = target
        target_path = (section, lv1, lv2, lv3)
        if target_path not in labels_by_path:
            skipped.append({"href": href, "reason": "target-path-not-found", "target": target_path})
            continue

        old_path = article_leaf(article)
        lv1_label, lv2_label, lv3_label = labels_by_path[target_path]
        article["section"] = section
        article["sectionLabel"] = "Thư viện" if section == "thu-vien" else "Bản tin"
        article["sectionHref"] = f"{section}.html"
        article["topicLv1Key"] = lv1
        article["topicLv1Label"] = lv1_label
        article["topicLv2Key"] = lv2
        article["topicLv2Label"] = lv2_label
        article["topicLv3Key"] = lv3
        article["topicLv3Label"] = lv3_label
        article["cardTopicLabel"] = lv3_label or lv2_label or lv1_label

        if isinstance(article.get("classificationReasons"), dict):
            article["classificationReasons"]["leafSingletonCleanup"] = reason

        ok, meta_reason = update_meta(ROOT / href, article)
        if not ok:
            skipped.append({"href": href, "reason": meta_reason})
            continue

        applied.append(
            {
                "href": href,
                "title": article.get("title") or "",
                "oldPath": old_path,
                "newPath": target_path,
                "reason": reason,
            }
        )

    importer.write_json(data_articles_path, data_articles)
    counts = rebuild(importer, data_articles)
    after_singletons, after_by_section = singleton_stats(data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "before": {"singletonCount": len(before_singletons), "bySection": dict(before_by_section)},
        "after": {"singletonCount": len(after_singletons), "bySection": dict(after_by_section)},
        "movesPlanned": len(MOVES),
        "applied": len(applied),
        "skipped": skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "remainingSingletons": [
            {"path": list(path), "href": rows[0].get("href"), "title": rows[0].get("title")}
            for path, rows in sorted(after_singletons.items(), key=lambda kv: kv[0])
        ],
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Dọn leaf category singleton - Apply",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Singleton trước: **{len(before_singletons)}** ({dict(before_by_section)})",
        f"- Moves planned: **{len(MOVES)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Singleton sau: **{len(after_singletons)}** ({dict(after_by_section)})",
        f"- Rebuild: Thư viện {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang; Bản tin {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách move",
        "",
        "| # | href | old leaf | new leaf | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['oldPath']}` | `{row['newPath']}` | `{row['reason']}` |"
        )
    lines += ["", "## Singleton còn lại", ""]
    if after_singletons:
        for path, rows in sorted(after_singletons.items(), key=lambda kv: kv[0]):
            lines.append(f"- `{path}`: `{rows[0].get('href')}` — {rows[0].get('title')}")
    else:
        lines.append("- Không còn leaf category nào chỉ có 1 bài.")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(json.dumps({
        "beforeSingletons": len(before_singletons),
        "applied": len(applied),
        "skipped": len(skipped),
        "afterSingletons": len(after_singletons),
        "report": str(OUT_MD.relative_to(ROOT)),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
