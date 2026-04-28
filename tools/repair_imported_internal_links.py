#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import importlib.util
import json
import re
import unicodedata
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple
from urllib.parse import parse_qs, unquote, urlsplit


ROOT = Path(__file__).resolve().parents[1]
SRC_ROOT = Path("/mnt/d/WORKING/KetoanThienUng/TailieuKeToanThienUng/bai-moi-cap-nhat")
IMPORT_TOOL_PATH = ROOT / "tools" / "import_stage1_20.py"
IMPORT_LOG_PATH = ROOT / "docs" / "update-800-bai-import-log.json"
MANIFEST_PATH = ROOT / "docs" / "update-800-bai-manifest.json"
ARTICLES_PATH = ROOT / "data" / "articles.json"
REPORT_JSON = ROOT / "docs" / "imported-internal-link-repair.json"
REPORT_MD = ROOT / "docs" / "imported-internal-link-repair.md"

ANCHOR_RE = re.compile(r"<a\b(?P<attrs>[^>]*)>(?P<body>.*?)</a>", re.IGNORECASE | re.DOTALL)
HREF_RE = re.compile(r"(?P<name>\bhref)\s*=\s*(?P<q>[\"'])(?P<url>.*?)(?P=q)", re.IGNORECASE | re.DOTALL)
ATTR_RE = re.compile(r"\b(?P<name>title|name|type)\s*=\s*(?P<q>[\"'])(?P<value>.*?)(?P=q)", re.IGNORECASE | re.DOTALL)


