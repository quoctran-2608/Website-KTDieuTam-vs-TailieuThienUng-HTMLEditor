#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import subprocess
from datetime import datetime
from pathlib import Path
from moderation_log import append_event

ROOT = Path(__file__).resolve().parents[1]
REQUESTS_FILE = ROOT / "data" / "employer-requests.json"
QUEUE_FEED_FILE = ROOT / "data" / "feeds" / "employer-requests-queue.json"
QUEUE_MD_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md"


def load_requests() -> list[dict]:
    if not REQUESTS_FILE.exists():
        return []
    return json.loads(REQUESTS_FILE.read_text(encoding="utf-8"))


def save_requests(rows: list[dict]) -> None:
    REQUESTS_FILE.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")
    QUEUE_FEED_FILE.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")
    lines = [
        "# Hàng chờ kiểm duyệt nhu cầu tuyển dụng",
        "",
        f"- Tổng brief hiện có: {len(rows)}",
        "",
        "| Trạng thái | Công ty | Vị trí | Khu vực | Liên hệ | Tạo lúc |",
        "|---|---|---|---|---|---|",
    ]
    for row in rows:
        lines.append(
            f"| {row['status']} | {row['companyName']} | {row['jobTitle']} | {row['jobLocation']} | {row['contactName']} / {row['contactPhone']} | {row['createdAt']} |"
        )
    lines += [
        "",
        "## Ghi chú workflow",
        "",
        "- `new`: brief mới nhận, chưa rà.",
        "- `reviewing`: đang xác minh hoặc chuẩn hóa.",
        "- `approved`: đã duyệt để tạo tin tuyển dụng public.",
        "- `rejected`: từ chối / thiếu dữ liệu / spam.",
        "",
    ]
    QUEUE_MD_FILE.write_text("\n".join(lines), encoding="utf-8")


def update_markdown_status(path: Path, new_status: str) -> None:
    text = path.read_text(encoding="utf-8")
    if "\nstatus: " not in text:
        raise SystemExit(f"File draft thiếu field status: {path}")
    text = text.replace("\nstatus: draft\n", f"\nstatus: {new_status}\n")
    path.write_text(text, encoding="utf-8")


def draft_path_to_public_href(path: Path) -> str:
    return f"tuyen-dung/{path.stem}.html"


def main() -> None:
    parser = argparse.ArgumentParser(description="Moderate brief tuyển dụng: approve / reject / reviewing")
    parser.add_argument("request_id", help="id hoặc requestId của brief")
    parser.add_argument("--action", required=True, choices=["approve", "reject", "reviewing"], help="Hành động moderation")
    parser.add_argument("--note", default="", help="Ghi chú moderation")
    parser.add_argument("--actor", default="system-cli", help="Người thực hiện (để log/audit)")
    args = parser.parse_args()

    rows = load_requests()
    target = None
    for row in rows:
        if row.get("id") == args.request_id or row.get("requestId") == args.request_id:
            target = row
            break
    if not target:
        raise SystemExit(f"Không tìm thấy brief: {args.request_id}")

    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    notes = target.get("reviewNotes") or []
    previous_status = target.get("status", "")
    changed_draft_path = str(target.get("jobDraftPath", "")).strip()
    error_message = ""

    try:
        if args.action == "approve":
            draft_path = ROOT / str(target.get("jobDraftPath", "")).strip()
            if not target.get("jobDraftPath"):
                raise SystemExit("Brief chưa có jobDraftPath để approve")
            if not draft_path.exists():
                raise SystemExit(f"Không tìm thấy file draft: {draft_path}")
            update_markdown_status(draft_path, "active")
            target["status"] = "approved"
            notes.append(args.note or f"Approved và public job từ draft lúc {timestamp}")
            target["approvedAt"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
            target["publishedAt"] = target["approvedAt"]
            target["jobPublicHref"] = draft_path_to_public_href(draft_path)
            save_requests(rows)
            subprocess.run(["python3", "tools/build_jobs.py"], cwd=ROOT, check=True)
            subprocess.run(["python3", "tools/audit_jobs_data.py"], cwd=ROOT, check=True)
            subprocess.run(["python3", "tools/audit_employer_requests.py"], cwd=ROOT, check=True)
        elif args.action == "reject":
            target["status"] = "rejected"
            notes.append(args.note or f"Rejected lúc {timestamp}")
            target["publishedAt"] = ""
            target["jobPublicHref"] = ""
            save_requests(rows)
            subprocess.run(["python3", "tools/audit_employer_requests.py"], cwd=ROOT, check=True)
        else:
            target["status"] = "reviewing"
            notes.append(args.note or f"Đặt lại trạng thái reviewing lúc {timestamp}")
            save_requests(rows)
            subprocess.run(["python3", "tools/audit_employer_requests.py"], cwd=ROOT, check=True)
    except BaseException as exc:
        error_message = str(exc)
        append_event(
            {
                "eventType": "brief-moderation",
                "tool": "moderate_employer_request.py",
                "result": "failed",
                "actor": args.actor,
                "action": args.action,
                "requestId": target.get("requestId") or target.get("id"),
                "briefId": target.get("id"),
                "fromStatus": previous_status,
                "toStatus": target.get("status"),
                "note": args.note,
                "jobDraftPath": changed_draft_path,
                "jobPublicHref": target.get("jobPublicHref", ""),
                "error": error_message,
            }
        )
        raise

    target["reviewNotes"] = notes
    save_requests(rows)

    event_payload = append_event(
        {
            "eventType": "brief-moderation",
            "tool": "moderate_employer_request.py",
            "result": "success",
            "actor": args.actor,
            "action": args.action,
            "requestId": target.get("requestId") or target.get("id"),
            "briefId": target.get("id"),
            "fromStatus": previous_status,
            "toStatus": target.get("status"),
            "note": args.note,
            "jobDraftPath": str(target.get("jobDraftPath", "")),
            "jobPublicHref": target.get("jobPublicHref", ""),
        }
    )

    print(
        json.dumps(
            {
                "request_id": target["id"],
                "status": target["status"],
                "jobDraftPath": target.get("jobDraftPath", ""),
                "notes": target.get("reviewNotes", []),
                "logEventId": event_payload["eventId"],
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
