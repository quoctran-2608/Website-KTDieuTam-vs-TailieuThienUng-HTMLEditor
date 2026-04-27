#!/usr/bin/env python3
"""Chặng 15 - Apply rule cho 2 cụm lớn:
- thu-tuc-doanh-nghiep
- kinh-nghiem-hoi-dap-nghe-nghiep

Mục tiêu: áp dụng bán-tự động cho các dòng có tín hiệu rõ, để giảm queue low/taxonomy-first.
"""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON_PATH = ROOT / "docs" / "lv3-stage15-apply.json"
OUT_MD_PATH = ROOT / "docs" / "lv3-stage15-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage15", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def taxonomy_labels() -> Dict[str, str]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    labels: Dict[str, str] = {}
    stack = list(data.get("roots", []))
    while stack:
        node = stack.pop()
        k, lb = node.get("key"), node.get("label")
        if k and lb:
            labels[k] = lb
        stack.extend(node.get("children") or [])
    return labels


def update_meta(path: Path, lv3_key: str, lv3_label: str) -> Tuple[bool, str]:
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
    meta["topicLv3Key"] = lv3_key
    meta["topicLv3Label"] = lv3_label
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


def decide_lv3(topic_lv2: str, title: str, href: str) -> Tuple[str, str]:
    text = f"{title} {href}".lower()

    if topic_lv2 == "thu-tuc-doanh-nghiep":
        if any(
            k in text
            for k in [
                "thue mon bai",
                "môn bài",
                "mon-bai",
                "thue-suat",
                "thue thu nhap doanh nghiep",
                "tndn",
                "gia han nop thue",
                "mien thue",
                "chi phi duoc tru",
                "thu nhap chiu thue",
            ]
        ):
            return "dn-thu-tuc-tai-chinh-vay-von", "rule-thu-tuc-tax-finance"
        if any(
            k in text
            for k in [
                "to chuc bo may ke toan",
                "ke toan truong",
                "he thong so sach",
                "bo nhiem",
                "khau hao",
                "nguyen gia tscd",
                "tai san co dinh",
                "hach toan 821",
                "hach toan 153",
                "von chu so huu",
            ]
        ):
            return "dn-thu-tuc-quan-tri-ho-tro", "rule-thu-tuc-governance"
        if any(k in text for k in ["phan mem", "thanh lap", "thay doi doanh nghiep"]):
            return "dn-thu-tuc-thanh-lap-thay-doi", "rule-thu-tuc-establish-change"
        if any(k in text for k in ["tien phat", "vi pham hop dong", "khuyen mai", "thuong mai"]):
            return "dn-thu-tuc-khuyen-mai-thuong-mai", "rule-thu-tuc-penalty-commercial"
        return "", "no-match-thu-tuc"

    if topic_lv2 == "kinh-nghiem-hoi-dap-nghe-nghiep":
        if any(k in text for k in ["tim viec", "xin viec", "phong van", "sat hach", "chung chi hanh nghe"]):
            return "kinh-nghiem-phong-van-xin-viec", "rule-career-job"
        if any(k in text for k in ["cong viec cua", "mo ta cong viec"]):
            return "mo-ta-cong-viec-ke-toan", "rule-career-job-desc"
        if any(
            k in text
            for k in [
                "thue",
                "to khai",
                "ngan sach",
                "le phi",
                "nop tien",
                "khai bo sung",
                "khbs",
                "gia han nop thue",
                "nsnn",
                "tbvmt",
                "tain",
                "co quan thue",
                "du an bat dong san",
                "hoi cho",
                "thuong mai",
            ]
        ):
            return "kinh-nghiem-quyet-toan-thue", "rule-career-tax-process"
        return "", "no-match-kinh-nghiem"

    return "", "unsupported-topic-lv2"


def main() -> None:
    importer = load_importer_module()
    labels = taxonomy_labels()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)

    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}

    targets = [
        a
        for a in data_articles
        if a.get("href") in imported_hrefs
        and a.get("section") == "thu-vien"
        and (a.get("topicLv2Key") or "") in {"thu-tuc-doanh-nghiep", "kinh-nghiem-hoi-dap-nghe-nghiep"}
        and not (a.get("topicLv3Key") or "").strip()
    ]

    applied = []
    skipped = []
    for a in targets:
        lv3_key, reason = decide_lv3(a.get("topicLv2Key") or "", a.get("title") or "", a.get("href") or "")
        if not lv3_key:
            skipped.append({"href": a.get("href"), "topicLv2Key": a.get("topicLv2Key"), "reason": reason})
            continue
        a["topicLv3Key"] = lv3_key
        a["topicLv3Label"] = labels.get(lv3_key) or ""
        applied.append(
            {
                "href": a.get("href"),
                "topicLv2Key": a.get("topicLv2Key"),
                "topicLv3Key": lv3_key,
                "topicLv3Label": a.get("topicLv3Label"),
                "reason": reason,
            }
        )

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for row in applied:
        ok, reason = update_meta(ROOT / row["href"], row["topicLv3Key"], row["topicLv3Label"])
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": row["href"], "reason": reason})

    counts = rebuild(importer, data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "scope": {"targets": len(targets), "applied": len(applied), "skipped": len(skipped)},
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "skippedRows": skipped,
    }
    OUT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 15 - Apply rule cho cụm Thu tục DN + Kinh nghiệm/Hỏi đáp",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Targets: **{len(targets)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Cập nhật article-meta HTML: **{meta_updated}**",
        "",
        "## Quy mô sau rebuild",
        "",
        f"- Thư viện: {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang",
        f"- Bản tin: {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách applied",
        "",
        "| # | href | lv2 | lv3 | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` | `{row['reason']}` |"
        )

    lines += ["", "## Danh sách skipped", "", "| # | href | lv2 | reason |", "|---:|---|---|---|"]
    for i, row in enumerate(skipped, 1):
        lines.append(f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['reason']}` |")

    OUT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "targets": len(targets),
                "applied": len(applied),
                "skipped": len(skipped),
                "articleMetaUpdated": meta_updated,
                "countsAfterRebuild": counts,
                "applyLog": str(OUT_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
