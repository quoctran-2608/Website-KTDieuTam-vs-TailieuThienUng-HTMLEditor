#!/usr/bin/env python3
"""Phase 3 - chuẩn hóa table cho cohort import: batch 100 tiếp theo (offset 200)."""

from __future__ import annotations

import json
import re
from datetime import datetime
from pathlib import Path
from typing import Dict, List

ROOT = Path(__file__).resolve().parent.parent
IMPORT_LOG = ROOT / "docs" / "update-800-bai-import-log.json"
REPORT_JSON = ROOT / "docs" / "table-fix-phase3-batch100.json"
REPORT_MD = ROOT / "docs" / "table-fix-phase3-batch100.md"

TABLE_TAG_RE = re.compile(r"<table\b([^>]*)>", re.IGNORECASE)


def load_imported_hrefs() -> List[str]:
    data = json.loads(IMPORT_LOG.read_text(encoding="utf-8"))
    hrefs = []
    for batch in data.get("batches", []):
        for item in batch.get("imported", []):
            href = item.get("target_path")
            if href:
                hrefs.append(href)
    return sorted(set(hrefs))


def has_table(html: str) -> bool:
    return "<table" in html.lower()


def tag_tables_preserve(html: str) -> tuple[str, int]:
    changed = 0

    def _repl(m: re.Match[str]) -> str:
        nonlocal changed
        attrs = m.group(1) or ""
        if re.search(r"\bdata-preserve-layout\s*=", attrs, re.IGNORECASE):
            return m.group(0)
        changed += 1
        return f'<table data-preserve-layout="true"{attrs}>'

    new_html = TABLE_TAG_RE.sub(_repl, html)
    return new_html, changed


def main() -> None:
    imported = load_imported_hrefs()
    candidates = []
    for href in imported:
        p = ROOT / href
        if not p.exists():
            continue
        html = p.read_text(encoding="utf-8", errors="ignore")
        if has_table(html):
            candidates.append(href)

    # phase 3: 100 bài tiếp theo
    selected = candidates[200:300]
    applied = []
    skipped = []
    total_table_tags_changed = 0

    for href in selected:
        p = ROOT / href
        html = p.read_text(encoding="utf-8", errors="ignore")
        new_html, changed = tag_tables_preserve(html)
        if changed <= 0:
            skipped.append({"href": href, "reason": "already-tagged"})
            continue
        p.write_text(new_html, encoding="utf-8")
        total_table_tags_changed += changed
        applied.append({"href": href, "tablesTagged": changed})

    payload: Dict = {
        "generatedAt": datetime.now().isoformat(),
        "phase": "table-fix-phase3-batch100",
        "importedTotal": len(imported),
        "importedWithTable": len(candidates),
        "selectedCount": len(selected),
        "appliedCount": len(applied),
        "skippedCount": len(skipped),
        "totalTableTagsChanged": total_table_tags_changed,
        "applied": applied,
        "skipped": skipped,
    }
    REPORT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Table fix phase 3 (batch 100)",
        "",
        f"- Generated: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Imported total: **{len(imported)}**",
        f"- Imported with table: **{len(candidates)}**",
        f"- Selected: **{len(selected)}**",
        f"- Applied: **{len(applied)}**",
        f"- Skipped: **{len(skipped)}**",
        f"- Total table tags changed: **{total_table_tags_changed}**",
        "",
        "## Applied",
        "",
        "| # | href | tables tagged |",
        "|---:|---|---:|",
    ]
    for i, row in enumerate(applied, 1):
        lines.append(f"| {i} | `{row['href']}` | {row['tablesTagged']} |")

    if skipped:
        lines += ["", "## Skipped", ""]
        for row in skipped:
            lines.append(f"- `{row['href']}`: {row['reason']}")

    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "selected": len(selected),
                "applied": len(applied),
                "skipped": len(skipped),
                "tableTagsChanged": total_table_tags_changed,
                "report": str(REPORT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
