#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import json
import re
import unicodedata
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Iterable

import requests

ROOT = Path(__file__).resolve().parents[1]
OUTPUT_DIR = ROOT / "content" / "tuyen-dung"
NOTES_DIR = ROOT / "docs" / "nghien-cuu-tuyen-dung"
RECLASS_DIR = ROOT / ".m" / "reclass"
HEADERS = {"User-Agent": "Mozilla/5.0"}
TODAY = date.today()

ROLE_SUMMARIES = {
    "kế toán thuế": "Vị trí phù hợp với ứng viên có kinh nghiệm kê khai, kiểm soát hồ sơ thuế và phối hợp xử lý số liệu tuân thủ.",
    "kế toán bán hàng": "Vị trí phù hợp với ứng viên có kinh nghiệm xử lý doanh thu, hóa đơn và đối chiếu công nợ bán hàng.",
    "kế toán nội bộ": "Vị trí phù hợp với ứng viên có kinh nghiệm theo dõi chứng từ nội bộ, thu chi và phối hợp kiểm soát số liệu vận hành.",
    "nhân viên kế toán": "Vị trí phù hợp với ứng viên đã có kinh nghiệm kế toán cơ bản, theo dõi chứng từ và hỗ trợ báo cáo nội bộ.",
    "kế toán trưởng": "Vị trí phù hợp với ứng viên có kinh nghiệm quản lý hệ thống kế toán, kiểm soát báo cáo và tham mưu tài chính ở cấp độ quản trị.",
    "hành chính nhân sự": "Vị trí phù hợp với ứng viên có kinh nghiệm hành chính - nhân sự, tuyển dụng, hồ sơ lao động và phối hợp vận hành nội bộ.",
    "kế toán tổng hợp": "Vị trí phù hợp với ứng viên có kinh nghiệm tổng hợp số liệu kế toán, theo dõi chứng từ và phối hợp lập báo cáo định kỳ cho doanh nghiệp.",
}


@dataclass
class SanketoanJob:
    source_url: str
    title: str
    company_name: str
    location: str
    salary_label: str
    salary_min: int | None
    salary_max: int | None
    experience_level: str
    experience_label: str
    publish_date: str
    deadline: str
    body_sections: dict[str, str]


def clean_text(value: str) -> str:
    value = html.unescape(re.sub(r"<[^>]+>", " ", value or ""))
    value = value.replace("\xa0", " ")
    value = re.sub(r"\s+", " ", value)
    return value.strip(" :\n\t")


def slugify(value: str) -> str:
    value = unicodedata.normalize("NFD", value)
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    value = value.replace("đ", "d").replace("Đ", "D").lower()
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    return re.sub(r"-{2,}", "-", value)


def normalize_publish_date(relative_text: str) -> str:
    text = clean_text(relative_text).lower()
    if "giờ trước" in text or "phút trước" in text:
        return TODAY.isoformat()
    if match := re.search(r"(\d+)\s+ngày trước", text):
        return (TODAY - timedelta(days=int(match.group(1)))).isoformat()
    if match := re.search(r"(\d+)\s+tuần trước", text):
        return (TODAY - timedelta(days=int(match.group(1)) * 7)).isoformat()
    return TODAY.isoformat()


def parse_salary(label: str) -> tuple[int | None, int | None]:
    text = clean_text(label).lower()
    nums = [int(num) for num in re.findall(r"(\d+)", text)]
    if "thỏa thuận" in text or "thoả thuận" in text or "thoa thuan" in text:
        return None, None
    if len(nums) >= 2:
        return nums[0] * 1_000_000, nums[1] * 1_000_000
    if len(nums) == 1:
        return nums[0] * 1_000_000, nums[0] * 1_000_000
    return None, None


def infer_experience_key(label: str) -> str:
    text = clean_text(label).lower()
    if "không yêu cầu" in text or "khong yeu cau" in text:
        return "fresher"
    if match := re.search(r"(\d+)\s*năm", text):
        return f"{match.group(1)}-nam"
    if "senior" in text:
        return "senior"
    return "junior"


def collect_job_urls(limit: int, offset: int) -> list[str]:
    home = requests.get("https://sanketoan.vn/", timeout=20, headers=HEADERS).text
    urls: list[str] = []
    for url in re.findall(r'href=["\'](https://sanketoan\.vn/cong-viec/[^"\']+)', home):
        if url not in urls:
            urls.append(url)
    return urls[offset: offset + limit]


def extract_section(text: str, heading: str, next_headings: Iterable[str]) -> str:
    idx = text.find(heading)
    if idx == -1:
        return ""
    end = len(text)
    for next_heading in next_headings:
        pos = text.find(next_heading, idx + len(heading))
        if pos != -1 and pos < end:
            end = pos
    return text[idx:end]


