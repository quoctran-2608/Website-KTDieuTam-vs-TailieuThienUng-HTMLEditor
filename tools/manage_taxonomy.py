#!/usr/bin/env python3
from __future__ import annotations

import argparse
import importlib.util
import json
import re
import shutil
import sys
import unicodedata
from copy import deepcopy
from datetime import UTC, datetime
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT / "data"
MASTER_PATH = DATA_DIR / "taxonomy-master.json"
TAXONOMY_PATH = DATA_DIR / "taxonomy.json"
EDITOR_TAXONOMY_PATH = DATA_DIR / "editor-taxonomy.json"
ARTICLES_PATH = DATA_DIR / "articles.json"
MENU_CONFIG_PATH = DATA_DIR / "menu-config.json"
HUBS_DIR = DATA_DIR / "hubs"
BACKUP_ROOT = ROOT / ".m" / "taxonomy-admin"
REBUILD_TOOL_PATH = ROOT / "tools" / "rebuild_public_from_articles.py"

SCHEMA = "taxonomy-master.v1"
KEY_RE = re.compile(r"^[a-z0-9][a-z0-9-]*$")
SECTION_LABELS = {"thu-vien": "Thư viện", "ban-tin": "Bản tin"}
MAX_DEPTH = {"thu-vien": 5, "ban-tin": 4}
FIELD_MAP = {
    "section": "primary_category_id",
    "library_kind": "library_kind",
    "topic_lv1": "domain",
    "topic_lv2": "subdomain",
    "tool_lv3": "variant",
}
KIND_DEFAULT_META = {
    "phan-loai-moi": {
        "icon": "fa-layer-group",
        "description": "Nhóm phân loại mới đang chuẩn bị nội dung",
    },
    "huong-dan": {
        "icon": "fa-compass-drafting",
        "description": "Quy trình, cách làm, nghiệp vụ thực tế",
    },
    "bieu-mau": {
        "icon": "fa-file-lines",
        "description": "Mẫu biểu, hồ sơ, tờ khai dùng ngay",
    },
    "cong-cu": {
        "icon": "fa-screwdriver-wrench",
        "description": "Excel, HTKK, MISA và file hỗ trợ",
    },
    "van-ban": {
        "icon": "fa-scale-balanced",
        "description": "Luật, nghị định, thông tư, công văn và cập nhật pháp lý",
    },
}


class TaxonomyError(RuntimeError):
    pass


def now_z() -> str:
    return datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def write_js_store(path: Path, global_name: str, key: str, data: Any) -> None:
    payload = json.dumps(data, ensure_ascii=False, separators=(",", ":"))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        f"window.{global_name}=window.{global_name}||{{}};window.{global_name}[{json.dumps(key)}]={payload};\n",
        encoding="utf-8",
    )


def rel(path: Path) -> str:
    return str(path.relative_to(ROOT))


def split_path(value: str) -> list[str]:
    raw = value.strip().strip("/")
    if raw == "":
        raise TaxonomyError("Path không được rỗng")
    return ["" if part == "__empty__" else part for part in raw.split("/")]


def fmt_path(parts: list[str]) -> str:
    return "/".join("__empty__" if part == "" else part for part in parts)


def slugify(value: str, fallback: str = "category") -> str:
    text = (value or "").strip().lower().replace("đ", "d").replace("Đ", "d")
    text = "".join(ch for ch in unicodedata.normalize("NFD", text) if unicodedata.category(ch) != "Mn")
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return text or fallback


def normalize_key(value: str, fallback: str = "category") -> str:
    key = str(value or "").strip()
    if KEY_RE.match(key) is not None:
        return key
    key = slugify(key, fallback)
    if KEY_RE.match(key) is None:
        raise TaxonomyError("Slug phải dùng a-z, 0-9 và dấu gạch ngang")
    return key


def unique_child_key(parent: dict[str, Any], base_key: str) -> str:
    taken = {node_key(child) for child in child_nodes(parent)}
    if base_key not in taken:
        return base_key
    index = 2
    while f"{base_key}-{index}" in taken:
        index += 1
    return f"{base_key}-{index}"


def node_key(node: dict[str, Any]) -> str:
    return str(node.get("key") if "key" in node else node.get("id", ""))


def node_label(node: dict[str, Any]) -> str:
    label = str(node.get("label") or "").strip()
    return label or node_key(node)


def child_nodes(node: dict[str, Any]) -> list[dict[str, Any]]:
    return [child for child in node.get("children", []) if isinstance(child, dict)]


def root_nodes(master: dict[str, Any]) -> list[dict[str, Any]]:
    return [root for root in master.get("roots", []) if isinstance(root, dict)]


