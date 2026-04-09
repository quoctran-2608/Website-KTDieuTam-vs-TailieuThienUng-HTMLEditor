#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from datetime import date
from pathlib import Path
from typing import Any
import re

import yaml

from import_sanketoan_jobs import parse_job, slugify, clean_text

ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "content" / "tuyen-dung"
REPORT_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "bao-cao-sync-sanketoan.md"
SKIP = {"README.md", "mau-tin-tuyen-dung.md"}

FIELD_ORDER = [
    "id",
    "slug",
    "title",
    "companyName",
    "companySlug",
    "location",
    "employmentType",
    "workMode",
    "salaryMin",
    "salaryMax",
    "salaryLabel",
    "experienceLevel",
    "deadline",
    "publishDate",
    "status",
    "featured",
    "urgent",
    "contactName",
    "contactPhone",
    "contactEmail",
    "applyUrl",
    "summary",
    "tags",
    "sourceSite",
    "sourceUrl",
    "lastReviewedDate",
    "sourceLastSynced",
]


def parse_front_matter(raw: str, path: Path) -> tuple[dict[str, Any], str]:
    import re

    match = re.match(r"^---\n(.*?)\n---\n(.*)$", raw, re.S)
    if not match:
        raise ValueError(f"{path}: thiếu front matter")
    meta = yaml.safe_load(match.group(1)) or {}
    body = match.group(2)
    return meta, body


def dump_front_matter(meta: dict[str, Any], body: str) -> str:
    ordered = {}
    for key in FIELD_ORDER:
        if key in meta and meta[key] not in ("", None, []):
            ordered[key] = meta[key]
    for key in meta:
        if key not in ordered and meta[key] not in ("", None, []):
            ordered[key] = meta[key]
    front = yaml.safe_dump(ordered, allow_unicode=True, sort_keys=False).strip()
    return f"---\n{front}\n---\n{body.lstrip()}"


def norm(value: Any) -> str:
    if value is None:
        return ""
    return clean_text(str(value))


def semantic_key(value: Any) -> str:
    text = norm(value).lower().replace("đ", "d")
    replacements = {
        "à": "a", "á": "a", "ạ": "a", "ả": "a", "ã": "a",
        "â": "a", "ầ": "a", "ấ": "a", "ậ": "a", "ẩ": "a", "ẫ": "a",
        "ă": "a", "ằ": "a", "ắ": "a", "ặ": "a", "ẳ": "a", "ẵ": "a",
        "è": "e", "é": "e", "ẹ": "e", "ẻ": "e", "ẽ": "e",
        "ê": "e", "ề": "e", "ế": "e", "ệ": "e", "ể": "e", "ễ": "e",
        "ì": "i", "í": "i", "ị": "i", "ỉ": "i", "ĩ": "i",
        "ò": "o", "ó": "o", "ọ": "o", "ỏ": "o", "õ": "o",
        "ô": "o", "ồ": "o", "ố": "o", "ộ": "o", "ổ": "o", "ỗ": "o",
        "ơ": "o", "ờ": "o", "ớ": "o", "ợ": "o", "ở": "o", "ỡ": "o",
        "ù": "u", "ú": "u", "ụ": "u", "ủ": "u", "ũ": "u",
        "ư": "u", "ừ": "u", "ứ": "u", "ự": "u", "ử": "u", "ữ": "u",
        "ỳ": "y", "ý": "y", "ỵ": "y", "ỷ": "y", "ỹ": "y",
    }
    for src, dst in replacements.items():
        text = text.replace(src, dst)
    return re.sub(r"[^a-z0-9]+", "", text)


def score_text_quality(value: str, kind: str) -> int:
    text = norm(value)
    if not text:
        return 0
    score = len(text)
    if kind == "location":
        if ", " in text:
            score += 3
        if any(token in text.lower() for token in ["hà nội", "ha noi", "tp.hcm", "ho chi minh", "bến tre", "bình dương"]):
            score += 5
        if " ," in text or text.endswith("."):
            score -= 2
    if kind == "salary":
        if re.search(r"\d+\s*-\s*\d+", text):
            score += 4
        if "thỏa thuận" in text.lower():
            score += 1
        if re.search(r"\d+\s*-\d+", text):
            score -= 1
    return score


def should_replace_text(old_value: str, new_value: str, kind: str) -> bool:
    old_text = norm(old_value)
    new_text = norm(new_value)
    if not new_text:
        return False
    if not old_text:
        return True
    if semantic_key(old_text) == semantic_key(new_text):
        return False
    return score_text_quality(new_text, kind) > score_text_quality(old_text, kind)


def normalize_salary_fields(meta: dict[str, Any], job) -> None:
    if job.salary_min is None:
        meta.pop("salaryMin", None)
    else:
        meta["salaryMin"] = job.salary_min
    if job.salary_max is None:
        meta.pop("salaryMax", None)
    else:
        meta["salaryMax"] = job.salary_max


