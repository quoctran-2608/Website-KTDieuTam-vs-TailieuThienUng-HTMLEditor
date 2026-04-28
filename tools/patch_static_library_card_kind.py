#!/usr/bin/env python3
"""Bổ sung data-card-kind cho topic button trên các card HTML tĩnh của Thư viện."""

from __future__ import annotations

import json
import re
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
REPORT_JSON = ROOT / "docs" / "static-library-card-kind-patch.json"
REPORT_MD = ROOT / "docs" / "static-library-card-kind-patch.md"

CARD_RE = re.compile(r'(<article class="catalog-card[^"]*">[\s\S]*?</article>)', re.IGNORECASE)
BADGE_KIND_RE = re.compile(r'class="catalog-card__badge"[^>]*\bdata-card-kind="([^"]+)"', re.IGNORECASE)
TOPIC_RE = re.compile(r'(<button class="catalog-card__topic" type="button")(?![^>]*\bdata-card-kind=)([^>]*>)', re.IGNORECASE)


def patch_card(card_html: str) -> tuple[str, int]:
    badge_match = BADGE_KIND_RE.search(card_html)
    if not badge_match:
        return card_html, 0
    kind = badge_match.group(1)
    count = 0

    def repl(match: re.Match) -> str:
        nonlocal count
        count += 1
        return f'{match.group(1)} data-card-kind="{kind}"{match.group(2)}'

    return TOPIC_RE.sub(repl, card_html), count


def patch_page(path: Path) -> tuple[int, int]:
    html = path.read_text(encoding="utf-8", errors="ignore")
    changed_cards = 0
    changed_topics = 0

    def repl(match: re.Match) -> str:
        nonlocal changed_cards, changed_topics
        card = match.group(1)
        patched, topic_count = patch_card(card)
        if topic_count:
            changed_cards += 1
            changed_topics += topic_count
        return patched

    patched_html = CARD_RE.sub(repl, html)
    if patched_html != html:
        path.write_text(patched_html, encoding="utf-8")
    return changed_cards, changed_topics


def main() -> None:
    pages = [ROOT / "thu-vien.html"] + sorted((ROOT / "thu-vien" / "trang").glob("*/index.html"))
    per_page = []
    total_cards = 0
    total_topics = 0

    for path in pages:
        cards, topics = patch_page(path)
        per_page.append({"path": str(path.relative_to(ROOT)), "patchedCards": cards, "patchedTopics": topics})
        total_cards += cards
        total_topics += topics

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "pageCount": len(pages),
        "patchedCards": total_cards,
        "patchedTopics": total_topics,
        "pages": per_page,
    }
    REPORT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Bổ sung data-card-kind cho card Thư viện tĩnh",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Số trang hub đã rà: **{len(pages)}**",
        f"- Số card đã patch: **{total_cards}**",
        f"- Số topic button đã patch: **{total_topics}**",
    ]
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "pageCount": len(pages),
                "patchedCards": total_cards,
                "patchedTopics": total_topics,
                "report": str(REPORT_MD.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
