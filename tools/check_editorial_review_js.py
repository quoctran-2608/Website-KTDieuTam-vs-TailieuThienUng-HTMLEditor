#!/usr/bin/env python3
"""Parse the rendered-equivalent return-review script from editorial/review.php."""

from __future__ import annotations

import argparse
import re
import subprocess
import tempfile
from pathlib import Path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Validate rendered-equivalent return-review JavaScript syntax."
    )
    parser.add_argument("--root", default=".", help="Project root")
    parser.add_argument("--node", default="node", help="Node executable")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root).resolve()
    source = (root / "editorial" / "review.php").read_text(encoding="utf-8")
    match = re.search(
        r"\$returnEditorScript = <<<'JS'\n(?P<script>.*?)\nJS;",
        source,
        flags=re.DOTALL,
    )
    if match is None:
        print("FAIL: return-review script not found")
        return 1

    rendered = match.group("script")
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
        print("FAIL: rendered-equivalent return-review JavaScript does not parse")
        print(result.stderr.strip() or result.stdout.strip())
        return result.returncode or 1

    print("PASS: rendered-equivalent return-review JavaScript parses with node --check")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
