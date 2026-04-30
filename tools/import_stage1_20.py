#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import importlib.util
import json
import math
import re
import shutil
import unicodedata
from collections import OrderedDict
from datetime import UTC, datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import quote, unquote, urlsplit


ROOT = Path(__file__).resolve().parents[1]
SRC_ROOT = Path("/mnt/d/WORKING/KetoanThienUng/TailieuKeToanThienUng/bai-moi-cap-nhat")
MANIFEST_PATH = ROOT / "docs" / "update-800-bai-manifest.json"
IMPORT_LOG_PATH = ROOT / "docs" / "update-800-bai-import-log.json"
DATA_DIR = ROOT / "data"
HUBS_DIR = DATA_DIR / "hubs"
FEEDS_DIR = DATA_DIR / "feeds"
VIEWS_DIR = DATA_DIR / "article-views"
CONTENT_IMAGES_DIR = ROOT / "assets" / "images" / "content"
BUILDER_PATH = ROOT.parent / ".m" / "build_sample_sections.py"
PAGE_SIZE = 12
SITE_BASE_URL = "https://ketoandieutam.vn"
FEATURE_IMAGE_PATH = "assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg"


def load_builder():
    spec = importlib.util.spec_from_file_location("builder", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không thể load builder từ {BUILDER_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


BUILDER = load_builder()
SECTION_CONFIG = BUILDER.SECTION_CONFIG
LIBRARY_KIND_META = BUILDER.LIBRARY_KIND_META
LIBRARY_KIND_LABELS = BUILDER.LIBRARY_KIND_LABELS
NEWS_SECTION_TAGS = {
    "khoa-hoc-dao-tao": "Khóa học",
    "co-so-dia-diem": "Cơ sở",
    "gioi-thieu-thuong-hieu": "Giới thiệu",
    "uu-dai-thong-bao": "Ưu đãi",
}
TOKEN_RE = BUILDER.TOKEN_RE
STOPWORDS = BUILDER.STOPWORDS


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def write_js_store(path: Path, global_name: str, key: str, data) -> None:
    payload = json.dumps(data, ensure_ascii=False, separators=(",", ":"))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        f"window.{global_name}=window.{global_name}||{{}};window.{global_name}[{json.dumps(key)}]={payload};\n",
        encoding="utf-8",
    )


def fold(value: str) -> str:
    value = html.unescape(value or "").lower()
    value = "".join(ch for ch in unicodedata.normalize("NFD", value) if unicodedata.category(ch) != "Mn")
    return re.sub(r"[^a-z0-9]+", " ", value).strip()


def strip_tags(text: str) -> str:
    return BUILDER.strip_tags(text or "")


def extract_title(doc: str, fallback: str) -> str:
    return BUILDER.extract_title(doc, fallback).strip() or fallback


def extract_main_content(doc: str) -> str:
    return BUILDER.extract_main_content(doc)


def excerpt_from_html(content: str, max_len: int = 160) -> str:
    plain = strip_tags(content)
    if len(plain) <= max_len:
        return plain
    return plain[: max_len - 1].rstrip() + "…"


def infer_publish_date(title: str, content_html: str, source_path: Path) -> str:
    return BUILDER.infer_publish_date(title, content_html, source_path)


def display_topic_for_record(record: Dict) -> str:
    section = record["section"]
    if section == "ban-tin":
        return BUILDER.infer_news_topic(record)
    topic = record.get("topic_lv2_label") or record.get("topic_lv1_label") or "Hướng dẫn"
    return BUILDER.normalize_library_display_topic(record, topic)


def display_badge_for_record(record: Dict) -> str:
    if record["section"] == "ban-tin":
        return BUILDER.infer_news_badge(record)
    if record.get("library_kind_label"):
        return record["library_kind_label"]
    return record.get("topic_lv1_label") or "Hướng dẫn"


def tags_for_record(record: Dict) -> List[str]:
    title = fold(record["title"])
    lv1 = record.get("topic_lv1_label") or ""
    lv2 = record.get("topic_lv2_label") or ""
    lv3 = record.get("topic_lv3_label") or ""
    tags: List[str] = []

    def push(tag: str) -> None:
        clean = (tag or "").strip()
        if clean and clean not in tags:
            tags.append(clean)

    if record["section"] == "ban-tin":
        push(lv2 or lv1 or "Bản tin")
        push(lv1)
        mapped = NEWS_SECTION_TAGS.get(record.get("topic_lv1_key") or "", "")
        push(mapped or "Thông tin")
        return tags[:6]

    mapping = [
        ("gtgt|hoa don", "GTGT"),
        ("tncn", "TNCN"),
        ("tndn", "TNDN"),
        ("bhxh|bhyt|bhtn|bao hiem", "BHXH"),
        ("misa", "MISA"),
        ("excel", "Excel"),
        ("htkk|etax|thue dien tu", "Thuế điện tử"),
    ]
    for pattern, label in mapping:
        if re.search(pattern, title):
            push(label)
    push(lv2)
    if lv3 and lv3.lower() != lv2.lower():
        push(lv3)
    push(record.get("library_kind_label") or "Hướng dẫn")
    return tags[:8]


def text_tokens(text: str) -> set[str]:
    tokens = set()
    for token in TOKEN_RE.findall(strip_tags(text).lower()):
        if len(token) < 3 or token in STOPWORDS:
            continue
        tokens.add(token)
    return tokens


def prepare_record_features(record: Dict) -> None:
    if "_title_tokens" in record:
        return
    text = " ".join(
        filter(
            None,
            [
                record.get("title") or "",
                record.get("excerpt") or "",
                record.get("topic_lv1_label") or "",
                record.get("topic_lv2_label") or "",
                " ".join(record.get("tags") or []),
            ],
        )
    )
    record["_title_tokens"] = text_tokens(text)
    if record.get("section") == "thu-vien":
        kind_boost = {
            "huong-dan": 80,
            "bieu-mau": 40,
            "cong-cu": 30,
            "van-ban": 20,
        }
        record["_bucket_priority"] = kind_boost.get(record.get("library_kind_key") or "", 10)
    else:
        record["_bucket_priority"] = 0


def related_score_value(base: Dict, candidate: Dict) -> int:
    base_tokens = base.get("_title_tokens") or set()
    candidate_tokens = candidate.get("_title_tokens") or set()
    overlap = len(base_tokens & candidate_tokens)
    if overlap == 0:
        return 0

    score = overlap * 7
    if base.get("topic_lv2_key") and base.get("topic_lv2_key") == candidate.get("topic_lv2_key"):
        score += 24
    elif base.get("topic_lv1_key") and base.get("topic_lv1_key") == candidate.get("topic_lv1_key"):
        score += 12
    if base.get("library_kind_key") and base.get("library_kind_key") == candidate.get("library_kind_key"):
        score += 6
    score += candidate.get("_bucket_priority", 0)
    return score


def pick_related_records(base: Dict, records: List[Dict], limit: int = 3) -> List[Dict]:
    prepare_record_features(base)
    candidates = []
    for item in records:
        if item["target_root"] == base["target_root"]:
            continue
        prepare_record_features(item)
        score = related_score_value(base, item)
        if score <= 0:
            continue
        candidates.append((score, item))
    candidates.sort(key=lambda x: (-x[0], x[1]["catalog_index"], x[1]["title"].lower()))
    return [item for _, item in candidates[:limit]]


def latest_sort_key(item: Dict) -> Tuple[int, int, str]:
    publish = str(item.get("publish_date") or "0000-00-00").replace("-", "")
    try:
        publish_value = int(publish)
    except ValueError:
        publish_value = 0
    return (-publish_value, item["catalog_index"], item["title"].lower())


def pick_latest_records(records: List[Dict], limit: int = 3, exclude: Optional[str] = None) -> List[Dict]:
    candidates = [r for r in records if r["target_root"] != exclude]
    candidates.sort(key=latest_sort_key)
    return candidates[:limit]


def discover_bucket_key(record: Dict) -> str:
    if record["section"] == "ban-tin":
        return "ban-tin"
    return record.get("library_kind_key") or "thu-vien"


def pick_discover_records(base: Dict, records_by_section: Dict[str, List[Dict]], limit: int = 4) -> List[Dict]:
    bucket_order = ("huong-dan", "bieu-mau", "cong-cu", "ban-tin")
    best_by_bucket: Dict[str, Tuple[Tuple[int, int, str], Dict]] = {}
    prepare_record_features(base)
    for records in records_by_section.values():
        for item in records:
            if item["target_root"] == base["target_root"]:
                continue
            prepare_record_features(item)
            score = related_score_value(base, item)
            if score < 45:
                continue
            bucket = discover_bucket_key(item)
            if bucket not in bucket_order:
                continue
            sort_key = (-score, item["catalog_index"], item["title"].lower())
            current = best_by_bucket.get(bucket)
            if current is None or sort_key < current[0]:
                best_by_bucket[bucket] = (sort_key, item)
    chosen: List[Dict] = []
    for bucket in bucket_order:
        if bucket in best_by_bucket:
            chosen.append(best_by_bucket[bucket][1])
            if len(chosen) >= limit:
                break
    return chosen


def source_hosts() -> set[str]:
    return {"ketoandieutam.vn", "www.ketoandieutam.vn", "ketoanthienung.net", "www.ketoanthienung.net"}


def parse_internalish_url(url: str) -> Optional[Tuple[str, str, str]]:
    raw = html.unescape((url or "").strip())
    if not raw:
        return None
    if raw.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
        return None
    if raw.startswith("//"):
        raw = "https:" + raw
    parsed = urlsplit(raw)
    if parsed.scheme in {"http", "https"}:
        if parsed.netloc.lower() not in source_hosts():
            return None
        path = unquote(parsed.path.lstrip("/"))
        return path, parsed.query, parsed.fragment
    if parsed.scheme:
        return None
    path = unquote(parsed.path.lstrip("/") if parsed.path.startswith("/") else parsed.path)
    return path, parsed.query, parsed.fragment


HTML_EXTS = {".htm", ".html", ".aspx"}
ASSET_FILE_EXTS = {
    ".png",
    ".jpg",
    ".jpeg",
    ".gif",
    ".webp",
    ".bmp",
    ".tif",
    ".tiff",
    ".svg",
    ".doc",
    ".docx",
    ".xls",
    ".xlsx",
    ".pdf",
}
LINK_ATTR_RE = re.compile(r"(?P<attr>\b(?:src|href))\s*=\s*(?P<q>[\"'])(?P<url>.*?)(?P=q)", re.IGNORECASE)
STYLE_URL_RE = re.compile(r"url\((?P<q>[\"']?)(?P<url>[^)\"']+)(?P=q)\)", re.IGNORECASE)


def is_html_path(path: str) -> bool:
    return Path(path).suffix.lower() in HTML_EXTS


def make_asset_url(path: str, link_prefix: str) -> str:
    clean = unquote(path).replace("\\", "/").lstrip("/")
    return f"{link_prefix}assets/images/content/{clean}"


def make_hub_fallback_url(meta: Optional[Dict], link_prefix: str) -> str:
    if not meta:
        return f"{link_prefix}index.html"
    keyword = quote(meta["title"], safe="")
    return f"{link_prefix}{meta['section']}.html?q={keyword}"


def rewrite_url(
    url: str,
    selected_lookup: Dict[str, str],
    all_lookup: Dict[str, Dict],
    imported_target_set: set[str],
    assets_to_copy: set[str],
    link_prefix: str,
    attr_name: str = "href",
) -> str:
    raw = html.unescape((url or "").strip()).strip("'\"")
    lowered = raw.lower()
    if lowered.startswith("http:/") and not lowered.startswith("http://"):
        raw = "http://" + raw[6:].lstrip("/")
        lowered = raw.lower()
    if lowered.startswith("https:/") and not lowered.startswith("https://"):
        raw = "https://" + raw[7:].lstrip("/")
        lowered = raw.lower()

    looks_like_local_path = bool(
        re.match(r"^[a-zA-Z]:[\\/]", raw)
        or "msohtmlclip" in lowered
        or "/c:/users/" in lowered
        or "\\c:\\users\\" in lowered
    )
    if lowered.startswith("file:") or looks_like_local_path:
        if attr_name == "href":
            return "#"
        return f"{link_prefix}{FEATURE_IMAGE_PATH}"
    parsed = parse_internalish_url(raw)
    if not parsed:
        return raw or url
    path, query, frag = parsed
    if not path:
        return raw or url
    key = path.lower()
    if is_html_path(path):
        target = selected_lookup.get(key)
        if not target:
            mapped = all_lookup.get(key)
            if mapped:
                candidate = mapped.get("target_path")
                if candidate and candidate in imported_target_set:
                    target = candidate
        if target:
            resolved = f"{link_prefix}{target}"
            if query:
                resolved += f"?{query}"
            if frag:
                resolved += f"#{frag}"
            return resolved
        return make_hub_fallback_url(all_lookup.get(key), link_prefix)
    src = SRC_ROOT / path
    if src.exists() and src.is_file():
        assets_to_copy.add(path)
        resolved = make_asset_url(path, link_prefix)
        if query:
            resolved += f"?{query}"
        if frag:
            resolved += f"#{frag}"
        return resolved
    ext = Path(path).suffix.lower()
    if ext in ASSET_FILE_EXTS:
        if attr_name == "href":
            return "#"
        return f"{link_prefix}{FEATURE_IMAGE_PATH}"
    return raw or url


def rewrite_content_urls(
    content: str,
    selected_lookup: Dict[str, str],
    all_lookup: Dict[str, Dict],
    imported_target_set: set[str],
    assets_to_copy: set[str],
    link_prefix: str,
) -> str:
    def repl_attr(match: re.Match) -> str:
        attr, q, url = match.group("attr"), match.group("q"), match.group("url")
        new_url = rewrite_url(
            url,
            selected_lookup,
            all_lookup,
            imported_target_set,
            assets_to_copy,
            link_prefix,
            attr_name=attr.lower(),
        )
        return f'{attr}={q}{new_url}{q}'

    out = LINK_ATTR_RE.sub(repl_attr, content)

    def repl_style(match: re.Match) -> str:
        q = match.group("q") or ""
        url = match.group("url").strip()
        new_url = rewrite_url(
            url,
            selected_lookup,
            all_lookup,
            imported_target_set,
            assets_to_copy,
            link_prefix,
            attr_name="style",
        )
        return f"url({q}{new_url}{q})"

    return STYLE_URL_RE.sub(repl_style, out)


def first_image_from_content(content: str) -> str:
    m = re.search(r'<img[^>]+src=["\']([^"\']+)["\']', content, flags=re.IGNORECASE)
    if not m:
        return FEATURE_IMAGE_PATH
    src = html.unescape(m.group(1).strip())
    if src.lower().startswith("file:"):
        return FEATURE_IMAGE_PATH
    if src.startswith(("http://", "https://", "//", "data:")):
        return FEATURE_IMAGE_PATH
    clean = src.lstrip("./")
    if clean.startswith("../"):
        clean = clean[3:]
    return clean or FEATURE_IMAGE_PATH


def copy_assets(asset_paths: set[str]) -> Tuple[int, List[str]]:
    copied = 0
    missing: List[str] = []
    for rel in sorted(asset_paths):
        src = SRC_ROOT / rel
        if not src.exists() or not src.is_file():
            missing.append(rel)
            continue
        dst = CONTENT_IMAGES_DIR / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dst)
        copied += 1
    return copied, missing


