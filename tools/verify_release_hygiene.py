#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict, List


ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    allowed_extensionless = {"_redirects"}
    extensionless: List[Dict[str, Any]] = []
    for path in ROOT.iterdir():
        if not path.is_file():
            continue
        if path.name.startswith(".") or "." in path.name or path.name in allowed_extensionless:
            continue
        extensionless.append({"name": path.name, "bytes": path.stat().st_size})

    report = {
        "ok": not extensionless,
        "extensionless_root_files": extensionless,
        "allowed_extensionless": sorted(allowed_extensionless),
        "article_dir_exists": (ROOT / "article").exists(),
        "article_view_article_dir_exists": (ROOT / "data/article-views/article").exists(),
    }
    if report["article_dir_exists"] or report["article_view_article_dir_exists"]:
        report["ok"] = False
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
