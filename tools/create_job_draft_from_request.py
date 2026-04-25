#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REQUESTS_FILE = ROOT / "data" / "employer-requests.json"
QUEUE_FEED_FILE = ROOT / "data" / "feeds" / "employer-requests-queue.json"
QUEUE_MD_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "hang-cho-kiem-duyet-nhu-cau-tuyen-dung.md"
OUTPUT_DIR = ROOT / "content" / "tuyen-dung"

ROLE_DEFAULTS = {
    "ke-toan-thue": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm kê khai, kiểm soát hồ sơ thuế và phối hợp xử lý số liệu tuân thủ.",
        "description": [
            "Phụ trách kê khai, rà soát hồ sơ thuế và phối hợp xử lý số liệu theo quy định hiện hành.",
            "Theo dõi chứng từ đầu vào/đầu ra, đối chiếu số liệu với các bộ phận liên quan.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã có kinh nghiệm kế toán thuế hoặc từng xử lý hồ sơ kê khai định kỳ.",
            "Cẩn thận, nắm được quy trình chứng từ và có khả năng làm việc với dữ liệu chi tiết.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, thưởng và chế độ phúc lợi với nhà tuyển dụng trước khi public.",
        ],
        "tags": ["kế toán thuế"],
    },
    "ke-toan-noi-bo": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm theo dõi chứng từ nội bộ, thu chi và phối hợp kiểm soát số liệu vận hành.",
        "description": [
            "Theo dõi chứng từ nội bộ, công nợ, thu chi và các báo cáo phục vụ vận hành doanh nghiệp.",
            "Phối hợp với các bộ phận liên quan để kiểm soát số liệu và hoàn thiện hồ sơ nội bộ.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã từng làm kế toán nội bộ hoặc kế toán vận hành.",
            "Cần cẩn thận, chủ động và có khả năng phối hợp liên phòng ban.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, thưởng và chế độ nội bộ với doanh nghiệp trước khi public.",
        ],
        "tags": ["kế toán nội bộ"],
    },
    "hanh-chinh-nhan-su": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm hành chính - nhân sự, tuyển dụng, hồ sơ lao động và phối hợp vận hành nội bộ.",
        "description": [
            "Thực hiện công tác hành chính - nhân sự, hỗ trợ hồ sơ lao động và các đầu việc tuyển dụng cơ bản.",
            "Phối hợp với quản lý để xử lý chấm công, hợp đồng, lưu trữ hồ sơ và các đầu việc văn phòng.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã có kinh nghiệm hành chính, nhân sự hoặc tuyển dụng.",
            "Cẩn thận, giao tiếp tốt và có khả năng tổ chức công việc nội bộ.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, phụ cấp và chế độ nhân sự với doanh nghiệp trước khi public.",
        ],
        "tags": ["hành chính nhân sự"],
    },
    "ke-toan-truong": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm quản lý hệ thống kế toán, kiểm soát báo cáo và tham mưu tài chính ở cấp độ quản trị.",
        "description": [
            "Phụ trách tổ chức hệ thống kế toán, kiểm soát báo cáo và tham mưu tài chính cho doanh nghiệp.",
            "Làm việc với ban điều hành, cơ quan thuế hoặc kiểm toán theo nhu cầu thực tế của đơn vị tuyển dụng.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã có kinh nghiệm quản lý đội ngũ kế toán hoặc giữ vai trò kế toán trưởng tương đương.",
            "Cần tư duy quản trị, kiểm soát rủi ro và khả năng tổng hợp số liệu tốt.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, quyền hạn công việc và chế độ quản lý trước khi public.",
        ],
        "tags": ["kế toán trưởng"],
    },
    "ke-toan-ban-hang": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm xử lý doanh thu, hóa đơn và đối chiếu công nợ bán hàng.",
        "description": [
            "Theo dõi doanh thu, hóa đơn đầu ra và đối chiếu công nợ bán hàng với bộ phận kinh doanh.",
            "Phối hợp xử lý chứng từ liên quan đến giao hàng, thanh toán và báo cáo bán hàng định kỳ.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã có kinh nghiệm kế toán bán hàng hoặc kế toán doanh thu.",
            "Cần cẩn thận và quen với việc xử lý số liệu phát sinh liên tục.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, thưởng doanh số và các quyền lợi liên quan trước khi public.",
        ],
        "tags": ["kế toán bán hàng"],
    },
    "nhan-vien-ke-toan": {
        "summary": "Vị trí phù hợp với ứng viên đã có kinh nghiệm kế toán cơ bản, theo dõi chứng từ và hỗ trợ báo cáo nội bộ.",
        "description": [
            "Hỗ trợ xử lý chứng từ, nhập liệu và theo dõi các đầu việc kế toán phát sinh hằng ngày.",
            "Phối hợp cùng kế toán phụ trách để hoàn thiện hồ sơ và báo cáo nội bộ theo phân công.",
        ],
        "requirements": [
            "Phù hợp với ứng viên đã có nền tảng kế toán cơ bản hoặc từng làm kế toán thực tế ở mức entry/junior.",
            "Cần cẩn thận, chủ động và sẵn sàng học nhanh theo quy trình của doanh nghiệp.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, chế độ thử việc và quyền lợi đi kèm trước khi public.",
        ],
        "tags": ["nhân viên kế toán"],
    },
    "ke-toan-tong-hop": {
        "summary": "Vị trí phù hợp với ứng viên có kinh nghiệm tổng hợp số liệu kế toán, theo dõi chứng từ và phối hợp lập báo cáo định kỳ cho doanh nghiệp.",
        "description": [
            "Theo dõi chứng từ, tổng hợp số liệu và phối hợp lập các báo cáo kế toán định kỳ cho doanh nghiệp.",
            "Làm việc cùng các bộ phận liên quan để kiểm soát đầu vào, đầu ra và số liệu vận hành.",
        ],
        "requirements": [
            "Ưu tiên ứng viên đã có kinh nghiệm kế toán tổng hợp hoặc từng theo dõi trọn vòng chứng từ - báo cáo.",
            "Cần nắm được logic số liệu, cẩn thận và có khả năng làm việc độc lập.",
        ],
        "benefits": [
            "Cần xác minh thêm lương, thưởng và các phúc lợi đi kèm với doanh nghiệp trước khi public.",
        ],
        "tags": ["kế toán tổng hợp"],
    },
}


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