def hub_output_file(section: str, page: int) -> Path:
    if page <= 1:
        return ROOT / f"{section}.html"
    return ROOT / section / "trang" / str(page) / "index.html"


def build_page_map(section: str, total_pages: int) -> Dict[int, str]:
    mapping = {}
    for page in range(1, total_pages + 1):
        if page == 1:
            mapping[page] = f"{section}.html"
        else:
            mapping[page] = f"{section}/trang/{page}/index.html"
    return mapping


def build_content_index(records_by_section: Dict[str, List[Dict]]) -> Dict:
    articles: Dict[str, Dict] = {}
    article_views: Dict[str, Dict] = {}
    for section, records in records_by_section.items():
        for idx, record in enumerate(records):
            article_id = record["target_root"]
            prev_record = records[idx - 1] if idx > 0 else None
            next_record = records[idx + 1] if idx + 1 < len(records) else None
            related = pick_related_records(record, records, limit=3)
            discover = pick_discover_records(record, records_by_section, limit=4)
            news_latest = pick_latest_records(
                records_by_section.get("ban-tin", []),
                limit=3,
                exclude=record["target_root"] if section == "ban-tin" else None,
            )
            library_latest = pick_latest_records(
                records_by_section.get("thu-vien", []),
                limit=3,
                exclude=record["target_root"] if section == "thu-vien" else None,
            )

            articles[article_id] = {
                "id": article_id,
                "section": section,
                "sectionLabel": SECTION_CONFIG[section]["label"],
                "sectionHref": f"{section}.html",
                "href": article_id,
                "canonical": f"{SITE_BASE_URL}/{article_id}",
                "articleHref": record.get("article_href", ""),
                "legacyHref": record.get("legacy_href", ""),
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

            article_views[article_id] = {
                "currentIndex": idx + 1,
                "totalCount": len(records),
                "prev": prev_record["target_root"] if prev_record else None,
                "next": next_record["target_root"] if next_record else None,
                "newsLatest": [item["target_root"] for item in news_latest],
                "libraryLatest": [item["target_root"] for item in library_latest],
                "related": [item["target_root"] for item in related],
                "latestOther": [item["target_root"] for item in discover],
            }

    return {
        "generatedAt": datetime.now(UTC).isoformat(),
        "sections": {
            key: {"label": cfg["label"], "href": f"{key}.html"} for key, cfg in SECTION_CONFIG.items()
        },
        "articles": articles,
        "articleViews": article_views,
    }


def expand_view_article(index_data: Dict, article_id: Optional[str]) -> Optional[Dict]:
    if not article_id:
        return None
    article = index_data["articles"].get(article_id)
    if not article:
        return None
    return {
        "id": article["id"],
        "section": article["section"],
        "sectionLabel": article["sectionLabel"],
        "sectionHref": article["sectionHref"],
        "href": article["href"],
        "canonical": article["canonical"],
        "title": article["title"],
        "excerpt": article["excerpt"],
        "topicLabel": article["topicLv2Label"],
        "tags": article.get("tags", []),
        "image": article.get("image", ""),
        "libraryKindLabel": article.get("libraryKindLabel", ""),
        "publishDate": article.get("publishDate"),
        "modifiedDate": article.get("modifiedDate"),
    }


def expand_view_group(index_data: Dict, article_ids: List[str]) -> List[Dict]:
    return [item for item in (expand_view_article(index_data, aid) for aid in article_ids) if item]


def build_hub_article_item(record: Dict) -> Dict:
    return {
        "file": record["file"],
        "title": record["title"],
        "excerpt": record["excerpt"],
        "topic_lv1_key": record["topic_lv1_key"],
        "topic_lv1_label": record["topic_lv1_label"],
        "topic_lv2_key": record["topic_lv2_key"],
        "topic_lv2_label": record["topic_lv2_label"],
        "topic_lv3_key": record.get("topic_lv3_key", ""),
        "topic_lv3_label": record.get("topic_lv3_label", ""),
        "tags": record.get("tags", []),
        "badge_label": record["display_badge"],
        "topic_label": record["display_topic"],
        "library_kind_key": record.get("library_kind_key"),
        "library_kind_label": record.get("library_kind_label"),
        "tool_lv3_key": record.get("tool_lv3_key"),
        "tool_lv3_label": record.get("tool_lv3_label"),
        "publish_date": record.get("publish_date"),
        "image": record["hub_image"],
        "href": record["target_root"],
    }


def build_taxonomy(records: List[Dict]) -> List[Dict]:
    ordered: OrderedDict[str, Dict] = OrderedDict()
    for rec in records:
        lv1_key = rec["topic_lv1_key"]
        lv1 = ordered.setdefault(
            lv1_key,
            {"key": lv1_key, "label": rec["topic_lv1_label"], "count": 0, "children": OrderedDict()},
        )
        lv1["count"] += 1
        lv2_key = rec["topic_lv2_key"]
        lv2 = lv1["children"].setdefault(
            lv2_key,
            {"key": lv2_key, "label": rec["topic_lv2_label"], "count": 0, "children": OrderedDict()},
        )
        lv2["count"] += 1
        lv3_key = rec.get("topic_lv3_key") or ""
        if lv3_key:
            lv3 = lv2["children"].setdefault(
                lv3_key,
                {"key": lv3_key, "label": rec.get("topic_lv3_label") or lv3_key, "count": 0},
            )
            lv3["count"] += 1
    out = []
    for lv1 in ordered.values():
        lv2_out = []
        for lv2 in lv1["children"].values():
            lv3_children = list(lv2["children"].values())
            lv3_children.sort(key=lambda x: (-x["count"], x["label"]))
            node = {"key": lv2["key"], "label": lv2["label"], "count": lv2["count"]}
            if lv3_children:
                node["children"] = lv3_children
            lv2_out.append(node)
        lv2_out.sort(key=lambda x: (-x["count"], x["label"]))
        out.append({"key": lv1["key"], "label": lv1["label"], "count": lv1["count"], "children": lv2_out})
    out.sort(key=lambda x: (-x["count"], x["label"]))
    return out


def build_library_kinds(records: List[Dict]) -> List[Dict]:
    counts = {}
    for rec in records:
        key = rec.get("library_kind_key")
        if not key:
            continue
        counts[key] = counts.get(key, 0) + 1
    items = []
    for key, meta in LIBRARY_KIND_META.items():
        count = counts.get(key, 0)
        if count <= 0:
            continue
        items.append(
            {
                "key": key,
                "label": LIBRARY_KIND_LABELS.get(key, key),
                "count": count,
                "href": f"thu-vien.html?kind={key}",
                "icon": meta["icon"],
                "description": meta["description"],
            }
        )
    return items


def build_feed(records: List[Dict], limit: int = 12) -> List[Dict]:
    picked = sorted(
        records,
        key=lambda r: (r.get("publish_date") or "", r.get("catalog_index", 0), r["title"].lower()),
        reverse=True,
    )[:limit]
    return [
        {
            "title": r["title"],
            "href": r["target_root"],
            "canonical": f"{SITE_BASE_URL}/{r['target_root']}",
            "publishDate": r.get("publish_date"),
            "modifiedDate": r.get("modified_date"),
            "image": r["hub_image"],
            "badgeLabel": r["display_badge"],
            "topicLabel": r["display_topic"],
            "libraryKindKey": r.get("library_kind_key"),
            "libraryKindLabel": r.get("library_kind_label"),
            "toolLv3Key": r.get("tool_lv3_key"),
            "toolLv3Label": r.get("tool_lv3_label"),
            "tags": r.get("tags", []),
        }
        for r in picked
    ]


def build_lv1_tree(records: List[Dict]) -> List[Dict]:
    ordered: OrderedDict[str, Dict] = OrderedDict()
    for rec in records:
        lv1_key = rec["topic_lv1_key"]
        lv1 = ordered.setdefault(
            lv1_key,
            {"key": lv1_key, "label": rec["topic_lv1_label"], "count": 0, "children": OrderedDict()},
        )
        lv1["count"] += 1
        lv2_key = rec["topic_lv2_key"]
        lv2 = lv1["children"].setdefault(
            lv2_key,
            {"key": lv2_key, "label": rec["topic_lv2_label"], "count": 0, "children": OrderedDict()},
        )
        lv2["count"] += 1
        lv3_key = rec.get("topic_lv3_key") or ""
        if lv3_key:
            lv3 = lv2["children"].setdefault(
                lv3_key,
                {"key": lv3_key, "label": rec.get("topic_lv3_label") or lv3_key, "count": 0},
            )
            lv3["count"] += 1
    out = []
    for lv1 in ordered.values():
        lv2_out = []
        for lv2 in lv1["children"].values():
            lv3_children = list(lv2["children"].values())
            lv3_children.sort(key=lambda x: (-x["count"], x["label"]))
            node = {"key": lv2["key"], "label": lv2["label"], "count": lv2["count"]}
            if lv3_children:
                node["children"] = lv3_children
            lv2_out.append(node)
        lv2_out.sort(key=lambda x: (-x["count"], x["label"]))
        out.append({"key": lv1["key"], "label": lv1["label"], "count": lv1["count"], "children": lv2_out})
    out.sort(key=lambda x: (-x["count"], x["label"]))
    return out


def write_taxonomy_data(records_by_section: Dict[str, List[Dict]]) -> None:
    thu_vien = records_by_section.get("thu-vien", [])
    generated = datetime.now(UTC).isoformat()
    payload = {
        "generatedAt": generated,
        "roots": [
            {
                "key": "thu-vien",
                "label": "Thư viện",
                "count": len(thu_vien),
                "children": [
                    {
                        "key": kind,
                        "label": label,
                        "count": len([r for r in thu_vien if r.get("library_kind_key") == kind]),
                        "children": build_lv1_tree([r for r in thu_vien if r.get("library_kind_key") == kind]),
                    }
                    for kind, label in LIBRARY_KIND_LABELS.items()
                ],
            },
            {
                "key": "ban-tin",
                "label": "Bản tin",
                "count": len(records_by_section.get("ban-tin", [])),
                "children": build_lv1_tree(records_by_section.get("ban-tin", [])),
            },
        ],
        "toolVariants": {},
    }
    tool_map: Dict[str, Dict] = {}
    for rec in [r for r in thu_vien if r.get("library_kind_key") == "cong-cu"]:
        lv2 = rec["topic_lv2_key"]
        bucket = tool_map.setdefault(lv2, {"label": rec["topic_lv2_label"], "children": OrderedDict()})
        if rec.get("tool_lv3_key"):
            bucket["children"].setdefault(
                rec["tool_lv3_key"],
                {"key": rec["tool_lv3_key"], "label": rec.get("tool_lv3_label") or rec["tool_lv3_key"]},
            )
    payload["toolVariants"] = {
        key: {"label": value["label"], "children": list(value["children"].values())}
        for key, value in tool_map.items()
    }
    write_json(DATA_DIR / "taxonomy.json", payload)

    editor_taxonomy = {
        "generatedAt": generated,
        "roots": [
            {
                "id": "thu-vien",
                "label": "Thư viện",
                "children": [
                    {
                        "id": kind,
                        "label": label,
                        "children": build_lv1_tree([r for r in thu_vien if r.get("library_kind_key") == kind]),
                    }
                    for kind, label in LIBRARY_KIND_LABELS.items()
                ],
            },
            {
                "id": "ban-tin",
                "label": "Bản tin",
                "children": build_lv1_tree(records_by_section.get("ban-tin", [])),
            },
        ],
        "variants": {"cong-cu": payload["toolVariants"]},
        "fieldMap": {
            "section": "primary_category_id",
            "library_kind": "library_kind",
            "topic_lv1": "domain",
            "topic_lv2": "subdomain",
            "tool_lv3": "variant",
        },
    }
    write_json(DATA_DIR / "editor-taxonomy.json", editor_taxonomy)

    menu_config = {
        "generatedAt": generated,
        "items": [
            {"key": "home", "label": "Trang Chủ", "href": "index.html"},
            {"key": "gioi-thieu", "label": "Giới Thiệu", "href": "gioi-thieu.html"},
            {"key": "giai-phap", "label": "Giải Pháp", "href": "giai-phap.html"},
            {"key": "dao-tao", "label": "Đào Tạo", "href": "dao-tao.html"},
            {
                "key": "thu-vien",
                "label": "Thư Viện",
                "href": "thu-vien.html",
                "category": "thu-vien",
                "children": [
                    {"key": f"thu-vien-{kind}", "label": label, "href": f"thu-vien.html?kind={kind}", "category": kind}
                    for kind, label in LIBRARY_KIND_LABELS.items()
                ],
            },
            {"key": "ban-tin", "label": "Bản Tin", "href": "ban-tin.html", "category": "ban-tin"},
            {"key": "lien-he", "label": "Liên Hệ", "href": "lien-he.html"},
        ],
    }
    write_json(DATA_DIR / "menu-config.json", menu_config)


def write_content_index(index_data: Dict) -> None:
    payload = json.dumps(index_data, ensure_ascii=False, separators=(",", ":"))
    (ROOT / "content-index.js").write_text("window.KetoanDieuTamContentIndex=" + payload + ";\n", encoding="utf-8")


def write_data_artifacts(records_by_section: Dict[str, List[Dict]], index_data: Dict, page_maps: Dict[str, Dict[int, str]]) -> None:
    articles_payload = list(index_data["articles"].values())
    articles_payload.sort(key=lambda item: (item["section"], item["title"].lower()))
    write_json(DATA_DIR / "articles.json", articles_payload)

    for article_id, article in index_data["articles"].items():
        view = index_data["articleViews"][article_id]
        expanded = {
            "currentIndex": view["currentIndex"],
            "totalCount": view["totalCount"],
            "prev": expand_view_article(index_data, view.get("prev")),
            "next": expand_view_article(index_data, view.get("next")),
            "newsLatest": expand_view_group(index_data, view.get("newsLatest", [])),
            "libraryLatest": expand_view_group(index_data, view.get("libraryLatest", [])),
            "related": expand_view_group(index_data, view.get("related", [])),
            "latestOther": expand_view_group(index_data, view.get("latestOther", [])),
        }
        view_path = VIEWS_DIR / f"{article_id}.json"
        write_json(view_path, expanded)
        write_js_store(view_path.with_suffix(".js"), "KetoanDieuTamArticleViewStore", article_id, expanded)

    for section, records in records_by_section.items():
        hub_payload = {
            "section": section,
            "sectionLabel": SECTION_CONFIG[section]["label"],
            "sectionHref": f"{section}.html",
            "pageMap": {str(k): v for k, v in page_maps[section].items()},
            "libraryKinds": build_library_kinds(records) if section == "thu-vien" else [],
            "taxonomy": build_taxonomy(records),
            "count": len(records),
            "articles": [build_hub_article_item(r) for r in records],
        }
        write_json(HUBS_DIR / f"{section}.json", hub_payload)
        write_js_store(HUBS_DIR / f"{section}.js", "KetoanDieuTamHubStore", section, hub_payload)
        write_json(FEEDS_DIR / f"latest-{section}.json", build_feed(records))


def sitemap_url_entry(loc: str, lastmod: Optional[str] = None) -> str:
    lastmod_xml = f"<lastmod>{html.escape(lastmod)}</lastmod>" if lastmod else ""
    return f"<url><loc>{html.escape(loc)}</loc>{lastmod_xml}</url>"


def write_sitemap(index_data: Dict, page_maps: Dict[str, Dict[int, str]]) -> None:
    urls: List[str] = [sitemap_url_entry(f"{SITE_BASE_URL}/index.html")]
    generated_date = datetime.now(UTC).strftime("%Y-%m-%d")
    for section, meta in index_data["sections"].items():
        urls.append(sitemap_url_entry(f"{SITE_BASE_URL}/{meta['href']}", generated_date))
        for page_no, href in sorted(page_maps.get(section, {}).items()):
            if page_no == 1:
                continue
            urls.append(sitemap_url_entry(f"{SITE_BASE_URL}/{href}", generated_date))
    for article in index_data["articles"].values():
        urls.append(
            sitemap_url_entry(
                article["canonical"],
                article.get("modifiedDate") or article.get("publishDate") or generated_date,
            )
        )
    xml = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n  '
        + "\n  ".join(urls)
        + "\n</urlset>\n"
    )
    (ROOT / "sitemap.xml").write_text(xml, encoding="utf-8")


