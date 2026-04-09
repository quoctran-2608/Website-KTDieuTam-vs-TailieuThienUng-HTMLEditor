#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

from moderation_log import LOG_FILE

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUT_MD = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "dashboard-moderation-events.md"
DEFAULT_OUT_JSON = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "dashboard-moderation-events.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render dashboard moderation events từ jsonl log")
    parser.add_argument("--log-path", default=str(LOG_FILE), help="Đường dẫn log .jsonl")
    parser.add_argument("--out-md", default=str(DEFAULT_OUT_MD), help="Đường dẫn dashboard markdown")
    parser.add_argument("--out-json", default=str(DEFAULT_OUT_JSON), help="Đường dẫn dashboard json summary")
    parser.add_argument("--day", default="", help="Lọc theo ngày UTC: YYYY-MM-DD")
    parser.add_argument("--actor", default="", help="Lọc theo actor")
    parser.add_argument("--limit", type=int, default=30, help="Số event mới nhất hiển thị")
    return parser.parse_args()


def parse_logged_at(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def to_utc_iso(value: datetime) -> str:
    return value.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def load_rows(path: Path) -> tuple[list[dict], int]:
    if not path.exists():
        return [], 0
    rows: list[dict] = []
    parse_errors = 0
    for line in path.read_text(encoding="utf-8").splitlines():
        text = line.strip()
        if not text:
            continue
        try:
            item = json.loads(text)
            item["_dt"] = parse_logged_at(str(item.get("loggedAt", "")))
            rows.append(item)
        except Exception:
            parse_errors += 1
    rows.sort(key=lambda item: item["_dt"])
    return rows, parse_errors


def render_markdown(
    *,
    log_path: Path,
    rows_all: list[dict],
    rows_filtered: list[dict],
    parse_errors: int,
    day_filter: str,
    actor_filter: str,
    limit: int,
) -> str:
    now = datetime.now(timezone.utc)
    failed_rows = [row for row in rows_filtered if row.get("result") == "failed"]
    success_rows = [row for row in rows_filtered if row.get("result") == "success"]
    latest_rows = list(reversed(rows_filtered[-limit:])) if rows_filtered else []
    latest_failed = list(reversed(failed_rows[-min(limit, 20):])) if failed_rows else []

    event_counter = Counter(str(row.get("eventType", "")) for row in rows_filtered)
    actor_counter = Counter(str(row.get("actor", "")) for row in rows_filtered)

    lines = [
        "# Dashboard moderation events",
        "",
        f"- Generated at (UTC): `{to_utc_iso(now)}`",
        f"- Log file: `{log_path.relative_to(ROOT) if log_path.is_absolute() and str(log_path).startswith(str(ROOT)) else log_path}`",
        f"- Tổng event trong file: **{len(rows_all)}**",
        f"- Event sau lọc: **{len(rows_filtered)}**",
        f"- Parse errors (invalid json/time): **{parse_errors}**",
        f"- Filter day (UTC): `{day_filter or 'none'}`",
        f"- Filter actor: `{actor_filter or 'none'}`",
        "",
    ]

    if failed_rows:
        lines.extend(
            [
                "> ⚠️ **CẢNH BÁO:** Có event `failed` trong phạm vi lọc.",
                f"> - failed: **{len(failed_rows)}**",
                f"> - success: **{len(success_rows)}**",
                "",
            ]
        )
    else:
        lines.extend(
            [
                "> ✅ Không có event `failed` trong phạm vi lọc.",
                f"> - success: **{len(success_rows)}**",
                "",
            ]
        )

    lines.extend(
        [
            "## Distribution theo eventType",
            "",
            "| eventType | count |",
            "|---|---:|",
        ]
    )
    if event_counter:
        for key, count in sorted(event_counter.items(), key=lambda item: (-item[1], item[0])):
            lines.append(f"| {key or '-'} | {count} |")
    else:
        lines.append("| - | 0 |")

    lines.extend(
        [
            "",
            "## Distribution theo actor",
            "",
            "| actor | count |",
            "|---|---:|",
        ]
    )
    if actor_counter:
        for key, count in sorted(actor_counter.items(), key=lambda item: (-item[1], item[0])):
            lines.append(f"| {key or '-'} | {count} |")
    else:
        lines.append("| - | 0 |")

    lines.extend(
        [
            "",
            f"## Latest {len(latest_rows)} events",
            "",
            "| loggedAt (UTC) | eventType | result | actor | action | target |",
            "|---|---|---|---|---|---|",
        ]
    )
    if latest_rows:
        for row in latest_rows:
            target = row.get("requestId") or row.get("jobSlug") or row.get("jobSourcePath") or "-"
            lines.append(
                f"| {row.get('loggedAt', '')} | {row.get('eventType', '')} | {row.get('result', '')} | {row.get('actor', '')} | {row.get('action', '')} | {target} |"
            )
    else:
        lines.append("| - | - | - | - | - | - |")

    lines.extend(
        [
            "",
            f"## Failed events ({len(latest_failed)})",
            "",
            "| loggedAt (UTC) | eventType | actor | action | target | error |",
            "|---|---|---|---|---|---|",
        ]
    )
    if latest_failed:
        for row in latest_failed:
            target = row.get("requestId") or row.get("jobSlug") or row.get("jobSourcePath") or "-"
            error = str(row.get("error", "")).replace("|", "/")
            lines.append(
                f"| {row.get('loggedAt', '')} | {row.get('eventType', '')} | {row.get('actor', '')} | {row.get('action', '')} | {target} | {error or '-'} |"
            )
    else:
        lines.append("| - | - | - | - | - | - |")

    lines.append("")
    return "\n".join(lines)


def main() -> int:
    args = parse_args()
    log_path = Path(args.log_path)
    out_md = Path(args.out_md)
    out_json = Path(args.out_json)
    limit = max(1, args.limit)

    rows_all, parse_errors = load_rows(log_path)
    rows_filtered = rows_all

    day_filter = args.day.strip()
    actor_filter = args.actor.strip().lower()
    if day_filter:
        day_dt = datetime.strptime(day_filter, "%Y-%m-%d").date()
        rows_filtered = [row for row in rows_filtered if row["_dt"].date() == day_dt]
    if actor_filter:
        rows_filtered = [row for row in rows_filtered if str(row.get("actor", "")).lower() == actor_filter]

    failed_count = sum(1 for row in rows_filtered if row.get("result") == "failed")
    success_count = sum(1 for row in rows_filtered if row.get("result") == "success")
    latest_logged_at = rows_filtered[-1]["loggedAt"] if rows_filtered else ""

    out_md.parent.mkdir(parents=True, exist_ok=True)
    out_json.parent.mkdir(parents=True, exist_ok=True)
    out_md.write_text(
        render_markdown(
            log_path=log_path,
            rows_all=rows_all,
            rows_filtered=rows_filtered,
            parse_errors=parse_errors,
            day_filter=day_filter,
            actor_filter=args.actor.strip(),
            limit=limit,
        ),
        encoding="utf-8",
    )

    summary = {
        "logPath": str(log_path),
        "eventsTotal": len(rows_all),
        "eventsFiltered": len(rows_filtered),
        "parseErrors": parse_errors,
        "failedCount": failed_count,
        "successCount": success_count,
        "dayFilterUtc": day_filter or "",
        "actorFilter": args.actor.strip(),
        "latestLoggedAt": latest_logged_at,
    }
    out_json.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    print(f"events_total={summary['eventsTotal']}")
    print(f"events_filtered={summary['eventsFiltered']}")
    print(f"failed_count={summary['failedCount']}")
    print(f"out_md={out_md}")
    print(f"out_json={out_json}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