def load_import_tool():
    spec = importlib.util.spec_from_file_location("import_stage1_20", IMPORT_TOOL_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Cannot load {IMPORT_TOOL_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


IMPORT_TOOL = load_import_tool()


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def fold(value: str) -> str:
    value = html.unescape(value or "").lower()
    value = value.replace("đ", "d").replace("Đ", "d")
    value = "".join(ch for ch in unicodedata.normalize("NFD", value) if unicodedata.category(ch) != "Mn")
    return re.sub(r"[^a-z0-9]+", " ", value).strip()


ACRONYM_EXPANSIONS = {
    "tncn": "thu nhap ca nhan",
    "tndn": "thu nhap doanh nghiep",
    "gtgt": "gia tri gia tang",
    "ttdb": "tieu thu dac biet",
    "bhxh": "bao hiem xa hoi",
    "bhyt": "bao hiem y te",
    "bhtn": "bao hiem that nghiep",
    "cccd": "can cuoc cong dan",
    "cmnd": "chung minh nhan dan",
    "mst": "ma so thue",
    "qtt": "quyet toan thue",
    "bctc": "bao cao tai chinh",
    "hddt": "hoa don dien tu",
}

STOPWORDS = {
    "xem", "them", "tai", "ve", "va", "hoac", "theo", "nam", "moi", "nhat",
    "cach", "huong", "dan", "thu", "tuc", "mau", "so", "cac", "quy", "thang",
    "online", "qua", "mang", "tren", "duoi", "bang", "cho", "cua", "voi",
}

MANUAL_ALIASES = {
    # Legacy source linked to a removed/missing "doanh thu chưa thực hiện"
    # article. The closest current article that covers TK 3387/doanh thu chờ
    # phân bổ is the TK 338 hạch toán guide.
    "doanh thu chua thuc hien": "thu-vien/cach-hach-toan-cac-khoan-phai-tra-phai-nop-khac-tai-khoan-338.html",
    "hach toan doanh thu chua thuc hien": "thu-vien/cach-hach-toan-cac-khoan-phai-tra-phai-nop-khac-tai-khoan-338.html",
    "cach hach toan doanh thu chua thuc hien": "thu-vien/cach-hach-toan-cac-khoan-phai-tra-phai-nop-khac-tai-khoan-338.html",
    # The old article URL for "thủ tục hoàn thuế TNCN" was not imported.
    # Use the closest current guide article, not a generic legal circular.
    "thu tuc hoan thue tncn": "thu-vien/thu-tuc-hoan-thue-thu-nhap-ca-nhan-online-moi-nhat-2025.html",
    "thu tuc hoan thue thu nhap ca nhan": "thu-vien/thu-tuc-hoan-thue-thu-nhap-ca-nhan-online-moi-nhat-2025.html",
    "hoan thue thu nhap ca nhan": "thu-vien/thu-tuc-hoan-thue-thu-nhap-ca-nhan-online-moi-nhat-2025.html",
}


def expand_acronyms(text: str) -> str:
    folded = fold(text)
    tokens = folded.split()
    extra = []
    for token in tokens:
        if token in ACRONYM_EXPANSIONS:
            extra.extend(ACRONYM_EXPANSIONS[token].split())
    return " ".join(tokens + extra)


def tokens(text: str, *, meaningful: bool = False) -> List[str]:
    expanded = expand_acronyms(text)
    out = [token for token in expanded.split() if token]
    if meaningful:
        out = [token for token in out if len(token) > 1 and token not in STOPWORDS]
    return out


def slug_from_path(value: str) -> str:
    parsed = urlsplit(html.unescape(value or ""))
    name = Path(unquote(parsed.path)).name
    if not name:
        return ""
    return re.sub(r"\.(?:html?|aspx)$", "", name, flags=re.IGNORECASE).lower()


def extract_attrs(attrs: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for match in ATTR_RE.finditer(attrs or ""):
        out[match.group("name").lower()] = html.unescape(match.group("value")).strip()
    return out


def text_from_anchor(body: str) -> str:
    return " ".join(html.unescape(re.sub(r"<[^>]+>", " ", body or "")).split())


def extract_anchors(fragment: str) -> List[Dict]:
    anchors = []
    for match in ANCHOR_RE.finditer(fragment or ""):
        attrs = match.group("attrs") or ""
        href_match = HREF_RE.search(attrs)
        href = html.unescape(href_match.group("url")).strip() if href_match else ""
        anchors.append(
            {
                "start": match.start(),
                "end": match.end(),
                "html": match.group(0),
                "attrs_raw": attrs,
                "attrs": extract_attrs(attrs),
                "body": match.group("body") or "",
                "text": text_from_anchor(match.group("body") or ""),
                "href": href,
            }
        )
    return anchors


def extract_article_prose_span(page_html: str) -> Optional[Tuple[int, int]]:
    match = re.search(
        r"<div\b[^>]*class=[\"'][^\"']*\barticle-prose\b[^\"']*[\"'][^>]*>",
        page_html,
        re.IGNORECASE,
    )
    if not match:
        return None
    marker = page_html.find('<div id="articleBottomNav"', match.end())
    if marker < 0:
        marker = page_html.find('<script id="article-meta"', match.end())
    if marker < 0:
        return None
    close_start = page_html.rfind("</div>", match.end(), marker)
    if close_start < 0:
        return None
    return match.end(), close_start


def is_hub_q_fallback(href: str) -> Tuple[bool, str]:
    parsed = urlsplit(html.unescape(href or ""))
    path = parsed.path.replace("\\", "/").lower()
    if not (path.endswith("thu-vien.html") or path.endswith("ban-tin.html")):
        return False, ""
    query = parse_qs(parsed.query or "")
    q = (query.get("q") or [""])[0].strip()
    return bool(q), unquote(q)


def is_plain_index_link(href: str) -> bool:
    parsed = urlsplit(html.unescape(href or ""))
    path = parsed.path.replace("\\", "/").lower()
    return path.endswith("index.html") and not parsed.query and not parsed.fragment


def is_obvious_home_link(anchor: Dict, source_href: str = "") -> bool:
    folded_bits = fold(" ".join([anchor.get("text", ""), *(anchor.get("attrs") or {}).values()]))
    if folded_bits in {"ke toan dieu tam", "trang chu"}:
        return True
    if "ke toan dieu tam" in folded_bits and len(folded_bits.split()) <= 8:
        return True
    return is_plain_index_link(source_href)


def make_relative_article_href(target_path: str) -> str:
    return f"../{target_path.lstrip('/')}"


def moved_direct_target(href: str, indexes: Dict) -> Optional[str]:
    parsed = urlsplit(html.unescape(href or ""))
    path = parsed.path.replace("\\", "/")
    if not re.match(r"^\.\./(?:thu-vien|ban-tin)/.+\.html$", path, re.IGNORECASE):
        return None
    current_path = path.replace("../", "", 1)
    if current_path in indexes["href_set"] and (ROOT / current_path).exists():
        return None
    target = unique_slug_target(slug_from_path(path), indexes)
    if not target or target == current_path:
        return None
    return target


def replace_href(anchor_html: str, new_href: str) -> str:
    def repl(match: re.Match) -> str:
        return f'{match.group("name")}={match.group("q")}{new_href}{match.group("q")}'

    return HREF_RE.sub(repl, anchor_html, count=1)


def manifest_records() -> List[Dict]:
    raw = read_json(MANIFEST_PATH)
    return raw["records"] if isinstance(raw, dict) else raw


def imported_items() -> List[Dict]:
    log = read_json(IMPORT_LOG_PATH)
    articles = read_json(ARTICLES_PATH)
    slug_to_hrefs: Dict[str, List[str]] = {}
    for item in articles:
        href = item.get("href") or ""
        slug = slug_from_path(href)
        if slug:
            slug_to_hrefs.setdefault(slug, []).append(href)
    by_target: Dict[str, Dict] = {}
    for batch in log.get("batches", []):
        for item in batch.get("imported", []):
            target = item.get("target_path")
            if target:
                current = target
                if not (ROOT / current).exists():
                    matches = slug_to_hrefs.get(slug_from_path(target), [])
                    if len(matches) == 1:
                        current = matches[0]
                normalized = dict(item)
                normalized["original_target_path"] = target
                normalized["target_path"] = current
                by_target[current] = normalized
    return list(by_target.values())


def build_indexes() -> Dict:
    articles = read_json(ARTICLES_PATH)
    href_set = {item["href"] for item in articles if item.get("href")}
    slug_to_hrefs: Dict[str, List[str]] = {}
    for item in articles:
        href = item.get("href") or ""
        slug = slug_from_path(href)
        if slug:
            slug_to_hrefs.setdefault(slug, []).append(href)

    manifest_by_source: Dict[str, str] = {}
    for row in manifest_records():
        source = str(row.get("source_file") or "").lower()
        target = str(row.get("target_path") or "")
        if target not in href_set:
            moved_matches = slug_to_hrefs.get(slug_from_path(target), [])
            target = moved_matches[0] if len(moved_matches) == 1 else ""
        if not source or target not in href_set:
            continue
        manifest_by_source[source] = target
        resolved = IMPORT_TOOL.resolve_source_file(source)
        manifest_by_source[resolved.lower()] = target

    article_features = []
    for item in articles:
        href = item.get("href") or ""
        title = item.get("title") or ""
        tags = " ".join(item.get("tags") or [])
        meta = " ".join(
            str(item.get(key) or "")
            for key in ("excerpt", "cardBadgeLabel", "cardTopicLabel", "topicLv1Label", "topicLv2Label", "topicLv3Label")
        )
        article_features.append(
            {
                "href": href,
                "title": title,
                "title_fold": expand_acronyms(title),
                "title_tokens": set(tokens(title, meaningful=True)),
                "slug_tokens": set(tokens(slug_from_path(href), meaningful=True)),
                "tags_tokens": set(tokens(tags, meaningful=True)),
                "meta_tokens": set(tokens(meta, meaningful=True)),
            }
        )
    return {
        "href_set": href_set,
        "manifest_by_source": manifest_by_source,
        "slug_to_hrefs": slug_to_hrefs,
        "article_features": article_features,
    }


def unique_slug_target(slug: str, indexes: Dict) -> Optional[str]:
    if not slug:
        return None
    hrefs = indexes["slug_to_hrefs"].get(slug.lower()) or []
    return hrefs[0] if len(hrefs) == 1 else None


def target_from_source_href(source_href: str, indexes: Dict) -> Optional[str]:
    parsed = IMPORT_TOOL.parse_internalish_url(source_href or "")
    if not parsed:
        return None
    path, _query, _fragment = parsed
    if not path:
        return None
    candidates = [path.lower(), Path(path).name.lower()]
    for key in candidates:
        target = indexes["manifest_by_source"].get(key)
        if target:
            return target
    return unique_slug_target(slug_from_path(path), indexes)


def semantic_target(query_text: str, indexes: Dict, current_target: str) -> Tuple[Optional[str], Dict]:
    manual_target = manual_alias_target(query_text, indexes)
    if manual_target:
        return manual_target[0], {"score": 999, "runner_up": 0, "title": manual_target[1], "confidence": "manual_alias"}
    q_tokens = set(tokens(query_text, meaningful=True))
    if len(q_tokens) < 2:
        return None, {"score": 0, "runner_up": 0, "reason": "too_few_tokens"}
    ranked = []
    q_phrase = expand_acronyms(query_text)
    for feature in indexes["article_features"]:
        if feature["href"] == current_target:
            continue
        title_hit = q_tokens & feature["title_tokens"]
        slug_hit = q_tokens & feature["slug_tokens"]
        tag_hit = q_tokens & feature["tags_tokens"]
        meta_hit = q_tokens & feature["meta_tokens"]
        score = 0
        score += len(title_hit) * 16
        score += len(slug_hit) * 13
        score += len(tag_hit) * 8
        score += len(meta_hit) * 4
        if q_phrase and q_phrase in feature["title_fold"]:
            score += 160
        if feature["title_fold"] and feature["title_fold"] in q_phrase:
            score += 80
        if len(title_hit | slug_hit) >= max(2, min(4, len(q_tokens))):
            score += 35
        if score > 0:
            ranked.append((score, feature["href"], feature["title"]))
    ranked.sort(reverse=True)
    if not ranked:
        return None, {"score": 0, "runner_up": 0, "reason": "no_candidate"}
    best = ranked[0]
    runner_up = ranked[1][0] if len(ranked) > 1 else 0
    confidence = "semantic"
    # A conservative threshold prevents brand/home links from being rewritten
    # while still fixing old article links whose source file was not imported.
    if best[0] >= 70 and (best[0] - runner_up >= 12 or best[0] >= 120):
        return best[1], {
            "score": best[0],
            "runner_up": runner_up,
            "title": best[2],
            "confidence": confidence,
        }
    return None, {
        "score": best[0],
        "runner_up": runner_up,
        "title": best[2],
        "href": best[1],
        "reason": "low_confidence",
    }


def manual_alias_target(query_text: str, indexes: Dict) -> Optional[Tuple[str, str]]:
    folded_query = fold(query_text)
    for alias, target in MANUAL_ALIASES.items():
        if alias in folded_query and target in indexes["href_set"]:
            return target, alias
    return None


def query_for_anchor(current_anchor: Dict, source_anchor: Optional[Dict], hub_q: str = "") -> str:
    parts = [current_anchor.get("text", ""), hub_q]
    parts.extend((current_anchor.get("attrs") or {}).values())
    if source_anchor:
        parts.append(source_anchor.get("text", ""))
        parts.extend((source_anchor.get("attrs") or {}).values())
        parts.append(slug_from_path(source_anchor.get("href", "")))
    return " ".join(part for part in parts if part)


def resolve_anchor(current_anchor: Dict, source_anchor: Optional[Dict], indexes: Dict, current_target: str) -> Tuple[Optional[str], Dict]:
    href = current_anchor.get("href") or ""
    is_q, hub_q = is_hub_q_fallback(href)
    if is_q:
        exact = unique_slug_target(slug_from_path(hub_q) or fold(hub_q).replace(" ", "-"), indexes)
        if exact:
            return exact, {"method": "hub_q_exact_slug", "hub_q": hub_q}
        source_target = target_from_source_href((source_anchor or {}).get("href", ""), indexes)
        if source_target:
            return source_target, {"method": "source_href_manifest", "source_href": (source_anchor or {}).get("href", "")}
        target, detail = semantic_target(query_for_anchor(current_anchor, source_anchor, hub_q), indexes, current_target)
        detail["method"] = "hub_q_semantic"
        detail["hub_q"] = hub_q
        return target, detail

    if is_plain_index_link(href):
        source_href = (source_anchor or {}).get("href", "")
        if is_obvious_home_link(current_anchor, source_href):
            return None, {"method": "skip_home"}
        source_target = target_from_source_href(source_href, indexes)
        if source_target:
            return source_target, {"method": "index_source_href_manifest", "source_href": source_href}
        target, detail = semantic_target(query_for_anchor(current_anchor, source_anchor), indexes, current_target)
        detail["method"] = "index_semantic"
        detail["source_href"] = source_href
        return target, detail

    return None, {"method": "not_fallback"}


def source_anchors_for_import(import_item: Dict) -> List[Dict]:
    source_name = import_item.get("source_file_resolved") or import_item.get("source_file") or ""
    if not source_name:
        return []
    source_path = SRC_ROOT / source_name
    if not source_path.exists():
        return []
    doc = source_path.read_text(encoding="utf-8", errors="ignore")
    try:
        main = IMPORT_TOOL.extract_main_content(doc)
    except Exception:
        main = doc
    return extract_anchors(main)


def process_file(import_item: Dict, indexes: Dict, apply: bool) -> Dict:
    target = import_item["target_path"]
    page_path = ROOT / target
    result = {
        "target_path": target,
        "changed": 0,
        "resolved": [],
        "unresolved": [],
        "skipped": [],
    }
    if not page_path.exists():
        result["unresolved"].append({"reason": "missing_page"})
        return result
    page_html = page_path.read_text(encoding="utf-8", errors="ignore")
    span = extract_article_prose_span(page_html)
    if not span:
        result["unresolved"].append({"reason": "missing_article_prose_span"})
        return result
    start, end = span
    prose = page_html[start:end]
    current_anchors = extract_anchors(prose)
    source_anchors = source_anchors_for_import(import_item)
    replacements: List[Tuple[int, int, str]] = []

    for idx, anchor in enumerate(current_anchors):
        href = anchor.get("href") or ""
        source_anchor = source_anchors[idx] if idx < len(source_anchors) else None
        source_href = (source_anchor or {}).get("href", "")
        moved_target = moved_direct_target(href, indexes)
        if moved_target:
            desired_href = make_relative_article_href(moved_target)
            replacements.append((anchor["start"], anchor["end"], replace_href(anchor["html"], desired_href)))
            result["resolved"].append(
                {
                    "text": anchor.get("text", ""),
                    "old_href": href,
                    "new_href": desired_href,
                    "source_href": source_href,
                    "method": "moved_direct_slug",
                }
            )
            continue
        source_has_direct_target = bool(target_from_source_href(source_href, indexes))
        manual_target = None if source_has_direct_target else manual_alias_target(query_for_anchor(anchor, source_anchor), indexes)
        if manual_target:
            desired_href = make_relative_article_href(manual_target[0])
            if href != desired_href:
                replacements.append((anchor["start"], anchor["end"], replace_href(anchor["html"], desired_href)))
                result["resolved"].append(
                    {
                        "text": anchor.get("text", ""),
                        "old_href": href,
                        "new_href": desired_href,
                        "source_href": source_href,
                        "method": "manual_alias_normalize",
                        "alias": manual_target[1],
                    }
                )
                continue
        is_q, _hub_q = is_hub_q_fallback(href)
        is_index = is_plain_index_link(href)
        if not (is_q or is_index):
            continue
        target_href, detail = resolve_anchor(anchor, source_anchor, indexes, target)
        if not target_href:
            if detail.get("method") == "skip_home":
                result["skipped"].append({"text": anchor.get("text", ""), "href": href, "reason": "home_link"})
            elif detail.get("method") != "not_fallback":
                result["unresolved"].append(
                    {
                        "text": anchor.get("text", ""),
                        "href": href,
                        "source_href": (source_anchor or {}).get("href", ""),
                        **detail,
                    }
                )
            continue
        new_href = make_relative_article_href(target_href)
        if new_href == href:
            continue
        replacements.append((anchor["start"], anchor["end"], replace_href(anchor["html"], new_href)))
        result["resolved"].append(
            {
                "text": anchor.get("text", ""),
                "old_href": href,
                "new_href": new_href,
                "source_href": (source_anchor or {}).get("href", ""),
                **detail,
            }
        )

    if replacements:
        pieces = []
        cursor = 0
        for repl_start, repl_end, repl_html in replacements:
            pieces.append(prose[cursor:repl_start])
            pieces.append(repl_html)
            cursor = repl_end
        pieces.append(prose[cursor:])
        new_prose = "".join(pieces)
        new_html = page_html[:start] + new_prose + page_html[end:]
        if apply:
            page_path.write_text(new_html, encoding="utf-8")
        result["changed"] = len(replacements)
    return result


def write_report(results: List[Dict], apply: bool) -> Dict:
    counts = Counter()
    examples = []
    unresolved = []
    for row in results:
        if row.get("changed"):
            counts["files_changed"] += 1
            counts["links_changed"] += int(row["changed"])
        for item in row.get("resolved", []):
            counts[f"method:{item.get('method')}"] += 1
            if len(examples) < 30:
                examples.append({"page": row["target_path"], **item})
        for item in row.get("unresolved", []):
            counts["unresolved"] += 1
            if len(unresolved) < 80:
                unresolved.append({"page": row["target_path"], **item})
        if row.get("skipped"):
            counts["skipped_home"] += len(row["skipped"])

    report = {
        "generated_at": datetime.now().isoformat(timespec="seconds"),
        "applied": apply,
        "counts": dict(counts),
        "examples": examples,
        "unresolved_sample": unresolved,
    }
    if apply:
        write_json(REPORT_JSON, report)
        lines = [
            "# Imported internal link repair",
            "",
            f"- Applied: `{apply}`",
            f"- Files changed: `{counts.get('files_changed', 0)}`",
            f"- Links changed: `{counts.get('links_changed', 0)}`",
            f"- Unresolved fallback links: `{counts.get('unresolved', 0)}`",
            f"- Home/index links intentionally skipped: `{counts.get('skipped_home', 0)}`",
            "",
            "## Methods",
            "",
        ]
        for key, value in sorted(counts.items()):
            if key.startswith("method:"):
                lines.append(f"- `{key[7:]}`: `{value}`")
        lines.extend(["", "## Examples", ""])
        for item in examples[:20]:
            lines.append(f"- `{item['page']}`: {item['text']} → `{item['new_href']}` ({item['method']})")
        if unresolved:
            lines.extend(["", "## Unresolved sample", ""])
            for item in unresolved[:30]:
                lines.append(f"- `{item['page']}`: {item.get('text','')} / `{item.get('href','')}` / {item.get('reason','')}")
        REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Repair imported article internal link fallbacks.")
    parser.add_argument("--apply", action="store_true", help="Write repaired HTML files.")
    args = parser.parse_args()
    indexes = build_indexes()
    results = [process_file(item, indexes, args.apply) for item in imported_items()]
    report = write_report(results, args.apply)
    print(json.dumps(report["counts"], ensure_ascii=False, indent=2))
    if report.get("examples"):
        print("examples:")
        for item in report["examples"][:8]:
            print(f"- {item['page']}: {item['text']} -> {item['new_href']} [{item['method']}]")
    if report.get("unresolved_sample"):
        print("unresolved_sample:")
        for item in report["unresolved_sample"][:8]:
            print(f"- {item['page']}: {item.get('text','')} ({item.get('reason','')})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
