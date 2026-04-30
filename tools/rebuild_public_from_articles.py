#!/usr/bin/env python3
from __future__ import annotations

import argparse
import importlib.util
import json
import math
import sys
from pathlib import Path
from typing import Any, Dict, List


ROOT = Path(__file__).resolve().parents[1]
IMPORT_TOOL = ROOT / "tools" / "import_stage1_20.py"
TAXONOMY_ADMIN_TOOL = ROOT / "tools" / "manage_taxonomy.py"
sys.dont_write_bytecode = True


def load_import_tool():
    spec = importlib.util.spec_from_file_location("kdt_import_stage1_20", IMPORT_TOOL)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không thể load tool import từ {IMPORT_TOOL}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_taxonomy_admin_tool():
    spec = importlib.util.spec_from_file_location("kdt_manage_taxonomy", TAXONOMY_ADMIN_TOOL)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không thể load tool taxonomy từ {TAXONOMY_ADMIN_TOOL}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def as_text(value: Any) -> str:
    return "" if value is None else str(value)


def as_list(value: Any) -> List[Any]:
    return value if isinstance(value, list) else []


def article_to_record(item: Dict[str, Any], catalog_index: int, feature_image_path: str) -> Dict[str, Any]:
    href = as_text(item.get("href") or item.get("id")).strip()
    section = as_text(item.get("section")).strip()
    if not href:
        raise RuntimeError("Bài viết thiếu href/id trong data/articles.json")
    if section not in {"thu-vien", "ban-tin"}:
        raise RuntimeError(f"Bài viết {href} có section không hợp lệ: {section}")

    image = as_text(item.get("image")).strip() or feature_image_path
    return {
        "file": Path(href).name.replace(".html", ".htm"),
        "target_root": href,
        "section": section,
        "title": as_text(item.get("title")).strip(),
        "excerpt": as_text(item.get("excerpt")).strip(),
        "content_html": "",
        "topic_lv1_key": as_text(item.get("topicLv1Key")).strip(),
        "topic_lv1_label": as_text(item.get("topicLv1Label")).strip(),
        "topic_lv2_key": as_text(item.get("topicLv2Key")).strip(),
        "topic_lv2_label": as_text(item.get("topicLv2Label")).strip(),
        "topic_lv3_key": as_text(item.get("topicLv3Key")).strip(),
        "topic_lv3_label": as_text(item.get("topicLv3Label")).strip(),
        "tags": [str(tag).strip() for tag in as_list(item.get("tags")) if str(tag).strip()],
        "display_badge": as_text(item.get("cardBadgeLabel")).strip(),
        "display_topic": as_text(item.get("cardTopicLabel")).strip(),
        "library_kind_key": as_text(item.get("libraryKindKey")).strip(),
        "library_kind_label": as_text(item.get("libraryKindLabel")).strip(),
        "tool_lv3_key": as_text(item.get("toolLv3Key")).strip(),
        "tool_lv3_label": as_text(item.get("toolLv3Label")).strip(),
        "publish_date": as_text(item.get("publishDate")).strip(),
        "modified_date": item.get("modifiedDate"),
        "author_name": as_text(item.get("authorName")).strip() or "Kế Toán Diệu Tâm",
        "author_type": as_text(item.get("authorType")).strip() or "Organization",
        "hub_image": image,
        "classification_primary": item.get("primarySection"),
        "classification_secondary": as_list(item.get("secondarySections")),
        "classification_reasons": item.get("classificationReasons") if isinstance(item.get("classificationReasons"), dict) else {},
        "classification_legacy_primary": item.get("legacyPrimarySection"),
        "classification_legacy_secondary": as_list(item.get("legacySecondarySections")),
        "legacy_href": as_text(item.get("legacyHref")).strip(),
        "article_href": as_text(item.get("articleHref")).strip(),
        "catalog_index": catalog_index,
    }


