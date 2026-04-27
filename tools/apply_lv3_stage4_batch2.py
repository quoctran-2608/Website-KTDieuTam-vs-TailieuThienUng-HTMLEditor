#!/usr/bin/env python3
"""Chặng 4 - Batch 2 apply từ medium/low (rule-based có kiểm soát)."""

from __future__ import annotations

import importlib.util
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
QUEUE_PATH = ROOT / "docs" / "lv3-review-queue-medium-low.json"
APPLY_JSON_PATH = ROOT / "docs" / "lv3-stage4-batch2-apply.json"
APPLY_MD_PATH = ROOT / "docs" / "lv3-stage4-batch2-apply.md"

META_RE = re.compile(
    r'(<script id="article-meta" type="application/json">)(.*?)(</script>)',
    re.IGNORECASE | re.DOTALL,
)


SAFE_SET: Dict[str, str] = {
    # GTGT/Hóa đơn - nhánh xử lý hóa đơn
    "thu-vien/cach-lam-tron-so-khi-viet-hoa-don.html": "lap-xu-ly-hoa-don",
    "thu-vien/cach-viet-hinh-thuc-thanh-toan-tren-hoa-don-gia-tri-gia-tang.html": "lap-xu-ly-hoa-don",
    "thu-vien/ma-hoa-don-cua-cuc-thue-cac-tinh-thanh-pho.html": "lap-xu-ly-hoa-don",
    "thu-vien/nghi-dinh-123-2020-nd-cp-quy-dinh-ve-hoa-don-chung-tu.html": "lap-xu-ly-hoa-don",
    "thu-vien/nghi-dinh-125-2020-nd-cp-quy-dinh-xu-phat-ve-hoa-don-va-thue.html": "lap-xu-ly-hoa-don",
    "thu-vien/nguyen-tac-lap-hoa-don-gia-tri-gia-tang-theo-thong-tu-64.html": "lap-xu-ly-hoa-don",
    "thu-vien/phan-biet-hoa-don-ban-hang-va-hoa-don-gia-tri-gia-tang.html": "lap-xu-ly-hoa-don",
    "thu-vien/phan-biet-xoa-bo-va-huy-hoa-don-tren-bao-cao-su-dung-hoa-don.html": "lap-xu-ly-hoa-don",
    "thu-vien/quy-dinh-ve-tieu-huy-hoa-don-chung-tu-dien-tu-moi-nhat.html": "lap-xu-ly-hoa-don",
    "thu-vien/xu-ly-khi-viet-sai-so-tien-bang-chu-tren-hoa-don-gtgt.html": "lap-xu-ly-hoa-don",
    # GTGT - khấu trừ/hoàn thuế
    "thu-vien/cach-dinh-khoan-thue-gtgt-duoc-khau-tru.html": "khau-tru-hoan-thue",
    "thu-vien/thue-gtgt-vang-lai-co-duoc-khau-tru.html": "khau-tru-hoan-thue",
    # Hóa đơn điện tử
    "thu-vien/nghi-dinh-119-2018-nd-cp-quy-dinh-hoa-don-dien-tu-khi-ban-hang.html": "hoa-don-dien-tu",
    "thu-vien/noi-dung-ghi-tren-hoa-don-gtgt-bat-buoc.html": "hoa-don-dien-tu",
    # Nhóm khác nhưng khá rõ từ title
    "thu-vien/mau-giay-de-nghi-thanh-toan-theo-qd-48-va-15.html": "mau-chung-tu-tien-thanh-toan",
    "thu-vien/muc-phat-khi-lap-va-trinh-bay-bctc-co-sai-sot.html": "nguyen-tac-trinh-bay-bctc",
    "thu-vien/nguyen-tac-chung-tu-ke-toan-lap-ky-noi-dung.html": "chung-tu-cong-tac-thanh-toan",
    "thu-vien/cach-hach-toan-cac-khoan-chi-phi-khac-tai-khoan-811.html": "doanh-thu-chi-phi-kqkd",
}


