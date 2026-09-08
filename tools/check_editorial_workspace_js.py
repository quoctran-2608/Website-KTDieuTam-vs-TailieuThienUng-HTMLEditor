#!/usr/bin/env python3
"""
Parse the JavaScript emitted by editorial/article.php's Workspace heredoc.

The PHP runtime JSON-encodes the three injected values. This harness replaces
those exact interpolation points with representative valid JSON and runs
node --check on the resulting JavaScript, catching heredoc escaping regressions.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import tempfile
from pathlib import Path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Validate rendered-equivalent Workspace JavaScript syntax."
    )
    parser.add_argument("--root", default=".", help="Project root")
    parser.add_argument("--node", default="node", help="Node executable")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root).resolve()
    article_path = root / "editorial" / "article.php"
    source = article_path.read_text(encoding="utf-8")
    match = re.search(
        r"\$innerScript = <<<JS\n(?P<script>.*?)\nJS;",
        source,
        flags=re.DOTALL,
    )
    if match is None:
        print("FAIL: Workspace heredoc not found")
        return 1

    replacements = {
        "$previewTemplateJson": json.dumps(
            "<!doctype html><html><body>__EDITORIAL_PREVIEW_PROSE__</body></html>"
        ),
        "$canonicalArticleOriginJson": json.dumps("https://example.test"),
        "$liveProseImagesJson": json.dumps([]),
    }
    rendered = match.group("script")
    for token, value in replacements.items():
        if token not in rendered:
            print(f"FAIL: expected interpolation {token} not found")
            return 1
        rendered = rendered.replace(token, value)

    unresolved = re.findall(r"\$[A-Za-z_][A-Za-z0-9_]*", rendered)
    if unresolved:
        print("FAIL: unresolved PHP interpolation: " + ", ".join(sorted(set(unresolved))))
        return 1

    with tempfile.NamedTemporaryFile(
        mode="w", suffix=".js", encoding="utf-8", delete=False
    ) as handle:
        handle.write(rendered)
        js_path = Path(handle.name)
    try:
        result = subprocess.run(
            [args.node, "--check", str(js_path)],
            cwd=root,
            text=True,
            capture_output=True,
            check=False,
        )
    finally:
        js_path.unlink(missing_ok=True)

    if result.returncode != 0:
        print("FAIL: rendered-equivalent Workspace JavaScript does not parse")
        print(result.stderr.strip() or result.stdout.strip())
        return result.returncode or 1

    print("PASS: rendered-equivalent Workspace JavaScript parses with node --check")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
