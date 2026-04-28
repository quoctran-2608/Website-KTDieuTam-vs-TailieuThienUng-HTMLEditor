#!/usr/bin/env python3
"""Sửa lỗi thumbnail hỏng + reclassify bài CV lọt sang Bản tin."""

from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
DATA_ARTICLES = ROOT / "data" / "articles.json"

OUT_JSON = ROOT / "docs" / "thumbnail-fix-and-cv-reclassify.json"
OUT_MD = ROOT / "docs" / "thumbnail-fix-and-cv-reclassify.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


TARGET_HREF = "ban-tin/mau-cv-xin-viec-cho-ke-toan-moi-ra-truong-hay-nhat.html"
TARGET_IMAGE = "assets/images/content/pic/Service/images/mau CV xin viec ke toan.jpg"
DEFAULT_IMAGE = "assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg"

TARGET_CLASSIFICATION = {
    "section": "thu-vien",
    "sectionLabel": "Thư viện",
    "sectionHref": "thu-vien.html",
    "libraryKindKey": "bieu-mau",
    "libraryKindLabel": "Biểu mẫu",
    "topicLv1Key": "ke-toan",
    "topicLv2Key": "mau-bieu-ke-toan",
    "topicLv3Key": "mau-hanh-chinh-quan-tri-khac",
}

