#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import shutil
import sys
from dataclasses import asdict, dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Dict, Iterable, List, Tuple


ROOT = Path(__file__).resolve().parents[1]
SITE_BASE = "https://ketoandieutam.vn"
SOURCE_SECTIONS = ("thu-vien", "ban-tin")
TARGET_DIR = "article"


@dataclass(frozen=True)
class Entry:
    index: int
    old_href: str
    new_href: str
    slug: str
    section: str
    title: str
    old_path: str
    new_path: str


def now_stamp() -> str:
    return datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, separators=(",", ": ")) + "\n",
        encoding="utf-8",
    )


def as_text(value: Any) -> str:
    return "" if value is None else str(value).strip()


def is_old_article_href(href: str) -> bool:
    return href.endswith(".html") and href.startswith(tuple(section + "/" for section in SOURCE_SECTIONS))


def build_entries(articles: List[Dict[str, Any]]) -> List[Entry]:
    entries: List[Entry] = []
    for idx, item in enumerate(articles):
        href = as_text(item.get("href") or item.get("id"))
        if not is_old_article_href(href):
            continue
        slug = Path(href).name
        entries.append(
            Entry(
                index=idx,
                old_href=href,
                new_href=f"{TARGET_DIR}/{slug}",
                slug=slug,
                section=as_text(item.get("section") or item.get("sectionKey")),
                title=as_text(item.get("title")),
                old_path=str(ROOT / href),
                new_path=str(ROOT / TARGET_DIR / slug),
            )
        )
    return entries


def validate_entries(entries: List[Entry]) -> Dict[str, Any]:
    slugs: Dict[str, List[str]] = {}
    for entry in entries:
        slugs.setdefault(entry.slug, []).append(entry.old_href)

    conflicts = {slug: hrefs for slug, hrefs in slugs.items() if len(hrefs) > 1}
    missing = [entry.old_href for entry in entries if not Path(entry.old_path).exists()]
    target_collisions = [
        entry.new_href
        for entry in entries
        if Path(entry.new_path).exists()
    ]

    data_href_set = {entry.old_href for entry in entries}
    old_direct: List[str] = []
    for section in SOURCE_SECTIONS:
        old_direct.extend(str(path.relative_to(ROOT)).replace("\\", "/") for path in (ROOT / section).glob("*.html"))
    orphan_old_direct = sorted([href for href in old_direct if href not in data_href_set])

    section_folder_mismatch = [
        {"href": entry.old_href, "section": entry.section}
        for entry in entries
        if entry.section and entry.section != entry.old_href.split("/", 1)[0]
    ]

    return {
        "slug_conflicts": conflicts,
        "missing_source_files": missing,
        "target_collisions": sorted(set(target_collisions)),
        "orphan_old_direct_files_not_in_data": orphan_old_direct,
        "section_folder_mismatch": section_folder_mismatch,
    }


def relative_article_url_from_article_dir(new_href: str) -> str:
    return "../" + new_href


def relative_article_view_store_from_article_dir(new_href: str) -> str:
    return "../data/article-views/" + new_href + ".js"


def absolute_site_url(href: str) -> str:
    return SITE_BASE.rstrip("/") + "/" + href.lstrip("/")


def replace_url_in_html_attrs(html: str, href_map: Dict[str, str]) -> Tuple[str, int]:
    attr_re = re.compile(
        r"(?P<prefix>\b(?:href|src|data-href)=)(?P<quote>[\"'])"
        r"(?P<url>(?:\.\./)?(?:thu-vien|ban-tin)/[^\"'#?]+\.html)"
        r"(?P<tail>[?#][^\"']*)?(?P=quote)",
        re.IGNORECASE,
    )
    count = 0

    def repl(match: re.Match[str]) -> str:
        nonlocal count
        url = match.group("url")
        normalized = url[3:] if url.startswith("../") else url
        new_href = href_map.get(normalized)
        if not new_href:
            return match.group(0)
        count += 1
        return (
            match.group("prefix")
            + match.group("quote")
            + relative_article_url_from_article_dir(new_href)
            + (match.group("tail") or "")
            + match.group("quote")
        )

    return attr_re.sub(repl, html), count