def normalize_experience(value: str) -> str:
    lower = value.lower()
    if "không yêu cầu" in lower or "fresher" in lower:
        return "fresher"
    match = re.search(r"(\d+)\s*n", lower)
    if match:
        return f"{match.group(1)}-nam"
    return "junior"


def normalize_employment(value: str) -> str:
    lower = value.lower()
    if "bán" in lower:
        return "part-time"
    if "thực tập" in lower:
        return "internship"
    if "hợp đồng" in lower:
        return "contract"
    return "full-time"


def normalize_work_mode(value: str) -> str:
    lower = value.lower()
    if "hybrid" in lower:
        return "hybrid"
    if "remote" in lower or "từ xa" in lower:
        return "remote"
    return "onsite"


def infer_tags(title: str, location: str, employment_type: str) -> list[str]:
    tags = []
    lower = title.lower()
    if "thuế" in lower:
        tags.append("kế toán thuế")
    elif "nội bộ" in lower:
        tags.append("kế toán nội bộ")
    elif "hành chính nhân sự" in lower:
        tags.append("hành chính nhân sự")
    elif "kế toán trưởng" in lower:
        tags.append("kế toán trưởng")
    elif "bán hàng" in lower:
        tags.append("kế toán bán hàng")
    else:
        tags.append("kế toán tổng hợp")

    loc = location.lower()
    if any(token in loc for token in ["hà nội", "ha noi"]):
        tags.append("hà nội")
    elif any(token in loc for token in ["tp.hcm", "tphcm", "hồ chí minh", "ho chi minh"]):
        tags.append("tp hcm")
    tags.append(employment_type)
    return tags


def infer_role_key(title: str) -> str:
    lower = title.lower()
    if "kế toán trưởng" in lower:
        return "ke-toan-truong"
    if "hành chính nhân sự" in lower:
        return "hanh-chinh-nhan-su"
    if "thuế" in lower:
        return "ke-toan-thue"
    if "nội bộ" in lower:
        return "ke-toan-noi-bo"
    if "bán hàng" in lower:
        return "ke-toan-ban-hang"
    if "nhân viên kế toán" in lower:
        return "nhan-vien-ke-toan"
    return "ke-toan-tong-hop"


