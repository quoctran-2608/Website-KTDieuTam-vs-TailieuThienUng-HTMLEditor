#!/usr/bin/env python3
from __future__ import annotations

import re
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "content" / "tuyen-dung"
REPORT_PATH = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "bao-cao-audit-du-lieu-tuyen-dung.md"
SKIP_FILES = {"README.md", "mau-tin-tuyen-dung.md"}
REQUIRED_FIELDS = [
    "id",
    "slug",
    "title",
    "companyName",
    "location",
    "employmentType",
    "workMode",
    "deadline",
    "publishDate",
    "status",
    "summary",
    "sourceSite",
    "sourceUrl",
]


def parse_front_matter(raw: str, path: Path) -> tuple[dict, str]:
    match = re.match(r"^---\n(.*?)\n---\n(.*)$", raw, re.S)
    if not match:
        raise ValueError(f"{path}: thiếu front matter")
    return yaml.safe_load(match.group(1)) or {}, match.group(2)


def parse_date(value: str) -> date | None:
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except Exception:
        return None


def main() -> None:
    today = date.today()
    jobs = []
    issues = []
    slug_counter = Counter()
    id_counter = Counter()
    status_counter = Counter()
    location_counter = Counter()

    for path in sorted(SOURCE_DIR.glob("*.md")):
        if path.name in SKIP_FILES:
            continue
        raw = path.read_text(encoding="utf-8")
        meta, _ = parse_front_matter(raw, path)
        jobs.append((path, meta))
        slug_counter[str(meta.get("slug", "")).strip()] += 1
        id_counter[str(meta.get("id", "")).strip()] += 1

    for path, meta in jobs:
        raw = path.read_text(encoding="utf-8")
        missing = [field for field in REQUIRED_FIELDS if not str(meta.get(field, "")).strip()]
        if missing:
            issues.append((path.name, f"Thiếu field bắt buộc: {', '.join(missing)}"))

        publish_date = parse_date(str(meta.get("publishDate", "")).strip())
        deadline = parse_date(str(meta.get("deadline", "")).strip())
        if publish_date is None:
            issues.append((path.name, "publishDate sai format"))
        if deadline is None:
            issues.append((path.name, "deadline sai format"))
        if publish_date and deadline and deadline < publish_date:
            issues.append((path.name, "deadline nhỏ hơn publishDate"))

        status = str(meta.get("status", "")).strip()
        status_counter[status] += 1
        location_counter[str(meta.get("location", "")).strip()] += 1

        if deadline and deadline < today and status == "active":
            issues.append((path.name, "Job quá hạn nhưng status vẫn là active"))

        if re.search(r"^- -\s+", raw, re.M):
            issues.append((path.name, "Còn bullet thô dạng '- -'"))

        sections = {}
        for chunk in re.split(r"^##\s+", raw, flags=re.M)[1:]:
            lines = chunk.splitlines()
            if not lines:
                continue
            sections[lines[0].strip()] = "\n".join(lines[1:])
        yeu_cau = sections.get("Yêu cầu", "")
        if re.search(r"^\-\s*(lương|thu nhập|thưởng|bhxh|bhyt|bhtn|phụ cấp|phu cap|chế độ|che do)\b", yeu_cau, re.I | re.M):
            issues.append((path.name, "Mục Yêu cầu đang lẫn bullet quyền lợi"))
        if re.search(r"^\-\s*(thời gian làm việc|địa điểm làm việc|địa điểm|dia diem)\b", yeu_cau, re.I | re.M):
            issues.append((path.name, "Mục Yêu cầu đang lẫn bullet thời gian/địa điểm"))

    for slug, count in slug_counter.items():
        if slug and count > 1:
            issues.append((slug, f"Slug trùng {count} lần"))

    for ident, count in id_counter.items():
        if ident and count > 1:
            issues.append((ident, f"ID trùng {count} lần"))

    lines = [
        "# Báo cáo audit dữ liệu tuyển dụng",
        "",
        f"- Ngày chạy: {today.isoformat()}",
        f"- Tổng file job source: {len(jobs)}",
        f"- Tổng issue: {len(issues)}",
        "",
        "## Thống kê trạng thái",
        "",
        "| Status | Số lượng |",
        "|---|---:|",
    ]
    for key, value in sorted(status_counter.items()):
        lines.append(f"| {key or '(trống)'} | {value} |")

    lines += [
        "",
        "## Top khu vực",
        "",
        "| Khu vực | Số tin |",
        "|---|---:|",
    ]
    for key, value in location_counter.most_common(10):
        lines.append(f"| {key} | {value} |")

    lines += [
        "",
        "## Danh sách issue",
        "",
    ]
    if not issues:
        lines.append("- Không phát hiện issue dữ liệu ở batch hiện tại.")
    else:
        for name, issue in issues:
            lines.append(f"- `{name}`: {issue}")

    REPORT_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(REPORT_PATH.relative_to(ROOT))
    print(f"jobs={len(jobs)} issues={len(issues)}")


if __name__ == "__main__":
    main()
