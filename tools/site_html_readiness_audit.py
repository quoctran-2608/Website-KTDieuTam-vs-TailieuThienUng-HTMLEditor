#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path
from typing import Dict, List, Tuple
from urllib.parse import urlparse, unquote


ROOT = Path(__file__).resolve().parents[2]
SITE = ROOT / "Ketoandieutam.com"
DOCS = SITE / "docs"
REPORT = DOCS / "site-html-readiness-audit.md"


ARTICLE_META_RE = re.compile(
    r'<script id="article-meta" type="application/json">(.*?)</script>',
    re.S,
)
BODY_ROOT_RE = re.compile(r'<body[^>]+data-root="([^"]+)"', re.I)
HREF_RE = re.compile(r'''(?:href|src)=["']([^"']+)["']''', re.I)


def load_articles() -> List[Dict]:
    return json.loads((SITE / "data" / "articles.json").read_text(encoding="utf-8"))


def site_article_files() -> List[Path]:
    return sorted(list((SITE / "thu-vien").glob("*.html")) + list((SITE / "ban-tin").glob("*.html")))


def check_article_meta(article_files: List[Path]) -> Tuple[int, int, List[str]]:
    ok = 0
    missing = []
    for path in article_files:
        text = path.read_text(encoding="utf-8", errors="ignore")
        m = ARTICLE_META_RE.search(text)
        if not m:
            missing.append(f"{path.relative_to(SITE)} | thiếu article-meta")
            continue
        try:
            meta = json.loads(m.group(1))
        except Exception:
            missing.append(f"{path.relative_to(SITE)} | article-meta JSON lỗi")
            continue
        if meta.get("publishDate") and meta.get("authorName"):
            ok += 1
        else:
            missing.append(
                f"{path.relative_to(SITE)} | publishDate={meta.get('publishDate')} | authorName={meta.get('authorName')}"
            )
    return ok, len(missing), missing[:40]


def is_internal_asset(ref: str) -> bool:
    if not ref or ref.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
        return False
    if re.match(r"^(?:https?:)?//", ref):
        return False
    return True


def resolve_ref(page: Path, ref: str, root_prefix: str) -> Path:
    parsed = urlparse(ref)
    path_ref = unquote(parsed.path or ref)
    if ref.startswith("/"):
        return SITE / path_ref.lstrip("/")
    if path_ref.startswith(("assets/", "data/", "thu-vien/", "ban-tin/")):
        return SITE / path_ref
    return (page.parent / path_ref).resolve()


def check_internal_refs(pages: List[Path]) -> Tuple[int, List[str]]:
    broken = []
    checked = 0
    for page in pages:
        text = page.read_text(encoding="utf-8", errors="ignore")
        m = BODY_ROOT_RE.search(text)
        root_prefix = m.group(1) if m else ""
        for ref in HREF_RE.findall(text):
            if not is_internal_asset(ref):
                continue
            checked += 1
            target = resolve_ref(page, ref, root_prefix)
            if not target.exists():
                broken.append(f"{page.relative_to(SITE)} -> {ref}")
                if len(broken) >= 60:
                    return checked, broken
    return checked, broken


def main() -> None:
    DOCS.mkdir(parents=True, exist_ok=True)
    articles = load_articles()
    article_files = site_article_files()
    article_ids = {a["id"] for a in articles}
    file_ids = {str(p.relative_to(SITE)).replace("\\", "/") for p in article_files}
    section_counts = Counter(a["section"] for a in articles)
    kind_counts = Counter(a.get("libraryKindKey") for a in articles if a["section"] == "thu-vien")

    meta_ok, meta_missing_count, meta_missing_preview = check_article_meta(article_files)
    checked_refs, broken_refs = check_internal_refs(
        [SITE / "index.html", SITE / "thu-vien.html", SITE / "ban-tin.html", *article_files[:120]]
    )

    lines = [
        "# QA readiness audit cho HTML site hiện tại",
        "",
        "## Tóm tắt",
        "",
        f"- Tổng article metadata trong `data/articles.json`: **{len(articles)}**",
        f"- Tổng file article HTML thực tế: **{len(article_files)}**",
        f"- Thư viện: **{section_counts.get('thu-vien', 0)}**",
        f"- Bản tin: **{section_counts.get('ban-tin', 0)}**",
        f"- Hướng dẫn: **{kind_counts.get('huong-dan', 0)}**",
        f"- Biểu mẫu: **{kind_counts.get('bieu-mau', 0)}**",
        f"- Công cụ: **{kind_counts.get('cong-cu', 0)}**",
        "",
        "## Kiểm tra cấu trúc",
        "",
        f"- Article HTML có `publishDate + authorName`: **{meta_ok} / {len(article_files)}**",
        f"- Missing/invalid article-meta: **{meta_missing_count}**",
        f"- Đã kiểm tra internal refs: **{checked_refs}**",
        f"- Internal refs lỗi (mẫu kiểm): **{len(broken_refs)}**",
        "",
        "## Preview article-meta lỗi",
        "",
    ]

    if meta_missing_preview:
        lines.extend([f"- {row}" for row in meta_missing_preview])
    else:
        lines.append("- Không có")

    lines.extend(["", "## Preview internal refs lỗi", ""])
    if broken_refs:
        lines.extend([f"- {row}" for row in broken_refs])
    else:
        lines.append("- Không có trong mẫu kiểm")

    lines.extend(
        [
            "",
            "## Kết luận vận hành",
            "",
            "Site HTML được xem là **sẵn sàng chuyển sang giai đoạn editor PHP** khi:",
            "",
            "- article count giữa `data/articles.json` và file HTML khớp nhau",
            "- article-meta của toàn bộ bài có `publishDate` và `authorName`",
            "- không còn internal ref lỗi trong mẫu kiểm",
            "- taxonomy public ổn định: `Thư viện / Bản tin`",
            "- taxonomy nội bộ ổn định: `Hướng dẫn / Biểu mẫu / Công cụ` + level 2/3 phù hợp",
        ]
    )

    REPORT.write_text("\n".join(lines), encoding="utf-8")
    print(REPORT)
    print(f"Scanned: {len(articles)} article records")


if __name__ == "__main__":
    main()