def build_records_by_section(tool) -> Dict[str, List[Dict[str, Any]]]:
    data_articles = tool.read_json(tool.DATA_DIR / "articles.json")
    if not isinstance(data_articles, list):
        raise RuntimeError("data/articles.json phải là array")

    records_by_section: Dict[str, List[Dict[str, Any]]] = {"thu-vien": [], "ban-tin": []}
    seen: set[str] = set()
    for idx, item in enumerate(data_articles):
        if not isinstance(item, dict):
            continue
        rec = article_to_record(item, idx, tool.FEATURE_IMAGE_PATH)
        if rec["target_root"] in seen:
            continue
        seen.add(rec["target_root"])
        records_by_section[rec["section"]].append(rec)

    for section in ("thu-vien", "ban-tin"):
        records_by_section[section].sort(key=lambda r: tool.fold(r["title"]))
        for idx, rec in enumerate(records_by_section[section]):
            rec["catalog_index"] = idx
            if not rec["display_badge"]:
                rec["display_badge"] = tool.display_badge_for_record(rec)
            if not rec["display_topic"]:
                rec["display_topic"] = tool.display_topic_for_record(rec)

    return records_by_section


def build_page_maps(tool, records_by_section: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Dict[int, str]]:
    page_maps: Dict[str, Dict[int, str]] = {}
    for section in ("thu-vien", "ban-tin"):
        total_pages = max(1, math.ceil(len(records_by_section[section]) / tool.PAGE_SIZE))
        page_maps[section] = tool.build_page_map(section, total_pages)
    return page_maps


def pick_latest_ids(records: List[Dict[str, Any]], limit: int, exclude: str = "") -> List[str]:
    picked = sorted(
        (record for record in records if record["target_root"] != exclude),
        key=lambda r: (r.get("publish_date") or "", r.get("catalog_index", 0), r["title"].lower()),
        reverse=True,
    )
    return [record["target_root"] for record in picked[:limit]]


def read_existing_article_views() -> Dict[str, Any]:
    path = ROOT / "content-index.js"
    if not path.exists():
        return {}
    raw = path.read_text(encoding="utf-8", errors="replace").strip()
    prefix = "window.KetoanDieuTamContentIndex="
    if raw.startswith(prefix):
        raw = raw[len(prefix):]
    if raw.endswith(";"):
        raw = raw[:-1]
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return {}
    views = decoded.get("articleViews") if isinstance(decoded, dict) else None
    return views if isinstance(views, dict) else {}


def build_fast_content_index(tool, records_by_section: Dict[str, List[Dict[str, Any]]], target_article_id: str = "") -> Dict[str, Any]:
    articles: Dict[str, Dict[str, Any]] = {}
    article_views: Dict[str, Dict[str, Any]] = {}
    existing_views = read_existing_article_views()

    latest_news = pick_latest_ids(records_by_section.get("ban-tin", []), 3)
    latest_library = pick_latest_ids(records_by_section.get("thu-vien", []), 3)

    topic_buckets: Dict[str, List[Dict[str, Any]]] = {}
    for records in records_by_section.values():
        for record in records:
            topic_key = "|".join([
                record.get("section", ""),
                record.get("library_kind_key", ""),
                record.get("topic_lv1_key", ""),
                record.get("topic_lv2_key", ""),
            ])
            topic_buckets.setdefault(topic_key, []).append(record)

    for section, records in records_by_section.items():
        section_latest = latest_news if section == "ban-tin" else latest_library
        other_latest = latest_library if section == "ban-tin" else latest_news
        for idx, record in enumerate(records):
            article_id = record["target_root"]
            articles[article_id] = {
                "id": article_id,
                "section": section,
                "sectionLabel": tool.SECTION_CONFIG[section]["label"],
                "sectionHref": f"{section}.html",
                "href": article_id,
                "canonical": f"{tool.SITE_BASE_URL}/{article_id}",
                "title": record["title"],
                "excerpt": record["excerpt"],
                "topicLv1Key": record["topic_lv1_key"],
                "topicLv1Label": record["topic_lv1_label"],
                "topicLv2Key": record["topic_lv2_key"],
                "topicLv2Label": record["topic_lv2_label"],
                "topicLv3Key": record.get("topic_lv3_key", ""),
                "topicLv3Label": record.get("topic_lv3_label", ""),
                "tags": record.get("tags", []),
                "primarySection": record.get("classification_primary"),
                "secondarySections": record.get("classification_secondary", []),
                "classificationReasons": record.get("classification_reasons", {}),
                "legacyPrimarySection": record.get("classification_legacy_primary"),
                "legacySecondarySections": record.get("classification_legacy_secondary", []),
                "libraryKindKey": record.get("library_kind_key"),
                "libraryKindLabel": record.get("library_kind_label"),
                "toolLv3Key": record.get("tool_lv3_key"),
                "toolLv3Label": record.get("tool_lv3_label"),
                "cardBadgeLabel": record.get("display_badge"),
                "cardTopicLabel": record.get("display_topic"),
                "image": record["hub_image"],
                "publishDate": record.get("publish_date"),
                "modifiedDate": record.get("modified_date"),
                "authorName": record.get("author_name") or "Kế Toán Diệu Tâm",
                "authorType": record.get("author_type") or "Organization",
            }
            topic_key = "|".join([
                record.get("section", ""),
                record.get("library_kind_key", ""),
                record.get("topic_lv1_key", ""),
                record.get("topic_lv2_key", ""),
            ])
            related = [
                candidate["target_root"]
                for candidate in topic_buckets.get(topic_key, [])
                if candidate["target_root"] != article_id
            ][:3]
            fallback_view = {
                "currentIndex": idx + 1,
                "totalCount": len(records),
                "prev": records[idx - 1]["target_root"] if idx > 0 else None,
                "next": records[idx + 1]["target_root"] if idx + 1 < len(records) else None,
                "newsLatest": [aid for aid in latest_news if aid != article_id][:3],
                "libraryLatest": [aid for aid in latest_library if aid != article_id][:3],
                "related": related,
                "latestOther": [aid for aid in other_latest if aid != article_id][:4],
                "fastView": True,
            }
            existing_view = existing_views.get(article_id)
            if article_id == target_article_id:
                existing_view = None
            article_views[article_id] = existing_view if isinstance(existing_view, dict) else fallback_view

    from datetime import UTC, datetime

    return {
        "generatedAt": datetime.now(UTC).isoformat(),
        "sections": {
            key: {"label": cfg["label"], "href": f"{key}.html"} for key, cfg in tool.SECTION_CONFIG.items()
        },
        "articles": articles,
        "articleViews": article_views,
    }


