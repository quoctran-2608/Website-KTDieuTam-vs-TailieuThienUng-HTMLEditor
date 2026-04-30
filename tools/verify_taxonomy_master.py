#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
TOOL_PATH = ROOT / "tools" / "manage_taxonomy.py"


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def load_tool():
    spec = importlib.util.spec_from_file_location("kdt_manage_taxonomy_verify", TOOL_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Không thể load {TOOL_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def comparable_taxonomy(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "roots": payload.get("roots", []),
        "toolVariants": payload.get("toolVariants", {}),
    }


def main() -> int:
    tool = load_tool()
    report: dict[str, Any] = {
        "ok": False,
        "master_exists": tool.MASTER_PATH.exists(),
        "checks": {},
    }
    if not tool.MASTER_PATH.exists():
        report["error"] = "missing data/taxonomy-master.json"
        print(json.dumps(report, ensure_ascii=True, indent=2))
        return 2

    master = tool.load_master()
    articles = tool.load_articles()
    validation = tool.validate_master(master, articles)
    expected_public = tool.build_public_taxonomy(master, articles)
    actual_public = read_json(tool.TAXONOMY_PATH)
    expected_by_kind = tool.taxonomy_by_kind_from_public(expected_public)
    thu_hub = read_json(ROOT / "data" / "hubs" / "thu-vien.json")
    ban_hub = read_json(ROOT / "data" / "hubs" / "ban-tin.json")

    checks = {
        "master_valid": validation.get("ok") is True,
        "public_taxonomy_matches_master": comparable_taxonomy(actual_public) == comparable_taxonomy(expected_public),
        "thu_vien_hub_has_taxonomy_by_kind": thu_hub.get("taxonomyByKind") == expected_by_kind,
        "thu_vien_library_kinds_match": thu_hub.get("libraryKinds") == tool.build_library_kinds(master, articles),
        "ban_tin_hub_taxonomy_matches": ban_hub.get("taxonomy") == next(
            (tool.child_nodes(root) for root in tool.root_nodes(expected_public) if tool.node_key(root) == "ban-tin"),
            [],
        ),
    }
    report.update(
        {
            "ok": all(checks.values()),
            "checks": checks,
            "nodes": validation.get("nodes"),
            "errors": validation.get("errors", []),
            "warnings": validation.get("warnings", []),
            "articles": len(articles),
        }
    )
    print(json.dumps(report, ensure_ascii=True, indent=2))
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