def pick_clean_candidates(
    manifest_records: List[Dict],
    already_imported_source_files: set[str],
    existing_target_paths: set[str],
    allow_duplicate: bool = False,
    allow_flags: bool = False,
    allow_missing_assets: bool = False,
) -> List[Dict]:
    out: List[Dict] = []
    for row in manifest_records:
        source_name = resolve_source_file(str(row.get("source_file") or "")).lower()
        target_path = str(row.get("target_path") or "")
        if source_name in already_imported_source_files:
            continue
        if target_path and target_path in existing_target_paths:
            continue
        if not allow_duplicate and row.get("duplicate_status") != "ok":
            continue
        if not allow_flags and row.get("flags"):
            continue
        if not allow_missing_assets and row.get("asset_missing_count", 0) > 0:
            continue
        out.append(row)
    return out


def load_source_lookup() -> Dict[str, Dict]:
    lookup = {}
    for source_file in sorted(SRC_ROOT.glob("*.htm")):
        key = source_file.name.lower()
        lookup[key] = {
            "section": "thu-vien",
            "title": source_file.stem,
        }
    return lookup


def load_manifest_lookup(manifest_records: List[Dict]) -> Dict[str, Dict]:
    lookup: Dict[str, Dict] = {}
    for row in manifest_records:
        source = str(row.get("source_file") or "").lower()
        if not source:
            continue
        lookup[source] = {
            "section": row.get("section") or "thu-vien",
            "title": row.get("title") or Path(source).stem,
            "target_path": row.get("target_path") or "",
        }
    return lookup