def write_target_article_view(tool, index_data: Dict[str, Any], article_id: str) -> bool:
    if not article_id or article_id not in index_data.get("articleViews", {}):
        return False
    view = index_data["articleViews"][article_id]
    expanded = {
        "currentIndex": view["currentIndex"],
        "totalCount": view["totalCount"],
        "prev": tool.expand_view_article(index_data, view.get("prev")),
        "next": tool.expand_view_article(index_data, view.get("next")),
        "newsLatest": tool.expand_view_group(index_data, view.get("newsLatest", [])),
        "libraryLatest": tool.expand_view_group(index_data, view.get("libraryLatest", [])),
        "related": tool.expand_view_group(index_data, view.get("related", [])),
        "latestOther": tool.expand_view_group(index_data, view.get("latestOther", [])),
    }
    view_path = tool.VIEWS_DIR / f"{article_id}.json"
    tool.write_json(view_path, expanded)
    tool.write_js_store(view_path.with_suffix(".js"), "KetoanDieuTamArticleViewStore", article_id, expanded)
    return True


def write_fast_public_artifacts(tool, records_by_section: Dict[str, List[Dict[str, Any]]], index_data: Dict[str, Any], page_maps: Dict[str, Dict[int, str]], article_id: str = "") -> bool:
    tool.write_content_index(index_data)
    for section, records in records_by_section.items():
        hub_payload = {
            "section": section,
            "sectionLabel": tool.SECTION_CONFIG[section]["label"],
            "sectionHref": f"{section}.html",
            "pageMap": {str(k): v for k, v in page_maps[section].items()},
            "libraryKinds": tool.build_library_kinds(records) if section == "thu-vien" else [],
            "taxonomy": tool.build_taxonomy(records),
            "count": len(records),
            "articles": [tool.build_hub_article_item(r) for r in records],
        }
        tool.write_json(tool.HUBS_DIR / f"{section}.json", hub_payload)
        tool.write_js_store(tool.HUBS_DIR / f"{section}.js", "KetoanDieuTamHubStore", section, hub_payload)
        tool.write_json(tool.FEEDS_DIR / f"latest-{section}.json", tool.build_feed(records))
    tool.write_taxonomy_data(records_by_section)
    tool.write_sitemap(index_data, page_maps)
    return write_target_article_view(tool, index_data, article_id)


