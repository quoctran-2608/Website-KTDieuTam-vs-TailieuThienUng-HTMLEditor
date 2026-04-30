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
from typing import Any, Dict, List, Tuple


ROOT = Path(__file__).resolve().parents[1]
SITE_BASE = "https://ketoandieutam.vn"
ARTICLE_DIR = "article"
CONFLICT_OVERRIDES = {
    "article/lien-he.html": "cong-ty-tnhh-tu-van-dao-tao-dieu-tam.html",
}


@dataclass(frozen=True)
class Entry:
    old_href: str
    new_href: str
    slug: str
    title: str
    section: str
    legacy_href: str
    old_path: str
    new_path: str


def now_stamp() -> str:
    return datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, separators=(",", ": ")) + "\n", encoding="utf-8")


def as_text(value: Any) -> str:
    return "" if value is None else str(value).strip()


def canonical(href: str) -> str:
    return SITE_BASE.rstrip("/") + "/" + href.lstrip("/")


def build_entries(articles: List[Dict[str, Any]]) -> List[Entry]:
    entries: List[Entry] = []
    for item in articles:
        href = as_text(item.get("href") or item.get("id"))
        if not (href.startswith(f"{ARTICLE_DIR}/") and href.endswith(".html")):
            continue
        new_href = CONFLICT_OVERRIDES.get(href, Path(href).name)
        entries.append(
            Entry(
                old_href=href,
                new_href=new_href,
                slug=Path(new_href).name,
                title=as_text(item.get("title")),
                section=as_text(item.get("section")),
                legacy_href=as_text(item.get("legacyHref")),
                old_path=str(ROOT / href),
                new_path=str(ROOT / new_href),
            )
        )
    return entries


def validate_entries(entries: List[Entry]) -> Dict[str, Any]:
    target_buckets: Dict[str, List[str]] = {}
    for entry in entries:
        target_buckets.setdefault(entry.new_href, []).append(entry.old_href)
    target_conflicts = {target: olds for target, olds in target_buckets.items() if len(olds) > 1}
    missing = [entry.old_href for entry in entries if not Path(entry.old_path).exists()]
    root_collisions = []
    for entry in entries:
        target = Path(entry.new_path)
        if target.exists():
            # Existing target is allowed only if it is the same source path, which cannot happen here.
            root_collisions.append({
                "target": entry.new_href,
                "source": entry.old_href,
                "existing_type": "dir" if target.is_dir() else "file",
            })
    return {
        "target_conflicts": target_conflicts,
        "missing_article_files": missing,
        "root_collisions": root_collisions,
    }


def article_href_map(entries: List[Entry]) -> Dict[str, str]:
    mapping: Dict[str, str] = {}
    for entry in entries:
        mapping[entry.old_href] = entry.new_href
        if entry.legacy_href:
            mapping[entry.legacy_href] = entry.new_href
    return mapping


def rewrite_article_attr_urls(html: str, href_map: Dict[str, str]) -> Tuple[str, int]:
    attr_re = re.compile(
        r"(?P<prefix>\b(?:href|src|data-href)=)(?P<quote>[\"'])"
        r"(?P<url>(?:\.\./)?(?:article|thu-vien|ban-tin)/[^\"'#?]+\.html)"
        r"(?P<tail>[?#][^\"']*)?(?P=quote)",
        re.IGNORECASE,
    )
    changed = 0

    def repl(match: re.Match[str]) -> str:
        nonlocal changed
        url = match.group("url")
        normalized = url[3:] if url.startswith("../") else url
        target = href_map.get(normalized)
        if not target:
            return match.group(0)
        changed += 1
        return match.group("prefix") + match.group("quote") + target + (match.group("tail") or "") + match.group("quote")

    return attr_re.sub(repl, html), changed


def strip_parent_prefix_for_root(html: str) -> str:
    # Article pages move one level up. Any ../site asset path should become root-relative local path.
    return re.sub(
        r"(?P<prefix>\b(?:href|src|data-href)=)(?P<quote>[\"'])\.\./(?P<url>(?!\.\./)[^\"']+)(?P=quote)",
        lambda m: m.group("prefix") + m.group("quote") + m.group("url") + m.group("quote"),
        html,
    )


def update_article_meta(html: str, entry: Entry) -> Tuple[str, bool]:
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
    payload["id"] = entry.new_href
    payload["href"] = entry.new_href
    payload["canonical"] = canonical(entry.new_href)
    payload["articleHref"] = entry.old_href
    if entry.legacy_href:
        payload["legacyHref"] = entry.legacy_href
    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ": "))
    return html[: match.start()] + match.group(1) + encoded + match.group(4) + html[match.end() :], True


