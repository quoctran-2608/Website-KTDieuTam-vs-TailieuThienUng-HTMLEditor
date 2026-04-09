#!/usr/bin/env python3
from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA_FILE = ROOT / "data" / "employer-requests.json"
REPORT_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "bao-cao-audit-brief-tuyen-dung.md"
JOBS_SOURCE_DIR = ROOT / "content" / "tuyen-dung"
JOBS_PUBLIC_DIR = ROOT / "tuyen-dung"
REQUIRED_FIELDS = [
    "id",
    "requestId",
    "companyName",
    "contactName",
    "contactPhone",
    "jobTitle",
    "jobLocation",
    "createdAt",
    "status",
]
VALID_STATUS = {"new", "reviewing", "approved", "rejected"}


def main() -> None:
    rows = json.loads(DATA_FILE.read_text(encoding="utf-8")) if DATA_FILE.exists() else []
    issues: list[str] = []
    ids = set()
    request_ids = set()

    for idx, row in enumerate(rows, start=1):
        label = row.get("id") or f"row-{idx}"
        for field in REQUIRED_FIELDS:
            if not str(row.get(field, "")).strip():
                issues.append(f"`{label}` thiếu field `{field}`")
        if row.get("id") in ids:
            issues.append(f"`{label}` bị trùng id")
        if row.get("requestId") in request_ids:
            issues.append(f"`{label}` bị trùng requestId")
        ids.add(row.get("id"))
        request_ids.add(row.get("requestId"))

        status = str(row.get("status", "")).strip()
        if status and status not in VALID_STATUS:
            issues.append(f"`{label}` có status không hợp lệ: {status}")

        created = str(row.get("createdAt", "")).strip()
        if created:
            try:
                datetime.strptime(created, "%Y-%m-%dT%H:%M:%S")
            except Exception:
                issues.append(f"`{label}` có createdAt sai format")

        draft_path = str(row.get("jobDraftPath", "")).strip()
        public_href = str(row.get("jobPublicHref", "")).strip()
        published_at = str(row.get("publishedAt", "")).strip()

        if status == "new" and draft_path:
            issues.append(f"`{label}` đang ở new nhưng đã có jobDraftPath")
        if status == "reviewing" and draft_path and not (ROOT / draft_path).exists():
            issues.append(f"`{label}` có jobDraftPath nhưng file draft không tồn tại")
        if status == "approved":
            if not draft_path:
                issues.append(f"`{label}` đã approved nhưng thiếu jobDraftPath")
            elif not (ROOT / draft_path).exists():
                issues.append(f"`{label}` đã approved nhưng file draft/source không tồn tại")
            if not public_href:
                issues.append(f"`{label}` đã approved nhưng thiếu jobPublicHref")
            elif not (ROOT / public_href).exists():
                issues.append(f"`{label}` đã approved nhưng file public không tồn tại")
            if not published_at:
                issues.append(f"`{label}` đã approved nhưng thiếu publishedAt")
            else:
                try:
                    datetime.strptime(published_at, "%Y-%m-%dT%H:%M:%S")
                except Exception:
                    issues.append(f"`{label}` có publishedAt sai format")

    lines = [
        "# Báo cáo audit brief tuyển dụng",
        "",
        f"- Tổng brief: {len(rows)}",
        f"- Tổng issue: {len(issues)}",
        "",
        "## Danh sách issue",
        "",
    ]
    if not issues:
        lines.append("- Không phát hiện issue ở queue brief hiện tại.")
    else:
        for issue in issues:
            lines.append(f"- {issue}")

    REPORT_FILE.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(REPORT_FILE.relative_to(ROOT))
    print(f"requests={len(rows)} issues={len(issues)}")


if __name__ == "__main__":
    main()
