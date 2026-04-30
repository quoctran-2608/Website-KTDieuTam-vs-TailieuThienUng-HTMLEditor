#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple


ROOT = Path(__file__).resolve().parents[1]
SITE_BASE = "https://ketoandieutam.vn"


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def parse_content_index() -> Dict[str, Any]:
    raw = (ROOT / "content-index.js").read_text(encoding="utf-8", errors="replace").strip()
    prefix = "window.KetoanDieuTamContentIndex="
    if raw.startswith(prefix):
        raw = raw[len(prefix) :]
    if raw.endswith(";"):
        raw = raw[:-1]
    return json.loads(raw)


def file_count(path: Path, pattern: str) -> int:
    return len(list(path.glob(pattern))) if path.exists() else 0


def verify_articles(articles: List[Dict[str, Any]]) -> Tuple[List[Any], Dict[str, int]]:
    errors: List[Any] = []
    redirects_text = (ROOT / "_redirects").read_text(encoding="utf-8", errors="replace") if (ROOT / "_redirects").exists() else ""
    htaccess_text = (ROOT / ".htaccess").read_text(encoding="utf-8", errors="replace") if (ROOT / ".htaccess").exists() else ""
    ids = [str(item.get("id", "")).strip() for item in articles]
    hrefs = [str(item.get("href", "")).strip() for item in articles]
    if len(ids) != len(set(ids)):
        errors.append(["duplicate_ids", len(ids) - len(set(ids))])
    if len(hrefs) != len(set(hrefs)):
        errors.append(["duplicate_hrefs", len(hrefs) - len(set(hrefs))])

    article_files = 0
    legacy_stubs = 0
    legacy_redirect_rules = 0
    for item in articles:
        href = str(item.get("href", "")).strip()
        article_id = str(item.get("id", "")).strip()
        legacy = str(item.get("legacyHref", "")).strip()
        if not href.startswith("article/"):
            errors.append(["bad_href_prefix", href])
            continue
        if article_id != href:
            errors.append(["id_href_mismatch", article_id, href])
            continue

        article_path = ROOT / href
        view_json = ROOT / "data/article-views" / f"{href}.json"
        view_js = ROOT / "data/article-views" / f"{href}.js"
        if not article_path.exists():
            errors.append(["missing_article_file", href])
            continue
        if not view_json.exists():
            errors.append(["missing_article_view_json", href])
        if not view_js.exists():
            errors.append(["missing_article_view_js", href])
        article_files += 1
        html = article_path.read_text(encoding="utf-8", errors="replace")
        if f'{SITE_BASE}/{href}' not in html:
            errors.append(["canonical_missing", href])
        if f"../data/article-views/{href}.js" not in html:
            errors.append(["article_view_script_missing", href])
        meta_match = re.search(r"<script[^>]+id=[\"']article-meta[\"'][^>]*>(.*?)</script>", html, re.S)
        if not meta_match:
            errors.append(["article_meta_missing", href])
        else:
            try:
                meta = json.loads(meta_match.group(1))
                if meta.get("id") != href:
                    errors.append(["article_meta_id_mismatch", href, meta.get("id")])
            except json.JSONDecodeError as exc:
                errors.append(["article_meta_invalid_json", href, str(exc)])

        if legacy:
            stub_path = ROOT / legacy
            if not stub_path.exists():
                redirects_rule = f"/{legacy} /{href} 301"
                htaccess_rule = f"Redirect 301 /{legacy} /{href}"
                if redirects_rule in redirects_text or htaccess_rule in htaccess_text:
                    legacy_redirect_rules += 1
                else:
                    errors.append(["missing_legacy_stub_or_redirect_rule", legacy, href])
            else:
                stub = stub_path.read_text(encoding="utf-8", errors="replace")
                if "noindex,follow" not in stub or ("../" + href) not in stub or f"{SITE_BASE}/{href}" not in stub:
                    errors.append(["bad_legacy_stub", legacy, href])
                else:
                    legacy_stubs += 1
        if len(errors) > 50:
            break

    return errors, {
        "article_files_checked": article_files,
        "legacy_stubs_checked": legacy_stubs,
        "legacy_redirect_rules_checked": legacy_redirect_rules,
    }


def verify_no_old_direct_article_links() -> List[Any]:
    errors: List[Any] = []
    attr_re = re.compile(
        r"""(?:href|src|data-href)=(["'])(?P<url>(?:\.\./)?(?:thu-vien|ban-tin)/[^"'#?]+\.html)(?:[?#][^"']*)?\1""",
        re.I,
    )
    for path in (ROOT / "article").glob("*.html"):
        hits = attr_re.findall(path.read_text(encoding="utf-8", errors="replace"))
        if hits:
            errors.append(["old_direct_article_links", str(path.relative_to(ROOT)), len(hits)])
            if len(errors) >= 20:
                break
    return errors


def verify_public_indexes() -> List[Any]:
    errors: List[Any] = []
    index = parse_content_index()
    bad_ids = [key for key in index.get("articles", {}) if not str(key).startswith("article/")]
    if bad_ids:
        errors.append(["bad_content_index_article_ids", bad_ids[:10]])

    for section in ["thu-vien", "ban-tin"]:
        hub = read_json(ROOT / f"data/hubs/{section}.json")
        bad_hrefs = [
            item.get("href")
            for item in hub.get("articles", [])
            if not str(item.get("href", "")).startswith("article/")
        ]
        if bad_hrefs:
            errors.append(["bad_hub_article_hrefs", section, bad_hrefs[:10]])
    return errors


def verify_sitemap(articles: List[Dict[str, Any]]) -> List[Any]:
    errors: List[Any] = []
    sitemap = ROOT / "sitemap.xml"
    if not sitemap.exists():
        return [["missing_sitemap"]]
    text = sitemap.read_text(encoding="utf-8", errors="replace")
    missing = []
    for item in articles:
        href = str(item.get("href", "")).strip()
        if href and f"{SITE_BASE}/{href}" not in text:
            missing.append(href)
            if len(missing) >= 10:
                break
    if missing:
        errors.append(["sitemap_missing_article_urls", missing])
    legacy_hits = []
    for item in articles:
        legacy = str(item.get("legacyHref", "")).strip()
        if legacy and f"{SITE_BASE}/{legacy}" in text:
            legacy_hits.append(legacy)
            if len(legacy_hits) >= 10:
                break
    if legacy_hits:
        errors.append(["sitemap_contains_legacy_article_urls", legacy_hits])
    return errors


def main() -> int:
    articles = read_json(ROOT / "data/articles.json")
    if not isinstance(articles, list):
        print(json.dumps({"ok": False, "error": "data/articles.json is not a list"}, ensure_ascii=False, indent=2))
        return 2

    article_errors, article_counts = verify_articles([item for item in articles if isinstance(item, dict)])
    errors: List[Any] = []
    errors.extend(article_errors)
    errors.extend(verify_no_old_direct_article_links())
    errors.extend(verify_public_indexes())
    errors.extend(verify_sitemap([item for item in articles if isinstance(item, dict)]))

    report = {
        "ok": not errors,
        "articles": len(articles),
        **article_counts,
        "article_view_json_new": file_count(ROOT / "data/article-views/article", "*.json"),
        "article_view_json_legacy_left": (
            file_count(ROOT / "data/article-views/thu-vien", "*.json")
            + file_count(ROOT / "data/article-views/ban-tin", "*.json")
        ),
        "errors": errors,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if not errors else 2


if __name__ == "__main__":
    raise SystemExit(main())