def find_node(master: dict[str, Any], parts: list[str]) -> dict[str, Any]:
    nodes = root_nodes(master)
    current: dict[str, Any] | None = None
    for key in parts:
        current = next((node for node in nodes if node_key(node) == key), None)
        if current is None:
            raise TaxonomyError(f"Không tìm thấy node: {fmt_path(parts)}")
        nodes = child_nodes(current)
    return current


def find_parent(master: dict[str, Any], parts: list[str]) -> tuple[dict[str, Any], dict[str, Any] | None]:
    if len(parts) == 1:
        return find_node(master, parts), None
    parent = find_node(master, parts[:-1])
    target = next((node for node in child_nodes(parent) if node_key(node) == parts[-1]), None)
    if target is None:
        raise TaxonomyError(f"Không tìm thấy node: {fmt_path(parts)}")
    return target, parent


def article_path_parts(article: dict[str, Any], master: dict[str, Any] | None = None) -> list[str]:
    section = str(article.get("section") or "").strip()
    parts = [section]
    if section == "thu-vien":
        parts.append(str(article.get("libraryKindKey") or "").strip())
        parts.append(str(article.get("topicLv1Key") or "").strip())
        lv2 = str(article.get("topicLv2Key") or "").strip()
        lv1_node: dict[str, Any] | None = None
        if master is not None:
            try:
                lv1_node = find_node(master, parts)
            except TaxonomyError:
                lv1_node = None
        if lv2 or (lv1_node is not None and any(node_key(child) == "" for child in child_nodes(lv1_node))):
            parts.append(lv2)
        lv3 = str(article.get("topicLv3Key") or "").strip()
        if lv3:
            parts.append(lv3)
    elif section == "ban-tin":
        parts.append(str(article.get("topicLv1Key") or "").strip())
        lv2 = str(article.get("topicLv2Key") or "").strip()
        lv1_node: dict[str, Any] | None = None
        if master is not None:
            try:
                lv1_node = find_node(master, parts)
            except TaxonomyError:
                lv1_node = None
        if lv2 or (lv1_node is not None and any(node_key(child) == "" for child in child_nodes(lv1_node))):
            parts.append(lv2)
        lv3 = str(article.get("topicLv3Key") or "").strip()
        if lv3:
            parts.append(lv3)
    return parts


def path_label_field(parts: list[str]) -> str:
    section = parts[0]
    depth = len(parts)
    if section == "thu-vien":
        return {
            2: "libraryKindLabel",
            3: "topicLv1Label",
            4: "topicLv2Label",
            5: "topicLv3Label",
        }.get(depth, "")
    if section == "ban-tin":
        return {
            2: "topicLv1Label",
            3: "topicLv2Label",
            4: "topicLv3Label",
        }.get(depth, "")
    return ""


def path_key_field(parts: list[str]) -> str:
    section = parts[0]
    depth = len(parts)
    if section == "thu-vien":
        return {
            2: "libraryKindKey",
            3: "topicLv1Key",
            4: "topicLv2Key",
            5: "topicLv3Key",
        }.get(depth, "")
    if section == "ban-tin":
        return {
            2: "topicLv1Key",
            3: "topicLv2Key",
            4: "topicLv3Key",
        }.get(depth, "")
    return ""


def article_matches_prefix(article: dict[str, Any], parts: list[str], master: dict[str, Any] | None = None) -> bool:
    article_parts = article_path_parts(article, master)
    if len(article_parts) < len(parts):
        return False
    return article_parts[: len(parts)] == parts


def load_master() -> dict[str, Any]:
    if not MASTER_PATH.exists():
        raise TaxonomyError("Chưa có data/taxonomy-master.json. Chạy bootstrap-master trước.")
    master = read_json(MASTER_PATH)
    if not isinstance(master, dict):
        raise TaxonomyError("taxonomy-master.json phải là object")
    return master


def load_articles() -> list[dict[str, Any]]:
    articles = read_json(ARTICLES_PATH)
    if not isinstance(articles, list):
        raise TaxonomyError("data/articles.json phải là array")
    return [item for item in articles if isinstance(item, dict)]


def read_kind_meta() -> dict[str, dict[str, str]]:
    meta = deepcopy(KIND_DEFAULT_META)
    hub_path = HUBS_DIR / "thu-vien.json"
    if not hub_path.exists():
        return meta
    hub = read_json(hub_path)
    for item in hub.get("libraryKinds", []) if isinstance(hub, dict) else []:
        if not isinstance(item, dict):
            continue
        key = str(item.get("key") or "").strip()
        if not key:
            continue
        row = meta.setdefault(key, {})
        for field in ("icon", "description"):
            if str(item.get(field) or "").strip():
                row[field] = str(item[field])
    return meta