def resolve_source_file(source_name: str) -> str:
    # Sửa 1 case lệch tên do manifest normalize sai dấu/ký tự đặc biệt.
    if source_name == "công ty tnhh tư vấn & đào tạo diệu tâm-tuyen-dung-ke-toan.htm":
        return "cong-ty-ke-toan-dieu-tam-tuyen-dung-ke-toan.htm"
    return source_name


def load_import_log() -> Dict:
    if not IMPORT_LOG_PATH.exists():
        return {"batches": []}
    try:
        data = read_json(IMPORT_LOG_PATH)
    except Exception:
        return {"batches": []}
    if not isinstance(data, dict) or "batches" not in data or not isinstance(data["batches"], list):
        return {"batches": []}
    return data


def imported_source_set(import_log: Dict) -> set[str]:
    out: set[str] = set()
    for batch in import_log.get("batches", []):
        for item in batch.get("imported", []):
            source = str(item.get("source_file") or "").lower()
            if source:
                out.add(source)
    return out


def append_import_log(
    import_log: Dict,
    batch_name: str,
    batch_size: int,
    criteria: Dict[str, bool],
    imported_rows: List[Dict],
    copied_assets: int,
    missing_assets: List[str],
    counts: Dict[str, int],
) -> None:
    if batch_name in {str(item.get("batch_name") or "") for item in import_log.get("batches", [])}:
        base = batch_name
        idx = 2
        while f"{base}-r{idx}" in {str(item.get("batch_name") or "") for item in import_log.get("batches", [])}:
            idx += 1
        batch_name = f"{base}-r{idx}"
    imported_payload = [
        {
            "source_file": rec.get("source_file_raw") or rec["file"],
            "source_file_resolved": rec["file"],
            "target_path": rec["target_root"],
            "section": rec["section"],
            "publish_date": rec.get("publish_date"),
        }
        for rec in imported_rows
    ]
    import_log.setdefault("batches", []).append(
        {
            "batch_name": batch_name,
            "batch_size": batch_size,
            "criteria": criteria,
            "run_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "imported_count": len(imported_rows),
            "assets_copied": copied_assets,
            "assets_missing": len(missing_assets),
            "counts": counts,
            "imported": imported_payload,
        }
    )
    write_json(IMPORT_LOG_PATH, import_log)


