#!/usr/bin/env python3
"""Chặng 26 - Chuẩn hóa topicLv1/topicLv2 cho 63 bài Ban-tin import."""

from __future__ import annotations

import importlib.util
import json
import re
import unicodedata
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
OUT_JSON_PATH = ROOT / "docs" / "ban-tin-stage26-normalize.json"
OUT_MD_PATH = ROOT / "docs" / "ban-tin-stage26-normalize.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage26_bantin", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def fold_text(value: str) -> str:
    text = (value or "").lower()
    text = "".join(ch for ch in unicodedata.normalize("NFD", text) if unicodedata.category(ch) != "Mn")
    return text.replace("đ", "d").strip()


def taxonomy_bantin_maps() -> Tuple[Dict[str, str], Dict[Tuple[str, str], str]]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    ban_tin_root = next((r for r in data.get("roots", []) if r.get("key") == "ban-tin"), None)
    if not ban_tin_root:
        raise RuntimeError("Không tìm thấy root ban-tin trong taxonomy.json")

    lv1_labels: Dict[str, str] = {}
    lv2_labels: Dict[Tuple[str, str], str] = {}
    for lv1 in ban_tin_root.get("children") or []:
        lv1_key = lv1.get("key") or ""
        lv1_label = lv1.get("label") or ""
        if not lv1_key:
            continue
        lv1_labels[lv1_key] = lv1_label
        for lv2 in lv1.get("children") or []:
            lv2_key = lv2.get("key") or ""
            lv2_label = lv2.get("label") or ""
            lv2_labels[(lv1_key, lv2_key)] = lv2_label
    return lv1_labels, lv2_labels


def classify_bantin(title: str, current_lv1: str, current_lv2: str) -> Tuple[str, str, str]:
    """Rule bảo thủ:
    - Tiêu đề có 'tuyển'/'thực tập' => Thông báo tuyển dụng
    - Tiêu đề bắt đầu bằng Khóa học/Lớp học => Khóa học & đào tạo + nhánh chi tiết
    - Còn lại giữ nguyên.
    """
    t = fold_text(title)
    if "tuyen" in t or "thuc tap" in t:
        return "thong-bao-tuyen-dung", "", "rule-job-posting"

    if t.startswith("khoa hoc") or t.startswith("lop hoc"):
        if "misa" in t:
            return "khoa-hoc-dao-tao", "khoa-hoc-misa", "rule-course-misa"
        if "excel" in t:
            return "khoa-hoc-dao-tao", "khoa-hoc-excel", "rule-course-excel"
        if "thue" in t:
            return "khoa-hoc-dao-tao", "khoa-hoc-thue", "rule-course-thue"
        return "khoa-hoc-dao-tao", "khoa-hoc-ke-toan", "rule-course-ke-toan"

    return current_lv1, current_lv2, "rule-keep"


def update_meta(
    path: Path,
    lv1_key: str,
    lv1_label: str,
    lv2_key: str,
    lv2_label: str,
) -> Tuple[bool, str]:
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

    meta["topicLv1Key"] = lv1_key
    meta["topicLv1Label"] = lv1_label
    meta["topicLv2Key"] = lv2_key
    meta["topicLv2Label"] = lv2_label
    meta["topicLv3Key"] = ""
    meta["topicLv3Label"] = ""
    meta["cardTopicLabel"] = lv2_label or lv1_label

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
    lv1_labels, lv2_labels = taxonomy_bantin_maps()
    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)

    import_log = json.loads((ROOT / "docs" / "update-800-bai-import-log.json").read_text(encoding="utf-8"))
    imported_hrefs = {item["target_path"] for b in import_log.get("batches", []) for item in b.get("imported", [])}

    targets = [a for a in data_articles if a.get("href") in imported_hrefs and a.get("section") == "ban-tin"]

    changed = []
    unchanged = []
    invalid = []

    for a in targets:
        old_lv1 = (a.get("topicLv1Key") or "").strip()
        old_lv2 = (a.get("topicLv2Key") or "").strip()
        new_lv1, new_lv2, reason = classify_bantin(a.get("title") or "", old_lv1, old_lv2)
        if new_lv1 not in lv1_labels:
            invalid.append({"href": a.get("href"), "reason": "invalid-lv1", "lv1": new_lv1})
            continue
        if (new_lv1, new_lv2) not in lv2_labels:
            invalid.append({"href": a.get("href"), "reason": "invalid-lv2-under-lv1", "lv1": new_lv1, "lv2": new_lv2})
            continue

        new_lv1_label = lv1_labels[new_lv1]
        new_lv2_label = lv2_labels[(new_lv1, new_lv2)]

        changed_flag = old_lv1 != new_lv1 or old_lv2 != new_lv2
        a["topicLv1Key"] = new_lv1
        a["topicLv1Label"] = new_lv1_label
        a["topicLv2Key"] = new_lv2
        a["topicLv2Label"] = new_lv2_label
        a["topicLv3Key"] = ""
        a["topicLv3Label"] = ""
        a["cardTopicLabel"] = new_lv2_label or new_lv1_label

        row = {
            "href": a.get("href"),
            "oldLv1": old_lv1,
            "oldLv2": old_lv2,
            "newLv1": new_lv1,
            "newLv2": new_lv2,
            "reason": reason,
        }
        if changed_flag:
            changed.append(row)
        else:
            unchanged.append(row)

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for a in targets:
        ok, reason = update_meta(
            ROOT / (a.get("href") or ""),
            a.get("topicLv1Key") or "",
            a.get("topicLv1Label") or "",
            a.get("topicLv2Key") or "",
            a.get("topicLv2Label") or "",
        )
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": a.get("href"), "reason": reason})

    counts = rebuild(importer, data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "targets": len(targets),
        "changed": len(changed),
        "unchanged": len(unchanged),
        "invalid": invalid,
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "changedRows": changed,
    }
    OUT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 26 - Chuẩn hóa topicLv1/topicLv2 cho Ban-tin import",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Targets (ban-tin imported): **{len(targets)}**",
        f"- Changed: **{len(changed)}**",
        f"- Unchanged: **{len(unchanged)}**",
        f"- Invalid taxonomy rows: **{len(invalid)}**",
        f"- Cập nhật article-meta HTML: **{meta_updated}**",
        "",
        "## Quy mô sau rebuild",
        "",
        f"- Thư viện: {counts['thu_vien_count']} bài / {counts['thu_vien_pages']} trang",
        f"- Bản tin: {counts['ban_tin_count']} bài / {counts['ban_tin_pages']} trang",
        "",
        "## Danh sách changed",
        "",
        "| # | href | old lv1/lv2 | new lv1/lv2 | reason |",
        "|---:|---|---|---|---|",
    ]
    for i, row in enumerate(changed, 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['oldLv1']}` / `{row['oldLv2']}` | `{row['newLv1']}` / `{row['newLv2']}` | `{row['reason']}` |"
        )
    OUT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "targets": len(targets),
                "changed": len(changed),
                "unchanged": len(unchanged),
                "invalid": len(invalid),
                "articleMetaUpdated": meta_updated,
                "applyLog": str(OUT_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