def update_article_meta_json(html: str, old_href: str, new_href: str) -> Tuple[str, bool]:
    script_re = re.compile(
        r"(<script\b[^>]*\bid=(?P<q>[\"'])article-meta(?P=q)[^>]*>)(?P<json>.*?)(</script>)",
        re.IGNORECASE | re.DOTALL,
    )
    match = script_re.search(html)
    if not match:
        return html, False
    try:
        payload = json.loads(match.group("json"))
    except json.JSONDecodeError:
        return html, False
    if not isinstance(payload, dict):
        return html, False

    payload["id"] = new_href
    if "href" in payload:
        payload["href"] = new_href
    if "canonical" in payload:
        payload["canonical"] = absolute_site_url(new_href)
    payload.setdefault("legacyHref", old_href)

    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ": "))
    return html[: match.start()] + match.group(1) + encoded + match.group(4) + html[match.end() :], True


def update_head_canonical(html: str, new_href: str) -> str:
    canonical = absolute_site_url(new_href)
    return re.sub(
        r'(<link\b[^>]*\brel=(["\'])canonical\2[^>]*\bhref=)(["\']).*?\3',
        lambda m: m.group(1) + m.group(3) + canonical + m.group(3),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def update_article_view_script(html: str, new_href: str) -> str:
    new_src = relative_article_view_store_from_article_dir(new_href)
    return re.sub(
        r'(<script\b[^>]*\bsrc=)(["\'])(?:\.\./)?data/article-views/(?:thu-vien|ban-tin)/[^"\']+?\.html\.js(?:\?[^"\']*)?\2',
        lambda m: m.group(1) + m.group(2) + new_src + m.group(2),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def update_body_root(html: str) -> str:
    # /article/*.html remains one folder below root, same as old /thu-vien and /ban-tin pages.
    return re.sub(
        r'(<body\b[^>]*\bdata-root=)(["\']).*?\2',
        lambda m: m.group(1) + m.group(2) + "../" + m.group(2),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def transform_article_html(html: str, entry: Entry, href_map: Dict[str, str]) -> Tuple[str, Dict[str, Any]]:
    changed_links = 0
    html = update_head_canonical(html, entry.new_href)
    html = update_article_view_script(html, entry.new_href)
    html = update_body_root(html)
    html, meta_ok = update_article_meta_json(html, entry.old_href, entry.new_href)
    html, changed_links = replace_url_in_html_attrs(html, href_map)
    return html, {
        "article_meta_updated": meta_ok,
        "internal_links_rewritten": changed_links,
    }


def redirect_stub_html(entry: Entry) -> str:
    target = "../" + entry.new_href
    title = entry.title or entry.slug
    canonical = absolute_site_url(entry.new_href)
    escaped_title = (
        title.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")
    )
    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đang chuyển hướng | {escaped_title}</title>
  <meta name="robots" content="noindex,follow">
  <link rel="canonical" href="{canonical}">
  <meta http-equiv="refresh" content="0; url={target}">
</head>
<body>
  <p>Bài viết đã chuyển sang <a href="{target}">{target}</a>.</p>
  <script>location.replace({json.dumps(target, ensure_ascii=False)});</script>
</body>
</html>
"""


def update_articles_data(articles: List[Dict[str, Any]], href_map: Dict[str, str]) -> Tuple[List[Dict[str, Any]], int]:
    updated = 0
    result: List[Dict[str, Any]] = []
    for item in articles:
        row = dict(item)
        href = as_text(row.get("href") or row.get("id"))
        new_href = href_map.get(href)
        if new_href:
            row.setdefault("legacyHref", href)
            row["id"] = new_href
            row["href"] = new_href
            row["canonical"] = absolute_site_url(new_href)
            updated += 1
        result.append(row)
    return result, updated


def discover_internal_link_rewrites(entries: List[Entry], href_map: Dict[str, str]) -> Dict[str, Any]:
    files = 0
    total = 0
    samples: List[Dict[str, Any]] = []
    for entry in entries:
        path = Path(entry.old_path)
        if not path.exists():
            continue
        _, count = replace_url_in_html_attrs(path.read_text(encoding="utf-8", errors="replace"), href_map)
        if count:
            files += 1
            total += count
            if len(samples) < 20:
                samples.append({"href": entry.old_href, "rewrite_count": count})
    return {
        "files": files,
        "links": total,
        "samples": samples,
    }


def storage_files() -> List[Path]:
    return [
        ROOT / "admin/storage/article-drafts.json",
        ROOT / "admin/storage/article-review-status.json",
        ROOT / "admin/storage/article-media-index.json",
        ROOT / "admin/storage/publish-history.json",
        ROOT / "admin/storage/parser-audit.json",
    ]


def rewrite_ids_recursive(value: Any, href_map: Dict[str, str]) -> Any:
    if isinstance(value, str):
        return href_map.get(value, value)
    if isinstance(value, list):
        return [rewrite_ids_recursive(item, href_map) for item in value]
    if isinstance(value, dict):
        rewritten: Dict[str, Any] = {}
        for key, child in value.items():
            new_key = href_map.get(key, key)
            rewritten[new_key] = rewrite_ids_recursive(child, href_map)
        return rewritten
    return value


def backup_path_for(run_dir: Path, target: Path) -> Path:
    rel = target.relative_to(ROOT)
    return run_dir / "backups" / rel


def backup_file(run_dir: Path, target: Path, manifest_ops: List[Dict[str, Any]]) -> None:
    rel = str(target.relative_to(ROOT)).replace("\\", "/")
    if target.exists():
        bkp = backup_path_for(run_dir, target)
        bkp.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, bkp)
        manifest_ops.append({"path": rel, "backup": str(bkp.relative_to(ROOT)).replace("\\", "/"), "rollback": "restore"})
    else:
        manifest_ops.append({"path": rel, "backup": None, "rollback": "remove_created"})


def build_plan(entries: List[Entry], validations: Dict[str, Any], href_map: Dict[str, str]) -> Dict[str, Any]:
    link_rewrites = discover_internal_link_rewrites(entries, href_map)
    existing_storage = [str(path.relative_to(ROOT)).replace("\\", "/") for path in storage_files() if path.exists()]
    fatal = bool(validations["slug_conflicts"] or validations["missing_source_files"] or validations["target_collisions"])
    return {
        "generated_at": datetime.now(UTC).isoformat(),
        "mode": "dry-run",
        "target_dir": TARGET_DIR,
        "fatal": fatal,
        "counts": {
            "entries": len(entries),
            "files_to_copy_to_article": len(entries),
            "old_files_to_replace_with_redirect_stub": len(entries),
            "data_articles_to_update": len(entries),
            "internal_link_files_to_rewrite": link_rewrites["files"],
            "internal_links_to_rewrite": link_rewrites["links"],
            "storage_files_to_rewrite_if_present": len(existing_storage),
            "section_folder_mismatch_now": len(validations["section_folder_mismatch"]),
        },
        "validations": validations,
        "existing_storage_files": existing_storage,
        "link_rewrite_samples": link_rewrites["samples"],
        "sample_entries": [asdict(entry) for entry in entries[:20]],
        "post_apply_required": [
            "Run full public rebuild: python3 tools/rebuild_public_from_articles.py --mode full --include-hub-pages",
            "Verify all /article/*.html files parse in admin.",
            "Verify old /thu-vien/*.html and /ban-tin/*.html redirect stubs load.",
            "Crawl generated hubs and sitemap for zero old article URLs except redirect stubs.",
        ],
    }


def apply_migration(entries: List[Entry], href_map: Dict[str, str], report_path: Path | None) -> Dict[str, Any]:
    run_dir = ROOT / ".m" / "article-url-migration" / now_stamp()
    run_dir.mkdir(parents=True, exist_ok=True)
    rollback_ops: List[Dict[str, Any]] = []
    transform_stats = {"copied": 0, "stubs": 0, "internal_links_rewritten": 0, "meta_updated": 0}

    data_path = ROOT / "data/articles.json"
    backup_file(run_dir, data_path, rollback_ops)
    articles = read_json(data_path)
    updated_articles, updated_count = update_articles_data(articles, href_map)

    for entry in entries:
        old_path = Path(entry.old_path)
        new_path = Path(entry.new_path)
        backup_file(run_dir, new_path, rollback_ops)
        backup_file(run_dir, old_path, rollback_ops)
        transformed, stats = transform_article_html(old_path.read_text(encoding="utf-8", errors="replace"), entry, href_map)
        new_path.parent.mkdir(parents=True, exist_ok=True)
        new_path.write_text(transformed, encoding="utf-8")
        old_path.write_text(redirect_stub_html(entry), encoding="utf-8")
        transform_stats["copied"] += 1
        transform_stats["stubs"] += 1
        transform_stats["internal_links_rewritten"] += int(stats["internal_links_rewritten"])
        transform_stats["meta_updated"] += 1 if stats["article_meta_updated"] else 0

    write_json(data_path, updated_articles)

    storage_updated: List[str] = []
    for path in storage_files():
        if not path.exists():
            continue
        backup_file(run_dir, path, rollback_ops)
        payload = read_json(path)
        write_json(path, rewrite_ids_recursive(payload, href_map))
        storage_updated.append(str(path.relative_to(ROOT)).replace("\\", "/"))

    manifest = {
        "generated_at": datetime.now(UTC).isoformat(),
        "run_dir": str(run_dir.relative_to(ROOT)).replace("\\", "/"),
        "entries": [asdict(entry) for entry in entries],
        "href_map": href_map,
        "rollback_ops": rollback_ops,
        "stats": {
            **transform_stats,
            "data_articles_updated": updated_count,
            "storage_files_updated": storage_updated,
        },
    }
    manifest_path = run_dir / "manifest.json"
    write_json(manifest_path, manifest)
    if report_path:
        write_json(report_path, manifest)
    return manifest


def rollback_manifest(manifest_path: Path) -> Dict[str, Any]:
    manifest = read_json(manifest_path)
    ops = list(reversed(manifest.get("rollback_ops", [])))
    restored = 0
    removed = 0
    for op in ops:
        path = ROOT / op["path"]
        backup = op.get("backup")
        if backup:
            backup_abs = ROOT / backup
            if backup_abs.exists():
                path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(backup_abs, path)
                restored += 1
        elif op.get("rollback") == "remove_created" and path.exists():
            path.unlink()
            removed += 1
    return {"ok": True, "restored": restored, "removed_created_files": removed}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Migrate article URLs from /thu-vien|/ban-tin to /article")
    parser.add_argument("--apply", action="store_true", help="Apply migration. Default is dry-run only.")
    parser.add_argument("--yes", action="store_true", help="Required with --apply.")
    parser.add_argument("--write-report", default="", help="Optional JSON report/manifest path.")
    parser.add_argument("--rollback", default="", help="Rollback from a manifest.json created by --apply.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    report_path = Path(args.write_report) if args.write_report else None
    if report_path and not report_path.is_absolute():
        report_path = ROOT / report_path

    if args.rollback:
        result = rollback_manifest(Path(args.rollback) if Path(args.rollback).is_absolute() else ROOT / args.rollback)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    articles_raw = read_json(ROOT / "data/articles.json")
    if not isinstance(articles_raw, list):
        raise RuntimeError("data/articles.json must be a list")
    articles: List[Dict[str, Any]] = [item for item in articles_raw if isinstance(item, dict)]
    entries = build_entries(articles)
    href_map = {entry.old_href: entry.new_href for entry in entries}
    validations = validate_entries(entries)
    plan = build_plan(entries, validations, href_map)

    if not args.apply:
        if report_path:
            write_json(report_path, plan)
        print(json.dumps(plan, ensure_ascii=False, indent=2))
        return 0 if not plan["fatal"] else 2

    if not args.yes:
        print(json.dumps({"ok": False, "error": "--apply requires --yes"}, ensure_ascii=False, indent=2), file=sys.stderr)
        return 2
    if plan["fatal"]:
        print(json.dumps({"ok": False, "error": "Fatal validation failed", "validations": validations}, ensure_ascii=False, indent=2), file=sys.stderr)
        return 3

    manifest = apply_migration(entries, href_map, report_path)
    print(json.dumps({"ok": True, "manifest": manifest.get("run_dir"), "stats": manifest.get("stats")}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