def build_selected_lookup(records: List[Dict]) -> Dict[str, str]:
    lookup = {}
    for rec in records:
        source = str(rec["source_file"]).lower()
        lookup[source] = rec["target_path"]
    return lookup


def build_record(
    row: Dict,
    catalog_index: int,
    selected_lookup: Dict[str, str],
    all_lookup: Dict[str, Dict],
    imported_target_set: set[str],
    assets_to_copy: set[str],
) -> Dict:
    source_name = resolve_source_file(row["source_file"])
    source_path = SRC_ROOT / source_name
    doc = source_path.read_text(encoding="utf-8", errors="ignore")
    title = extract_title(doc, row["title"])
    content_html = extract_main_content(doc)
    rewritten = rewrite_content_urls(
        content_html,
        selected_lookup=selected_lookup,
        all_lookup=all_lookup,
        imported_target_set=imported_target_set,
        assets_to_copy=assets_to_copy,
        link_prefix="../",
    )
    excerpt = row["description"] or excerpt_from_html(rewritten, max_len=160)
    publish_date = infer_publish_date(title, rewritten, source_path)
    library_kind = row.get("library_kind") or ""
    library_kind_label = LIBRARY_KIND_LABELS.get(library_kind, "")
    record = {
        "file": source_name,
        "source_file_raw": row["source_file"],
        "target_root": row["target_path"],
        "section": row["section"],
        "title": title,
        "content_html": rewritten,
        "excerpt": excerpt,
        "topic_lv1_key": row["lv2_key"],
        "topic_lv1_label": row["lv2_label"],
        "topic_lv2_key": row["lv3_key"],
        "topic_lv2_label": row["lv3_label"] or row["lv2_label"],
        "topic_lv3_key": "",
        "topic_lv3_label": "",
        "library_kind_key": library_kind if row["section"] == "thu-vien" else "",
        "library_kind_label": library_kind_label if row["section"] == "thu-vien" else "",
        "tool_lv3_key": "",
        "tool_lv3_label": "",
        "publish_date": publish_date,
        "modified_date": None,
        "author_name": "Kế Toán Diệu Tâm",
        "author_type": "Organization",
        "catalog_index": catalog_index,
    }
    record["display_topic"] = display_topic_for_record(record)
    record["display_badge"] = display_badge_for_record(record)
    record["tags"] = tags_for_record(record)
    hub_image = first_image_from_content(rewritten)
    if not hub_image or hub_image.startswith("http"):
        hub_image = FEATURE_IMAGE_PATH
    record["hub_image"] = hub_image
    return record