def build_draft(row: dict) -> tuple[str, str]:
    slug = slugify(f"{row['jobTitle']} {row['companyName']}")
    company_slug = slugify(row["companyName"])
    publish_date = datetime.now().strftime("%Y-%m-%d")
    deadline = row.get("jobDeadline") or publish_date
    employment = normalize_employment(row.get("employmentType", ""))
    work_mode = normalize_work_mode(row.get("workMode", ""))
    experience = normalize_experience(row.get("experienceLevel", ""))
    brief_source_id = row.get("id") or row.get("requestId") or f"lead/{slug}"
    apply_url = ""
    if row.get("contactEmail"):
        apply_url = f"mailto:{row['contactEmail']}"
    elif row.get("contactPhone"):
        apply_url = f"tel:{row['contactPhone']}"
    role_key = infer_role_key(row["jobTitle"])
    role = ROLE_DEFAULTS[role_key]
    summary = (
        row.get("jobNotes")
        or role["summary"]
        or f"Tin nháp được tạo từ brief tuyển dụng của {row['companyName']}, cần biên tập lại trước khi public."
    ).strip()
    job_location = row.get("jobLocation") or ", ".join(
        part for part in [
            row.get("jobAddressDetail", ""),
            row.get("jobAreaName", ""),
            row.get("jobProvinceName", ""),
        ]
        if part
    )
    tags = list(dict.fromkeys(role["tags"] + infer_tags(row["jobTitle"], job_location, employment)))
    description_lines = role["description"][:]
    if row.get("jobNotes"):
        description_lines.insert(0, row["jobNotes"])
    requirements_lines = role["requirements"][:]
    if row.get("experienceLevel"):
        requirements_lines.append(f"Doanh nghiệp đang ưu tiên mức kinh nghiệm: {row['experienceLevel']}.")
    benefits_lines = role["benefits"][:]
    if row.get("salaryLabel"):
        benefits_lines.insert(0, f"Lương tham khảo theo brief: {row['salaryLabel']}.")
    body = "## Mô tả công việc\n\n" + "\n".join(f"- {line}" for line in description_lines) + "\n\n"
    body += "## Yêu cầu\n\n" + "\n".join(f"- {line}" for line in requirements_lines) + "\n\n"
    body += "## Quyền lợi\n\n" + "\n".join(f"- {line}" for line in benefits_lines) + "\n\n"
    body += "## Thời gian và địa điểm làm việc\n\n"
    body += f"- Địa điểm: {job_location or row.get('jobLocation', '')}\n"
    body += f"- Hình thức: {row.get('employmentType') or 'Toàn thời gian'}, {row.get('workMode') or 'Làm việc tại văn phòng'}\n\n"
    body += "## Cách ứng tuyển\n\n"
    body += f"- Liên hệ: {row['contactName']} — {row['contactPhone']}\n"
    body += f"- Email: {row.get('contactEmail') or 'Chưa cung cấp'}\n"
    front = f"""---
id: job/{slug}
slug: {slug}
title: {row['jobTitle']}
companyName: {row['companyName']}
companySlug: {company_slug}
location: {job_location or row.get('jobLocation', '')}
locationProvinceKey: {row.get('jobProvinceKey') or ''}
locationProvinceLabel: {row.get('jobProvinceName') or ''}
locationAreaKey: {row.get('jobAreaKey') or ''}
locationAreaLabel: {row.get('jobAreaName') or ''}
employmentType: {employment}
workMode: {work_mode}
salaryLabel: {row.get('salaryLabel') or 'Thỏa thuận'}
experienceLevel: {experience}
deadline: {deadline}
publishDate: {publish_date}
status: draft
featured: false
urgent: false
contactName: {row['contactName']}
contactPhone: {row['contactPhone']}
contactEmail: {row.get('contactEmail') or ''}
applyUrl: {apply_url}
summary: {summary}
tags:
""" + "\n".join(f"  - {tag}" for tag in tags) + f"""
sourceSite: employer-brief
sourceUrl: {brief_source_id}
lastReviewedDate: {publish_date}
---

{body}
"""
    return f"{slug}.md", front


def main() -> None:
    parser = argparse.ArgumentParser(description="Tạo job draft markdown từ brief tuyển dụng trong queue nội bộ")
    parser.add_argument("request_id", help="requestId hoặc id của brief")
    args = parser.parse_args()

    rows = load_requests()
    target = None
    for row in rows:
        if row.get("id") == args.request_id or row.get("requestId") == args.request_id:
            target = row
            break
    if not target:
        raise SystemExit(f"Không tìm thấy brief: {args.request_id}")

    file_name, body = build_draft(target)
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    output_path = OUTPUT_DIR / file_name
    if output_path.exists():
        raise SystemExit(f"Draft đã tồn tại: {output_path}")
    output_path.write_text(body, encoding="utf-8")

    target["status"] = "reviewing"
    target["jobDraftPath"] = str(output_path.relative_to(ROOT))
    notes = target.get("reviewNotes") or []
    notes.append(f"Tạo job draft tại {target['jobDraftPath']}")
    target["reviewNotes"] = notes
    save_requests(rows)

    print(json.dumps(
        {
            "request_id": target["id"],
            "request_status": target["status"],
            "job_draft_path": target["jobDraftPath"],
        },
        ensure_ascii=False,
        indent=2,
    ))


if __name__ == "__main__":
    main()
