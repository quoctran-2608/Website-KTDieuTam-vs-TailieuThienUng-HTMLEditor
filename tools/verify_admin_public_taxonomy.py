#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict, List


ROOT = Path(__file__).resolve().parents[1]


Tree = List[Dict[str, Any]]


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def node_key(item: Dict[str, Any]) -> tuple[int, str]:
    return (-int(item.get("count") or 0), str(item.get("label") or ""))


def prune_tree(items: List[Dict[str, Any]], depth: int) -> Tree:
    result: Tree = []
    for item in items:
        node = {
            "key": str(item.get("key") or ""),
            "label": str(item.get("label") or ""),
            "count": int(item.get("count") or 0),
            "children": [],
        }
        if depth > 1:
            node["children"] = prune_tree([x for x in item.get("children", []) if isinstance(x, dict)], depth - 1)
        result.append(node)
    return result


def build_public_taxonomy_from_articles(articles: List[Dict[str, Any]]) -> Tree:
    top: Dict[str, Dict[str, Any]] = {}
    for article in articles:
        lv1_key = str(article.get("topic_lv1_key") or "")
        lv1_label = str(article.get("topic_lv1_label") or lv1_key)
        lv2_key = str(article.get("topic_lv2_key") or "")
        lv2_label = str(article.get("topic_lv2_label") or lv2_key)
        if not lv1_key:
            continue
        node = top.setdefault(
            lv1_key,
            {"key": lv1_key, "label": lv1_label, "count": 0, "_children": {}},
        )
        node["count"] += 1
        if lv2_key:
            child = node["_children"].setdefault(
                lv2_key,
                {"key": lv2_key, "label": lv2_label, "count": 0, "children": []},
            )
            child["count"] += 1

    result: Tree = []
    for node in top.values():
        children = sorted(node["_children"].values(), key=node_key)
        result.append(
            {
                "key": node["key"],
                "label": node["label"],
                "count": node["count"],
                "children": children,
            }
        )
    return sorted(result, key=node_key)


def expected_public_tree(section: str, hub: Dict[str, Any]) -> Tree:
    articles = [x for x in hub.get("articles", []) if isinstance(x, dict)]
    if section == "thu-vien":
        tree: Tree = []
        for kind in [x for x in hub.get("libraryKinds", []) if isinstance(x, dict)]:
            kind_key = str(kind.get("key") or "")
            subset = [article for article in articles if str(article.get("library_kind_key") or "") == kind_key]
            tree.append(
                {
                    "key": kind_key,
                    "label": str(kind.get("label") or ""),
                    "count": int(kind.get("count") or 0),
                    "children": build_public_taxonomy_from_articles(subset),
                }
            )
        return tree
    return prune_tree([x for x in hub.get("taxonomy", []) if isinstance(x, dict)], 2)


def main() -> int:
    taxonomy = read_json(ROOT / "data/taxonomy.json")
    roots = [x for x in taxonomy.get("roots", []) if isinstance(x, dict)]
    reports: List[Dict[str, Any]] = []
    for section in ["thu-vien", "ban-tin"]:
        root = next((x for x in roots if x.get("key") == section), None)
        hub = read_json(ROOT / f"data/hubs/{section}.json")
        if root is None:
            reports.append({"section": section, "ok": False, "error": "missing root in data/taxonomy.json"})
            continue
        visible_depth = 3 if section == "thu-vien" else 2
        admin_tree = prune_tree([x for x in root.get("children", []) if isinstance(x, dict)], visible_depth)
        public_tree = expected_public_tree(section, hub)
        reports.append(
            {
                "section": section,
                "ok": admin_tree == public_tree,
                "visible_depth": visible_depth,
                "admin_top": len(admin_tree),
                "public_top": len(public_tree),
                "first_diff": None if admin_tree == public_tree else {
                    "admin": admin_tree[:1],
                    "public": public_tree[:1],
                },
            }
        )
    report = {"ok": all(item.get("ok") for item in reports), "reports": reports}
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