def clean_master_node(node: dict[str, Any], path: list[str], kind_meta: dict[str, dict[str, str]]) -> dict[str, Any]:
    key = node_key(node)
    out: dict[str, Any] = {"key": key, "label": node_label(node)}
    if len(path) == 0:
        out["system"] = True
        out["locked"] = True
    elif path[0] == "thu-vien" and len(path) == 1:
        out["system"] = True
        out["lockedKey"] = True
        out.update(kind_meta.get(key, {}))
    if child_nodes(node):
        out["children"] = [clean_master_node(child, path + [key], kind_meta) for child in child_nodes(node)]
    return out


def build_master_from_current_taxonomy() -> dict[str, Any]:
    taxonomy = read_json(TAXONOMY_PATH)
    kind_meta = read_kind_meta()
    roots = [clean_master_node(root, [], kind_meta) for root in root_nodes(taxonomy)]
    return {
        "schema": SCHEMA,
        "generatedAt": now_z(),
        "source": {
            "bootstrappedFrom": rel(TAXONOMY_PATH),
            "articles": rel(ARTICLES_PATH),
        },
        "fieldMap": FIELD_MAP,
        "rules": {
            "systemRoots": ["thu-vien", "ban-tin"],
            "maxDepth": MAX_DEPTH,
            "emptyKeyToken": "__empty__",
            "note": "Counts are derived from data/articles.json; this file stores editable taxonomy intent.",
        },
        "roots": roots,
        "toolVariants": taxonomy.get("toolVariants") if isinstance(taxonomy.get("toolVariants"), dict) else {},
    }


def collect_paths(master: dict[str, Any]) -> dict[str, dict[str, Any]]:
    paths: dict[str, dict[str, Any]] = {}

    def walk(node: dict[str, Any], path: list[str]) -> None:
        current = path + [node_key(node)]
        paths[fmt_path(current)] = node
        for child in child_nodes(node):
            walk(child, current)

    for root in root_nodes(master):
        walk(root, [])
    return paths