def update_canonical(html: str, new_href: str) -> str:
    return re.sub(
        r'(<link\b[^>]*\brel=(["\'])canonical\2[^>]*\bhref=)(["\']).*?\3',
        lambda m: m.group(1) + m.group(3) + canonical(new_href) + m.group(3),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def update_body_root(html: str) -> str:
    return re.sub(
        r'(<body\b[^>]*\bdata-root=)(["\']).*?\2',
        lambda m: m.group(1) + m.group(2) + "" + m.group(2),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def update_article_view_script(html: str, entry: Entry) -> str:
    new_src = f"data/article-views/{entry.new_href}.js"
    return re.sub(
        r'(<script\b[^>]*\bsrc=)(["\'])(?:\.\./)?data/article-views/article/[^"\']+?\.html\.js(?:\?[^"\']*)?\2',
        lambda m: m.group(1) + m.group(2) + new_src + m.group(2),
        html,
        count=1,
        flags=re.IGNORECASE,
    )


def transform_html(html: str, entry: Entry, href_map: Dict[str, str]) -> Tuple[str, Dict[str, Any]]:
    html = update_canonical(html, entry.new_href)
    html = update_body_root(html)
    html = update_article_view_script(html, entry)
    html, meta_ok = update_article_meta(html, entry)
    html, link_count = rewrite_article_attr_urls(html, href_map)
    html = strip_parent_prefix_for_root(html)
    return html, {
        "article_meta_updated": meta_ok,
        "article_links_rewritten": link_count,
    }


def update_articles_data(articles: List[Dict[str, Any]], entries: List[Entry]) -> Tuple[List[Dict[str, Any]], int]:
    by_old = {entry.old_href: entry for entry in entries}
    updated = 0
    result: List[Dict[str, Any]] = []
    for item in articles:
        row = dict(item)
        href = as_text(row.get("href") or row.get("id"))
        entry = by_old.get(href)
        if entry:
            row["id"] = entry.new_href
            row["href"] = entry.new_href
            row["canonical"] = canonical(entry.new_href)
            row["articleHref"] = entry.old_href
            if entry.legacy_href:
                row["legacyHref"] = entry.legacy_href
            updated += 1
        result.append(row)
    return result, updated


def rewrite_ids_recursive(value: Any, href_map: Dict[str, str]) -> Any:
    if isinstance(value, str):
        return href_map.get(value, value)
    if isinstance(value, list):
        return [rewrite_ids_recursive(item, href_map) for item in value]
    if isinstance(value, dict):
        rewritten: Dict[str, Any] = {}
        for key, child in value.items():
            rewritten[href_map.get(key, key)] = rewrite_ids_recursive(child, href_map)
        return rewritten
    return value


def storage_files() -> List[Path]:
    return [
        ROOT / "admin/storage/article-drafts.json",
        ROOT / "admin/storage/article-review-status.json",
        ROOT / "admin/storage/article-media-index.json",
        ROOT / "admin/storage/publish-history.json",
        ROOT / "admin/storage/parser-audit.json",
    ]


def discover_link_rewrites(entries: List[Entry], href_map: Dict[str, str]) -> Dict[str, Any]:
    files = 0
    links = 0
    samples: List[Dict[str, Any]] = []
    for entry in entries:
        path = Path(entry.old_path)
        if not path.exists():
            continue
        _, count = rewrite_article_attr_urls(path.read_text(encoding="utf-8", errors="replace"), href_map)
        if count:
            files += 1
            links += count
            if len(samples) < 20:
                samples.append({"file": entry.old_href, "links": count})
    return {"files": files, "links": links, "samples": samples}


def backup_file(run_dir: Path, path: Path, ops: List[Dict[str, Any]]) -> None:
    rel = str(path.relative_to(ROOT)).replace("\\", "/")
    if path.exists():
        backup = run_dir / "backups" / rel
        backup.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, backup)
        ops.append({"path": rel, "backup": str(backup.relative_to(ROOT)).replace("\\", "/"), "rollback": "restore"})
    else:
        ops.append({"path": rel, "backup": None, "rollback": "remove_created"})


def generate_redirect_rules(entries: List[Entry]) -> Tuple[str, str, int]:
    redirects: List[str] = []
    htaccess: List[str] = []
    for entry in entries:
        targets = [entry.old_href]
        if entry.legacy_href:
            targets.append(entry.legacy_href)
        for old in targets:
            redirects.append(f"/{old} /{entry.new_href} 301")
            htaccess.append(f"Redirect 301 /{old} /{entry.new_href}")
    return "\n".join(redirects) + "\n", "\n".join(htaccess) + "\n", len(redirects)


def build_plan(entries: List[Entry], validations: Dict[str, Any], href_map: Dict[str, str]) -> Dict[str, Any]:
    link_info = discover_link_rewrites(entries, href_map)
    fatal = bool(validations["target_conflicts"] or validations["missing_article_files"] or validations["root_collisions"])
    return {
        "generated_at": datetime.now(UTC).isoformat(),
        "mode": "dry-run",
        "target": "/article/slug.html -> /slug.html",
        "fatal": fatal,
        "counts": {
            "entries": len(entries),
            "root_html_to_create": len(entries),
            "article_dir_html_to_archive": len(entries),
            "data_articles_to_update": len(entries),
            "internal_link_files_to_rewrite": link_info["files"],
            "internal_links_to_rewrite": link_info["links"],
            "redirect_rules_to_write": len(entries) * 2,
        },
        "validations": validations,
        "conflict_overrides": CONFLICT_OVERRIDES,
        "link_rewrite_samples": link_info["samples"],
        "sample_entries": [asdict(entry) for entry in entries[:20]],
        "post_apply_required": [
            "Run full public rebuild: python3 tools/rebuild_public_from_articles.py --mode full --include-hub-pages",
            "Archive/remove old article/ directory and data/article-views/article after root article-view stores are generated.",
            "Verify with tools/verify_root_url_migration.py (to be added in apply phase).",
        ],
    }


def apply_migration(entries: List[Entry], href_map: Dict[str, str], report_path: Path | None) -> Dict[str, Any]:
    run_dir = ROOT / ".m" / "root-url-migration" / now_stamp()
    run_dir.mkdir(parents=True, exist_ok=True)
    ops: List[Dict[str, Any]] = []
    stats = {"root_files_written": 0, "article_links_rewritten": 0, "article_meta_updated": 0}

    data_path = ROOT / "data/articles.json"
    backup_file(run_dir, data_path, ops)
    backup_file(run_dir, ROOT / "_redirects", ops)
    backup_file(run_dir, ROOT / ".htaccess", ops)
    articles = read_json(data_path)
    updated_articles, updated_count = update_articles_data(articles, entries)

    for entry in entries:
        source = Path(entry.old_path)
        target = Path(entry.new_path)
        backup_file(run_dir, target, ops)
        html, transform_stats = transform_html(source.read_text(encoding="utf-8", errors="replace"), entry, href_map)
        target.write_text(html, encoding="utf-8")
        stats["root_files_written"] += 1
        stats["article_links_rewritten"] += int(transform_stats["article_links_rewritten"])
        stats["article_meta_updated"] += 1 if transform_stats["article_meta_updated"] else 0

    write_json(data_path, updated_articles)

    storage_updated: List[str] = []
    for path in storage_files():
        if not path.exists():
            continue
        backup_file(run_dir, path, ops)
        write_json(path, rewrite_ids_recursive(read_json(path), href_map))
        storage_updated.append(str(path.relative_to(ROOT)).replace("\\", "/"))

    redirects, htaccess, redirect_count = generate_redirect_rules(entries)
    (ROOT / "_redirects").write_text(redirects, encoding="utf-8")
    (ROOT / ".htaccess").write_text(htaccess, encoding="utf-8")

    manifest = {
        "generated_at": datetime.now(UTC).isoformat(),
        "run_dir": str(run_dir.relative_to(ROOT)).replace("\\", "/"),
        "entries": [asdict(entry) for entry in entries],
        "href_map": href_map,
        "rollback_ops": ops,
        "stats": {
            **stats,
            "data_articles_updated": updated_count,
            "redirect_rules_written": redirect_count,
            "storage_files_updated": storage_updated,
        },
    }
    write_json(run_dir / "manifest.json", manifest)
    if report_path:
        write_json(report_path, manifest)
    return manifest


def rollback(manifest_path: Path) -> Dict[str, Any]:
    manifest = read_json(manifest_path)
    restored = 0
    removed = 0
    for op in reversed(manifest.get("rollback_ops", [])):
        path = ROOT / op["path"]
        backup = op.get("backup")
        if backup:
            source = ROOT / backup
            if source.exists():
                path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, path)
                restored += 1
        elif op.get("rollback") == "remove_created" and path.exists():
            path.unlink()
            removed += 1
    return {"ok": True, "restored": restored, "removed_created": removed}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Migrate article URLs from /article/slug.html to /slug.html")
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--yes", action="store_true")
    parser.add_argument("--write-report", default="")
    parser.add_argument("--rollback", default="")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    report_path = Path(args.write_report) if args.write_report else None
    if report_path and not report_path.is_absolute():
        report_path = ROOT / report_path

    if args.rollback:
        result = rollback(Path(args.rollback) if Path(args.rollback).is_absolute() else ROOT / args.rollback)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    raw = read_json(ROOT / "data/articles.json")
    if not isinstance(raw, list):
        raise RuntimeError("data/articles.json must be a list")
    articles = [item for item in raw if isinstance(item, dict)]
    entries = build_entries(articles)
    href_map = article_href_map(entries)
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
    print(json.dumps({"ok": True, "manifest": manifest["run_dir"], "stats": manifest["stats"]}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