def html_block_to_markdown_list(block: str) -> str:
    def normalize_bullet(value: str) -> list[str]:
        cleaned = clean_text(value)
        cleaned = re.sub(r"^\-+\s*", "", cleaned)
        if not cleaned:
            return []
        if cleaned.count(" - ") >= 2:
            parts = [part.strip(" -") for part in re.split(r"\s+\-\s+", cleaned) if part.strip(" -")]
            return parts or [cleaned]
        return [cleaned]

    items = re.findall(r"<li>(.*?)</li>", block, re.I | re.S)
    lines = []
    for item in items:
        for bullet in normalize_bullet(item):
            lines.append(f"- {bullet}")
    if lines:
        return "\n".join(lines)
    paragraphs = re.findall(r"<p[^>]*>(.*?)</p>", block, re.I | re.S)
    para_lines = []
    for paragraph in paragraphs:
        para_lines.extend(normalize_bullet(paragraph))
    return "\n".join(f"- {line}" for line in para_lines)


def parse_job(url: str) -> SanketoanJob:
    text = requests.get(url, timeout=20, headers=HEADERS).text
    title = clean_text(re.search(r'<h1[^>]*class="title_job"[^>]*>(.*?)</h1>', text, re.I | re.S).group(1))
    company_name = clean_text(re.search(r'class="titleCompanyName[^"]*"[^>]*>(.*?)</a>', text, re.I | re.S).group(1))

    location_match = re.search(r"Địa điểm làm việc\s*:([^<]{1,300})</p>", text, re.I | re.S)
    location = clean_text(location_match.group(1)) if location_match else ""

    salary_match = re.search(r"Mức lương\s*:([^<]{1,200})</p>", text, re.I | re.S)
    salary_label = clean_text(salary_match.group(1)) if salary_match else "Thỏa thuận"

    exp_match = re.search(r"Kinh nghiệm\s*:([^<]{1,200})</p>", text, re.I | re.S)
    experience_label = clean_text(exp_match.group(1)) if exp_match else "Không yêu cầu"
    experience_level = infer_experience_key(experience_label)

    rel_match = re.search(r"Ngày đăng tin\s*:\s*([^<]+)</span>", text, re.I | re.S)
    publish_date = normalize_publish_date(rel_match.group(1) if rel_match else "")

    deadline_match = re.search(r"Hạn nộp hồ\s*sơ:\s*([0-9]{2}/[0-9]{2}/[0-9]{4})", text, re.I)
    deadline = datetime.strptime(deadline_match.group(1), "%d/%m/%Y").date().isoformat() if deadline_match else TODAY.isoformat()

    work_block = extract_section(text, "Mô tả công việc", ["Yêu cầu công việc", "Quyền lợi", "Hạn nộp hồ"])
    req_block = extract_section(text, "Yêu cầu công việc", ["Quyền lợi", "Hạn nộp hồ"])
    benefit_block = extract_section(text, "Quyền lợi", ["Hạn nộp hồ"])

    salary_min, salary_max = parse_salary(salary_label)
    return SanketoanJob(
        source_url=url,
        title=title,
        company_name=company_name,
        location=location,
        salary_label=salary_label if salary_label else "Thỏa thuận",
        salary_min=salary_min,
        salary_max=salary_max,
        experience_level=experience_level,
        experience_label=experience_label,
        publish_date=publish_date,
        deadline=deadline,
        body_sections={
            "Mô tả công việc": html_block_to_markdown_list(work_block),
            "Yêu cầu": html_block_to_markdown_list(req_block),
            "Quyền lợi": html_block_to_markdown_list(benefit_block),
        },
    )


def infer_summary(title: str, experience_label: str) -> str:
    lower = title.lower()
    if "kế toán trưởng" in lower:
        return ROLE_SUMMARIES["kế toán trưởng"]
    if "hành chính nhân sự" in lower:
        return ROLE_SUMMARIES["hành chính nhân sự"]
    if "thuế" in lower:
        return ROLE_SUMMARIES["kế toán thuế"]
    if "bán hàng" in lower:
        return ROLE_SUMMARIES["kế toán bán hàng"]
    if "nội bộ" in lower:
        return ROLE_SUMMARIES["kế toán nội bộ"]
    if "nhân viên kế toán" in lower:
        return ROLE_SUMMARIES["nhân viên kế toán"]
    if "5 năm" in experience_label.lower():
        return "Vị trí phù hợp với ứng viên có kinh nghiệm tổng hợp số liệu kế toán, kiểm soát chứng từ và xử lý báo cáo ở mức độ sâu hơn."
    return ROLE_SUMMARIES["kế toán tổng hợp"]


def infer_tags(job: SanketoanJob) -> list[str]:
    tags: list[str] = []
    lower = job.title.lower()
    if "kế toán trưởng" in lower:
        tags.append("kế toán trưởng")
    elif "hành chính nhân sự" in lower:
        tags.append("hành chính nhân sự")
    elif "thuế" in lower:
        tags.append("kế toán thuế")
    elif "bán hàng" in lower:
        tags.append("kế toán bán hàng")
    elif "nội bộ" in lower:
        tags.append("kế toán nội bộ")
    elif "nhân viên kế toán" in lower:
        tags.append("nhân viên kế toán")
    else:
        tags.append("kế toán tổng hợp")
    location_fold = slugify(job.location)
    if any(token in location_fold for token in ["ha-noi", "tay-ho", "ba-dinh", "dong-da", "gia-lam", "hoang-mai"]):
        tags.append("hà nội")
    elif "ho-chi-minh" in location_fold or "tphcm" in location_fold or "cat-lai" in location_fold or "thu-thuan" in location_fold:
        tags.append("tp hcm")
    elif "giao-long" in location_fold or "ben-tre" in location_fold:
        tags.append("bến tre")
    elif "binh-duong" in location_fold:
        tags.append("bình dương")
    tags.append("full-time")
    return tags