def sync_file(path: Path, apply: bool, force: bool) -> dict[str, Any] | None:
    raw = path.read_text(encoding="utf-8")
    meta, body = parse_front_matter(raw, path)
    if str(meta.get("sourceSite", "")).strip() != "sanketoan.vn":
        return None
    source_url = str(meta.get("sourceUrl", "")).strip()
    if not source_url:
        return None

    remote = parse_job(source_url)
    changed = {}
    skipped = {}

    old_title = norm(meta.get("title"))
    new_title = remote.title
    if old_title != new_title:
        if force or should_replace_text(old_title, new_title, "title"):
            changed["title"] = [old_title, new_title]
            meta["title"] = new_title
        else:
            skipped["title"] = [old_title, new_title]

    old_company = norm(meta.get("companyName"))
    new_company = remote.company_name
    if old_company != new_company:
        if force or should_replace_text(old_company, new_company, "company"):
            changed["companyName"] = [old_company, new_company]
            meta["companyName"] = new_company
            meta["companySlug"] = slugify(new_company)
        else:
            skipped["companyName"] = [old_company, new_company]

    old_location = norm(meta.get("location"))
    if old_location != remote.location:
        if force or should_replace_text(old_location, remote.location, "location"):
            changed["location"] = [old_location, remote.location]
            meta["location"] = remote.location
        else:
            skipped["location"] = [old_location, remote.location]

    old_salary_label = norm(meta.get("salaryLabel"))
    if old_salary_label != remote.salary_label:
        if force or should_replace_text(old_salary_label, remote.salary_label, "salary"):
            changed["salaryLabel"] = [old_salary_label, remote.salary_label]
            meta["salaryLabel"] = remote.salary_label
        else:
            skipped["salaryLabel"] = [old_salary_label, remote.salary_label]

    before_salary = (meta.get("salaryMin"), meta.get("salaryMax"))
    normalize_salary_fields(meta, remote)
    after_salary = (meta.get("salaryMin"), meta.get("salaryMax"))
    if before_salary != after_salary and (force or not skipped.get("salaryLabel")):
        changed["salaryRange"] = [before_salary, after_salary]

    old_exp = norm(meta.get("experienceLevel"))
    if old_exp != remote.experience_level:
        if force or not old_exp:
            changed["experienceLevel"] = [old_exp, remote.experience_level]
            meta["experienceLevel"] = remote.experience_level
        else:
            skipped["experienceLevel"] = [old_exp, remote.experience_level]

    old_deadline = norm(meta.get("deadline"))
    if old_deadline != remote.deadline:
        changed["deadline"] = [old_deadline, remote.deadline]
        meta["deadline"] = remote.deadline

    old_publish = norm(meta.get("publishDate"))
    if old_publish != remote.publish_date:
        changed["publishDate"] = [old_publish, remote.publish_date]
        meta["publishDate"] = remote.publish_date

    old_apply = norm(meta.get("applyUrl"))
    if old_apply != source_url:
        changed["applyUrl"] = [old_apply, source_url]
        meta["applyUrl"] = source_url

    meta["sourceLastSynced"] = date.today().isoformat()

    if apply and changed:
        path.write_text(dump_front_matter(meta, body), encoding="utf-8")

    return {"file": path.name, "sourceUrl": source_url, "changed": changed, "skipped": skipped}


def main() -> None:
    parser = argparse.ArgumentParser(description="Sync metadata cứng của job seed từ Sanketoan")
    parser.add_argument("--apply", action="store_true", help="Ghi các thay đổi an toàn vào source .md")
    parser.add_argument("--force-apply", action="store_true", help="Ghi đè toàn bộ drift metadata, kể cả khi nguồn mới không rõ là tốt hơn")
    args = parser.parse_args()

    rows = []
    for path in sorted(SOURCE_DIR.glob("*.md")):
        if path.name in SKIP:
            continue
        result = sync_file(path, apply=args.apply or args.force_apply, force=args.force_apply)
        if result is not None:
            rows.append(result)

    changed_rows = [row for row in rows if row["changed"]]
    skipped_rows = [row for row in rows if row["skipped"]]
    lines = [
        "# Báo cáo sync metadata từ Sanketoan",
        "",
        f"- Ngày chạy: {date.today().isoformat()}",
        f"- Tổng file nguồn Sanketoan: {len(rows)}",
        f"- File có thay đổi: {len(changed_rows)}",
        f"- File bị giữ local (không auto-apply): {len(skipped_rows)}",
        f"- Apply mode: {'Force' if args.force_apply else ('Safe' if args.apply else 'Không')}",
        "",
        "## Danh sách thay đổi",
        "",
    ]
    if not changed_rows:
        lines.append("- Không phát hiện thay đổi metadata nào từ nguồn Sanketoan.")
    else:
        for row in changed_rows:
            lines.append(f"- `{row['file']}`")
            for field, values in row["changed"].items():
                lines.append(f"  - `{field}`: `{values[0]}` -> `{values[1]}`")
    lines += [
        "",
        "## Danh sách drift bị giữ local",
        "",
    ]
    if not skipped_rows:
        lines.append("- Không có drift nào bị giữ local.")
    else:
        for row in skipped_rows:
            lines.append(f"- `{row['file']}`")
            for field, values in row["skipped"].items():
                lines.append(f"  - `{field}`: giữ local `{values[0]}`, không lấy nguồn `{values[1]}`")

    REPORT_FILE.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(
        {
            "scanned": len(rows),
            "changed": len(changed_rows),
            "skipped": len(skipped_rows),
            "report": str(REPORT_FILE.relative_to(ROOT)),
            "apply": args.apply,
            "forceApply": args.force_apply,
        },
        ensure_ascii=False,
        indent=2,
    ))


if __name__ == "__main__":
    main()
