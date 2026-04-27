#!/usr/bin/env python3
"""Chặng 21 - Rule-based apply cho cụm Thu tục DN + Kinh nghiệm/Hỏi đáp."""

from __future__ import annotations

import importlib.util
import json
import re
import unicodedata
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON_PATH = ROOT / "docs" / "lv3-stage21-apply.json"
OUT_MD_PATH = ROOT / "docs" / "lv3-stage21-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def norm(text: str) -> str:
    text = (text or "").lower()
    text = "".join(ch for ch in unicodedata.normalize("NFD", text) if unicodedata.category(ch) != "Mn")
    return text.replace("đ", "d")


def has_any(text: str, needles: List[str]) -> bool:
    return any(n in text for n in needles)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage21", module_path)
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
    t = norm(f"{title} {href}")

    if topic_lv2 == "thu-tuc-doanh-nghiep":
        if has_any(t, ["ho tro doanh nghiep nho va vua", "nghi dinh 39", "nghi dinh 80", "bo nhiem ke toan truong"]):
            return "dn-thu-tuc-quan-tri-ho-tro", "rule-thu-tuc-quan-tri-ho-tro"
        if has_any(t, ["xu ly tien phat", "vi pham hop dong kinh te"]):
            return "dn-thu-tuc-khuyen-mai-thuong-mai", "rule-thu-tuc-khuyen-mai-thuong-mai"
        if has_any(
            t,
            [
                "tscd",
                "tai san co dinh",
                "khau hao",
                "hach toan",
                "tai khoan 821",
                "tai khoan 153",
                "thue thu nhap doanh nghiep",
                "doanh thu de tinh thu nhap chiu thue",
                "nguyen tac ke toan",
                "von chu so huu",
                "he thong so sach ke toan",
                "dat in va thong bao phat hanh hoa don",
            ],
        ):
            return "dn-thu-tuc-tai-chinh-vay-von", "rule-thu-tuc-tai-chinh-vay-von"
        return "", "no-match-thu-tuc-doanh-nghiep"

    if topic_lv2 == "kinh-nghiem-hoi-dap-nghe-nghiep":
        if has_any(t, ["cach lap the tinh gia thanh san pham", "thong tu 200", "tt 200", "thong tu 133"]):
            return "hoc-va-dao-tao-ke-toan", "rule-kinh-nghiem-hoc-dao-tao"
        if has_any(
            t,
            [
                "to khai thue",
                "bang ke",
                "hoa don",
                "le phi truoc ba",
                "ca nhan cu tru",
                "nguoi nop thue",
                "nop tien vao ngan sach",
                "thue cua to chuc khai thay",
                "bien ban xac minh tinh trang hoat dong",
            ],
        ):
            return "kinh-nghiem-quyet-toan-thue", "rule-kinh-nghiem-quyet-toan-thue"
        return "", "no-match-kinh-nghiem"

    return "", "unsupported-topic-lv2"


def write_final_summary(data_articles: List[Dict]) -> None:
    final_md = ROOT / "docs" / "lv3-final-summary.md"
    final_json = ROOT / "docs" / "lv3-final-summary.json"
    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}
    imported_rows = [a for a in data_articles if a.get("href") in imported_hrefs]

    total = len(imported_rows)
    non_empty = sum(1 for a in imported_rows if (a.get("topicLv3Key") or "").strip())
    empty = total - non_empty
    coverage = round((non_empty / total) * 100, 2) if total else 0.0

    from collections import Counter

    remaining = [a for a in imported_rows if not (a.get("topicLv3Key") or "").strip() and a.get("section") == "thu-vien"]
    by_lv2 = Counter((a.get("topicLv2Key") or "") for a in remaining)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "imported745": {"total": total, "topicLv3NonEmpty": non_empty, "topicLv3Empty": empty, "coveragePercent": coverage},
        "remainingThuVienByLv2": dict(by_lv2),
    }
    final_json.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Tổng kết tiến độ gán LV3 cho 745 bài import",
        "",
        f"- Thời gian cập nhật: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Tổng bài import: **{total}**",
        f"- Đã có topicLv3: **{non_empty}**",
        f"- Còn trống topicLv3: **{empty}**",
        f"- Coverage: **{coverage}%**",
        "",
        "## Còn trống theo LV2 (Thu viện)",
        "",
    ]
    for k, v in by_lv2.most_common():
        lines.append(f"- `{k}`: {v}")
    final_md.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    importer = load_importer_module()
    labels = taxonomy_labels()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)

    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}

    target_lv2 = {"thu-tuc-doanh-nghiep", "kinh-nghiem-hoi-dap-nghe-nghiep"}
    targets = [
        a
        for a in data_articles
        if a.get("href") in imported_hrefs
        and a.get("section") == "thu-vien"
        and (a.get("topicLv2Key") or "") in target_lv2
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
    write_final_summary(data_articles)

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
        "# Chặng 21 - Apply rule cụm Thu tục DN + Kinh nghiệm/Hỏi đáp",
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
                "finalSummary": "docs/lv3-final-summary.md",
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
