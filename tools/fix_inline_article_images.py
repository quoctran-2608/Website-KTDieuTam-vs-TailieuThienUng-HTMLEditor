#!/usr/bin/env python3
"""Sửa hàng loạt ảnh inline hỏng trong nội dung bài viết (thư viện + bản tin)."""

from __future__ import annotations

import json
import os
import re
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple


ROOT = Path(__file__).resolve().parent.parent
DATA_ARTICLES_PATH = ROOT / "data" / "articles.json"
REPORT_JSON_PATH = ROOT / "docs" / "inline-image-fix-report.json"
REPORT_MD_PATH = ROOT / "docs" / "inline-image-fix-report.md"

DEFAULT_FALLBACK_IMAGE = "assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg"

IMG_EXTS = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp", ".svg", ".avif", ".ico", ".jfif"}
IMG_SRC_RE = re.compile(r'(<img\b[^>]*\bsrc\s*=\s*)(["\'])(.*?)\2', re.IGNORECASE | re.DOTALL)


def is_remote_src(src: str) -> bool:
    s = (src or "").strip().lower()
    return (
        s.startswith("http://")
        or s.startswith("https://")
        or s.startswith("//")
        or s.startswith("data:")
        or s.startswith("mailto:")
        or s.startswith("tel:")
    )


def strip_query_fragment(src: str) -> str:
    clean = src
    if "#" in clean:
        clean = clean.split("#", 1)[0]
    if "?" in clean:
        clean = clean.split("?", 1)[0]
    return clean


def resolve_src_to_path(page_path: Path, src: str) -> Tuple[Optional[Path], str]:
    clean = strip_query_fragment(src.strip())
    if not clean:
        return None, "empty"
    if is_remote_src(clean):
        return None, ""
    if clean.startswith("/"):
        candidate = (ROOT / clean.lstrip("/")).resolve()
    else:
        candidate = (page_path.parent / clean).resolve()
    try:
        candidate.relative_to(ROOT.resolve())
    except ValueError:
        return None, "outside-root"
    return candidate, ""


def sniff_file_issue(path: Path) -> str:
    if not path.exists():
        return "missing"
    if path.is_dir():
        return "is-dir"
    data = path.read_bytes()[:1024]
    if not data:
        return "empty"
    lower = data.lower()

    # Common image signatures
    if data.startswith(b"\xFF\xD8\xFF"):
        return ""
    if data.startswith(b"\x89PNG\r\n\x1A\n"):
        return ""
    if data.startswith(b"GIF87a") or data.startswith(b"GIF89a"):
        return ""
    if data.startswith(b"RIFF") and data[8:12] == b"WEBP":
        return ""
    if data.startswith(b"BM"):
        return ""
    if data[:4] == b"\x00\x00\x01\x00":
        return ""
    if b"<svg" in lower:
        return ""

    if b"<html" in lower or b"<!doctype" in lower:
        return "html-content"
    if b"function " in lower or b"var " in lower or b"const " in lower:
        return "script-content"
    return "unknown-bytes"


def relative_to_root(path: Path) -> str:
    return path.resolve().relative_to(ROOT.resolve()).as_posix()


def path_to_src(page_path: Path, root_rel_path: str) -> str:
    src = os.path.relpath(ROOT / root_rel_path, page_path.parent).replace("\\", "/")
    return src


def is_valid_image_root_rel(root_rel_path: str) -> bool:
    path = ROOT / root_rel_path
    return sniff_file_issue(path) == ""


def build_valid_image_index() -> Dict[str, List[str]]:
    index: Dict[str, List[str]] = {}
    assets_root = ROOT / "assets"
    if not assets_root.exists():
        return index
    for file_path in assets_root.rglob("*"):
        if not file_path.is_file():
            continue
        if file_path.suffix.lower() not in IMG_EXTS:
            continue
        rel = file_path.resolve().relative_to(ROOT.resolve()).as_posix()
        if sniff_file_issue(file_path) != "":
            continue
        index.setdefault(file_path.name.lower(), []).append(rel)
    return index


def rank_candidates(candidates: List[str]) -> List[str]:
    def key(path: str) -> Tuple[int, int, str]:
        # Ưu tiên kho ảnh service vì đây là kho gốc nội dung bài cũ.
        return (0 if "/assets/images/content/pic/Service/images/" in f"/{path}" else 1, len(path), path)

    return sorted(candidates, key=key)


def choose_replacement(
    *,
    article: Dict,
    page_path: Path,
    src: str,
    issue: str,
    valid_image_index: Dict[str, List[str]],
) -> Tuple[str, str]:
    clean = strip_query_fragment(src.strip())
    basename = Path(clean).name.lower()

    # 1) Nếu có file hợp lệ cùng basename thì remap.
    if basename and basename in valid_image_index:
        picked = rank_candidates(valid_image_index[basename])[0]
        return path_to_src(page_path, picked), "exact-basename-remap"

    # 2) Nếu src chứa pic/Service/images nhưng sai relative, thử map về assets/images/content.
    marker = "pic/Service/images/"
    if marker in clean:
        tail = clean[clean.index(marker) :]
        candidate = f"assets/images/content/{tail}"
        if is_valid_image_root_rel(candidate):
            return path_to_src(page_path, candidate), "service-prefix-remap"

    # 3) Fallback theo thumbnail của chính bài (thường đúng chủ đề).
    article_image = (article.get("image") or "").strip()
    if article_image and is_valid_image_root_rel(article_image):
        return path_to_src(page_path, article_image), "article-thumbnail-fallback"

    # 4) Fallback cuối cùng.
    return path_to_src(page_path, DEFAULT_FALLBACK_IMAGE), f"default-fallback:{issue}"


