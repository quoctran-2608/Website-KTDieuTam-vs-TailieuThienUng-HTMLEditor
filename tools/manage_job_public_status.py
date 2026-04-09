#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import subprocess
from datetime import date
from pathlib import Path
from moderation_log import append_event

ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "content" / "tuyen-dung"
SKIP = {"README.md", "mau-tin-tuyen-dung.md"}
VALID_STATUS = {"draft", "active", "closed"}


def resolve_target(identifier: str) -> Path:
    direct = Path(identifier)
    if direct.exists():
        return direct.resolve()
    if not identifier.endswith(".md"):
        candidate = SOURCE_DIR / f"{identifier}.md"
        if candidate.exists():
            return candidate.resolve()
    raise SystemExit(f"Không tìm thấy job source: {identifier}")


def replace_field(text: str, field: str, new_value: str) -> str:
    pattern = re.compile(rf"^({re.escape(field)}:\s*).*$", re.M)
    if not pattern.search(text):
        raise SystemExit(f"Thiếu field `{field}` trong source")
    return pattern.sub(lambda match: f"{match.group(1)}{new_value}", text)


def display_path(path: Path) -> str:
    try:
        return str(path.relative_to(ROOT))
    except ValueError:
        return str(path)


def main() -> None:
    parser = argparse.ArgumentParser(description="Quản lý vòng đời job public bằng cách sửa status trong source .md")
    parser.add_argument("job_source", help="slug hoặc path tới file .md của job")
    parser.add_argument("--status", required=True, choices=sorted(VALID_STATUS), help="Trạng thái mới")
    parser.add_argument("--deadline", default="", help="Nếu cần, cập nhật deadline mới theo YYYY-MM-DD")
    parser.add_argument("--skip-build", action="store_true", help="Chỉ sửa source, không chạy build/audit")
    parser.add_argument("--actor", default="system-cli", help="Người thực hiện (để log/audit)")
    args = parser.parse_args()

    path = resolve_target(args.job_source)
    if path.name in SKIP:
        raise SystemExit("Không áp dụng cho file mẫu/README")

    text = path.read_text(encoding="utf-8")
    status_match = re.search(r"^status:\s*(.+)$", text, re.M)
    previous_status = status_match.group(1).strip() if status_match else ""
    effective_deadline = args.deadline
    if not effective_deadline:
        deadline_match = re.search(r"^deadline:\s*(.+)$", text, re.M)
        effective_deadline = deadline_match.group(1).strip() if deadline_match else ""

    text = replace_field(text, "status", args.status)
    text = replace_field(text, "lastReviewedDate", date.today().isoformat())
    if args.deadline:
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", args.deadline):
            raise SystemExit("deadline phải theo format YYYY-MM-DD")
        text = replace_field(text, "deadline", args.deadline)
    path.write_text(text, encoding="utf-8")

    try:
        if not args.skip_build:
            subprocess.run(["python3", "tools/build_jobs.py"], cwd=ROOT, check=True)
            subprocess.run(["python3", "tools/audit_jobs_data.py"], cwd=ROOT, check=True)
    except BaseException as exc:
        append_event(
            {
                "eventType": "job-status-change",
                "tool": "manage_job_public_status.py",
                "result": "failed",
                "actor": args.actor,
                "action": "update-job-status",
                "jobSourcePath": display_path(path),
                "jobSlug": path.stem,
                "fromStatus": previous_status,
                "toStatus": args.status,
                "deadline": effective_deadline,
                "skipBuild": bool(args.skip_build),
                "error": str(exc),
            }
        )
        raise

    event_payload = append_event(
        {
            "eventType": "job-status-change",
            "tool": "manage_job_public_status.py",
            "result": "success",
            "actor": args.actor,
            "action": "update-job-status",
            "jobSourcePath": display_path(path),
            "jobSlug": path.stem,
            "fromStatus": previous_status,
            "toStatus": args.status,
            "deadline": effective_deadline,
            "skipBuild": bool(args.skip_build),
        }
    )

    print(f"{display_path(path)} -> status={args.status}")
    print(f"logEventId={event_payload['eventId']}")


if __name__ == "__main__":
    main()