def sync_taxonomy_master_if_available(dry_run: bool) -> Dict[str, Any]:
    master_path = ROOT / "data" / "taxonomy-master.json"
    if not master_path.exists():
        return {"enabled": False, "reason": "missing data/taxonomy-master.json"}
    taxonomy_tool = load_taxonomy_admin_tool()
    master = taxonomy_tool.load_master()
    articles = taxonomy_tool.load_articles()
    validation = taxonomy_tool.validate_master(master, articles)
    if not validation.get("ok"):
        raise RuntimeError("taxonomy-master không hợp lệ: " + "; ".join(validation.get("errors", [])[:5]))
    if dry_run:
        return {"enabled": True, "dry_run": True, "nodes": validation.get("nodes")}
    result = taxonomy_tool.write_derived_artifacts(master, articles, True)
    return {"enabled": True, "dry_run": False, **result}


def rebuild_public_artifacts(include_hub_pages: bool, dry_run: bool, mode: str, article_id: str = "") -> Dict[str, Any]:
    tool = load_import_tool()
    records_by_section = build_records_by_section(tool)
    page_maps = build_page_maps(tool, records_by_section)
    if mode == "full":
        index_data = tool.build_content_index(records_by_section)
    else:
        index_data = build_fast_content_index(tool, records_by_section, target_article_id=article_id)

    target_view_written = False
    taxonomy_master_sync: Dict[str, Any] = sync_taxonomy_master_if_available(True) if dry_run else {"enabled": False}
    if not dry_run:
        if include_hub_pages:
            tool.rebuild_hub_pages(records_by_section, page_maps)
        if mode == "full":
            tool.write_content_index(index_data)
            tool.write_data_artifacts(records_by_section, index_data, page_maps)
            tool.write_taxonomy_data(records_by_section)
            tool.write_sitemap(index_data, page_maps)
            target_view_written = bool(article_id and article_id in index_data.get("articleViews", {}))
        else:
            target_view_written = write_fast_public_artifacts(tool, records_by_section, index_data, page_maps, article_id=article_id)
        taxonomy_master_sync = sync_taxonomy_master_if_available(False)

    artifacts = [
        "content-index.js",
        "data/hubs/thu-vien.json",
        "data/hubs/thu-vien.js",
        "data/hubs/ban-tin.json",
        "data/hubs/ban-tin.js",
        "data/feeds/latest-thu-vien.json",
        "data/feeds/latest-ban-tin.json",
        "data/taxonomy.json",
        "data/editor-taxonomy.json",
        "data/menu-config.json",
        "sitemap.xml",
    ]
    if mode == "full":
        artifacts[1:1] = [
            "data/articles.json",
            "data/article-views/**/*.json",
            "data/article-views/**/*.js",
        ]
    elif article_id:
        artifacts.append(f"data/article-views/{article_id}.json")
        artifacts.append(f"data/article-views/{article_id}.js")

    return {
        "ok": True,
        "mode": mode,
        "dry_run": dry_run,
        "include_hub_pages": include_hub_pages,
        "articles": len(index_data["articles"]),
        "article_views": len(index_data["articleViews"]),
        "thu_vien_count": len(records_by_section["thu-vien"]),
        "ban_tin_count": len(records_by_section["ban-tin"]),
        "thu_vien_pages": len(page_maps["thu-vien"]),
        "ban_tin_pages": len(page_maps["ban-tin"]),
        "target_article_view": article_id,
        "target_article_view_written": target_view_written,
        "taxonomy_master_sync": taxonomy_master_sync,
        "artifacts": artifacts,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Rebuild public hub/content artifacts from data/articles.json")
    parser.add_argument("--dry-run", action="store_true", help="Build in memory and print summary only")
    parser.add_argument("--mode", choices=["fast", "full"], default="fast", help="fast skips heavy data/article-views rewrites; full rebuilds all article views")
    parser.add_argument("--include-hub-pages", action="store_true", help="Also rewrite static thu-vien/ban-tin pagination HTML")
    parser.add_argument("--source", default="", help="Trace source, e.g. admin-publish")
    parser.add_argument("--article-id", default="", help="Article id that triggered rebuild")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        summary = rebuild_public_artifacts(include_hub_pages=bool(args.include_hub_pages), dry_run=bool(args.dry_run), mode=str(args.mode), article_id=str(args.article_id))
        summary["source"] = args.source
        summary["article_id"] = args.article_id
        print(json.dumps(summary, ensure_ascii=True, indent=2))
        return 0
    except Exception as exc:  # noqa: BLE001 - CLI must return a structured failure.
        print(json.dumps({"ok": False, "error": str(exc), "source": args.source, "article_id": args.article_id}, ensure_ascii=True, indent=2), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
