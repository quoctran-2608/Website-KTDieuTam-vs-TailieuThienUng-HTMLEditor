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


def is_root_article_href(href: str) -> bool:
    return href.endswith(".html") and "/" not in href and href not in {
        "index.html",
        "thu-vien.html",
        "ban-tin.html",
        "lien-he.html",
        "gioi-thieu.html",
        "giai-phap.html",
        "dao-tao.html",
        "tuyen-dung.html",
    }


def count_files(path: Path, pattern: str) -> int:
    return len(list(path.glob(pattern))) if path.exists() else 0


def verify_articles(articles: List[Dict[str, Any]]) -> Tuple[List[Any], Dict[str, int]]:
    errors: List[Any] = []
    redirects = (ROOT / "_redirects").read_text(encoding="utf-8", errors="replace") if (ROOT / "_redirects").exists() else ""
    htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8", errors="replace") if (ROOT / ".htaccess").exists() else ""
    ids = [str(item.get("id", "")).strip() for item in articles]
    hrefs = [str(item.get("href", "")).strip() for item in articles]
    if len(ids) != len(set(ids)):
        errors.append(["duplicate_ids", len(ids) - len(set(ids))])
    if len(hrefs) != len(set(hrefs)):
        errors.append(["duplicate_hrefs", len(hrefs) - len(set(hrefs))])

    article_files = 0
    redirects_checked = 0
    for item in articles:
        href = str(item.get("href", "")).strip()
        article_id = str(item.get("id", "")).strip()
        article_href = str(item.get("articleHref", "")).strip()
        legacy_href = str(item.get("legacyHref", "")).strip()
        if not is_root_article_href(href):
            errors.append(["bad_root_href", href])
            continue
        if article_id != href:
            errors.append(["id_href_mismatch", article_id, href])
            continue
        if not article_href.startswith("article/"):
            errors.append(["missing_articleHref", href, article_href])
        if not legacy_href.startswith(("thu-vien/", "ban-tin/")):
            errors.append(["missing_legacyHref", href, legacy_href])

        path = ROOT / href
        if not path.exists():
            errors.append(["missing_root_article_file", href])
            continue
        article_files += 1
        html = path.read_text(encoding="utf-8", errors="replace")
        if f"{SITE_BASE}/{href}" not in html:
            errors.append(["canonical_missing", href])
        if f"data/article-views/{href}.js" not in html:
            errors.append(["article_view_script_missing", href])
        if 'data-root=""' not in html and "data-root=''" not in html:
            errors.append(["body_data_root_not_empty", href])
        meta_match = re.search(r"<script[^>]+id=[\"']article-meta[\"'][^>]*>(.*?)</script>", html, re.S)
        if not meta_match:
            errors.append(["article_meta_missing", href])
        else:
            try:
                meta = json.loads(meta_match.group(1))
                if meta.get("id") != href:
                    errors.append(["article_meta_id_mismatch", href, meta.get("id")])
                if meta.get("articleHref") != article_href:
                    errors.append(["article_meta_articleHref_mismatch", href, meta.get("articleHref")])
            except json.JSONDecodeError as exc:
                errors.append(["article_meta_invalid_json", href, str(exc)])

        for old in [article_href, legacy_href]:
            if not old:
                continue
            redirects_rule = f"/{old} /{href} 301"
            htaccess_rule = f"Redirect 301 /{old} /{href}"
            if redirects_rule in redirects or htaccess_rule in htaccess:
                redirects_checked += 1
            else:
                errors.append(["missing_redirect_rule", old, href])
        if len(errors) > 50:
            break

    return errors, {
        "root_article_files_checked": article_files,
        "redirect_rules_checked": redirects_checked,
    }


def verify_no_old_article_links() -> List[Any]:
    errors: List[Any] = []
    attr_re = re.compile(
        r"""(?:href|src|data-href)=(["'])(?P<url>(?:\.\./)?(?:article|thu-vien|ban-tin)/[^"'#?]+\.html)(?:[?#][^"']*)?\1""",
        re.I,
    )
    # Only root article files should be checked here; hub pagination still uses /thu-vien/trang/*.
    articles = read_json(ROOT / "data/articles.json")
    for item in articles:
        href = str(item.get("href", "")).strip()
        path = ROOT / href
        if not path.exists():
            continue
        hits = attr_re.findall(path.read_text(encoding="utf-8", errors="replace"))
        if hits:
            errors.append(["old_article_links", href, len(hits)])
            if len(errors) >= 20:
                break
    return errors


def verify_public_indexes() -> List[Any]:
    errors: List[Any] = []
    index = parse_content_index()
    bad_ids = [key for key in index.get("articles", {}) if not is_root_article_href(str(key))]
    if bad_ids:
        errors.append(["bad_content_index_article_ids", bad_ids[:10]])
    for section in ["thu-vien", "ban-tin"]:
        hub = read_json(ROOT / f"data/hubs/{section}.json")
        bad = [item.get("href") for item in hub.get("articles", []) if not is_root_article_href(str(item.get("href", "")))]
        if bad:
            errors.append(["bad_hub_hrefs", section, bad[:10]])
    return errors


def verify_sitemap(articles: List[Dict[str, Any]]) -> List[Any]:
    errors: List[Any] = []
    text = (ROOT / "sitemap.xml").read_text(encoding="utf-8", errors="replace")
    missing = []
    bad_old = []
    for item in articles:
        href = str(item.get("href", "")).strip()
        if href and f"{SITE_BASE}/{href}" not in text:
            missing.append(href)
            if len(missing) >= 10:
                break
        for old_key in ["articleHref", "legacyHref"]:
            old = str(item.get(old_key, "")).strip()
            if old and f"{SITE_BASE}/{old}" in text:
                bad_old.append(old)
                if len(bad_old) >= 10:
                    break
    if missing:
        errors.append(["sitemap_missing_root_article_urls", missing])
    if bad_old:
        errors.append(["sitemap_contains_old_article_urls", bad_old])
    return errors


def main() -> int:
    raw = read_json(ROOT / "data/articles.json")
    if not isinstance(raw, list):
        print(json.dumps({"ok": False, "error": "data/articles.json is not a list"}, ensure_ascii=False, indent=2))
        return 2
    articles = [item for item in raw if isinstance(item, dict)]
    errors: List[Any] = []
    article_errors, counts = verify_articles(articles)
    errors.extend(article_errors)
    errors.extend(verify_no_old_article_links())
    errors.extend(verify_public_indexes())
    errors.extend(verify_sitemap(articles))

    report = {
        "ok": not errors,
        "articles": len(articles),
        **counts,
        "root_article_view_json": count_files(ROOT / "data/article-views", "*.html.json"),
        "article_dir_exists": (ROOT / "article").exists(),
        "article_view_article_dir_exists": (ROOT / "data/article-views/article").exists(),
        "errors": errors,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if not errors else 2


if __name__ == "__main__":
    raise SystemExit(main())