IMG_EXTS = {
    ".jpg",
    ".jpeg",
    ".png",
    ".gif",
    ".webp",
    ".bmp",
    ".svg",
    ".avif",
    ".ico",
    ".jfif",
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_thumb_fix", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def resolve_path(raw_path: str) -> Path:
    path = raw_path or ""
    if path.startswith("./"):
        path = path[2:]
    if path.startswith("/"):
        path = path[1:]
    return ROOT / path


def detect_image_issue(raw_path: str) -> str:
    if not raw_path:
        return "empty"
    path = resolve_path(raw_path)
    if not path.exists():
        return "missing"
    if path.is_dir():
        return "is-dir"
    try:
        head = path.read_bytes()[:1024]
    except Exception as exc:  # pragma: no cover
        return f"read-error:{exc.__class__.__name__}"
    if not head:
        return "empty"

    # Binary signatures
    if head.startswith(b"\xFF\xD8\xFF"):
        return ""
    if head.startswith(b"\x89PNG\r\n\x1A\n"):
        return ""
    if head.startswith(b"GIF87a") or head.startswith(b"GIF89a"):
        return ""
    if head.startswith(b"RIFF") and head[8:12] == b"WEBP":
        return ""
    if head.startswith(b"BM"):
        return ""
    if head[:4] == b"\x00\x00\x01\x00":
        return ""

    lower_head = head.lower()
    if b"<svg" in lower_head:
        return ""
    if b"<html" in lower_head or b"<!doctype" in lower_head:
        return "html-content"
    if b"function " in lower_head or b"var " in lower_head or b"const " in lower_head:
        return "script-content"
    return "unknown-bytes"


def is_valid_image(path_str: str) -> bool:
    return detect_image_issue(path_str) == ""


def build_valid_image_index() -> Dict[str, List[str]]:
    index: Dict[str, List[str]] = {}
    assets_root = ROOT / "assets"
    for file_path in assets_root.rglob("*"):
        if not file_path.is_file():
            continue
        if file_path.suffix.lower() not in IMG_EXTS:
            continue
        rel = file_path.relative_to(ROOT).as_posix()
        if not is_valid_image(rel):
            continue
        index.setdefault(file_path.name.lower(), []).append(rel)
    return index


def pick_replacement_image(article: Dict, reason: str, valid_index: Dict[str, List[str]]) -> Tuple[str, str]:
    href = article.get("href") or ""
    current = article.get("image") or ""
    basename = Path(current).name.lower()

    # Case đặc biệt theo user báo lỗi.
    if href == TARGET_HREF and is_valid_image(TARGET_IMAGE):
        return TARGET_IMAGE, "target-cv-fix"

    # Exact basename match tới file ảnh hợp lệ.
    candidates = valid_index.get(basename, [])
    if candidates:
        candidates = sorted(
            candidates,
            key=lambda p: (0 if "/pic/Service/images/" in p else 1, len(p), p),
        )
        return candidates[0], "exact-basename-remap"

    # Map từ nhánh ảnh import lỗi về nhánh ảnh service chuẩn.
    if basename:
        service_candidate = f"assets/images/content/pic/Service/images/{Path(current).name}"
        if is_valid_image(service_candidate):
            return service_candidate, "service-path-remap"

    # Không suy luận mạo hiểm: fallback ảnh mặc định.
    return DEFAULT_IMAGE, f"default-fallback:{reason}"


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


def apply_target_reclassification(article: Dict, lv1: Dict[str, str], lv2: Dict[str, str], lv3: Dict[str, str]) -> None:
    article["section"] = TARGET_CLASSIFICATION["section"]
    article["sectionLabel"] = TARGET_CLASSIFICATION["sectionLabel"]
    article["sectionHref"] = TARGET_CLASSIFICATION["sectionHref"]
    article["libraryKindKey"] = TARGET_CLASSIFICATION["libraryKindKey"]
    article["libraryKindLabel"] = TARGET_CLASSIFICATION["libraryKindLabel"]
    article["cardBadgeLabel"] = TARGET_CLASSIFICATION["libraryKindLabel"]

    lv1_key = TARGET_CLASSIFICATION["topicLv1Key"]
    lv2_key = TARGET_CLASSIFICATION["topicLv2Key"]
    lv3_key = TARGET_CLASSIFICATION["topicLv3Key"]
    article["topicLv1Key"] = lv1_key
    article["topicLv2Key"] = lv2_key
    article["topicLv3Key"] = lv3_key
    article["topicLv1Label"] = lv1.get(lv1_key, "Kế toán")
    article["topicLv2Label"] = lv2.get(lv2_key, "Mẫu biểu kế toán")
    article["topicLv3Label"] = lv3.get(lv3_key, "Mẫu hành chính/quản trị khác")
    article["cardTopicLabel"] = article["topicLv2Label"]


def update_article_meta(article: Dict) -> Tuple[bool, str]:
    path = ROOT / (article.get("href") or "")
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
        "image",
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

    # Giữ tương thích fallback legacy trong article-layout.
    meta["sectionKey"] = article.get("section") or ""
    meta["topicLabel"] = article.get("topicLv2Label") or article.get("topicLv1Label") or ""

    patched = html[: m.start(2)] + json.dumps(meta, ensure_ascii=False) + html[m.end(2) :]
    path.write_text(patched, encoding="utf-8")
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
    data_articles: List[Dict] = importer.read_json(DATA_ARTICLES)
    lv1_labels, lv2_labels, lv3_labels = build_label_maps(data_articles)

    valid_index = build_valid_image_index()

    before_issues = []
    applied_image_fixes = []
    applied_reclass = []
    skipped_meta = []

    for article in data_articles:
        issue = detect_image_issue(article.get("image") or "")
        if issue:
            before_issues.append(
                {"href": article["href"], "image": article.get("image"), "reason": issue}
            )
            new_image, how = pick_replacement_image(article, issue, valid_index)
            old_image = article.get("image") or ""
            article["image"] = new_image
            applied_image_fixes.append(
                {
                    "href": article["href"],
                    "reason": issue,
                    "oldImage": old_image,
                    "newImage": new_image,
                    "strategy": how,
                }
            )

    # Reclassify target CV article from Bản tin -> Thư viện/Biểu mẫu.
    target = next((a for a in data_articles if a.get("href") == TARGET_HREF), None)
    if target:
        before = {
            "section": target.get("section") or "",
            "libraryKindKey": target.get("libraryKindKey") or "",
            "topicLv1Key": target.get("topicLv1Key") or "",
            "topicLv2Key": target.get("topicLv2Key") or "",
            "topicLv3Key": target.get("topicLv3Key") or "",
        }
        apply_target_reclassification(target, lv1_labels, lv2_labels, lv3_labels)
        after = {
            "section": target.get("section") or "",
            "libraryKindKey": target.get("libraryKindKey") or "",
            "topicLv1Key": target.get("topicLv1Key") or "",
            "topicLv2Key": target.get("topicLv2Key") or "",
            "topicLv3Key": target.get("topicLv3Key") or "",
        }
        applied_reclass.append({"href": TARGET_HREF, "before": before, "after": after})
        if isinstance(target.get("classificationReasons"), dict):
            target["classificationReasons"]["manualFixCVMisclassified"] = "move-ban-tin-to-thu-vien-bieu-mau"

    # Sync meta for touched articles + target.
    touched_hrefs = {row["href"] for row in applied_image_fixes}
    if target:
        touched_hrefs.add(TARGET_HREF)
    for href in sorted(touched_hrefs):
        article = next((a for a in data_articles if a.get("href") == href), None)
        if not article:
            continue
        ok, reason = update_article_meta(article)
        if not ok:
            skipped_meta.append({"href": href, "reason": reason})

    importer.write_json(DATA_ARTICLES, data_articles)
    rebuild_counts = rebuild(importer, data_articles)

    after_issues = []
    for article in data_articles:
        issue = detect_image_issue(article.get("image") or "")
        if issue:
            after_issues.append(
                {"href": article["href"], "image": article.get("image"), "reason": issue}
            )

    strategy_counter = Counter(item["strategy"] for item in applied_image_fixes)
    before_reason_counter = Counter(item["reason"] for item in before_issues)
    after_reason_counter = Counter(item["reason"] for item in after_issues)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "targetHref": TARGET_HREF,
        "before": {
            "brokenThumbnailCount": len(before_issues),
            "reasonCounts": dict(before_reason_counter),
        },
        "after": {
            "brokenThumbnailCount": len(after_issues),
            "reasonCounts": dict(after_reason_counter),
        },
        "imageFixes": {
            "appliedCount": len(applied_image_fixes),
            "strategyCounts": dict(strategy_counter),
            "applied": applied_image_fixes,
        },
        "reclassification": applied_reclass,
        "metaSkipped": skipped_meta,
        "rebuild": rebuild_counts,
        "remainingIssues": after_issues,
    }
    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Fix thumbnail hỏng + reclassify bài CV",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Broken thumbnail trước: **{len(before_issues)}** ({dict(before_reason_counter)})",
        f"- Thumbnail đã sửa: **{len(applied_image_fixes)}** ({dict(strategy_counter)})",
        f"- Broken thumbnail sau: **{len(after_issues)}** ({dict(after_reason_counter)})",
        f"- Reclassify target: **{len(applied_reclass)}**",
        f"- Meta skipped: **{len(skipped_meta)}**",
        f"- Rebuild: Thư viện {rebuild_counts['thu_vien_count']} bài / {rebuild_counts['thu_vien_pages']} trang; Bản tin {rebuild_counts['ban_tin_count']} bài / {rebuild_counts['ban_tin_pages']} trang",
        "",
        "## Target reclassify",
        "",
    ]
    if applied_reclass:
        row = applied_reclass[0]
        lines.extend(
            [
                f"- href: `{row['href']}`",
                f"- before: `{row['before']}`",
                f"- after: `{row['after']}`",
            ]
        )
    lines.extend(
        [
            "",
            "## Các chiến lược sửa thumbnail",
            "",
            "| Strategy | Count |",
            "|---|---:|",
        ]
    )
    for strategy, count in sorted(strategy_counter.items(), key=lambda kv: (-kv[1], kv[0])):
        lines.append(f"| `{strategy}` | {count} |")
    OUT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "beforeBroken": len(before_issues),
                "fixed": len(applied_image_fixes),
                "afterBroken": len(after_issues),
                "reclassified": len(applied_reclass),
                "report": str(OUT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