def load_importer_module():
    module_path = ROOT / "tools" / "import_stage1_20.py"
    spec = importlib.util.spec_from_file_location("importer_stage4b2", module_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không import được module: {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[attr-defined]
    return module


def load_taxonomy_labels() -> Dict[str, str]:
    data = json.loads((ROOT / "data" / "taxonomy.json").read_text(encoding="utf-8"))
    out: Dict[str, str] = {}
    stack = list(data["roots"])
    while stack:
        node = stack.pop()
        if node.get("key") and node.get("label"):
            out[node["key"]] = node["label"]
        for c in node.get("children") or []:
            stack.append(c)
    return out


def update_article_meta_file(article_path: Path, lv3_key: str, lv3_label: str) -> Tuple[bool, str]:
    if not article_path.exists():
        return False, "missing-file"
    html = article_path.read_text(encoding="utf-8", errors="ignore")
    match = META_RE.search(html)
    if not match:
        return False, "missing-article-meta"
    try:
        meta = json.loads(match.group(2))
    except json.JSONDecodeError:
        return False, "invalid-article-meta-json"
    meta["topicLv3Key"] = lv3_key
    meta["topicLv3Label"] = lv3_label
    replaced = html[: match.start(2)] + json.dumps(meta, ensure_ascii=False) + html[match.end(2) :]
    article_path.write_text(replaced, encoding="utf-8")
    return True, "updated"


def rebuild_data(importer, data_articles: List[Dict]) -> Dict[str, int]:
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
    index_data = importer.build_content_index(records_by_section)
    importer.write_content_index(index_data)
    importer.write_data_artifacts(records_by_section, index_data, page_maps)
    importer.write_taxonomy_data(records_by_section)
    importer.write_sitemap(index_data, page_maps)
    return {
        "thu_vien_count": len(records_by_section["thu-vien"]),
        "ban_tin_count": len(records_by_section["ban-tin"]),
        "thu_vien_pages": len(page_maps["thu-vien"]),
        "ban_tin_pages": len(page_maps["ban-tin"]),
    }


def main() -> None:
    importer = load_importer_module()
    labels = load_taxonomy_labels()
    queue = json.loads(QUEUE_PATH.read_text(encoding="utf-8")).get("records", [])
    queue_by_href = {row["href"]: row for row in queue}

    data_articles_path = importer.DATA_DIR / "articles.json"
    data_articles: List[Dict] = importer.read_json(data_articles_path)
    article_map = {a["href"]: a for a in data_articles}

    candidates: List[Dict] = []
    skipped: List[Dict] = []

    for href, lv3_key in SAFE_SET.items():
        row = queue_by_href.get(href)
        if not row:
            skipped.append({"href": href, "reason": "not-in-medium-low-queue"})
            continue
        article = article_map.get(href)
        if not article:
            skipped.append({"href": href, "reason": "missing-data-article"})
            continue
        if article.get("section") != "thu-vien":
            skipped.append({"href": href, "reason": "not-thu-vien"})
            continue
        if (article.get("topicLv3Key") or "").strip():
            skipped.append({"href": href, "reason": "already-has-lv3"})
            continue
        current_lv2 = article.get("topicLv2Key") or ""
        if current_lv2 != row.get("topicLv2Key"):
            skipped.append(
                {"href": href, "reason": f"lv2-mismatch article={current_lv2} queue={row.get('topicLv2Key')}"}
            )
            continue
        queue_suggest = row.get("suggestedTopicLv3Key") or ""
        if queue_suggest and queue_suggest != lv3_key:
            skipped.append(
                {"href": href, "reason": f"override-diff queue={queue_suggest} set={lv3_key}"}
            )
            continue
        candidates.append(
            {
                "href": href,
                "topicLv2Key": current_lv2,
                "topicLv3Key": lv3_key,
                "topicLv3Label": labels.get(lv3_key) or "",
                "confidence": row.get("confidence"),
                "score": row.get("score", 0),
                "rationale": row.get("rationale", ""),
            }
        )

    applied: List[Dict] = []
    for cand in candidates:
        article = article_map[cand["href"]]
        article["topicLv3Key"] = cand["topicLv3Key"]
        article["topicLv3Label"] = cand["topicLv3Label"]
        applied.append(cand)

    importer.write_json(data_articles_path, data_articles)

    meta_updated = 0
    meta_skipped = []
    for row in applied:
        ok, reason = update_article_meta_file(ROOT / row["href"], row["topicLv3Key"], row["topicLv3Label"])
        if ok:
            meta_updated += 1
        else:
            meta_skipped.append({"href": row["href"], "reason": reason})

    counts = rebuild_data(importer, data_articles)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "policy": "stage4-batch2-safe-allowlist",
        "allowSetSize": len(SAFE_SET),
        "candidateCount": len(candidates),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "articleMetaUpdated": meta_updated,
        "articleMetaSkipped": meta_skipped,
        "countsAfterRebuild": counts,
        "appliedRows": applied,
        "skippedRows": skipped,
    }
    APPLY_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Chặng 4 - Batch 2 apply từ medium/low (allowlist an toàn)",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Allowlist cứng: **{len(SAFE_SET)}** href",
        f"- Candidate pass điều kiện: **{len(candidates)}**",
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
        "| # | href | lv2 | lv3 | score | rationale |",
        "|---:|---|---|---|---:|---|",
    ]
    for idx, row in enumerate(applied, 1):
        lines.append(
            f"| {idx} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['topicLv3Key']}` | {row['score']} | {row['rationale']} |"
        )
    APPLY_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "allowSetSize": len(SAFE_SET),
                "candidateCount": len(candidates),
                "appliedCount": len(applied),
                "articleMetaUpdated": meta_updated,
                "countsAfterRebuild": counts,
                "applyLog": str(APPLY_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