def collect_issues_for_page(page_path: Path) -> List[Dict]:
    text = page_path.read_text(encoding="utf-8", errors="ignore")
    issues: List[Dict] = []
    for match in IMG_SRC_RE.finditer(text):
        src = (match.group(3) or "").strip()
        if not src or is_remote_src(src):
            continue
        resolved, error = resolve_src_to_path(page_path, src)
        if error:
            issues.append(
                {
                    "src": src,
                    "reason": error,
                    "resolvedPath": "",
                }
            )
            continue
        assert resolved is not None
        issue = sniff_file_issue(resolved)
        if issue:
            issues.append(
                {
                    "src": src,
                    "reason": issue,
                    "resolvedPath": relative_to_root(resolved),
                }
            )
    return issues


def fix_page_inline_images(
    *,
    article: Dict,
    page_path: Path,
    valid_image_index: Dict[str, List[str]],
) -> List[Dict]:
    text = page_path.read_text(encoding="utf-8", errors="ignore")
    replacements: List[Dict] = []
    changed = False

    def repl(match: re.Match) -> str:
        nonlocal changed
        prefix, quote, old_src_raw = match.group(1), match.group(2), match.group(3)
        old_src = (old_src_raw or "").strip()
        if not old_src or is_remote_src(old_src):
            return match.group(0)

        resolved, err = resolve_src_to_path(page_path, old_src)
        if err:
            issue = err
        else:
            assert resolved is not None
            issue = sniff_file_issue(resolved)

        if not issue:
            return match.group(0)

        new_src, strategy = choose_replacement(
            article=article,
            page_path=page_path,
            src=old_src,
            issue=issue,
            valid_image_index=valid_image_index,
        )
        if new_src == old_src:
            return match.group(0)

        changed = True
        replacements.append(
            {
                "href": article["href"],
                "oldSrc": old_src,
                "newSrc": new_src,
                "reason": issue,
                "strategy": strategy,
            }
        )
        return f"{prefix}{quote}{new_src}{quote}"

    new_text = IMG_SRC_RE.sub(repl, text)
    if changed:
        page_path.write_text(new_text, encoding="utf-8")
    return replacements


def collect_all_inline_issues(articles: List[Dict]) -> List[Dict]:
    all_issues: List[Dict] = []
    for article in articles:
        page = ROOT / article["href"]
        if not page.exists():
            continue
        for issue in collect_issues_for_page(page):
            row = {"href": article["href"]}
            row.update(issue)
            all_issues.append(row)
    return all_issues


def main() -> None:
    articles: List[Dict] = json.loads(DATA_ARTICLES_PATH.read_text(encoding="utf-8"))
    valid_index = build_valid_image_index()

    before_issues = collect_all_inline_issues(articles)
    applied_replacements: List[Dict] = []

    for article in articles:
        page = ROOT / article["href"]
        if not page.exists():
            continue
        applied_replacements.extend(
            fix_page_inline_images(
                article=article,
                page_path=page,
                valid_image_index=valid_index,
            )
        )

    after_issues = collect_all_inline_issues(articles)

    before_reason_counts = Counter(i["reason"] for i in before_issues)
    after_reason_counts = Counter(i["reason"] for i in after_issues)
    strategy_counts = Counter(i["strategy"] for i in applied_replacements)

    payload = {
        "generatedAt": datetime.now().isoformat(),
        "before": {
            "issueCount": len(before_issues),
            "articleCount": len({i["href"] for i in before_issues}),
            "reasonCounts": dict(before_reason_counts),
        },
        "applied": {
            "replacementCount": len(applied_replacements),
            "strategyCounts": dict(strategy_counts),
            "replacements": applied_replacements,
        },
        "after": {
            "issueCount": len(after_issues),
            "articleCount": len({i["href"] for i in after_issues}),
            "reasonCounts": dict(after_reason_counts),
            "remainingIssues": after_issues,
        },
    }
    REPORT_JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Sửa lỗi ảnh inline trong bài viết",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Inline image lỗi trước: **{len(before_issues)}** trong **{len({i['href'] for i in before_issues})}** bài ({dict(before_reason_counts)})",
        f"- Replacements đã áp dụng: **{len(applied_replacements)}** ({dict(strategy_counts)})",
        f"- Inline image lỗi sau: **{len(after_issues)}** trong **{len({i['href'] for i in after_issues})}** bài ({dict(after_reason_counts)})",
        "",
        "## Chiến lược thay thế",
        "",
        "| Strategy | Count |",
        "|---|---:|",
    ]
    for strategy, count in sorted(strategy_counts.items(), key=lambda kv: (-kv[1], kv[0])):
        lines.append(f"| `{strategy}` | {count} |")
    REPORT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "beforeIssues": len(before_issues),
                "appliedReplacements": len(applied_replacements),
                "afterIssues": len(after_issues),
                "report": str(REPORT_MD_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