def render_markdown(job: SanketoanJob) -> tuple[str, str]:
    title_core = re.sub(r"^Tuyển dụng\s*", "", job.title, flags=re.I).strip()
    slug = slugify(f"{title_core} {job.company_name}")
    file_name = f"{slug}.md"
    id_value = f"job/{slug}"
    company_slug = slugify(job.company_name)
    summary = infer_summary(job.title, job.experience_label)
    tags = infer_tags(job)
    featured = "true" if (job.salary_max or 0) >= 18_000_000 else "false"
    urgent = "false"
    salary_lines = []
    if job.salary_min is not None:
        salary_lines.append(f"salaryMin: {job.salary_min}")
    if job.salary_max is not None:
        salary_lines.append(f"salaryMax: {job.salary_max}")
    salary_block = "\n".join(salary_lines)
    body_md = "\n\n".join(
        [
            "## Mô tả công việc",
            job.body_sections.get("Mô tả công việc") or "- Theo dõi và xử lý các đầu việc liên quan đến nghiệp vụ kế toán theo phân công.",
            "## Yêu cầu",
            job.body_sections.get("Yêu cầu") or "- Ứng viên có kinh nghiệm phù hợp với vị trí tuyển dụng.",
            "## Quyền lợi",
            job.body_sections.get("Quyền lợi") or "- Quyền lợi chi tiết theo chính sách doanh nghiệp tuyển dụng.",
            "## Thời gian và địa điểm làm việc",
            f"- Địa điểm: {job.location}",
            "- Hình thức: Toàn thời gian, làm việc tại văn phòng.",
            "## Cách ứng tuyển",
            "- Theo dõi và nộp hồ sơ qua đường dẫn gốc được lưu trong metadata.",
        ]
    )
    text = f"""---
id: {id_value}
slug: {slug}
title: {job.title}
companyName: {job.company_name}
companySlug: {company_slug}
location: {job.location}
employmentType: full-time
workMode: onsite
{salary_block}
salaryLabel: {job.salary_label}
experienceLevel: {job.experience_level}
deadline: {job.deadline}
publishDate: {job.publish_date}
status: active
featured: {featured}
urgent: {urgent}
applyUrl: {job.source_url}
summary: {summary}
tags:
""" + "\n".join(f"  - {tag}" for tag in tags) + f"""
sourceSite: sanketoan.vn
sourceUrl: {job.source_url}
lastReviewedDate: {TODAY.isoformat()}
---

{body_md}
"""
    return file_name, text.replace("\n\n\n", "\n\n")


def main() -> None:
    parser = argparse.ArgumentParser(description="Import seed jobs từ Sanketoan sang Markdown source")
    parser.add_argument("--limit", type=int, default=10, help="Số lượng job muốn import")
    parser.add_argument("--offset", type=int, default=10, help="Bỏ qua bao nhiêu job đầu tiên từ homepage")
    parser.add_argument("--prefix", default="batch-02", help="Prefix notes/output review")
    args = parser.parse_args()

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    NOTES_DIR.mkdir(parents=True, exist_ok=True)
    RECLASS_DIR.mkdir(parents=True, exist_ok=True)

    urls = collect_job_urls(args.limit, args.offset)
    jobs = [parse_job(url) for url in urls]
    written = []
    for job in jobs:
        file_name, body = render_markdown(job)
        path = OUTPUT_DIR / file_name
        if path.exists():
            continue
        path.write_text(body, encoding="utf-8")
        written.append({"file": file_name, "title": job.title, "company": job.company_name, "sourceUrl": job.source_url})

    review_path = RECLASS_DIR / f"jobs-seed-{args.prefix}-review.json"
    review_path.write_text(json.dumps(written, ensure_ascii=False, indent=2), encoding="utf-8")

    notes_path = NOTES_DIR / f"phase1-seed-{args.prefix}-notes.md"
    note_lines = [
        f"# Phase 1 — Seed {args.prefix} từ Sanketoan",
        "",
        f"- Ngày import: {TODAY.isoformat()}",
        f"- Offset: {args.offset}",
        f"- Limit: {args.limit}",
        f"- Ghi được: {len(written)} file mới",
        "",
        "## File đã tạo",
        "",
    ]
    for row in written:
        note_lines.append(f"- `{row['file']}` — {row['title']} — {row['company']}")
    notes_path.write_text("\n".join(note_lines) + "\n", encoding="utf-8")

    print(json.dumps({
        "requested": len(urls),
        "written": len(written),
        "review_file": str(review_path.relative_to(ROOT)),
        "notes_file": str(notes_path.relative_to(ROOT)),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