def validate_master(master: dict[str, Any], articles: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    if master.get("schema") != SCHEMA:
        errors.append(f"schema không hợp lệ: {master.get('schema')!r}")

    roots = root_nodes(master)
    root_keys = [node_key(root) for root in roots]
    for required in ("thu-vien", "ban-tin"):
        if required not in root_keys:
            errors.append(f"Thiếu root {required}")

    def walk(node: dict[str, Any], path: list[str]) -> None:
        key = node_key(node)
        current = path + [key]
        section = current[0] if current else ""
        if not node_label(node):
            errors.append(f"Node thiếu label: {fmt_path(current)}")
        if key != "" and KEY_RE.match(key) is None:
            errors.append(f"Key không đúng slug: {fmt_path(current)}")
        if key == "" and len(current) <= 1:
            errors.append("Root không được dùng key rỗng")
        if section in MAX_DEPTH and len(current) > MAX_DEPTH[section]:
            errors.append(f"Node vượt maxDepth: {fmt_path(current)}")
        seen: set[str] = set()
        for child in child_nodes(node):
            child_key = node_key(child)
            if child_key in seen:
                errors.append(f"Trùng sibling key {child_key!r} dưới {fmt_path(current)}")
            seen.add(child_key)
            walk(child, current)

    for root in roots:
        walk(root, [])

    if articles is not None:
        paths = collect_paths(master)
        for article in articles:
            section = str(article.get("section") or "").strip()
            if section not in ("thu-vien", "ban-tin"):
                continue
            parts = article_path_parts(article, master)
            missing_required = section == "thu-vien" and (len(parts) < 3 or any(part == "" for part in parts[:3]))
            if missing_required:
                errors.append(f"Article thiếu path bắt buộc: {article.get('href')}")
                continue
            if fmt_path(parts) not in paths:
                errors.append(f"Article path không có trong master: {article.get('href')} -> {fmt_path(parts)}")
                continue
            if section == "thu-vien" and len(parts) == 3 and child_nodes(paths[fmt_path(parts)]):
                errors.append(f"Article thiếu Cấp 3 cho nhánh có category con: {article.get('href')} -> {fmt_path(parts)}")
    return {
        "ok": not errors,
        "errors": errors,
        "warnings": warnings,
        "nodes": len(collect_paths(master)),
    }


def usage_for_path(master: dict[str, Any], articles: list[dict[str, Any]], parts: list[str]) -> dict[str, Any]:
    node = find_node(master, parts)
    matches = [article for article in articles if article_matches_prefix(article, parts, master)]
    return {
        "path": fmt_path(parts),
        "key": node_key(node),
        "label": node_label(node),
        "articleCount": len(matches),
        "sampleArticles": [
            {"href": article.get("href") or article.get("id"), "title": article.get("title")}
            for article in matches[:10]
        ],
    }


def count_for_path(master: dict[str, Any], articles: list[dict[str, Any]], parts: list[str]) -> int:
    return sum(1 for article in articles if article_matches_prefix(article, parts, master))


def public_node_from_master(master: dict[str, Any], articles: list[dict[str, Any]], node: dict[str, Any], path: list[str]) -> dict[str, Any]:
    current = path + [node_key(node)]
    out: dict[str, Any] = {
        "key": node_key(node),
        "label": node_label(node),
        "count": count_for_path(master, articles, current),
    }
    children = [
        public_node_from_master(master, articles, child, current)
        for child in child_nodes(node)
        if not bool(child.get("hidden"))
    ]
    if children:
        out["children"] = children
    return out


def build_public_taxonomy(master: dict[str, Any], articles: list[dict[str, Any]]) -> dict[str, Any]:
    roots = []
    for root in root_nodes(master):
        if bool(root.get("hidden")):
            continue
        roots.append(public_node_from_master(master, articles, root, []))
    return {
        "generatedAt": now_z(),
        "roots": roots,
        "toolVariants": master.get("toolVariants") if isinstance(master.get("toolVariants"), dict) else {},
    }


def editor_node_from_public(node: dict[str, Any], use_id: bool = False) -> dict[str, Any]:
    out = {"id" if use_id else "key": node["key"], "label": node["label"]}
    if node.get("children"):
        out["children"] = [editor_node_from_public(child) for child in node["children"]]
    return out


def build_editor_taxonomy(public_taxonomy: dict[str, Any]) -> dict[str, Any]:
    roots = []
    for root in root_nodes(public_taxonomy):
        editor_root = {"id": node_key(root), "label": node_label(root)}
        children = []
        for child in child_nodes(root):
            children.append(editor_node_from_public(child, use_id=True))
        if children:
            editor_root["children"] = children
        roots.append(editor_root)
    return {
        "generatedAt": public_taxonomy["generatedAt"],
        "roots": roots,
        "variants": {"cong-cu": public_taxonomy.get("toolVariants", {})},
        "fieldMap": FIELD_MAP,
    }


def build_library_kinds(master: dict[str, Any], articles: list[dict[str, Any]]) -> list[dict[str, Any]]:
    thu_vien = find_node(master, ["thu-vien"])
    kinds = []
    for kind in child_nodes(thu_vien):
        key = node_key(kind)
        kinds.append(
            {
                "key": key,
                "label": node_label(kind),
                "count": count_for_path(master, articles, ["thu-vien", key]),
                "href": f"thu-vien.html?kind={key}",
                "icon": str(kind.get("icon") or KIND_DEFAULT_META.get(key, {}).get("icon") or "fa-layer-group"),
                "description": str(kind.get("description") or KIND_DEFAULT_META.get(key, {}).get("description") or ""),
            }
        )
    return kinds


def merge_taxonomy_groups(groups: list[list[dict[str, Any]]]) -> list[dict[str, Any]]:
    merged: dict[str, dict[str, Any]] = {}

    def merge_node(targets: dict[str, dict[str, Any]], node: dict[str, Any]) -> None:
        key = node_key(node)
        target = targets.setdefault(
            key,
            {"key": key, "label": node_label(node), "count": 0, "_children": {}},
        )
        target["label"] = node_label(node) or target["label"]
        target["count"] += int(node.get("count") or 0)
        for child in child_nodes(node):
            merge_node(target["_children"], child)

    def finalize(nodes: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
        out: list[dict[str, Any]] = []
        for node in nodes.values():
            item = {"key": node["key"], "label": node["label"], "count": int(node["count"])}
            children = finalize(node["_children"])
            if children:
                item["children"] = children
            out.append(item)
        out.sort(key=lambda item: (-int(item.get("count") or 0), str(item.get("label") or "")))
        return out

    for group in groups:
        for item in group:
            merge_node(merged, item)
    return finalize(merged)


def taxonomy_by_kind_from_public(public_taxonomy: dict[str, Any]) -> dict[str, list[dict[str, Any]]]:
    thu_vien = next((item for item in root_nodes(public_taxonomy) if node_key(item) == "thu-vien"), None)
    if thu_vien is None:
        return {}
    return {node_key(kind): child_nodes(kind) for kind in child_nodes(thu_vien)}


def write_derived_artifacts(master: dict[str, Any], articles: list[dict[str, Any]], apply: bool) -> dict[str, Any]:
    public_taxonomy = build_public_taxonomy(master, articles)
    editor_taxonomy = build_editor_taxonomy(public_taxonomy)
    artifacts = [TAXONOMY_PATH, EDITOR_TAXONOMY_PATH]
    hub_updates: list[Path] = []
    hub_js_updates: list[Path] = []
    if apply:
        write_json(TAXONOMY_PATH, public_taxonomy)
        write_json(EDITOR_TAXONOMY_PATH, editor_taxonomy)
    taxonomy_by_kind = taxonomy_by_kind_from_public(public_taxonomy)
    for section in ("thu-vien", "ban-tin"):
        hub_path = HUBS_DIR / f"{section}.json"
        if not hub_path.exists():
            continue
        hub = read_json(hub_path)
        if not isinstance(hub, dict):
            continue
        root = next((item for item in root_nodes(public_taxonomy) if node_key(item) == section), None)
        if section == "thu-vien":
            hub["libraryKinds"] = build_library_kinds(master, articles)
            hub["taxonomyByKind"] = taxonomy_by_kind
            hub["taxonomy"] = merge_taxonomy_groups(list(taxonomy_by_kind.values()))
        elif root is not None:
            hub["taxonomy"] = child_nodes(root)
        hub_updates.append(hub_path)
        hub_js_path = hub_path.with_suffix(".js")
        hub_js_updates.append(hub_js_path)
        if apply:
            write_json(hub_path, hub)
            write_js_store(hub_js_path, "KetoanDieuTamHubStore", section, hub)
    artifacts.extend(hub_updates)
    artifacts.extend(hub_js_updates)

    # Keep top navigation labels in sync with library-kind labels.
    if MENU_CONFIG_PATH.exists():
        menu = read_json(MENU_CONFIG_PATH)
        if isinstance(menu, dict):
            try:
                kinds = build_library_kinds(master, articles)
                for item in menu.get("items", []):
                    if isinstance(item, dict) and item.get("key") == "thu-vien":
                        item["children"] = [
                            {
                                "key": "thu-vien-" + kind["key"],
                                "label": kind["label"],
                                "href": kind["href"],
                                "category": kind["key"],
                            }
                            for kind in kinds
                        ]
                artifacts.append(MENU_CONFIG_PATH)
                if apply:
                    write_json(MENU_CONFIG_PATH, menu)
            except TaxonomyError:
                pass
    return {
        "taxonomyNodes": len(collect_paths(public_taxonomy)),
        "artifacts": [rel(path) for path in artifacts],
    }


def rebuild_public_from_articles(article_id: str = "") -> dict[str, Any]:
    if not REBUILD_TOOL_PATH.exists():
        raise TaxonomyError("Không tìm thấy tools/rebuild_public_from_articles.py")
    spec = importlib.util.spec_from_file_location("kdt_rebuild_public_from_articles", REBUILD_TOOL_PATH)
    if spec is None or spec.loader is None:
        raise TaxonomyError("Không thể load rebuild_public_from_articles.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.rebuild_public_artifacts(
        include_hub_pages=False,
        dry_run=False,
        mode="fast",
        article_id=article_id,
    )


def changed_article_labels(master: dict[str, Any], articles: list[dict[str, Any]], parts: list[str], old: str, new: str) -> int:
    label_field = path_label_field(parts)
    if label_field == "":
        return 0
    changed = 0
    key_field = path_key_field(parts)
    for article in articles:
        if not article_matches_prefix(article, parts, master):
            continue
        if key_field and str(article.get(key_field) or "") != parts[-1]:
            continue
        if article.get(label_field) != new:
            article[label_field] = new
            changed += 1
        for card_field in ("cardBadgeLabel", "cardTopicLabel"):
            if article.get(card_field) == old:
                article[card_field] = new
        if label_field == "topicLv3Label" and article.get("toolLv3Key") == parts[-1]:
            article["toolLv3Label"] = new
    return changed


def changed_article_keys(master: dict[str, Any], articles: list[dict[str, Any]], parts: list[str], old: str, new: str) -> int:
    key_field = path_key_field(parts)
    if key_field == "" or old == new:
        return 0
    changed = 0
    for article in articles:
        if not article_matches_prefix(article, parts, master):
            continue
        if str(article.get(key_field) or "") == old:
            article[key_field] = new
            changed += 1
        if key_field == "topicLv3Key" and article.get("toolLv3Key") == old:
            article["toolLv3Key"] = new
    return changed


def make_backup(paths: list[Path], action: str, details: dict[str, Any]) -> Path:
    run_id = datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ") + "-" + re.sub(r"[^a-z0-9-]+", "-", action.lower()).strip("-")
    target = BACKUP_ROOT / run_id
    backup_dir = target / "backup"
    backup_dir.mkdir(parents=True, exist_ok=True)
    copied = []
    for path in paths:
        if not path.exists():
            continue
        dest = backup_dir / rel(path)
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, dest)
        copied.append(rel(path))
    manifest = {"createdAt": now_z(), "action": action, "backups": copied, "details": details}
    write_json(target / "manifest.json", manifest)
    return target


def bootstrap_master(args: argparse.Namespace) -> dict[str, Any]:
    if MASTER_PATH.exists() and not args.force:
        raise TaxonomyError("data/taxonomy-master.json đã tồn tại. Dùng --force nếu muốn ghi lại.")
    master = build_master_from_current_taxonomy()
    articles = load_articles()
    validation = validate_master(master, articles)
    if not validation["ok"]:
        raise TaxonomyError("Bootstrap tạo master không hợp lệ: " + "; ".join(validation["errors"][:5]))
    if args.apply:
        if MASTER_PATH.exists():
            make_backup([MASTER_PATH], "bootstrap-master", {"force": bool(args.force)})
        write_json(MASTER_PATH, master)
    return {
        "ok": True,
        "action": "bootstrap-master",
        "dryRun": not args.apply,
        "target": rel(MASTER_PATH),
        "nodes": validation["nodes"],
    }


def command_verify(_: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    validation = validate_master(master, articles)
    return {"ok": validation["ok"], "action": "verify", **validation}


def command_usage(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    return {"ok": True, "action": "usage", **usage_for_path(master, articles, split_path(args.path))}


def command_sync(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    validation = validate_master(master, articles)
    if not validation["ok"]:
        raise TaxonomyError("Master không hợp lệ: " + "; ".join(validation["errors"][:5]))
    if args.apply:
        make_backup(
            [TAXONOMY_PATH, EDITOR_TAXONOMY_PATH, MENU_CONFIG_PATH, HUBS_DIR / "thu-vien.json", HUBS_DIR / "ban-tin.json"],
            "sync-derived",
            {},
        )
    result = write_derived_artifacts(master, articles, bool(args.apply))
    return {"ok": True, "action": "sync-derived", "dryRun": not args.apply, **result}


def command_rename_label(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    parts = split_path(args.path)
    if len(parts) == 1:
        raise TaxonomyError("Không đổi label system root bằng CLI này")
    target = find_node(master, parts)
    old_label = node_label(target)
    new_label = str(args.label or "").strip()
    if not new_label:
        raise TaxonomyError("Label mới không được rỗng")
    usage = usage_for_path(master, articles, parts)
    plan = {
        "ok": True,
        "action": "rename-label",
        "dryRun": not args.apply,
        "path": fmt_path(parts),
        "oldLabel": old_label,
        "newLabel": new_label,
        "articleCount": usage["articleCount"],
    }
    if not args.apply:
        return plan

    backup_paths = [MASTER_PATH, ARTICLES_PATH, TAXONOMY_PATH, EDITOR_TAXONOMY_PATH, MENU_CONFIG_PATH, HUBS_DIR / "thu-vien.json", HUBS_DIR / "ban-tin.json"]
    backup_dir = make_backup(backup_paths, "rename-label", plan)
    target["label"] = new_label
    if isinstance(master.get("toolVariants"), dict) and parts[-1] in master["toolVariants"]:
        master["toolVariants"][parts[-1]]["label"] = new_label
    changed = changed_article_labels(master, articles, parts, old_label, new_label)
    master["generatedAt"] = now_z()
    write_json(MASTER_PATH, master)
    if changed:
        write_json(ARTICLES_PATH, articles)
    rebuild = rebuild_public_from_articles() if changed else None
    derived = write_derived_artifacts(master, articles, True)
    return {
        **plan,
        "backupDir": rel(backup_dir),
        "articleLabelsChanged": changed,
        "publicRebuild": rebuild,
        **derived,
    }


def command_add_node(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    parent_parts = split_path(args.parent)
    parent = find_node(master, parent_parts)
    section = parent_parts[0]
    if section == "thu-vien" and len(parent_parts) == 1 and not args.allow_system_kind:
        raise TaxonomyError("Không thêm library kind cấp 1 của Thư viện trong Phase 2. Dùng --allow-system-kind nếu thật sự cần.")
    if len(parent_parts) + 1 > MAX_DEPTH.get(section, 99):
        raise TaxonomyError("Node mới vượt maxDepth")
    label = str(args.label or "").strip()
    if not label:
        raise TaxonomyError("Label mới không được rỗng")
    requested_key = str(args.key or "").strip()
    auto_key = requested_key == ""
    key = normalize_key(requested_key or label, "category")
    if auto_key:
        key = unique_child_key(parent, key)
    if any(node_key(child) == key for child in child_nodes(parent)):
        raise TaxonomyError(f"Key {key!r} đã tồn tại dưới {fmt_path(parent_parts)}")
    plan = {
        "ok": True,
        "action": "add-node",
        "dryRun": not args.apply,
        "parent": fmt_path(parent_parts),
        "path": fmt_path(parent_parts + [key]),
        "key": key,
        "label": label,
        "autoKey": auto_key,
    }
    if not args.apply:
        return plan
    backup_dir = make_backup(
        [MASTER_PATH, TAXONOMY_PATH, EDITOR_TAXONOMY_PATH, MENU_CONFIG_PATH, HUBS_DIR / "thu-vien.json", HUBS_DIR / "ban-tin.json"],
        "add-node",
        plan,
    )
    children = parent.setdefault("children", [])
    if not isinstance(children, list):
        raise TaxonomyError("Parent children không phải array")
    node: dict[str, Any] = {"key": key, "label": label, "manual": True}
    if args.description:
        node["description"] = str(args.description)
    if args.icon:
        node["icon"] = str(args.icon)
    children.append(node)
    master["generatedAt"] = now_z()
    validation = validate_master(master, articles)
    if not validation["ok"]:
        raise TaxonomyError("Node mới làm master không hợp lệ: " + "; ".join(validation["errors"][:5]))
    write_json(MASTER_PATH, master)
    derived = write_derived_artifacts(master, articles, True)
    return {**plan, "backupDir": rel(backup_dir), **derived}


def command_edit_node(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    parts = split_path(args.path)
    if len(parts) == 1:
        raise TaxonomyError("Không sửa system root bằng CLI này")
    target, parent = find_parent(master, parts)
    old_label = node_label(target)
    old_key = node_key(target)
    new_label = str(args.label or "").strip() if args.label is not None else old_label
    if not new_label:
        raise TaxonomyError("Label không được rỗng")
    new_key = old_key
    if args.key is not None:
        raw_key = str(args.key or "").strip()
        if raw_key == "":
            raise TaxonomyError("Slug không được rỗng")
        new_key = normalize_key(raw_key, old_key or "category")
    if new_key != old_key:
        if parent is None:
            raise TaxonomyError("Không đổi slug root")
        if bool(target.get("lockedKey")) or bool(target.get("locked")) or bool(target.get("system")):
            raise TaxonomyError("Node hệ thống đang khóa slug, chỉ được sửa label/mô tả")
        if any(child is not target and node_key(child) == new_key for child in child_nodes(parent)):
            raise TaxonomyError(f"Slug {new_key!r} đã tồn tại dưới {fmt_path(parts[:-1])}")
    new_parts = parts[:-1] + [new_key]
    plan = {
        "ok": True,
        "action": "edit-node",
        "dryRun": not args.apply,
        "path": fmt_path(new_parts),
        "oldPath": fmt_path(parts),
        "oldKey": old_key,
        "newKey": new_key,
        "oldLabel": old_label,
        "newLabel": new_label,
        "articleCount": usage_for_path(master, articles, parts)["articleCount"],
        "description": args.description,
        "icon": args.icon,
    }
    if not args.apply:
        return plan

    backup_dir = make_backup(
        [MASTER_PATH, ARTICLES_PATH, TAXONOMY_PATH, EDITOR_TAXONOMY_PATH, MENU_CONFIG_PATH, HUBS_DIR / "thu-vien.json", HUBS_DIR / "ban-tin.json"],
        "edit-node",
        plan,
    )
    changed_labels = changed_article_labels(master, articles, parts, old_label, new_label) if new_label != old_label else 0
    changed_keys = changed_article_keys(master, articles, parts, old_key, new_key) if new_key != old_key else 0
    target["key"] = new_key
    target["label"] = new_label
    if args.description is not None:
        target["description"] = str(args.description)
    if args.icon is not None:
        target["icon"] = str(args.icon)
    if isinstance(master.get("toolVariants"), dict) and old_key in master["toolVariants"]:
        variant = master["toolVariants"].pop(old_key)
        variant["label"] = new_label
        master["toolVariants"][new_key] = variant
    master["generatedAt"] = now_z()
    validation = validate_master(master, articles)
    if not validation["ok"]:
        raise TaxonomyError("Sửa node làm master không hợp lệ: " + "; ".join(validation["errors"][:5]))
    write_json(MASTER_PATH, master)
    changed = changed_labels + changed_keys
    if changed:
        write_json(ARTICLES_PATH, articles)
    rebuild = rebuild_public_from_articles() if changed else None
    derived = write_derived_artifacts(master, articles, True)
    return {
        **plan,
        "backupDir": rel(backup_dir),
        "articleLabelsChanged": changed_labels,
        "articleKeysChanged": changed_keys,
        "publicRebuild": rebuild,
        **derived,
    }


def command_delete_node(args: argparse.Namespace) -> dict[str, Any]:
    master = load_master()
    articles = load_articles()
    parts = split_path(args.path)
    if len(parts) == 1:
        raise TaxonomyError("Không xóa system root")
    target, parent = find_parent(master, parts)
    if parent is None:
        raise TaxonomyError("Không xóa root")
    if bool(target.get("system")) or bool(target.get("locked")) or bool(target.get("lockedKey")):
        raise TaxonomyError("Node hệ thống đang bị khóa, không xóa trong Phase 2")
    usage = usage_for_path(master, articles, parts)
    if usage["articleCount"] > 0:
        raise TaxonomyError("Không xóa category đang có bài. Hãy reassign/merge trước.")
    plan = {
        "ok": True,
        "action": "delete-node",
        "dryRun": not args.apply,
        "path": fmt_path(parts),
        "label": node_label(target),
        "articleCount": usage["articleCount"],
        "deleteMode": "archive" if args.archive else "remove",
    }
    if not args.apply:
        return plan

    backup_dir = make_backup(
        [MASTER_PATH, TAXONOMY_PATH, EDITOR_TAXONOMY_PATH, MENU_CONFIG_PATH, HUBS_DIR / "thu-vien.json", HUBS_DIR / "ban-tin.json"],
        "delete-node",
        plan,
    )
    if args.archive:
        target["hidden"] = True
        target["archivedAt"] = now_z()
    else:
        children = parent.get("children", [])
        if not isinstance(children, list):
            raise TaxonomyError("Parent children không phải array")
        parent["children"] = [child for child in children if not (isinstance(child, dict) and node_key(child) == parts[-1])]
    master["generatedAt"] = now_z()
    validation = validate_master(master, articles)
    if not validation["ok"]:
        raise TaxonomyError("Xóa node làm master không hợp lệ: " + "; ".join(validation["errors"][:5]))
    write_json(MASTER_PATH, master)
    derived = write_derived_artifacts(master, articles, True)
    return {**plan, "backupDir": rel(backup_dir), **derived}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Manage editable taxonomy master safely")
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("bootstrap-master", help="Create data/taxonomy-master.json from current taxonomy")
    p.add_argument("--apply", action="store_true", help="Write the file; default is dry-run")
    p.add_argument("--force", action="store_true", help="Overwrite existing master")
    p.set_defaults(func=bootstrap_master)

    p = sub.add_parser("verify", help="Validate taxonomy-master against article assignments")
    p.set_defaults(func=command_verify)

    p = sub.add_parser("usage", help="Show article usage for a taxonomy path")
    p.add_argument("--path", required=True)
    p.set_defaults(func=command_usage)

    p = sub.add_parser("sync-derived", help="Rewrite derived taxonomy/editor/hub category artifacts from master")
    p.add_argument("--apply", action="store_true", help="Write files; default is dry-run")
    p.set_defaults(func=command_sync)

    p = sub.add_parser("rename-label", help="Rename a category label without changing its key/path")
    p.add_argument("--path", required=True)
    p.add_argument("--label", required=True)
    p.add_argument("--apply", action="store_true", help="Write files; default is dry-run")
    p.set_defaults(func=command_rename_label)

    p = sub.add_parser("add-node", help="Add a child category node")
    p.add_argument("--parent", required=True)
    p.add_argument("--key", default="", help="Optional slug; omitted/blank auto-generates from --label")
    p.add_argument("--label", required=True)
    p.add_argument("--description", default="")
    p.add_argument("--icon", default="")
    p.add_argument("--allow-system-kind", action="store_true")
    p.add_argument("--apply", action="store_true", help="Write files; default is dry-run")
    p.set_defaults(func=command_add_node)

    p = sub.add_parser("edit-node", help="Edit category label/slug/description/icon")
    p.add_argument("--path", required=True)
    p.add_argument("--key")
    p.add_argument("--label")
    p.add_argument("--description")
    p.add_argument("--icon")
    p.add_argument("--apply", action="store_true", help="Write files; default is dry-run")
    p.set_defaults(func=command_edit_node)

    p = sub.add_parser("delete-node", help="Delete an unused category node")
    p.add_argument("--path", required=True)
    p.add_argument("--archive", action="store_true", help="Hide/archive instead of removing")
    p.add_argument("--apply", action="store_true", help="Write files; default is dry-run")
    p.set_defaults(func=command_delete_node)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        result = args.func(args)
        print(json.dumps(result, ensure_ascii=True, indent=2))
        return 0 if result.get("ok") else 2
    except Exception as exc:  # noqa: BLE001 - CLI should return structured errors.
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=True, indent=2), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
