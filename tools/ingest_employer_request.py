#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DATA_FILE = ROOT / "data" / "employer-requests.json"
QUEUE_FEED_FILE = ROOT / "data" / "feeds" / "employer-requests-queue.json"
QUEUE_MD_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md"

REQUIRED_FIELDS = [
    "companyName",
    "contactName",
    "contactPhone",
    "jobTitle",
    "jobLocation",
    "createdAt",
]


def slugify(value: str) -> str:
    value = value.lower().replace("đ", "d")
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
        value = value.replace(src, dst)
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    return re.sub(r"-{2,}", "-", value)


def load_json(path: Path, default: Any) -> Any:
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8"))


def normalize_payload(payload: dict[str, Any]) -> dict[str, Any]:
    missing = [field for field in REQUIRED_FIELDS if not str(payload.get(field, "")).strip()]
    if missing:
        raise ValueError(f"Thiếu field bắt buộc: {', '.join(missing)}")

    created_at = str(payload["createdAt"]).strip()
    datetime.strptime(created_at, "%Y-%m-%dT%H:%M:%S")

    request_id = (
        str(payload.get("requestId", "")).strip()
        or f"brief-{created_at.replace(':', '-').replace('T', '-')}"
    )
    ident = f"lead/{slugify(request_id)}"

    return {
        "id": ident,
        "requestId": request_id,
        "companyName": str(payload["companyName"]).strip(),
        "contactName": str(payload["contactName"]).strip(),
        "contactPhone": str(payload["contactPhone"]).strip(),
        "contactEmail": str(payload.get("contactEmail", "")).strip(),
        "jobTitle": str(payload["jobTitle"]).strip(),
        "jobLocation": str(payload["jobLocation"]).strip(),
        "jobQuantity": int(payload.get("jobQuantity") or 1),
        "jobDeadline": str(payload.get("jobDeadline", "")).strip(),
        "employmentType": str(payload.get("employmentType", "")).strip(),
        "workMode": str(payload.get("workMode", "")).strip(),
        "salaryLabel": str(payload.get("salaryLabel", "")).strip(),
        "experienceLevel": str(payload.get("experienceLevel", "")).strip(),
        "jobNotes": str(payload.get("jobNotes", "")).strip(),
        "createdAt": created_at,
        "sourcePage": str(payload.get("sourcePage", "dang-tin-tuyen-dung.html")).strip(),
        "sourceChannel": str(payload.get("sourceChannel", "website-brief-json")).strip(),
        "status": str(payload.get("status", "new")).strip() or "new",
        "ingestedAt": datetime.now().strftime("%Y-%m-%dT%H:%M:%S"),
        "reviewNotes": payload.get("reviewNotes") or [],
        "jobDraftPath": str(payload.get("jobDraftPath", "")).strip(),
        "jobPublicHref": str(payload.get("jobPublicHref", "")).strip(),
        "publishedAt": str(payload.get("publishedAt", "")).strip(),
    }


def render_queue_markdown(rows: list[dict[str, Any]]) -> str:
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
    return "\n".join(lines) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser(description="Ingest brief tuyển dụng từ file JSON vào moderation queue nội bộ")
    parser.add_argument("input_json", help="Đường dẫn tới file JSON brief được xuất từ trang dang-tin-tuyen-dung")
    args = parser.parse_args()

    input_path = Path(args.input_json).resolve()
    if not input_path.exists():
        raise SystemExit(f"Không tìm thấy file input: {input_path}")

    payload = json.loads(input_path.read_text(encoding="utf-8"))
    row = normalize_payload(payload)

    current = load_json(DATA_FILE, [])
    if any(existing.get("id") == row["id"] for existing in current):
        raise SystemExit(f"Request đã tồn tại: {row['id']}")

    current.append(row)
    current.sort(key=lambda item: item["createdAt"], reverse=True)

    DATA_FILE.parent.mkdir(parents=True, exist_ok=True)
    QUEUE_FEED_FILE.parent.mkdir(parents=True, exist_ok=True)
    QUEUE_MD_FILE.parent.mkdir(parents=True, exist_ok=True)

    DATA_FILE.write_text(json.dumps(current, ensure_ascii=False, indent=2), encoding="utf-8")
    QUEUE_FEED_FILE.write_text(json.dumps(current, ensure_ascii=False, indent=2), encoding="utf-8")
    QUEUE_MD_FILE.write_text(render_queue_markdown(current), encoding="utf-8")

    print(json.dumps(
        {
            "saved_to": str(DATA_FILE.relative_to(ROOT)),
            "queue_feed": str(QUEUE_FEED_FILE.relative_to(ROOT)),
            "queue_md": str(QUEUE_MD_FILE.relative_to(ROOT)),
            "total_requests": len(current),
            "new_request_id": row["id"],
        },
        ensure_ascii=False,
        indent=2,
    ))


if __name__ == "__main__":
    main()