def rebuild_hub_pages(records_by_section: Dict[str, List[Dict]], page_maps: Dict[str, Dict[int, str]]) -> None:
    for section, records in records_by_section.items():
        total_pages = max(1, math.ceil(len(records) / PAGE_SIZE))
        page_map = page_maps[section]
        for page in range(1, total_pages + 1):
            out = hub_output_file(section, page)
            out.parent.mkdir(parents=True, exist_ok=True)
            rel_map = {p: BUILDER.hub_nav_href(section, p, out) for p in page_map}
            html_content = BUILDER.render_hub_page(
                section=section,
                records=records,
                current_page=page,
                total_pages=total_pages,
                output_file=out,
                page_map=rel_map,
            )
            html_content = html_content.replace("https://ketoandieutam.com", SITE_BASE_URL)
            out.write_text(html_content, encoding="utf-8")


def rebuild_articles(records: List[Dict]) -> None:
    for record in records:
        out = ROOT / record["target_root"]
        out.parent.mkdir(parents=True, exist_ok=True)
        html_content = BUILDER.render_article_page(record).replace("https://ketoandieutam.com", SITE_BASE_URL)
        out.write_text(html_content, encoding="utf-8")


def write_batch_report(
    report_path: Path,
    stage_label: str,
    batch_name: str,
    imported: List[Dict],
    copied: int,
    missing_assets: List[str],
    page_maps: Dict[str, Dict[int, str]],
    criteria_text: str,
) -> None:
    generated = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    lines = [
        f"# {stage_label}",
        "",
        f"- Thời gian chạy: `{generated}`",
        f"- Batch: `{batch_name}`",
        f"- Số bài import/upsert: **{len(imported)}**",
        f"- Tiêu chí: {criteria_text}",
        f"- Ảnh/asset copy thêm: **{copied}**",
        f"- Asset thiếu khi copy thực tế: **{len(missing_assets)}**",
        "",
        "## Danh sách bài đã import",
        "",
        "| # | File nguồn | File đích | Section | LV1 | LV2 | LV3 | Publish |",
        "|---:|---|---|---|---|---|---|---|",
    ]
    for idx, rec in enumerate(imported, 1):
        source_display = rec.get("source_file_raw") or rec["file"]
        lines.append(
            f"| {idx} | `{source_display}` | `{rec['target_root']}` | {rec['section']} | {rec['library_kind_label'] or '-'} | {rec['topic_lv1_label']} | {rec['topic_lv2_label']} | {rec['publish_date']} |"
        )
    lines += [
        "",
        "## Phân trang sau import",
        "",
        f"- Thư viện: {len(page_maps['thu-vien'])} trang",
        f"- Bản tin: {len(page_maps['ban-tin'])} trang",
    ]
    if missing_assets:
        lines += ["", "## Asset thiếu khi copy", ""] + [f"- `{item}`" for item in missing_assets]
    report_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Import batch bài viết từ manifest update-800-bai")
    parser.add_argument("--batch-size", type=int, default=20, help="Số bài sạch cần import mỗi batch")
    parser.add_argument("--batch-name", default="", help="Tên batch để lưu log/report")
    parser.add_argument("--stage-label", default="", help="Tiêu đề report markdown")
    parser.add_argument("--report-path", default="", help="Path report markdown (relative root)")
    parser.add_argument("--source-offset", type=int, default=0, help="Bỏ qua N bài sạch đầu tiên (không tính đã import)")
    parser.add_argument("--allow-duplicate", action="store_true", help="Cho phép import các dòng duplicate_status != ok")
    parser.add_argument("--allow-flags", action="store_true", help="Cho phép import các dòng có flags needs-review/taxonomy-review")
    parser.add_argument("--allow-missing-assets", action="store_true", help="Cho phép import các dòng có asset_missing_count > 0")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.batch_size <= 0:
        raise RuntimeError("--batch-size phải > 0")
    if args.source_offset < 0:
        raise RuntimeError("--source-offset phải >= 0")

    criteria = {
        "allow_duplicate": bool(args.allow_duplicate),
        "allow_flags": bool(args.allow_flags),
        "allow_missing_assets": bool(args.allow_missing_assets),
    }
    denied = []
    if not criteria["allow_duplicate"]:
        denied.append("duplicate=ok")
    if not criteria["allow_flags"]:
        denied.append("không cờ review")
    if not criteria["allow_missing_assets"]:
        denied.append("không thiếu asset")
    if denied:
        criteria_text = ", ".join(denied)
    else:
        criteria_text = "mở toàn bộ filter (cho phép duplicate/flags/missing-asset)"

    manifest = read_json(MANIFEST_PATH)
    manifest_records = manifest["records"]
    manifest_lookup = load_manifest_lookup(manifest_records)
    data_articles = read_json(DATA_DIR / "articles.json")
    existing_target_paths = {item["href"] for item in data_articles}
    import_log = load_import_log()
    done_sources = imported_source_set(import_log)
    clean_candidates = pick_clean_candidates(
        manifest_records,
        already_imported_source_files=done_sources,
        existing_target_paths=existing_target_paths,
        allow_duplicate=args.allow_duplicate,
        allow_flags=args.allow_flags,
        allow_missing_assets=args.allow_missing_assets,
    )
    if args.source_offset >= len(clean_candidates):
        raise RuntimeError(
            f"--source-offset={args.source_offset} vượt số ứng viên sạch còn lại ({len(clean_candidates)})"
        )
    planned = clean_candidates[args.source_offset : args.source_offset + args.batch_size]
    if len(planned) < args.batch_size:
        raise RuntimeError(
            f"Không đủ bài sạch sau offset, cần {args.batch_size} nhưng còn {len(planned)}"
        )

    batch_name = args.batch_name.strip() or f"batch-{len(import_log.get('batches', [])) + 1:02d}"
    stage_label = args.stage_label.strip() or f"Chặng import - {batch_name}"
    if args.report_path.strip():
        report_path = ROOT / args.report_path.strip()
    else:
        report_path = ROOT / "docs" / f"update-800-bai-{batch_name}.md"
    report_path.parent.mkdir(parents=True, exist_ok=True)

    records_by_section = {"thu-vien": [], "ban-tin": []}
    records_by_id = {"thu-vien": {}, "ban-tin": {}}

    for idx, item in enumerate(data_articles):
        section = item["section"]
        article_id = item["href"]
        if article_id in records_by_id[section]:
            # Guard idempotency: nếu data cũ có trùng id thì giữ bản đầu.
            continue
        rec = {
            "file": Path(item["href"]).name.replace(".html", ".htm"),
            "target_root": item["href"],
            "section": section,
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
            "hub_image": item.get("image") or FEATURE_IMAGE_PATH,
            "catalog_index": idx,
        }
        records_by_id[section][article_id] = rec

    all_lookup = load_source_lookup()
    # Manifest rows contain the final imported target_path. Prefer them over
    # the source-file stub lookup so internal links resolve to article pages.
    all_lookup.update(manifest_lookup)
    selected_lookup = build_selected_lookup(planned)
    assets_to_copy: set[str] = set()
    imported_target_set = set(item["href"] for item in data_articles)

    upserted_records: List[Dict] = []
    start_index = len(records_by_id["thu-vien"]) + len(records_by_id["ban-tin"])
    for offset, row in enumerate(planned):
        record = build_record(
            row,
            catalog_index=start_index + offset,
            selected_lookup=selected_lookup,
            all_lookup=all_lookup,
            imported_target_set=imported_target_set,
            assets_to_copy=assets_to_copy,
        )
        records_by_id[record["section"]][record["target_root"]] = record
        imported_target_set.add(record["target_root"])
        upserted_records.append(record)

    for section in ("thu-vien", "ban-tin"):
        records_by_section[section] = list(records_by_id[section].values())

    for section in ("thu-vien", "ban-tin"):
        records_by_section[section].sort(key=lambda r: fold(r["title"]))
        for idx, rec in enumerate(records_by_section[section]):
            rec["catalog_index"] = idx

    rebuild_articles(upserted_records)
    copied, missing_assets = copy_assets(assets_to_copy)

    page_maps = {}
    for section in ("thu-vien", "ban-tin"):
        total_pages = max(1, math.ceil(len(records_by_section[section]) / PAGE_SIZE))
        page_maps[section] = build_page_map(section, total_pages)
    rebuild_hub_pages(records_by_section, page_maps)

    index_data = build_content_index(records_by_section)
    write_content_index(index_data)
    write_data_artifacts(records_by_section, index_data, page_maps)
    write_taxonomy_data(records_by_section)
    write_sitemap(index_data, page_maps)
    write_batch_report(
        report_path=report_path,
        stage_label=stage_label,
        batch_name=batch_name,
        imported=upserted_records,
        copied=copied,
        missing_assets=missing_assets,
        page_maps=page_maps,
        criteria_text=criteria_text,
    )

    counts = {
        "thu_vien_count": len(records_by_section["thu-vien"]),
        "ban_tin_count": len(records_by_section["ban-tin"]),
        "thu_vien_pages": len(page_maps["thu-vien"]),
        "ban_tin_pages": len(page_maps["ban-tin"]),
    }
    append_import_log(
        import_log=import_log,
        batch_name=batch_name,
        batch_size=args.batch_size,
        criteria=criteria,
        imported_rows=upserted_records,
        copied_assets=copied,
        missing_assets=missing_assets,
        counts=counts,
    )

    summary = {
        "batch_name": batch_name,
        "batch_size": args.batch_size,
        "upserted": len(upserted_records),
        "assets_copied": copied,
        "assets_missing": len(missing_assets),
        **counts,
        "report": str(report_path.relative_to(ROOT)),
        "import_log": str(IMPORT_LOG_PATH.relative_to(ROOT)),
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    if missing_assets:
        print("MISSING_ASSETS")
        for item in missing_assets:
            print(item)


if __name__ == "__main__":
    main()
