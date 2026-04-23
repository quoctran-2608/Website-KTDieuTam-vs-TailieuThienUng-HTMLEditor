#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import date, datetime
from html import escape
from pathlib import Path
from typing import Any
from urllib.parse import quote

import yaml

ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "content" / "tuyen-dung"
OUTPUT_DIR = ROOT / "tuyen-dung"
DATA_DIR = ROOT / "data"
FEED_DIR = DATA_DIR / "feeds"
LIST_PAGE = ROOT / "tuyen-dung.html"
CANDIDATE_LIST_PAGE = ROOT / "danh-sach-ung-vien.html"
CANDIDATE_OUTPUT_DIR = ROOT / "ung-vien"
RECRUITER_CANDIDATE_PAGE = ROOT / "ung-vien-tuyen-dung.html"
EMPLOYER_PAGE = ROOT / "dang-tin-tuyen-dung.html"
RECRUITMENT_PORTAL_PAGES = [
    ROOT / "dang-nhap-tuyen-dung.html",
    ROOT / "tai-khoan-ung-vien.html",
    ROOT / "danh-sach-ung-vien.html",
    ROOT / "ho-so-ung-vien.html",
    ROOT / "viec-lam-da-luu.html",
    ROOT / "don-ung-tuyen.html",
    ROOT / "ung-tuyen.html",
    ROOT / "nha-tuyen-dung.html",
    ROOT / "dang-tin-viec-lam.html",
    ROOT / "quan-ly-tin-tuyen-dung.html",
    ROOT / "chi-tiet-tin-tuyen-dung.html",
    ROOT / "ung-vien-tuyen-dung.html",
]
DATA_FILE = DATA_DIR / "jobs.json"
FEED_FILE = FEED_DIR / "tuyen-dung.json"
CANDIDATE_FEED_FILE = FEED_DIR / "featured-candidates.json"
JOBS_SITEMAP_FILE = ROOT / "sitemap-jobs.xml"
SITEMAP_INDEX_FILE = ROOT / "sitemap-index.xml"
ROBOTS_FILE = ROOT / "robots.txt"
INDEX_PAGE = ROOT / "index.html"
SITE_URL = "https://ketoandieutam.com"

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

EMPLOYMENT_LABELS = {
    "full-time": "Toàn thời gian",
    "part-time": "Bán thời gian",
    "internship": "Thực tập",
    "freelance": "Freelance",
    "contract": "Hợp đồng",
}

WORK_MODE_LABELS = {
    "onsite": "Làm việc tại văn phòng",
    "hybrid": "Kết hợp",
    "remote": "Từ xa",
}

EXPERIENCE_LABELS = {
    "fresher": "Không yêu cầu / Fresher",
    "junior": "Junior",
    "1-nam": "1 năm",
    "2-nam": "2 năm",
    "3-nam": "3 năm",
    "5-nam": "5 năm",
    "senior": "Senior",
    "manager": "Quản lý",
}

ROLE_LABELS = {
    "ke-toan-tong-hop": "Kế toán tổng hợp",
    "ke-toan-thue": "Kế toán thuế",
    "ke-toan-noi-bo": "Kế toán nội bộ",
    "ke-toan-truong": "Kế toán trưởng",
    "ke-toan-ban-hang": "Kế toán bán hàng",
    "nhan-vien-ke-toan": "Nhân viên kế toán",
    "hanh-chinh-nhan-su": "Hành chính nhân sự",
}

RECRUITER_STATUS_LABELS = {
    "new": "Hồ sơ mới",
    "reviewing": "Đang xem hồ sơ",
    "interview": "Đề nghị phỏng vấn",
    "contacted": "Đã liên hệ",
    "need-update": "Cần bổ sung thông tin",
}

RECRUITER_STATUS_PILLS = {
    "new": "is-saved",
    "reviewing": "is-reviewing",
    "interview": "is-active",
    "contacted": "is-active",
    "need-update": "is-muted",
}

RECRUITER_ACTION_SUGGESTIONS = {
    "new": "Ưu tiên xem trong 24h để giữ tốc độ tuyển dụng.",
    "reviewing": "Đặt lịch trao đổi nhanh nếu hồ sơ phù hợp tiêu chí.",
    "interview": "Xác nhận lịch phỏng vấn và gửi tài liệu chuẩn bị.",
    "contacted": "Theo dõi phản hồi và hẹn bước tiếp theo.",
    "need-update": "Gửi yêu cầu bổ sung thông tin trước khi quyết định.",
}

AVATAR_PALETTES = [
    ("#f8ecd2", "#d7a458", "#7a4f1d", "#fff8eb", "#f5d8a6"),
    ("#efe8ff", "#9577f2", "#47357f", "#f6f1ff", "#d6cbff"),
    ("#e7f6f1", "#49a88a", "#1e5d49", "#f4fffb", "#c8eee2"),
    ("#fde8ea", "#de7482", "#7e3142", "#fff5f6", "#f8c3cc"),
    ("#e9f0ff", "#6d93e8", "#294b97", "#f8fbff", "#cadeff"),
    ("#fff0e6", "#df8f56", "#82451c", "#fff8f2", "#ffd8bc"),
]


@dataclass
class Job:
    meta: dict[str, Any]
    body_markdown: str
    body_html: str
    href: str
    detail_path: Path
    publish_date: date
    deadline: date
    effective_status: str


def parse_front_matter(raw: str, path: Path) -> tuple[dict[str, Any], str]:
    match = re.match(r"^---\n(.*?)\n---\n(.*)$", raw, re.S)
    if not match:
        raise ValueError(f"{path}: thiếu YAML front matter")
    meta = yaml.safe_load(match.group(1)) or {}
    body = match.group(2).strip() + "\n"
    return meta, body


def parse_date(value: str, field: str, path: Path) -> date:
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except Exception as exc:
        raise ValueError(f"{path}: field `{field}` không đúng format YYYY-MM-DD") from exc


def clean_text(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def fold_text(value: str) -> str:
    folded = clean_text(value)
    folded = folded.lower()
    folded = folded.replace("đ", "d")
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
        folded = folded.replace(src, dst)
    return re.sub(r"\s+", " ", folded).strip()


def infer_location_group(location: str, tags: list[str] | None = None) -> tuple[str, str]:
    folded = fold_text(location)
    tags_text = fold_text(" ".join(tags or []))
    combined = f"{folded} {tags_text}".strip()
    if any(token in combined for token in ["ho chi minh", "tphcm", "tp hcm", "thanh pho ho chi minh", "phu nhuan", "binh tan", "cat lai", "quan 7", "q7", "hoc mon", "xuan thoi son", "pham van chieu", "tan binh", "binh thuan", "binh hung", "duong hong"]):
        return "tp-hcm", "TP.HCM"
    if any(token in combined for token in ["ha noi", "tay ho", "ba dinh", "dong da", "gia lam", "hoang mai", "cau giay", "bach mai", "trung kinh", "nguyen chi thanh", "phan chu trinh", "au co", "thanh tri"]):
        return "ha-noi", "Hà Nội"
    if any(token in combined for token in ["binh duong", "thuan an", "dai lo binh duong"]):
        return "binh-duong", "Bình Dương"
    if any(token in combined for token in ["ben tre", "giao long"]):
        return "ben-tre", "Bến Tre"
    return "khac", "Khác"


def infer_role_group(title: str, tags: list[str] | None = None) -> tuple[str, str]:
    title_fold = fold_text(title)
    tags_fold = fold_text(" ".join(tags or []))
    combined = f"{title_fold} {tags_fold}".strip()
    if "ke toan truong" in combined:
        return "ke-toan-truong", ROLE_LABELS["ke-toan-truong"]
    if "hanh chinh nhan su" in combined:
        return "hanh-chinh-nhan-su", ROLE_LABELS["hanh-chinh-nhan-su"]
    if "ke toan thue" in combined:
        return "ke-toan-thue", ROLE_LABELS["ke-toan-thue"]
    if "ke toan noi bo" in combined:
        return "ke-toan-noi-bo", ROLE_LABELS["ke-toan-noi-bo"]
    if "ke toan ban hang" in combined:
        return "ke-toan-ban-hang", ROLE_LABELS["ke-toan-ban-hang"]
    if "nhan vien ke toan" in combined:
        return "nhan-vien-ke-toan", ROLE_LABELS["nhan-vien-ke-toan"]
    return "ke-toan-tong-hop", ROLE_LABELS["ke-toan-tong-hop"]


def infer_effective_status(meta: dict[str, Any], deadline: date, today: date) -> str:
    raw = clean_text(meta.get("status")).lower()
    if raw == "active" and deadline < today:
        return "expired"
    return raw


def render_inline(text: str) -> str:
    text = escape(text)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*([^\*]+)\*\*", r"<strong>\1</strong>", text)
    return text


def markdown_to_html(markdown: str) -> str:
    lines = markdown.strip().splitlines()
    out: list[str] = []
    para: list[str] = []
    in_list = False

    def flush_para() -> None:
        nonlocal para
        if para:
            out.append(f"<p>{render_inline(' '.join(para).strip())}</p>")
            para = []

    def close_list() -> None:
        nonlocal in_list
        if in_list:
            out.append("</ul>")
            in_list = False

    for raw_line in lines:
        line = raw_line.rstrip()
        stripped = line.strip()

        if not stripped:
            flush_para()
            close_list()
            continue

        if stripped.startswith("## "):
            flush_para()
            close_list()
            out.append(f"<h2>{render_inline(stripped[3:].strip())}</h2>")
            continue

        if stripped.startswith("- "):
            flush_para()
            if not in_list:
                out.append("<ul>")
                in_list = True
            out.append(f"<li>{render_inline(stripped[2:].strip())}</li>")
            continue

        close_list()
        para.append(stripped)

    flush_para()
    close_list()
    return "\n".join(out)


def load_jobs(today: date) -> list[Job]:
    jobs: list[Job] = []
    for path in sorted(SOURCE_DIR.glob("*.md")):
        if path.name in SKIP_FILES:
            continue
        raw = path.read_text(encoding="utf-8")
        meta, body = parse_front_matter(raw, path)

        missing = [field for field in REQUIRED_FIELDS if not clean_text(meta.get(field))]
        if missing:
            raise ValueError(f"{path}: thiếu field bắt buộc {missing}")

        publish_date = parse_date(clean_text(meta["publishDate"]), "publishDate", path)
        deadline = parse_date(clean_text(meta["deadline"]), "deadline", path)
        href = f"tuyen-dung/{clean_text(meta['slug'])}.html"
        detail_path = OUTPUT_DIR / f"{clean_text(meta['slug'])}.html"
        effective_status = infer_effective_status(meta, deadline, today)

        meta = dict(meta)
        meta["status"] = effective_status
        meta["publishDate"] = publish_date.isoformat()
        meta["deadline"] = deadline.isoformat()
        meta["href"] = href
        meta["salaryLabel"] = clean_text(meta.get("salaryLabel"))
        meta["location"] = clean_text(meta.get("location"))
        meta["summary"] = clean_text(meta.get("summary"))
        meta["companyName"] = clean_text(meta.get("companyName"))
        meta["title"] = clean_text(meta.get("title"))
        meta["workMode"] = clean_text(meta.get("workMode"))
        meta["employmentType"] = clean_text(meta.get("employmentType"))
        meta["experienceLevel"] = clean_text(meta.get("experienceLevel"))
        meta["featured"] = bool(meta.get("featured"))
        meta["urgent"] = bool(meta.get("urgent"))
        meta["tags"] = meta.get("tags") or []
        location_key, location_label = infer_location_group(meta["location"], meta["tags"])
        meta["locationGroupKey"] = location_key
        meta["locationGroupLabel"] = location_label
        role_key, role_label = infer_role_group(meta["title"], meta["tags"])
        meta["roleGroupKey"] = role_key
        meta["roleGroupLabel"] = role_label

        jobs.append(
            Job(
                meta=meta,
                body_markdown=body,
                body_html=markdown_to_html(body),
                href=href,
                detail_path=detail_path,
                publish_date=publish_date,
                deadline=deadline,
                effective_status=effective_status,
            )
        )
    return jobs


def format_date_vi(value: date) -> str:
    return value.strftime("%d/%m/%Y")


def format_relative_publish(today: date, publish_date: date) -> str:
    delta = (today - publish_date).days
    if delta <= 0:
        return "Hôm nay"
    if delta == 1:
        return "1 ngày trước"
    if delta < 7:
        return f"{delta} ngày trước"
    if delta < 30:
        weeks = max(1, delta // 7)
        return f"{weeks} tuần trước"
    if delta < 365:
        months = max(1, delta // 30)
        return f"{months} tháng trước"
    years = max(1, delta // 365)
    return f"{years} năm trước"


def display_employment(value: str) -> str:
    return EMPLOYMENT_LABELS.get(value, value)


def display_work_mode(value: str) -> str:
    return WORK_MODE_LABELS.get(value, value)


def display_experience(value: str) -> str:
    return EXPERIENCE_LABELS.get(value, value)


def badge_html(job: Job) -> str:
    badges: list[str] = []
    if job.meta.get("featured"):
        badges.append('<span class="job-badge featured">Nổi bật</span>')
    if job.meta.get("urgent"):
        badges.append('<span class="job-badge urgent">Tuyển gấp</span>')
    if job.effective_status == "expired":
        badges.append('<span class="job-badge expired">Hết hạn</span>')
    return "".join(badges)


def list_card_badge_html(job: Job, today: date) -> str:
    badges: list[str] = []
    delta = max(0, (today - job.publish_date).days)
    if delta <= 7:
        fresh_label = "Mới hôm nay" if delta == 0 else f"Mới {delta} ngày"
        badges.append(f'<span class="job-badge fresh">{escape(fresh_label)}</span>')
    if job.meta.get("featured"):
        badges.append('<span class="job-badge featured">Nổi bật</span>')
    if job.meta.get("urgent"):
        badges.append('<span class="job-badge urgent">Tuyển gấp</span>')
    return "".join(badges)


def job_card(job: Job, today: date, *, show_employment: bool = True, show_work_mode: bool = True) -> str:
    meta = job.meta
    search_blob = " ".join(
        [
            meta["title"],
            meta["companyName"],
            meta["summary"],
            meta["location"],
            meta.get("experienceLevel", ""),
        ]
    )
    location_label = clean_text(meta.get("locationGroupLabel")) or meta["location"]
    salary_label = clean_text(meta.get("salaryLabel")) or "Liên hệ"
    experience_label = display_experience(meta["experienceLevel"])
    deadline_label = format_date_vi(job.deadline)
    badges_html = list_card_badge_html(job, today)
    save_label = f"Lưu việc làm: {meta['title']}"
    return f"""
      <article class="job-card" data-status="{escape(job.effective_status)}" data-search="{escape(fold_text(search_blob))}" data-location-group="{escape(meta['locationGroupKey'])}" data-role-group="{escape(meta['roleGroupKey'])}" data-employment="{escape(meta['employmentType'])}" data-work-mode="{escape(meta['workMode'])}" data-experience="{escape(meta['experienceLevel'])}" data-featured="{1 if meta.get('featured') else 0}" data-publish-date="{escape(meta['publishDate'])}" data-deadline="{escape(meta['deadline'])}" data-salary-max="{int(meta.get('salaryMax') or 0)}">
        <div class="job-card-top">
          <div class="job-card-badges">
            {badges_html}
          </div>
          <button type="button" class="job-card-save-btn" aria-label="{escape(save_label)}" title="Lưu việc làm">
            <i class="fa-regular fa-bookmark" aria-hidden="true"></i>
          </button>
        </div>
        <div class="job-card-main">
          <h3><a href="{escape(meta['href'])}" class="job-card-stretched-link">{escape(meta['title'])}</a></h3>
          <div class="job-card-salary">
            <i class="fa-solid fa-dollar-sign" aria-hidden="true"></i>
            <span>Lương: {escape(salary_label)}</span>
          </div>
        </div>
        <div class="job-card-context">
          <div class="company-full-name">
            <i class="fa-regular fa-building" aria-hidden="true"></i>
            <span>{escape(meta['companyName'])}</span>
          </div>
          <div class="job-location">
            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            <span>{escape(location_label)}</span>
          </div>
        </div>
        <p class="job-card-summary">{escape(meta['summary'])}</p>
        <div class="job-card-footer">
          <span class="fact-item"><i class="fa-solid fa-briefcase" aria-hidden="true"></i>{escape(experience_label)}</span>
          <span class="fact-item urgency"><i class="fa-regular fa-clock" aria-hidden="true"></i>Hạn: {escape(deadline_label)}</span>
        </div>
      </article>
    """.strip()


def select_related_jobs(current_job: Job, jobs: list[Job], limit: int = 3) -> list[Job]:
    current_slug = clean_text(current_job.meta.get("slug"))
    current_role = clean_text(current_job.meta.get("roleGroupKey"))
    current_location = clean_text(current_job.meta.get("locationGroupKey"))
    ranked: list[tuple[int, int, int, int, Job]] = []

    for candidate in jobs:
        if candidate.effective_status != "active":
            continue
        if clean_text(candidate.meta.get("slug")) == current_slug:
            continue

        score = 0
        if clean_text(candidate.meta.get("roleGroupKey")) == current_role:
            score += 4
        if clean_text(candidate.meta.get("locationGroupKey")) == current_location:
            score += 2
        if candidate.meta.get("featured"):
            score += 1

        publish_rank = int(candidate.publish_date.strftime("%Y%m%d"))
        salary_rank = int(candidate.meta.get("salaryMax") or 0)
        deadline_rank = int(candidate.deadline.strftime("%Y%m%d"))
        ranked.append((score, publish_rank, salary_rank, deadline_rank, candidate))

    ranked.sort(
        key=lambda item: (
            -item[0],
            -item[1],
            -item[2],
            item[3],
            item[4].meta["title"],
        )
    )
    return [item[4] for item in ranked[:limit]]


def render_select_options(options: list[tuple[str, str]], placeholder: str) -> str:
    html_parts = [f'<option value="">{escape(placeholder)}</option>']
    for value, label in options:
        html_parts.append(f'<option value="{escape(value)}">{escape(label)}</option>')
    return "\n".join(html_parts)


def render_quick_role_filters(role_counts: dict[str, int], role_options: list[tuple[str, str]]) -> str:
    html_parts = []
    for value, label in role_options:
        count = role_counts.get(value, 0)
        count_label = f"{count} việc làm"
        html_parts.append(
            f'<button type="button" class="jobs-quick-chip" data-role-value="{escape(value)}" aria-label="{escape(label)} - {escape(count_label)}">'
            f"{escape(label)}"
            f'<span class="jobs-quick-chip-count">{escape(count_label)}</span>'
            "</button>"
        )
    return "\n".join(html_parts)


def candidate_initials(name: str) -> str:
    tokens = [token for token in re.split(r"\s+", clean_text(name)) if token]
    if not tokens:
        return "UV"
    if len(tokens) == 1:
        return tokens[0][:2].upper()
    return f"{tokens[0][0]}{tokens[-1][0]}".upper()


def candidate_visual_seed(value: str) -> int:
    text = clean_text(value) or "candidate"
    return sum((idx + 1) * ord(ch) for idx, ch in enumerate(text))


def build_candidate_avatar_data_uri(full_name: str, slug: str) -> str:
    seed = candidate_visual_seed(slug or full_name)
    bg_start, bg_end, ink, badge_fill, shape_fill = AVATAR_PALETTES[seed % len(AVATAR_PALETTES)]
    orbit_x = 164 - (seed % 28)
    orbit_y = 64 + (seed % 24)
    initials = escape(candidate_initials(full_name))
    svg = f"""
<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240" fill="none">
  <defs>
    <linearGradient id="avatarGradient" x1="28" y1="22" x2="214" y2="218" gradientUnits="userSpaceOnUse">
      <stop stop-color="{bg_start}"/>
      <stop offset="1" stop-color="{bg_end}"/>
    </linearGradient>
  </defs>
  <rect width="240" height="240" rx="44" fill="url(#avatarGradient)"/>
  <circle cx="{orbit_x}" cy="{orbit_y}" r="72" fill="{badge_fill}" fill-opacity="0.24"/>
  <circle cx="66" cy="60" r="38" fill="{shape_fill}" fill-opacity="0.28"/>
  <rect x="28" y="28" width="184" height="184" rx="48" fill="#FFFFFF" fill-opacity="0.14" stroke="#FFFFFF" stroke-opacity="0.38"/>
  <circle cx="120" cy="94" r="34" fill="#FFFFFF" fill-opacity="0.94"/>
  <path d="M64 184c0-29 25-52 56-52s56 23 56 52" fill="#FFFFFF" fill-opacity="0.94"/>
  <rect x="128" y="154" width="72" height="42" rx="18" fill="#FFFFFF"/>
  <text x="164" y="181" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="24" font-weight="700" fill="{ink}">{initials}</text>
</svg>
""".strip()
    return f"data:image/svg+xml;charset=UTF-8,{quote(svg, safe='')}"


def render_candidate_avatar_thumb(candidate: dict[str, Any], variant: str = "card", path_prefix: str = "") -> str:
    avatar_src = clean_text(candidate.get("avatarSrc"))
    if path_prefix and avatar_src and not avatar_src.startswith(("http://", "https://", "data:", "/", "../")):
        avatar_src = f"{path_prefix}{avatar_src}"
    return (
        f'<span class="jobs-candidate-avatar-thumb jobs-candidate-avatar-thumb--{escape(variant)}">'
        f'<img src="{escape(avatar_src or candidate["avatarSrc"])}" alt="Ảnh đại diện của {escape(candidate["fullName"])}" loading="lazy" decoding="async">'
        "</span>"
    )


def format_candidate_updated_label(value: str) -> str:
    raw = clean_text(value)
    if not raw:
        return "Cập nhật gần đây"
    try:
        updated = datetime.strptime(raw, "%Y-%m-%d").date()
    except ValueError:
        return "Cập nhật gần đây"
    return f"Cập nhật {format_date_vi(updated)}"


def mask_email(value: str) -> str:
    email = clean_text(value)
    if not email or "@" not in email:
        return "********@*****.***"
    local, domain = email.split("@", 1)
    local_visible = local[:2] if len(local) >= 2 else local[:1]
    domain_parts = domain.split(".")
    domain_name = domain_parts[0] if domain_parts else domain
    domain_ext = ".".join(domain_parts[1:]) if len(domain_parts) > 1 else ""
    domain_visible = domain_name[:2] if len(domain_name) >= 2 else domain_name[:1]
    local_mask = local_visible + "*" * max(4, len(local) - len(local_visible))
    domain_mask = domain_visible + "*" * max(4, len(domain_name) - len(domain_visible))
    if domain_ext:
        return f"{local_mask}@{domain_mask}.{domain_ext}"
    return f"{local_mask}@{domain_mask}"


def mask_phone(value: str) -> str:
    digits = "".join(ch for ch in clean_text(value) if ch.isdigit())
    if len(digits) < 7:
        return "*** *** ***"
    return f"{digits[:3]} *** **{digits[-2:]}"


def compact_salary_label(value: str) -> str:
    salary = clean_text(value)
    if not salary:
        return "Theo thỏa thuận"
    return re.sub(r"^Mong muốn\s*", "", salary, flags=re.IGNORECASE)


def salary_preference_text(value: str) -> str:
    salary = compact_salary_label(value)
    if not salary or salary.lower() == "theo thỏa thuận":
        return "Theo thỏa thuận"
    return f"Mong muốn {salary}"


def load_featured_candidates(limit: int = 6) -> list[dict[str, Any]]:
    if not CANDIDATE_FEED_FILE.exists():
        return []
    try:
        payload = json.loads(CANDIDATE_FEED_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return []
    if not isinstance(payload, list):
        return []

    candidates: list[dict[str, Any]] = []
    for row in payload:
        if not isinstance(row, dict):
            continue

        full_name = clean_text(row.get("fullName"))
        headline = clean_text(row.get("headline"))
        intro = clean_text(row.get("intro"))
        experience_label = clean_text(row.get("experienceLabel"))
        location_label = clean_text(row.get("locationLabel"))
        if not all([full_name, headline, intro, experience_label, location_label]):
            continue

        skills: list[str] = []
        for skill in row.get("skills") or []:
            skill_text = clean_text(skill)
            if not skill_text or skill_text in skills:
                continue
            skills.append(skill_text)
            if len(skills) >= 4:
                break

        candidates.append(
            {
                "fullName": full_name,
                "initials": candidate_initials(full_name),
                "headline": headline,
                "intro": intro,
                "experienceLabel": experience_label,
                "locationLabel": location_label,
                "salaryExpectation": compact_salary_label(clean_text(row.get("salaryExpectation")) or "Theo thỏa thuận"),
                "availabilityLabel": clean_text(row.get("availabilityLabel")) or "Sẵn sàng trao đổi",
                "updatedLabel": format_candidate_updated_label(clean_text(row.get("updatedDate"))),
                "skills": skills,
                "profileUrl": clean_text(row.get("profileUrl")) or "dang-nhap-tuyen-dung.html",
            }
        )
        if len(candidates) >= limit:
            break
    return candidates


def candidate_sort_key(candidate: dict[str, Any]) -> tuple[int, int, str]:
    is_featured = 1 if candidate.get("featured") else 0
    updated_iso = clean_text(candidate.get("updatedDate"))
    try:
        updated_rank = int(datetime.strptime(updated_iso, "%Y-%m-%d").strftime("%Y%m%d"))
    except ValueError:
        updated_rank = 0
    return (is_featured, updated_rank, clean_text(candidate.get("fullName")))


def map_candidate_payload(row: dict[str, Any]) -> dict[str, Any] | None:
    full_name = clean_text(row.get("fullName"))
    headline = clean_text(row.get("headline"))
    intro = clean_text(row.get("intro"))
    experience_label = clean_text(row.get("experienceLabel"))
    location_label = clean_text(row.get("locationLabel"))
    if not all([full_name, headline, intro, experience_label, location_label]):
        return None

    skills: list[str] = []
    for skill in row.get("skills") or []:
        skill_text = clean_text(skill)
        if not skill_text or skill_text in skills:
            continue
        skills.append(skill_text)
        if len(skills) >= 6:
            break

    candidate_id = clean_text(row.get("id"))
    slug = clean_text(row.get("slug"))
    if not slug and candidate_id.startswith("candidate/"):
        slug = clean_text(candidate_id.split("/", 1)[1])
    if not slug:
        slug = fold_text(full_name).replace(" ", "-")

    target_role = clean_text(row.get("targetRole"))
    if not target_role:
        target_role = clean_text(headline.split("·")[0]) or "Ứng viên kế toán"
    target_role_key = fold_text(target_role)

    recruiter_status = clean_text(row.get("recruiterStatus")).lower()
    if recruiter_status not in RECRUITER_STATUS_LABELS:
        fallback_statuses = ["new", "reviewing", "interview", "need-update", "contacted"]
        seed = sum(ord(ch) for ch in slug)
        recruiter_status = fallback_statuses[seed % len(fallback_statuses)]

    contact_email = clean_text(row.get("contactEmail"))
    contact_phone = clean_text(row.get("contactPhone"))
    profile_summary = clean_text(row.get("profileSummary")) or intro
    avatar_image = clean_text(row.get("avatarImage"))
    education_level = clean_text(row.get("educationLevel"))
    major_label = clean_text(row.get("majorLabel"))
    school_name = clean_text(row.get("schoolName"))
    graduation_year = clean_text(row.get("graduationYear"))
    work_mode_preference = clean_text(row.get("workModePreference"))
    address_public = clean_text(row.get("addressPublic"))
    desired_work_area = clean_text(row.get("desiredWorkArea"))
    if not desired_work_area:
        desired_work_area = address_public or clean_text(location_label.split("·")[0])
    profile_highlights: list[str] = []
    for item in row.get("highlights") or []:
        text = clean_text(item)
        if text:
            profile_highlights.append(text)
    if not profile_highlights and skills:
        profile_highlights = [f"Thành thạo {skills[0]}", f"Kinh nghiệm thực chiến ở vai trò {headline.split('·')[0].strip()}"]

    return {
        "id": candidate_id or f"candidate/{slug}",
        "slug": slug,
        "fullName": full_name,
        "initials": candidate_initials(full_name),
        "headline": headline,
        "targetRole": target_role,
        "targetRoleKey": target_role_key,
        "intro": intro,
        "profileSummary": profile_summary,
        "profileHighlights": profile_highlights[:4],
        "experienceLabel": experience_label,
        "locationLabel": location_label,
        "avatarDataUri": build_candidate_avatar_data_uri(full_name, slug),
        "avatarSrc": avatar_image or build_candidate_avatar_data_uri(full_name, slug),
        "salaryExpectation": compact_salary_label(clean_text(row.get("salaryExpectation")) or "Theo thỏa thuận"),
        "availabilityLabel": clean_text(row.get("availabilityLabel")) or "Sẵn sàng trao đổi",
        "updatedDate": clean_text(row.get("updatedDate")),
        "updatedLabel": format_candidate_updated_label(clean_text(row.get("updatedDate"))),
        "educationLevel": education_level,
        "majorLabel": major_label,
        "schoolName": school_name,
        "graduationYear": graduation_year,
        "workModePreference": work_mode_preference,
        "addressPublic": address_public,
        "desiredWorkArea": desired_work_area,
        "skills": skills,
        "profilePath": f"ung-vien/{slug}.html",
        "profileUrl": clean_text(row.get("profileUrl")) or f"ung-vien/{slug}.html",
        "featured": bool(row.get("featured")),
        "recruiterStatus": recruiter_status,
        "recruiterStatusLabel": RECRUITER_STATUS_LABELS.get(recruiter_status, "Đang xử lý"),
        "recruiterStatusPill": RECRUITER_STATUS_PILLS.get(recruiter_status, "is-reviewing"),
        "recruiterActionSuggestion": RECRUITER_ACTION_SUGGESTIONS.get(recruiter_status, "Tiếp tục theo dõi hồ sơ và cập nhật trạng thái phù hợp."),
        "experienceKey": fold_text(experience_label),
        "locationKey": fold_text(location_label),
        "contactEmail": contact_email,
        "contactPhone": contact_phone,
        "maskedEmail": mask_email(contact_email),
        "maskedPhone": mask_phone(contact_phone),
        "contactPolicyNote": clean_text(row.get("contactPolicyNote")) or "Email và số điện thoại chỉ hiển thị đầy đủ khi nhà tuyển dụng đăng nhập và xác thực nhu cầu phù hợp.",
        "searchText": fold_text(" ".join([full_name, headline, intro, location_label, experience_label, " ".join(skills)])),
    }


def load_candidates_feed() -> list[dict[str, Any]]:
    if not CANDIDATE_FEED_FILE.exists():
        return []
    try:
        payload = json.loads(CANDIDATE_FEED_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return []
    if not isinstance(payload, list):
        return []

    candidates: list[dict[str, Any]] = []
    for row in payload:
        if not isinstance(row, dict):
            continue
        mapped = map_candidate_payload(row)
        if mapped is None:
            continue
        candidates.append(mapped)

    candidates.sort(key=candidate_sort_key, reverse=True)
    return candidates


def build_candidate_filter_options(candidates: list[dict[str, Any]], key: str) -> list[tuple[str, str]]:
    options_map: dict[str, str] = {}
    for candidate in candidates:
        value = clean_text(candidate.get(key))
        if not value:
            continue
        options_map[value] = value
    return sorted(options_map.items(), key=lambda item: item[1])


def candidate_list_card(candidate: dict[str, Any]) -> str:
    skills = candidate.get("skills") or []
    skill_list = ""
    if skills:
        chips = "".join(f"<li>{escape(skill)}</li>" for skill in skills[:5])
        skill_list = f'<ul class="jobs-candidate-skill-list">{chips}</ul>'
    featured_badge = '<span class="job-badge featured">Nổi bật</span>' if candidate.get("featured") else ""
    search_blob = clean_text(candidate.get("searchText"))
    avatar_thumb = render_candidate_avatar_thumb(candidate, "showcase")
    return f"""
      <article class="jobs-candidate-card jobs-candidate-list-card" data-search="{escape(search_blob)}" data-location="{escape(candidate['locationLabel'])}" data-experience="{escape(candidate['experienceLabel'])}" data-updated-date="{escape(candidate['updatedDate']) or '1970-01-01'}" data-featured="{1 if candidate.get('featured') else 0}">
        <a href="{escape(candidate['profilePath'])}" class="jobs-candidate-card-link" aria-label="Xem chi tiết hồ sơ {escape(candidate['fullName'])}">
          <div class="jobs-candidate-card-head">
            {avatar_thumb}
            <div class="jobs-candidate-headline">
              <div class="job-card-badges jobs-candidate-badges">{featured_badge}</div>
              <h3>{escape(candidate['fullName'])}</h3>
              <p>{escape(candidate['headline'])}</p>
            </div>
            <span class="jobs-candidate-card-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i></span>
          </div>
          <div class="jobs-candidate-facts">
            <span><i class="fa-solid fa-briefcase" aria-hidden="true"></i>{escape(candidate['experienceLabel'])}</span>
            <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>{escape(candidate['locationLabel'])}</span>
            <span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>{escape(salary_preference_text(candidate['salaryExpectation']))}</span>
          </div>
          <p class="jobs-candidate-intro">{escape(candidate['intro'])}</p>
          {skill_list}
          <div class="jobs-candidate-foot">
            <div class="jobs-candidate-foot-meta">
              <strong>{escape(candidate['availabilityLabel'])}</strong>
              <span>{escape(candidate['updatedLabel'])}</span>
            </div>
            <span class="jobs-candidate-card-cta">Hồ sơ chi tiết</span>
          </div>
        </a>
      </article>
    """.strip()


def render_candidate_filter_options(options: list[tuple[str, str]], placeholder: str) -> str:
    html_parts = [f'<option value="">{escape(placeholder)}</option>']
    for value, label in options:
        html_parts.append(f'<option value="{escape(value)}">{escape(label)}</option>')
    return "\n".join(html_parts)


def render_candidates_filter_script() -> str:
    return """
  <script>
    (function () {
      function normalizeText(value) {
        return String(value || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\\u0300-\\u036f]/g, '')
          .replace(/đ/g, 'd')
          .replace(/\\s+/g, ' ')
          .trim();
      }

      function initCandidateFilter() {
        var form = document.getElementById('candidateFilterForm');
        var grid = document.getElementById('candidateListGrid');
        if (!form || !grid) return;

        var cards = Array.prototype.slice.call(grid.querySelectorAll('.jobs-candidate-list-card'));
        var countLabel = document.getElementById('candidateFilterCount');
        var searchInput = document.getElementById('candidateFilterSearch');
        var locationSelect = document.getElementById('candidateFilterLocation');
        var experienceSelect = document.getElementById('candidateFilterExperience');
        var sortSelect = document.getElementById('candidateSortOrder');
        var resetBtn = document.getElementById('candidateFilterReset');
        var emptyState = document.getElementById('candidateEmptyState');
        var pagination = document.getElementById('candidatePagination');
        var paginationPages = document.getElementById('candidatePaginationPages');
        var paginationPrev = pagination ? pagination.querySelector('[data-page-action=\"prev\"]') : null;
        var paginationNext = pagination ? pagination.querySelector('[data-page-action=\"next\"]') : null;
        var pageSize = 8;
        var currentPage = 1;
        var totalPages = 1;

        function compareCards(a, b, sortValue) {
          var aFeatured = Number(a.dataset.featured || 0);
          var bFeatured = Number(b.dataset.featured || 0);
          var aUpdated = Date.parse(a.dataset.updatedDate || '') || 0;
          var bUpdated = Date.parse(b.dataset.updatedDate || '') || 0;
          var aName = normalizeText((a.querySelector('h3') || {}).textContent || '');
          var bName = normalizeText((b.querySelector('h3') || {}).textContent || '');

          if (sortValue === 'name-asc') {
            return aName.localeCompare(bName, 'vi');
          }

          if (sortValue === 'featured-first') {
            return bFeatured - aFeatured || bUpdated - aUpdated || aName.localeCompare(bName, 'vi');
          }

          return bUpdated - aUpdated || bFeatured - aFeatured || aName.localeCompare(bName, 'vi');
        }

        function renderPagination(totalItems) {
          if (!pagination || !paginationPages || !paginationPrev || !paginationNext) return;
          totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
          if (currentPage > totalPages) currentPage = totalPages;

          if (totalItems <= pageSize) {
            pagination.hidden = true;
            paginationPages.innerHTML = '';
            return;
          }

          pagination.hidden = false;
          var buttons = [];
          for (var page = 1; page <= totalPages; page += 1) {
            buttons.push('<button type=\"button\" class=\"jobs-pagination-page' + (page === currentPage ? ' is-active' : '') + '\" data-page-number=\"' + page + '\">' + page + '</button>');
          }
          paginationPages.innerHTML = buttons.join('');
          paginationPrev.disabled = currentPage <= 1;
          paginationNext.disabled = currentPage >= totalPages;
        }

        function applyFilters(options) {
          options = options || {};
          var query = normalizeText(searchInput ? searchInput.value : '');
          var location = normalizeText(locationSelect ? locationSelect.value : '');
          var experience = normalizeText(experienceSelect ? experienceSelect.value : '');
          var sortValue = sortSelect ? sortSelect.value : 'updated-desc';

          var matched = cards.filter(function (card) {
            var searchBlob = normalizeText(card.dataset.search || card.textContent || '');
            var locationValue = normalizeText(card.dataset.location || '');
            var experienceValue = normalizeText(card.dataset.experience || '');
            var matchesQuery = !query || searchBlob.indexOf(query) !== -1;
            var matchesLocation = !location || locationValue === location;
            var matchesExperience = !experience || experienceValue === experience;
            return matchesQuery && matchesLocation && matchesExperience;
          });

          matched.sort(function (a, b) { return compareCards(a, b, sortValue); });

          if (!options.keepPage) currentPage = 1;
          renderPagination(matched.length);

          var pageStart = (currentPage - 1) * pageSize;
          var pageEnd = pageStart + pageSize;
          var visibleSlice = matched.slice(pageStart, pageEnd);
          var visibleSet = new Set(visibleSlice);

          cards.forEach(function (card) {
            card.hidden = !visibleSet.has(card);
          });

          if (countLabel) {
            countLabel.textContent = matched.length + ' hồ sơ phù hợp';
          }
          if (emptyState) {
            emptyState.hidden = matched.length !== 0;
          }
        }

        if (form) {
          form.addEventListener('input', function () { applyFilters(); });
          form.addEventListener('change', function () { applyFilters(); });
        }

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            form.reset();
            applyFilters();
          });
        }

        if (paginationPages) {
          paginationPages.addEventListener('click', function (event) {
            var button = event.target.closest('[data-page-number]');
            if (!button) return;
            var nextPage = Number(button.dataset.pageNumber || '1');
            if (!nextPage || nextPage === currentPage) return;
            currentPage = nextPage;
            applyFilters({ keepPage: true });
          });
        }

        if (paginationPrev) {
          paginationPrev.addEventListener('click', function () {
            if (currentPage <= 1) return;
            currentPage -= 1;
            applyFilters({ keepPage: true });
          });
        }

        if (paginationNext) {
          paginationNext.addEventListener('click', function () {
            if (currentPage >= totalPages) return;
            currentPage += 1;
            applyFilters({ keepPage: true });
          });
        }

        applyFilters();
      }

      document.addEventListener('DOMContentLoaded', initCandidateFilter);
    })();
  </script>
""".strip()


def render_candidate_list_page(candidates: list[dict[str, Any]]) -> str:
    candidate_cards = "\n".join(candidate_list_card(candidate) for candidate in candidates)
    location_options = build_candidate_filter_options(candidates, "locationLabel")
    experience_options = build_candidate_filter_options(candidates, "experienceLabel")
    featured_count = sum(1 for candidate in candidates if candidate.get("featured"))

    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Danh sách ứng viên kế toán | Kế Toán Diệu Tâm</title>
  <meta name="description" content="Danh sách hồ sơ ứng viên kế toán đã cập nhật gần đây, hỗ trợ lọc theo kinh nghiệm, khu vực và vai trò phù hợp nhu cầu tuyển dụng.">
  <link rel="canonical" href="{SITE_URL}/danh-sach-ung-vien.html">
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/jobs.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="jobs-page jobs-candidate-page" data-root="" data-nav="tuyen-dung">
  <div id="siteHeader"></div>
  <main>
    <section class="jobs-hero jobs-hero-compact">
      <div class="container">
        <nav class="jobs-breadcrumbs" aria-label="Breadcrumb">
          <a href="index.html">Trang chủ</a>
          <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
          <a href="tuyen-dung.html">Tuyển dụng</a>
          <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
          <span>Danh sách ứng viên</span>
        </nav>
        <div class="jobs-hero-compact-head jobs-hero-minimal-head">
          <h1>Danh sách hồ sơ ứng viên kế toán</h1>
          <p class="jobs-hero-text">Khám phá hồ sơ ứng viên đã cập nhật đầy đủ thông tin chuyên môn để kết nối đúng người, đúng vị trí ngay từ bước sàng lọc đầu tiên.</p>
        </div>
      </div>
    </section>

    <section class="jobs-section section-padding jobs-section-soft jobs-candidate-list-core">
      <div class="container">
        <div class="jobs-candidate-discovery">
          <div class="jobs-candidate-discovery-copy">
            <strong>{len(candidates)} hồ sơ đang hiển thị</strong>
            <span>{featured_count} hồ sơ nổi bật được ưu tiên hiển thị đầu danh sách</span>
          </div>
          <div class="jobs-candidate-discovery-actions">
            <a href="dang-nhap-tuyen-dung.html" class="btn-outline-brown">Đăng nhập nhà tuyển dụng</a>
            <a href="ho-so-ung-vien.html" class="btn-primary-orange">Ứng viên đăng hồ sơ</a>
          </div>
        </div>

        <form class="jobs-filter-bar jobs-candidate-filter-bar" id="candidateFilterForm">
          <div class="jobs-filter-grid jobs-candidate-filter-grid">
            <label class="jobs-filter-field">
              <span>Tìm hồ sơ</span>
              <input type="search" id="candidateFilterSearch" placeholder="Tên ứng viên, vị trí, kỹ năng...">
            </label>
            <label class="jobs-filter-field">
              <span>Khu vực</span>
              <select id="candidateFilterLocation">
{render_candidate_filter_options(location_options, 'Tất cả khu vực')}
              </select>
            </label>
            <label class="jobs-filter-field">
              <span>Kinh nghiệm</span>
              <select id="candidateFilterExperience">
{render_candidate_filter_options(experience_options, 'Tất cả mức kinh nghiệm')}
              </select>
            </label>
            <label class="jobs-filter-field">
              <span>Sắp xếp</span>
              <select id="candidateSortOrder">
                <option value="updated-desc">Mới cập nhật</option>
                <option value="featured-first">Nổi bật trước</option>
                <option value="name-asc">Tên A → Z</option>
              </select>
            </label>
          </div>
          <div class="jobs-filter-meta">
            <strong id="candidateFilterCount">{len(candidates)} hồ sơ phù hợp</strong>
            <button type="button" class="jobs-filter-reset" id="candidateFilterReset">Xóa lọc</button>
          </div>
        </form>

        <div class="jobs-candidate-grid jobs-candidate-list-grid" id="candidateListGrid">
{candidate_cards}
        </div>
        <div class="jobs-empty-state" id="candidateEmptyState" hidden>Chưa có hồ sơ phù hợp với bộ lọc hiện tại.</div>
        <div class="jobs-pagination" id="candidatePagination" hidden>
          <button type="button" class="jobs-pagination-btn" data-page-action="prev">Trang trước</button>
          <div class="jobs-pagination-pages" id="candidatePaginationPages"></div>
          <button type="button" class="jobs-pagination-btn" data-page-action="next">Trang sau</button>
        </div>
      </div>
    </section>
  </main>
  <div id="siteFooter"></div>
  <script src="site-shell.js"></script>
{render_candidates_filter_script()}
</body>
</html>
"""


def render_recruiter_candidate_rows(candidates: list[dict[str, Any]]) -> str:
    rows: list[str] = []
    for candidate in candidates:
        status_options_html = "".join(
            f'<option value="{escape(key)}"{" selected" if key == candidate["recruiterStatus"] else ""}>{escape(label)}</option>'
            for key, label in RECRUITER_STATUS_LABELS.items()
        )
        rows.append(
            f"""
                  <tr class="jobs-recruiter-candidate-row" data-candidate-id="{escape(candidate['id'])}" data-candidate-name="{escape(candidate['fullName'])}" data-candidate-role="{escape(candidate['targetRole'])}" data-candidate-profile="{escape(candidate['profilePath'])}" data-search="{escape(candidate['searchText'])}" data-role="{escape(candidate['targetRole'])}" data-experience="{escape(candidate['experienceLabel'])}" data-status="{escape(candidate['recruiterStatus'])}" data-updated-date="{escape(candidate['updatedDate']) or '1970-01-01'}">
                    <td>
                      <div class="jobs-recruiter-candidate-name">
                        <strong>{escape(candidate['fullName'])}</strong>
                        <span>{escape(candidate['locationLabel'])}</span>
                      </div>
                    </td>
                    <td>{escape(candidate['targetRole'])}</td>
                    <td>{escape(candidate['experienceLabel'])}</td>
                    <td>
                      <span class="jobs-status-pill {escape(candidate['recruiterStatusPill'])}">{escape(candidate['recruiterStatusLabel'])}</span>
                      <label class="jobs-recruiter-status-control">
                        <span>Cập nhật trạng thái</span>
                        <select class="jobs-recruiter-status-select" data-candidate-id="{escape(candidate['id'])}">
{status_options_html}
                        </select>
                      </label>
                    </td>
                    <td>{escape(candidate['updatedLabel'])}</td>
                    <td>
                      <div class="jobs-recruiter-actions-inline">
                        <a href="{escape(candidate['profilePath'])}" class="job-source-link">Mở hồ sơ</a>
                        <button type="button" class="jobs-recruiter-shortlist-btn" data-candidate-id="{escape(candidate['id'])}" aria-pressed="false">
                          <i class="fa-regular fa-star" aria-hidden="true"></i>
                          <span>Lưu shortlist</span>
                        </button>
                        <button type="button" class="jobs-recruiter-note-btn" data-candidate-id="{escape(candidate['id'])}" data-candidate-name="{escape(candidate['fullName'])}">
                          <i class="fa-regular fa-note-sticky" aria-hidden="true"></i>
                          <span>Ghi chú</span>
                        </button>
                      </div>
                      <p class="jobs-recruiter-action-tip">{escape(candidate['recruiterActionSuggestion'])}</p>
                    </td>
                  </tr>
            """.strip()
        )
    return "\n".join(rows)


def render_recruiter_candidate_filter_script() -> str:
    return """
  <script>
    (function () {
      var STATUS_LABELS = {
        'new': 'Hồ sơ mới',
        'reviewing': 'Đang xem hồ sơ',
        'interview': 'Đề nghị phỏng vấn',
        'contacted': 'Đã liên hệ',
        'need-update': 'Cần bổ sung thông tin'
      };

      var STATUS_PILLS = {
        'new': 'is-saved',
        'reviewing': 'is-reviewing',
        'interview': 'is-active',
        'contacted': 'is-active',
        'need-update': 'is-muted'
      };

      function normalizeText(value) {
        return String(value || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\\u0300-\\u036f]/g, '')
          .replace(/đ/g, 'd')
          .replace(/\\s+/g, ' ')
          .trim();
      }

      function initRecruiterCandidateFilter() {
        var form = document.getElementById('recruiterCandidateFilterForm');
        var table = document.getElementById('recruiterCandidateTable');
        if (!form || !table) return;

        var rows = Array.prototype.slice.call(table.querySelectorAll('.jobs-recruiter-candidate-row'));
        var searchInput = document.getElementById('recruiterCandidateSearch');
        var roleSelect = document.getElementById('recruiterCandidateRole');
        var experienceSelect = document.getElementById('recruiterCandidateExperience');
        var statusSelect = document.getElementById('recruiterCandidateStatus');
        var sortSelect = document.getElementById('recruiterCandidateSort');
        var resetBtn = document.getElementById('recruiterCandidateReset');
        var countLabel = document.getElementById('recruiterCandidateCount');
        var emptyState = document.getElementById('recruiterCandidateEmpty');
        var shortlistCount = document.getElementById('recruiterShortlistCount');
        var shortlistList = document.getElementById('recruiterShortlistList');
        var activityFeed = document.getElementById('recruiterActivityFeed');
        var noteDialog = document.getElementById('recruiterNoteDialog');
        var noteTitle = document.getElementById('recruiterNoteTitle');
        var noteInput = document.getElementById('recruiterNoteInput');
        var noteSave = document.getElementById('recruiterNoteSave');
        var noteCancel = document.getElementById('recruiterNoteCancel');

        var shortlistStore = new Set();
        var noteStore = {};
        var currentNoteCandidateId = null;

        function compareRows(a, b, sortValue) {
          var aUpdated = Date.parse(a.dataset.updatedDate || '') || 0;
          var bUpdated = Date.parse(b.dataset.updatedDate || '') || 0;
          var aName = normalizeText((a.querySelector('.jobs-recruiter-candidate-name strong') || {}).textContent || '');
          var bName = normalizeText((b.querySelector('.jobs-recruiter-candidate-name strong') || {}).textContent || '');
          if (sortValue === 'name-asc') {
            return aName.localeCompare(bName, 'vi');
          }
          return bUpdated - aUpdated || aName.localeCompare(bName, 'vi');
        }

        function pushActivity(message) {
          if (!activityFeed) return;
          var item = document.createElement('li');
          item.textContent = message;
          activityFeed.prepend(item);
          var items = activityFeed.querySelectorAll('li');
          if (items.length > 8) {
            items[items.length - 1].remove();
          }
        }

        function syncShortlistPanel() {
          if (!shortlistCount || !shortlistList) return;
          shortlistCount.textContent = String(shortlistStore.size);

          if (!shortlistStore.size) {
            shortlistList.innerHTML = '<li class="jobs-recruiter-empty-note">Chưa có ứng viên nào trong shortlist.</li>';
            return;
          }

          var items = [];
          rows.forEach(function (row) {
            var candidateId = row.dataset.candidateId || '';
            if (!shortlistStore.has(candidateId)) return;
            var name = (row.querySelector('.jobs-recruiter-candidate-name strong') || {}).textContent || '';
            var role = row.dataset.candidateRole || '';
            var profile = row.dataset.candidateProfile || '#';
            items.push('<li><a href=\"' + profile + '\">' + name + '</a><span>' + role + '</span></li>');
          });
          shortlistList.innerHTML = items.join('');
        }

        function openNoteDialog(candidateId, candidateName) {
          if (!noteDialog || !noteInput || !noteTitle) return;
          currentNoteCandidateId = candidateId;
          noteTitle.textContent = 'Ghi chú nội bộ: ' + candidateName;
          noteInput.value = noteStore[candidateId] || '';
          noteDialog.hidden = false;
          document.body.classList.add('is-note-dialog-open');
          window.setTimeout(function () { noteInput.focus(); }, 10);
        }

        function closeNoteDialog() {
          if (!noteDialog) return;
          noteDialog.hidden = true;
          document.body.classList.remove('is-note-dialog-open');
          currentNoteCandidateId = null;
        }

        function updateStatusPill(row, statusKey) {
          var pill = row.querySelector('.jobs-status-pill');
          if (!pill) return;
          pill.classList.remove('is-active', 'is-saved', 'is-reviewing', 'is-muted');
          pill.classList.add(STATUS_PILLS[statusKey] || 'is-reviewing');
          pill.textContent = STATUS_LABELS[statusKey] || 'Đang xem hồ sơ';
          row.dataset.status = statusKey;
        }

        function applyFilters() {
          var query = normalizeText(searchInput ? searchInput.value : '');
          var role = normalizeText(roleSelect ? roleSelect.value : '');
          var experience = normalizeText(experienceSelect ? experienceSelect.value : '');
          var status = normalizeText(statusSelect ? statusSelect.value : '');
          var sortValue = sortSelect ? sortSelect.value : 'updated-desc';

          var matched = rows.filter(function (row) {
            var searchBlob = normalizeText(row.dataset.search || row.textContent || '');
            var rowRole = normalizeText(row.dataset.role || '');
            var rowExperience = normalizeText(row.dataset.experience || '');
            var rowStatus = normalizeText(row.dataset.status || '');
            var matchesQuery = !query || searchBlob.indexOf(query) !== -1;
            var matchesRole = !role || rowRole === role;
            var matchesExperience = !experience || rowExperience === experience;
            var matchesStatus = !status || rowStatus === status;
            return matchesQuery && matchesRole && matchesExperience && matchesStatus;
          });

          matched.sort(function (a, b) { return compareRows(a, b, sortValue); });

          rows.forEach(function (row) { row.hidden = true; });
          matched.forEach(function (row) {
            row.hidden = false;
            row.parentElement.appendChild(row);
          });

          if (countLabel) {
            countLabel.textContent = matched.length + ' hồ sơ phù hợp';
          }
          if (emptyState) {
            emptyState.hidden = matched.length !== 0;
          }
        }

        if (form) {
          form.addEventListener('input', applyFilters);
          form.addEventListener('change', applyFilters);
        }

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            form.reset();
            applyFilters();
          });
        }

        rows.forEach(function (row) {
          var shortlistBtn = row.querySelector('.jobs-recruiter-shortlist-btn');
          var noteBtn = row.querySelector('.jobs-recruiter-note-btn');
          var statusControl = row.querySelector('.jobs-recruiter-status-select');
          var candidateId = row.dataset.candidateId || '';
          var candidateName = row.dataset.candidateName || 'Ứng viên';

          if (shortlistBtn) {
            shortlistBtn.addEventListener('click', function () {
              var active = shortlistStore.has(candidateId);
              if (active) {
                shortlistStore.delete(candidateId);
                shortlistBtn.classList.remove('is-active');
                shortlistBtn.setAttribute('aria-pressed', 'false');
                shortlistBtn.querySelector('span').textContent = 'Lưu shortlist';
                pushActivity('Đã bỏ ' + candidateName + ' khỏi shortlist.');
              } else {
                shortlistStore.add(candidateId);
                shortlistBtn.classList.add('is-active');
                shortlistBtn.setAttribute('aria-pressed', 'true');
                shortlistBtn.querySelector('span').textContent = 'Đã lưu shortlist';
                pushActivity('Đã thêm ' + candidateName + ' vào shortlist.');
              }
              syncShortlistPanel();
            });
          }

          if (noteBtn) {
            noteBtn.addEventListener('click', function () {
              openNoteDialog(candidateId, candidateName);
            });
          }

          if (statusControl) {
            statusControl.addEventListener('change', function () {
              var nextStatus = statusControl.value || 'reviewing';
              updateStatusPill(row, nextStatus);
              pushActivity('Đã cập nhật trạng thái hồ sơ ' + candidateName + ' thành ' + (STATUS_LABELS[nextStatus] || 'Đang xem hồ sơ') + '.');
              applyFilters();
            });
          }
        });

        if (noteSave && noteInput) {
          noteSave.addEventListener('click', function () {
            if (!currentNoteCandidateId) return;
            var value = noteInput.value.trim();
            if (!value) {
              delete noteStore[currentNoteCandidateId];
            } else {
              noteStore[currentNoteCandidateId] = value;
            }
            var row = rows.find(function (item) { return (item.dataset.candidateId || '') === currentNoteCandidateId; });
            if (row) {
              var button = row.querySelector('.jobs-recruiter-note-btn');
              if (button) {
                button.classList.toggle('is-active', !!value);
              }
              var candidateName = row.dataset.candidateName || 'Ứng viên';
              pushActivity(value ? ('Đã lưu ghi chú nội bộ cho ' + candidateName + '.') : ('Đã xóa ghi chú nội bộ của ' + candidateName + '.'));
            }
            closeNoteDialog();
          });
        }

        if (noteCancel) {
          noteCancel.addEventListener('click', closeNoteDialog);
        }

        if (noteDialog) {
          noteDialog.addEventListener('click', function (event) {
            var dismissTarget = event.target.closest('[data-note-dismiss=\"backdrop\"]');
            if (dismissTarget) {
              closeNoteDialog();
            }
          });
        }

        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && noteDialog && !noteDialog.hidden) {
            closeNoteDialog();
          }
        });

        syncShortlistPanel();
        applyFilters();
      }

      document.addEventListener('DOMContentLoaded', initRecruiterCandidateFilter);
    })();
  </script>
""".strip()


def render_recruiter_candidate_page(candidates: list[dict[str, Any]]) -> str:
    rows_html = render_recruiter_candidate_rows(candidates)
    role_options = sorted({candidate.get("targetRole", "") for candidate in candidates if clean_text(candidate.get("targetRole"))})
    experience_options = sorted({candidate.get("experienceLabel", "") for candidate in candidates if clean_text(candidate.get("experienceLabel"))})
    status_options = sorted({candidate.get("recruiterStatus", "") for candidate in candidates if clean_text(candidate.get("recruiterStatus"))})
    status_labels = {key: RECRUITER_STATUS_LABELS.get(key, key) for key in status_options}
    new_count = sum(1 for candidate in candidates if candidate.get("recruiterStatus") == "new")
    interview_count = sum(1 for candidate in candidates if candidate.get("recruiterStatus") == "interview")
    reviewing_count = sum(1 for candidate in candidates if candidate.get("recruiterStatus") == "reviewing")

    role_options_html = "\n".join(
        [f'<option value="">{escape("Tất cả vị trí quan tâm")}</option>'] + [f'<option value="{escape(item)}">{escape(item)}</option>' for item in role_options]
    )
    experience_options_html = "\n".join(
        [f'<option value="">{escape("Tất cả mức kinh nghiệm")}</option>'] + [f'<option value="{escape(item)}">{escape(item)}</option>' for item in experience_options]
    )
    status_options_html = "\n".join(
        [f'<option value="">{escape("Tất cả trạng thái")}</option>'] + [f'<option value="{escape(item)}">{escape(status_labels[item])}</option>' for item in status_options]
    )

    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ứng viên tuyển dụng | Kế Toán Diệu Tâm</title>
  <meta name="description" content="Trang ứng viên dành cho nhà tuyển dụng: theo dõi hồ sơ theo vị trí quan tâm, trạng thái xử lý và mức kinh nghiệm.">
  <link rel="canonical" href="{SITE_URL}/ung-vien-tuyen-dung.html">
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/jobs.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="jobs-page jobs-dashboard-page" data-root="" data-nav="tuyen-dung">
  <div id="siteHeader"></div>
  <main>
    <section class="jobs-hero">
      <div class="container">
        <nav class="jobs-breadcrumbs" aria-label="Breadcrumb">
          <a href="index.html">Trang chủ</a>
          <span>/</span>
          <a href="tuyen-dung.html">Tuyển dụng</a>
          <span>/</span>
          <a href="nha-tuyen-dung.html">Khu nhà tuyển dụng</a>
          <span>/</span>
          <span>Ứng viên tuyển dụng</span>
        </nav>
        <div class="jobs-hero-grid">
          <div>
            <span class="jobs-kicker">Ứng viên quan tâm</span>
            <h1>Danh sách hồ sơ ứng viên theo trạng thái xử lý</h1>
            <p class="jobs-hero-text">Theo dõi hồ sơ mới, hồ sơ đang xem và hồ sơ đã mời phỏng vấn trên một màn hình tổng hợp rõ ràng.</p>
            <div class="jobs-hero-actions">
              <a href="quan-ly-tin-tuyen-dung.html" class="btn-outline-brown">Quay lại quản lý tin</a>
              <a href="danh-sach-ung-vien.html" class="btn-primary-orange">Mở danh sách ứng viên công khai</a>
            </div>
          </div>
          <div class="jobs-hero-stats">
            <article class="jobs-stat-card"><strong>{len(candidates)}</strong><span>Tổng hồ sơ đang theo dõi</span></article>
            <article class="jobs-stat-card"><strong>{new_count}</strong><span>Hồ sơ mới cần xem</span></article>
            <article class="jobs-stat-card"><strong>{reviewing_count}</strong><span>Đang ở vòng đánh giá</span></article>
            <article class="jobs-stat-card"><strong>{interview_count}</strong><span>Đã mời phỏng vấn</span></article>
          </div>
        </div>
      </div>
    </section>

    <section class="jobs-section section-padding jobs-section-soft">
      <div class="container jobs-dashboard-shell">
        <aside class="jobs-dashboard-sidebar">
          <div class="jobs-dashboard-menu">
            <a href="nha-tuyen-dung.html">Tổng quan</a>
            <a href="dang-tin-viec-lam.html">Đăng tin mới</a>
            <a href="quan-ly-tin-tuyen-dung.html">Quản lý tin</a>
            <a href="ung-vien-tuyen-dung.html" class="is-active">Ứng viên</a>
          </div>
        </aside>

        <div class="jobs-dashboard-main">
          <section class="jobs-dashboard-panel">
            <div class="jobs-dashboard-panel-head">
              <h2>Bộ lọc hồ sơ ứng viên</h2>
              <span class="jobs-dashboard-note" id="recruiterCandidateCount">{len(candidates)} hồ sơ phù hợp</span>
            </div>
            <form class="jobs-filter-bar jobs-candidate-filter-bar" id="recruiterCandidateFilterForm">
              <div class="jobs-filter-grid jobs-recruiter-filter-grid">
                <label class="jobs-filter-field">
                  <span>Tìm hồ sơ</span>
                  <input type="search" id="recruiterCandidateSearch" placeholder="Tên ứng viên, vị trí quan tâm, kỹ năng...">
                </label>
                <label class="jobs-filter-field">
                  <span>Vị trí quan tâm</span>
                  <select id="recruiterCandidateRole">
{role_options_html}
                  </select>
                </label>
                <label class="jobs-filter-field">
                  <span>Kinh nghiệm</span>
                  <select id="recruiterCandidateExperience">
{experience_options_html}
                  </select>
                </label>
                <label class="jobs-filter-field">
                  <span>Trạng thái</span>
                  <select id="recruiterCandidateStatus">
{status_options_html}
                  </select>
                </label>
                <label class="jobs-filter-field">
                  <span>Sắp xếp</span>
                  <select id="recruiterCandidateSort">
                    <option value="updated-desc">Mới cập nhật</option>
                    <option value="name-asc">Tên A → Z</option>
                  </select>
                </label>
              </div>
              <div class="jobs-filter-meta">
                <strong>Ưu tiên hồ sơ đã cập nhật gần đây để tăng tỷ lệ phản hồi</strong>
                <button type="button" class="jobs-filter-reset" id="recruiterCandidateReset">Xóa lọc</button>
              </div>
            </form>
          </section>

          <section class="jobs-dashboard-panel">
            <div class="jobs-dashboard-panel-head">
              <h2>Danh sách ứng viên</h2>
            </div>
            <div class="jobs-dashboard-table-wrap">
              <table class="jobs-dashboard-table jobs-recruiter-candidate-table" id="recruiterCandidateTable">
                <thead>
                  <tr>
                    <th>Ứng viên</th>
                    <th>Vị trí quan tâm</th>
                    <th>Kinh nghiệm</th>
                    <th>Trạng thái</th>
                    <th>Cập nhật</th>
                    <th>Hành động</th>
                  </tr>
                </thead>
                <tbody>
{rows_html}
                </tbody>
              </table>
            </div>
            <div class="jobs-empty-state" id="recruiterCandidateEmpty" hidden>Chưa có hồ sơ phù hợp với bộ lọc hiện tại.</div>
          </section>

          <section class="jobs-dashboard-panel jobs-recruiter-ops-panel">
            <div class="jobs-dashboard-panel-head">
              <h2>Bảng điều phối nội bộ</h2>
            </div>
            <div class="jobs-recruiter-ops-grid">
              <article class="jobs-recruiter-ops-card">
                <div class="jobs-recruiter-ops-head">
                  <h3>Shortlist đang theo dõi</h3>
                  <span class="jobs-status-pill is-saved"><strong id="recruiterShortlistCount">0</strong> hồ sơ</span>
                </div>
                <ul class="jobs-recruiter-shortlist-list" id="recruiterShortlistList">
                  <li class="jobs-recruiter-empty-note">Chưa có ứng viên nào trong shortlist.</li>
                </ul>
              </article>

              <article class="jobs-recruiter-ops-card">
                <div class="jobs-recruiter-ops-head">
                  <h3>Nhật ký thao tác gần đây</h3>
                </div>
                <ul class="jobs-recruiter-activity-feed" id="recruiterActivityFeed">
                  <li>Bạn có thể lưu shortlist, cập nhật trạng thái hoặc thêm ghi chú cho từng hồ sơ ngay tại bảng danh sách.</li>
                </ul>
              </article>
            </div>
          </section>
        </div>
      </div>
    </section>
  </main>
  <div id="siteFooter"></div>
  <div class="jobs-note-dialog" id="recruiterNoteDialog" hidden>
    <div class="jobs-note-dialog-backdrop" data-note-dismiss="backdrop"></div>
    <div class="jobs-note-dialog-card" role="dialog" aria-modal="true" aria-labelledby="recruiterNoteTitle">
      <h3 id="recruiterNoteTitle">Ghi chú nội bộ</h3>
      <p>Ghi chú này chỉ phục vụ điều phối nội bộ cho nhà tuyển dụng.</p>
      <label class="jobs-filter-field">
        <span>Nội dung ghi chú</span>
        <textarea id="recruiterNoteInput" rows="5" placeholder="Ví dụ: Phù hợp vị trí kế toán tổng hợp, ưu tiên mời phỏng vấn chiều thứ 4..."></textarea>
      </label>
      <div class="jobs-note-dialog-actions">
        <button type="button" class="btn-outline-brown" id="recruiterNoteCancel">Hủy</button>
        <button type="button" class="btn-primary-orange" id="recruiterNoteSave">Lưu ghi chú</button>
      </div>
    </div>
  </div>
  <script src="site-shell.js"></script>
{render_recruiter_candidate_filter_script()}
</body>
</html>
"""


def render_candidate_detail_page(candidate: dict[str, Any], related_candidates: list[dict[str, Any]]) -> str:
    skills = candidate.get("skills") or []
    highlights = candidate.get("profileHighlights") or []
    candidate_area_label = clean_text(candidate.get("desiredWorkArea")) or clean_text(candidate.get("addressPublic")) or clean_text(candidate.get("locationLabel"))
    detail_avatar_thumb = render_candidate_avatar_thumb(candidate, "detail", "../")
    featured_badge_html = '<span class="job-badge featured">Nổi bật</span>' if candidate.get("featured") else ""
    skills_html = "".join(f"<li>{escape(skill)}</li>" for skill in skills)
    highlights_html = "".join(
        f'<li><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>{escape(item)}</span></li>' for item in highlights
    )
    hero_facts: list[tuple[str, str]] = [
        ("Mức lương mong muốn", clean_text(candidate.get("salaryExpectation"))),
        ("Sẵn sàng nhận việc", clean_text(candidate.get("availabilityLabel")) or "Sẵn sàng trao đổi"),
        ("Hình thức làm việc", clean_text(candidate.get("workModePreference")) or "Trao đổi thêm"),
    ]
    updated_note = clean_text(candidate.get("updatedLabel")) or "Hồ sơ mới"
    hero_facts_html = "".join(
        f'<li><span>{escape(label)}</span><strong>{escape(value)}</strong></li>'
        for label, value in hero_facts
        if value
    )
    primary_facts: list[tuple[str, str, bool]] = [
        ("Vị trí mục tiêu", clean_text(candidate.get("targetRole")), False),
        ("Kinh nghiệm", clean_text(candidate.get("experienceLabel")), False),
        ("Hình thức làm việc", clean_text(candidate.get("workModePreference")), False),
        ("Nơi ở hiện tại", clean_text(candidate.get("addressPublic")), False),
        ("Khu vực mong muốn", clean_text(candidate.get("desiredWorkArea")), False),
        ("Sẵn sàng nhận việc", clean_text(candidate.get("availabilityLabel")), False),
    ]
    education_facts: list[tuple[str, str, bool]] = [
        ("Trình độ", clean_text(candidate.get("educationLevel")), False),
        ("Chuyên ngành", clean_text(candidate.get("majorLabel")), False),
        ("Trường tốt nghiệp", clean_text(candidate.get("schoolName")), False),
        ("Năm tốt nghiệp", clean_text(candidate.get("graduationYear")), False),
    ]

    def render_fact_group(facts: list[tuple[str, str, bool]]) -> str:
        fact_parts: list[str] = []
        for label, value, is_highlight in facts:
            if not value:
                continue
            strong_class = ' class="salary-highlight"' if is_highlight else ""
            fact_parts.append(
                f'<div class="job-detail-fact"><span>{escape(label)}</span><strong{strong_class}>{escape(value)}</strong></div>'
            )
        return "".join(fact_parts)

    primary_facts_html = render_fact_group(primary_facts)
    education_facts_html = render_fact_group(education_facts)

    about_paragraphs: list[str] = []
    seen_about: set[str] = set()
    for text in [clean_text(candidate.get("intro")), clean_text(candidate.get("profileSummary"))]:
        if not text:
            continue
        normalized = fold_text(text)
        if normalized in seen_about:
            continue
        seen_about.add(normalized)
        about_paragraphs.append(f"<p>{escape(text)}</p>")
    about_html = "".join(about_paragraphs)

    primary_box_html = ""
    if primary_facts_html:
        primary_box_html = f"""
          <div class="job-detail-box jobs-candidate-facts-box">
            <h2>Thông tin nhanh</h2>
            <div class="job-detail-facts">
{primary_facts_html}
            </div>
          </div>
        """.strip()

    education_box_html = ""
    if education_facts_html:
        education_box_html = f"""
          <div class="job-detail-box jobs-candidate-facts-box jobs-candidate-facts-box--secondary">
            <h2>Học vấn</h2>
            <div class="job-detail-facts">
{education_facts_html}
            </div>
          </div>
        """.strip()

    related_html = ""
    if related_candidates:
        cards = []
        for related in related_candidates[:3]:
            avatar_thumb = render_candidate_avatar_thumb(related, "mini", "../")
            related_area = clean_text(related.get("desiredWorkArea")) or clean_text(related.get("addressPublic")) or clean_text(related.get("locationLabel"))
            cards.append(
                f"""
              <article class="jobs-candidate-mini-card">
                <a href="../{escape(related['profilePath'])}" class="jobs-candidate-mini-link" aria-label="Xem chi tiết hồ sơ {escape(related['fullName'])}">
                  {avatar_thumb}
                  <div class="jobs-candidate-mini-copy">
                    <h3>{escape(related['fullName'])}</h3>
                    <p>{escape(related['headline'])}</p>
                    <div class="jobs-candidate-mini-meta">
                      <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>{escape(related_area)}</span>
                    </div>
                    <div class="jobs-candidate-mini-salary"><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i><span>{escape(salary_preference_text(related['salaryExpectation']))}</span></div>
                  </div>
                </a>
              </article>
                """.strip()
            )
        related_html = f"""
        <section class="jobs-related-candidate-section">
          <div class="jobs-dashboard-panel-head">
            <h2>Hồ sơ liên quan</h2>
          </div>
          <div class="jobs-related-candidate-grid">
{chr(10).join(cards)}
          </div>
        </section>
        """.strip()

    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hồ sơ ứng viên {escape(candidate['fullName'])} | Kế Toán Diệu Tâm</title>
  <meta name="description" content="{escape(candidate['profileSummary'])}">
  <link rel="canonical" href="{SITE_URL}/{escape(candidate['profilePath'])}">
  <link rel="stylesheet" href="../assets/css/styles.css">
  <link rel="stylesheet" href="../assets/css/jobs.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="jobs-page jobs-candidate-detail-page" data-root="../" data-nav="tuyen-dung">
  <div id="siteHeader"></div>
  <main>
    <section class="job-detail-hero jobs-candidate-detail-hero">
      <div class="container">
        <div class="job-detail-hero-nav">
          <nav class="jobs-breadcrumbs" aria-label="Breadcrumb">
            <a href="../index.html">Trang chủ</a>
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            <a href="../tuyen-dung.html">Tuyển dụng</a>
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            <a href="../danh-sach-ung-vien.html">Danh sách ứng viên</a>
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            <span>{escape(candidate['fullName'])}</span>
          </nav>
          <a href="../danh-sach-ung-vien.html" class="job-detail-back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Về danh sách ứng viên</a>
        </div>

        <div class="jobs-candidate-hero-card">
          <div class="job-detail-top jobs-candidate-detail-top">
            <div class="jobs-candidate-detail-main">
              <div class="jobs-candidate-detail-avatar-wrap">
                {detail_avatar_thumb}
              </div>
              <div class="jobs-candidate-detail-main-copy">
                <div class="job-detail-eyebrow">
                  <div class="job-card-badges">
                    <span class="job-badge neutral">{escape(candidate['experienceLabel'])}</span>{featured_badge_html}
                  </div>
                  <span class="job-detail-company">{escape(candidate_area_label)}</span>
                </div>
                <h1>{escape(candidate['fullName'])}</h1>
                <p class="job-detail-summary">{escape(candidate['headline'])}</p>
                <p class="jobs-candidate-updated-note"><i class="fa-regular fa-clock" aria-hidden="true"></i><span>{escape(updated_note)}</span></p>
                <ul class="jobs-candidate-hero-facts">
{hero_facts_html}
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="job-detail-section section-padding">
      <div class="container">
        <div class="job-detail-grid jobs-candidate-detail-grid">
          <article class="job-detail-main">
            <section class="job-detail-prose jobs-candidate-prose jobs-candidate-about-section" id="gioi-thieu-ung-vien">
              <h2>Giới thiệu bản thân</h2>
              {about_html}
            </section>
            <section class="job-detail-prose jobs-candidate-prose" id="diem-noi-bat-ung-vien">
              <h2>Điểm nổi bật</h2>
              <ul class="jobs-candidate-highlight-list">
                {highlights_html}
              </ul>
            </section>
            <section class="job-detail-prose jobs-candidate-prose" id="ky-nang-ung-vien">
              <h2>Kỹ năng nổi bật</h2>
              <ul class="jobs-candidate-skill-list jobs-candidate-skill-list--detail">
                {skills_html}
              </ul>
            </section>
            {education_box_html}
          </article>

          <aside class="job-detail-side">
            {primary_box_html}
            <div class="job-detail-box job-detail-support jobs-candidate-contact-lock" id="lien-he-ung-vien">
              <h2>Thông tin liên hệ</h2>
              <p>{escape(candidate['contactPolicyNote'])}</p>
              <div class="job-detail-support-actions">
                <a href="../dang-nhap-tuyen-dung.html" class="btn-primary-orange">Đăng nhập nhà tuyển dụng</a>
                <a href="../ung-vien-tuyen-dung.html" class="btn-outline-brown" data-candidate-request-open>Yêu cầu kết nối ứng viên</a>
              </div>
              <ul class="jobs-candidate-contact-preview">
                <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>{escape(candidate.get('maskedEmail') or '********@*****.***')}</span></li>
                <li><i class="fa-solid fa-phone" aria-hidden="true"></i><span>{escape(candidate.get('maskedPhone') or '*** *** ***')}</span></li>
                <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>{escape(candidate.get('addressPublic') or candidate['locationLabel'])}</span></li>
              </ul>
            </div>
          </aside>
        </div>
        {related_html}
      </div>
    </section>

    <div class="job-detail-mobile-bar jobs-candidate-detail-mobile-bar" aria-label="Tác vụ nhanh">
      <a href="../dang-nhap-tuyen-dung.html" class="btn-primary-orange">Xem liên hệ</a>
      <a href="../ung-vien-tuyen-dung.html" class="btn-outline-brown" data-candidate-request-open>Yêu cầu kết nối</a>
    </div>
  </main>
  <div id="siteFooter"></div>
  <div class="jobs-request-dialog" id="candidateRequestDialog" hidden>
    <button type="button" class="jobs-request-dialog-backdrop" data-candidate-request-close aria-label="Đóng cửa sổ"></button>
    <div class="jobs-request-dialog-card" role="dialog" aria-modal="true" aria-labelledby="candidateRequestTitle">
      <button type="button" class="jobs-request-dialog-close" data-candidate-request-close aria-label="Đóng">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
      <h3 id="candidateRequestTitle">Kết nối với {escape(candidate['fullName'])}</h3>
      <p>Thông tin liên hệ đầy đủ chỉ mở cho nhà tuyển dụng đã đăng nhập. Bạn có thể đăng nhập để gửi yêu cầu cho đúng hồ sơ này hoặc mở trang ứng viên tuyển dụng để xem thêm.</p>
      <ul class="jobs-request-dialog-list">
        <li>Xem đầy đủ email và số điện thoại của ứng viên.</li>
        <li>Gửi yêu cầu kết nối đúng hồ sơ bạn đang quan tâm.</li>
        <li>Quản lý shortlist và ghi chú nội bộ tập trung.</li>
      </ul>
      <div class="jobs-request-dialog-actions">
        <a href="../dang-nhap-tuyen-dung.html" class="btn-primary-orange">Đăng nhập để kết nối</a>
        <a href="../ung-vien-tuyen-dung.html" class="btn-outline-brown">Mở trang ứng viên tuyển dụng</a>
      </div>
    </div>
  </div>
  <script src="../site-shell.js"></script>
  {render_detail_mobile_bar_script()}
  {render_candidate_request_dialog_script()}
</body>
</html>
"""


def select_related_candidates(current: dict[str, Any], candidates: list[dict[str, Any]], limit: int = 3) -> list[dict[str, Any]]:
    current_slug = clean_text(current.get("slug"))
    ranked: list[tuple[int, int, dict[str, Any]]] = []
    for candidate in candidates:
        if clean_text(candidate.get("slug")) == current_slug:
            continue
        score = 0
        if clean_text(candidate.get("locationLabel")) == clean_text(current.get("locationLabel")):
            score += 2
        if clean_text(candidate.get("experienceLabel")) == clean_text(current.get("experienceLabel")):
            score += 2
        if candidate.get("featured"):
            score += 1
        updated_iso = clean_text(candidate.get("updatedDate"))
        try:
            updated_rank = int(datetime.strptime(updated_iso, "%Y-%m-%d").strftime("%Y%m%d"))
        except ValueError:
            updated_rank = 0
        ranked.append((score, updated_rank, candidate))
    ranked.sort(key=lambda item: (item[0], item[1], clean_text(item[2].get("fullName"))), reverse=True)
    return [item[2] for item in ranked[:limit]]


def render_featured_candidates_section(candidates: list[dict[str, Any]]) -> str:
    if not candidates:
        return ""

    cards_html: list[str] = []
    for candidate in candidates:
        skills = candidate.get("skills") or []
        skill_list = ""
        if skills:
            chips = "".join(f"<li>{escape(skill)}</li>" for skill in skills)
            skill_list = f'<ul class="jobs-candidate-skill-list">{chips}</ul>'
        avatar_thumb = render_candidate_avatar_thumb(candidate, "showcase")

        cards_html.append(
            f"""
          <article class="jobs-candidate-card">
            <a href="{escape(candidate["profilePath"])}" class="jobs-candidate-card-link" aria-label="Xem chi tiết hồ sơ {escape(candidate["fullName"])}">
              <div class="jobs-candidate-card-head">
                {avatar_thumb}
                <div class="jobs-candidate-headline">
                  <div class="job-card-badges jobs-candidate-badges">{'<span class="job-badge featured">Nổi bật</span>' if candidate.get("featured") else ''}</div>
                  <h3>{escape(candidate["fullName"])}</h3>
                  <p>{escape(candidate["headline"])}</p>
                </div>
                <span class="jobs-candidate-card-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i></span>
              </div>
              <div class="jobs-candidate-facts">
                <span><i class="fa-solid fa-briefcase" aria-hidden="true"></i>{escape(candidate["experienceLabel"])}</span>
                <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>{escape(candidate["locationLabel"])}</span>
                <span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>{escape(salary_preference_text(candidate["salaryExpectation"]))}</span>
              </div>
              <p class="jobs-candidate-intro">{escape(candidate["intro"])}</p>
              {skill_list}
              <div class="jobs-candidate-foot">
                <div class="jobs-candidate-foot-meta">
                  <strong>{escape(candidate["availabilityLabel"])}</strong>
                  <span>{escape(candidate["updatedLabel"])}</span>
                </div>
                <span class="jobs-candidate-card-cta">Hồ sơ chi tiết</span>
              </div>
            </a>
          </article>
            """.strip()
        )

    return f"""
    <section class="jobs-section section-padding jobs-candidate-showcase" id="candidate-showcase">
      <div class="container">
        <div class="jobs-candidate-showcase-head">
          <div class="jobs-candidate-showcase-copy">
            <div class="section-label"><span>HỒ SƠ ỨNG VIÊN</span></div>
            <h2 class="jobs-section-title">Ứng viên kế toán nổi bật</h2>
            <p class="jobs-section-subtitle">Những hồ sơ đã cập nhật đầy đủ thông tin nghề nghiệp, nổi bật về chuyên môn và sẵn sàng trao đổi cơ hội phù hợp.</p>
          </div>
          <div class="jobs-candidate-showcase-note">
            <strong>{len(candidates)} hồ sơ nổi bật</strong>
            <span>Ưu tiên hồ sơ cập nhật gần đây, sẵn sàng trao đổi nhanh và phù hợp nhu cầu tuyển dụng thực tế.</span>
          </div>
        </div>
        <div class="jobs-candidate-grid">
{chr(10).join(cards_html)}
        </div>
        <div class="jobs-candidate-actions">
          <a href="danh-sach-ung-vien.html" class="btn-outline-brown">Xem danh sách hồ sơ ứng viên</a>
          <a href="dang-nhap-tuyen-dung.html" class="btn-primary-orange">Đăng nhập nhà tuyển dụng để liên hệ</a>
        </div>
      </div>
    </section>
    """.strip()


def render_jobs_filter_script() -> str:
    return """
  <script>
    (function () {
      function normalizeText(value) {
        return String(value || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\\u0300-\\u036f]/g, '')
          .replace(/đ/g, 'd')
          .replace(/\\s+/g, ' ')
          .trim();
      }

      function initJobsFilter() {
        var form = document.getElementById('jobsFilterForm');
        var grid = document.getElementById('jobsListGrid');
        if (!form || !grid) return;

        var filterShell = document.getElementById('jobsFilterShell');
        var filterToggle = document.getElementById('jobsFilterToggle');
        var discoveryBar = document.getElementById('jobsDiscoveryBar');
        var discoverySentinel = document.getElementById('jobsDiscoverySentinel');
        var mobileQuery = window.matchMedia('(max-width: 767px)');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.job-card'));
        var resultCount = document.getElementById('jobsFilterCount');
        var emptyState = document.getElementById('jobsEmptyState');
        var searchInput = document.getElementById('jobsFilterSearch');
        var locationSelect = document.getElementById('jobsFilterLocation');
        var roleSelect = document.getElementById('jobsFilterRole');
        var employmentSelect = document.getElementById('jobsFilterEmployment');
        var workModeSelect = document.getElementById('jobsFilterWorkMode');
        var experienceSelect = document.getElementById('jobsFilterExperience');
        var sortSelect = document.getElementById('jobsSortOrder');
        var resetBtn = document.getElementById('jobsFilterReset');
        var activeFilters = document.getElementById('jobsActiveFilters');
        var quickRoleButtons = Array.prototype.slice.call(document.querySelectorAll('.jobs-quick-chip[data-role-value]'));
        var quickFilters = document.getElementById('jobsQuickFilters');
        var quickScrollWrap = document.getElementById('jobsQuickRoleScroll');
        var quickNavPrev = document.querySelector('[data-jobs-quick-nav="prev"]');
        var quickNavNext = document.querySelector('[data-jobs-quick-nav="next"]');
        var pagination = document.getElementById('jobsPagination');
        var paginationPages = document.getElementById('jobsPaginationPages');
        var paginationPrev = pagination ? pagination.querySelector('[data-page-action="prev"]') : null;
        var paginationNext = pagination ? pagination.querySelector('[data-page-action="next"]') : null;
        var currentPage = 1;
        var pageSize = 9;
        var totalPages = 1;

        function syncDiscoverySticky() {
          if (!discoveryBar || !discoverySentinel) return;
          if (!mobileQuery.matches) {
            discoveryBar.classList.remove('is-sticky');
            return;
          }
          var rect = discoverySentinel.getBoundingClientRect();
          discoveryBar.classList.toggle('is-sticky', rect.top <= 0);
        }

        function setFilterShellOpen(open) {
          if (!filterShell || !filterToggle) return;
          var isOpen = !!open;
          filterShell.classList.toggle('is-open', isOpen);
          filterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          var toggleLabel = filterToggle.querySelector('span');
          if (toggleLabel) {
            toggleLabel.textContent = isOpen ? 'Đóng bộ lọc' : 'Mở bộ lọc';
          }
        }

        function syncFilterShellByViewport() {
          if (!filterShell || !filterToggle) return;
          if (mobileQuery.matches) {
            if (!filterShell.classList.contains('is-open')) {
              setFilterShellOpen(false);
            }
            return;
          }
          setFilterShellOpen(true);
        }

        function getSelectedLabel(select) {
          if (!select || !select.options || select.selectedIndex < 0) return '';
          return select.options[select.selectedIndex].textContent || '';
        }

        function syncQuickRoleButtons() {
          var selectedRole = roleSelect ? roleSelect.value : '';
          quickRoleButtons.forEach(function (button) {
            button.classList.toggle('is-active', (button.dataset.roleValue || '') === selectedRole);
          });
        }

        function syncQuickFilterNav() {
          if (!quickFilters || !quickScrollWrap || !quickNavPrev || !quickNavNext) return;

          if (!mobileQuery.matches) {
            quickScrollWrap.classList.remove('is-scrollable');
            quickNavPrev.hidden = true;
            quickNavNext.hidden = true;
            quickNavPrev.disabled = true;
            quickNavNext.disabled = true;
            return;
          }

          var maxScroll = Math.max(0, quickFilters.scrollWidth - quickFilters.clientWidth);
          var hasOverflow = maxScroll > 6;
          quickScrollWrap.classList.toggle('is-scrollable', hasOverflow);
          quickNavPrev.hidden = !hasOverflow;
          quickNavNext.hidden = !hasOverflow;

          if (!hasOverflow) {
            quickNavPrev.disabled = true;
            quickNavNext.disabled = true;
            return;
          }

          var currentScroll = quickFilters.scrollLeft;
          quickNavPrev.disabled = currentScroll <= 4;
          quickNavNext.disabled = currentScroll >= maxScroll - 4;
        }

        function scrollQuickFilters(direction) {
          if (!quickFilters) return;
          var distance = Math.max(120, Math.round(quickFilters.clientWidth * 0.78));
          var delta = direction === 'next' ? distance : -distance;
          quickFilters.scrollBy({ left: delta, behavior: 'smooth' });
        }

        function renderActiveFilters(filters) {
          if (!activeFilters) return;
          if (!filters.length) {
            activeFilters.hidden = true;
            activeFilters.innerHTML = '';
            return;
          }

          activeFilters.hidden = false;
          activeFilters.innerHTML = filters.map(function (item) {
            return '<button type="button" class="jobs-active-chip" data-clear-key="' + item.key + '">' + item.label + '<i class="fa-solid fa-xmark" aria-hidden="true"></i></button>';
          }).join('') + '<button type="button" class="jobs-active-chip is-clear-all" data-clear-key="all">Xóa tất cả</button>';
        }

        function compareCards(a, b, sortValue) {
          var aFeatured = Number(a.dataset.featured || 0);
          var bFeatured = Number(b.dataset.featured || 0);
          var aPublish = Date.parse(a.dataset.publishDate || '') || 0;
          var bPublish = Date.parse(b.dataset.publishDate || '') || 0;
          var aDeadline = Date.parse(a.dataset.deadline || '') || 0;
          var bDeadline = Date.parse(b.dataset.deadline || '') || 0;
          var aSalary = Number(a.dataset.salaryMax || 0);
          var bSalary = Number(b.dataset.salaryMax || 0);

          if (sortValue === 'deadline-asc') {
            return aDeadline - bDeadline || bPublish - aPublish;
          }
          if (sortValue === 'salary-desc') {
            return bSalary - aSalary || bPublish - aPublish;
          }
          if (sortValue === 'featured-first') {
            return bFeatured - aFeatured || bPublish - aPublish;
          }
          return bPublish - aPublish || aDeadline - bDeadline;
        }

        function applyPagination(visibleCards) {
          totalPages = Math.max(1, Math.ceil(visibleCards.length / pageSize));
          if (currentPage > totalPages) {
            currentPage = totalPages;
          }

          var start = (currentPage - 1) * pageSize;
          var end = start + pageSize;
          visibleCards.forEach(function (card, index) {
            var inPage = index >= start && index < end;
            card.classList.toggle('is-page-hidden', !inPage);
          });

          if (!pagination) return;

          if (visibleCards.length <= pageSize) {
            pagination.hidden = true;
            if (paginationPages) paginationPages.innerHTML = '';
            if (paginationPrev) paginationPrev.disabled = true;
            if (paginationNext) paginationNext.disabled = true;
            return;
          }

          pagination.hidden = false;
          if (paginationPages) {
            var buttons = [];
            for (var page = 1; page <= totalPages; page += 1) {
              buttons.push('<button type="button" class="jobs-pagination-page' + (page === currentPage ? ' is-active' : '') + '" data-page-number="' + page + '">' + page + '</button>');
            }
            paginationPages.innerHTML = buttons.join('');
          }
          if (paginationPrev) paginationPrev.disabled = currentPage <= 1;
          if (paginationNext) paginationNext.disabled = currentPage >= totalPages;
        }

        function applyFilters(options) {
          var keepPage = options && options.keepPage;
          var searchValue = normalizeText(searchInput ? searchInput.value : '');
          var locationValue = locationSelect ? locationSelect.value : '';
          var roleValue = roleSelect ? roleSelect.value : '';
          var employmentValue = employmentSelect ? employmentSelect.value : '';
          var workModeValue = workModeSelect ? workModeSelect.value : '';
          var experienceValue = experienceSelect ? experienceSelect.value : '';
          var sortValue = sortSelect ? sortSelect.value : 'publish-desc';
          var visible = 0;
          var visibleCards = [];

          cards.forEach(function (card) {
            var matchesSearch = !searchValue || (card.dataset.search || '').indexOf(searchValue) !== -1;
            var matchesLocation = !locationValue || (card.dataset.locationGroup || '') === locationValue;
            var matchesRole = !roleValue || (card.dataset.roleGroup || '') === roleValue;
            var matchesEmployment = !employmentValue || (card.dataset.employment || '') === employmentValue;
            var matchesWorkMode = !workModeValue || (card.dataset.workMode || '') === workModeValue;
            var matchesExperience = !experienceValue || (card.dataset.experience || '') === experienceValue;

            var show = matchesSearch && matchesLocation && matchesRole && matchesEmployment && matchesWorkMode && matchesExperience;
            card.classList.toggle('is-hidden', !show);
            card.classList.remove('is-page-hidden');
            if (show) {
              visible += 1;
              visibleCards.push(card);
            }
          });

          visibleCards.sort(function (a, b) {
            return compareCards(a, b, sortValue);
          });
          visibleCards.forEach(function (card) {
            grid.appendChild(card);
          });

          var filters = [];
          if (searchValue) filters.push({ key: 'search', label: 'Từ khóa: ' + (searchInput ? searchInput.value.trim() : '') });
          if (locationValue) filters.push({ key: 'location', label: 'Khu vực: ' + getSelectedLabel(locationSelect) });
          if (roleValue) filters.push({ key: 'role', label: 'Vai trò: ' + getSelectedLabel(roleSelect) });
          if (employmentValue) filters.push({ key: 'employment', label: 'Hình thức: ' + getSelectedLabel(employmentSelect) });
          if (workModeValue) filters.push({ key: 'workMode', label: 'Cách làm việc: ' + getSelectedLabel(workModeSelect) });
          if (experienceValue) filters.push({ key: 'experience', label: 'Kinh nghiệm: ' + getSelectedLabel(experienceSelect) });
          if (sortValue && sortValue !== 'publish-desc') filters.push({ key: 'sort', label: 'Sắp xếp: ' + getSelectedLabel(sortSelect) });

          if (resultCount) {
            resultCount.textContent = visible + ' vị trí phù hợp';
          }
          if (emptyState) {
            emptyState.hidden = visible !== 0;
          }
          renderActiveFilters(filters);
          syncQuickRoleButtons();
          syncQuickFilterNav();

          if (!keepPage) {
            currentPage = 1;
          }
          applyPagination(visibleCards);
        }

        form.addEventListener('input', applyFilters);
        form.addEventListener('change', applyFilters);

        quickRoleButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            if (!roleSelect) return;
            var nextValue = button.dataset.roleValue || '';
            roleSelect.value = roleSelect.value === nextValue ? '' : nextValue;
            applyFilters();
          });
        });

        if (quickFilters) {
          quickFilters.addEventListener('scroll', syncQuickFilterNav, { passive: true });
        }
        if (quickNavPrev) {
          quickNavPrev.addEventListener('click', function () {
            scrollQuickFilters('prev');
          });
        }
        if (quickNavNext) {
          quickNavNext.addEventListener('click', function () {
            scrollQuickFilters('next');
          });
        }

        if (activeFilters) {
          activeFilters.addEventListener('click', function (event) {
            var button = event.target.closest('[data-clear-key]');
            if (!button) return;
            var key = button.dataset.clearKey || '';
            if (key === 'all') {
              form.reset();
              applyFilters();
              return;
            }
            if (key === 'search' && searchInput) searchInput.value = '';
            if (key === 'location' && locationSelect) locationSelect.value = '';
            if (key === 'role' && roleSelect) roleSelect.value = '';
            if (key === 'employment' && employmentSelect) employmentSelect.value = '';
            if (key === 'workMode' && workModeSelect) workModeSelect.value = '';
            if (key === 'experience' && experienceSelect) experienceSelect.value = '';
            if (key === 'sort' && sortSelect) sortSelect.value = 'publish-desc';
            applyFilters();
          });
        }

        if (paginationPages) {
          paginationPages.addEventListener('click', function (event) {
            var button = event.target.closest('[data-page-number]');
            if (!button) return;
            var nextPage = Number(button.dataset.pageNumber || '1');
            if (!nextPage || nextPage === currentPage) return;
            currentPage = nextPage;
            applyFilters({ keepPage: true });
          });
        }

        if (paginationPrev) {
          paginationPrev.addEventListener('click', function () {
            if (currentPage <= 1) return;
            currentPage -= 1;
            applyFilters({ keepPage: true });
          });
        }

        if (paginationNext) {
          paginationNext.addEventListener('click', function () {
            if (currentPage >= totalPages) return;
            currentPage += 1;
            applyFilters({ keepPage: true });
          });
        }

        if (filterToggle && filterShell) {
          setFilterShellOpen(!mobileQuery.matches);
          filterToggle.addEventListener('click', function () {
            setFilterShellOpen(!filterShell.classList.contains('is-open'));
          });
          if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', function () {
              syncFilterShellByViewport();
              applyFilters();
              syncQuickFilterNav();
            });
          } else if (mobileQuery.addListener) {
            mobileQuery.addListener(function () {
              syncFilterShellByViewport();
              applyFilters();
              syncQuickFilterNav();
            });
          }
        }

        syncDiscoverySticky();
        syncQuickFilterNav();
        window.addEventListener('scroll', syncDiscoverySticky, { passive: true });
        window.addEventListener('resize', syncDiscoverySticky);
        window.addEventListener('resize', syncQuickFilterNav);

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            form.reset();
            applyFilters();
          });
        }

        applyFilters();
        window.setTimeout(syncQuickFilterNav, 120);
      }

      document.addEventListener('DOMContentLoaded', initJobsFilter);
    })();
  </script>
""".strip()


def render_detail_mobile_bar_script() -> str:
    return """
  <script>
    (function () {
      function initJobDetailMobileBar() {
        var bar = document.querySelector('.job-detail-mobile-bar');
        var primaryActions = document.querySelector('.job-detail-actions, .jobs-candidate-hero-card, .job-detail-hero');
        if (!bar || !primaryActions) return;

        var mobileQuery = window.matchMedia('(max-width: 767px)');
        var observer = null;
        var fallbackListening = false;
        var resizeTimer = null;
        var scrollTimer = null;
        var currentVisible = false;

        function setBarVisible(nextVisible) {
          var shouldShow = !!nextVisible;
          if (currentVisible === shouldShow) return;
          currentVisible = shouldShow;
          bar.classList.toggle('is-visible', shouldShow);
        }

        function syncFallbackVisibility() {
          if (!mobileQuery.matches) {
            setBarVisible(false);
            return;
          }
          var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
          var rect = primaryActions.getBoundingClientRect();
          var topSafe = 16;
          var bottomSafe = 56;
          var actionsInView = rect.bottom > topSafe && rect.top < (viewportHeight - bottomSafe);
          setBarVisible(!actionsInView);
        }

        function removeFallbackListener() {
          if (!fallbackListening) return;
          window.removeEventListener('scroll', scheduleFallbackSync);
          if (scrollTimer) {
            window.clearTimeout(scrollTimer);
            scrollTimer = null;
          }
          fallbackListening = false;
        }

        function setupBarObserver() {
          bar.classList.add('is-managed');
          if (observer) {
            observer.disconnect();
            observer = null;
          }

          if (!mobileQuery.matches) {
            setBarVisible(false);
            removeFallbackListener();
            return;
          }

          if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(function (entries) {
              var entry = entries[0];
              var ratio = entry && typeof entry.intersectionRatio === 'number' ? entry.intersectionRatio : 0;
              var shouldShow = !entry || !entry.isIntersecting || ratio < 0.16;
              setBarVisible(shouldShow);
            }, { threshold: [0, 0.16, 0.32], rootMargin: '-6% 0px -18% 0px' });
            observer.observe(primaryActions);
            removeFallbackListener();
            return;
          }

          syncFallbackVisibility();
          if (!fallbackListening) {
            window.addEventListener('scroll', scheduleFallbackSync, { passive: true });
            fallbackListening = true;
          }
        }

        function scheduleSetup() {
          if (resizeTimer) {
            window.clearTimeout(resizeTimer);
          }
          resizeTimer = window.setTimeout(setupBarObserver, 120);
        }

        function scheduleFallbackSync() {
          if (!fallbackListening) return;
          if (scrollTimer) return;
          scrollTimer = window.setTimeout(function () {
            scrollTimer = null;
            syncFallbackVisibility();
          }, 80);
        }

        setupBarObserver();
        if (mobileQuery.addEventListener) {
          mobileQuery.addEventListener('change', scheduleSetup);
        } else if (mobileQuery.addListener) {
          mobileQuery.addListener(scheduleSetup);
        }
        window.addEventListener('resize', scheduleSetup);
      }

      document.addEventListener('DOMContentLoaded', initJobDetailMobileBar);
    })();
  </script>
""".strip()


def render_candidate_request_dialog_script() -> str:
    return """
  <script>
    (function () {
      function initCandidateRequestDialog() {
        var dialog = document.getElementById('candidateRequestDialog');
        var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-candidate-request-open]'));
        if (!dialog || !triggers.length) return;

        var dialogCard = dialog.querySelector('.jobs-request-dialog-card');
        var closeButton = dialog.querySelector('.jobs-request-dialog-close');
        var closeTargets = Array.prototype.slice.call(dialog.querySelectorAll('[data-candidate-request-close]'));

        function openDialog() {
          dialog.hidden = false;
          document.body.classList.add('is-request-dialog-open');
          if (closeButton) {
            window.setTimeout(function () { closeButton.focus(); }, 10);
          }
        }

        function closeDialog() {
          if (dialog.hidden) return;
          dialog.hidden = true;
          document.body.classList.remove('is-request-dialog-open');
        }

        triggers.forEach(function (trigger) {
          trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openDialog();
          });
        });

        closeTargets.forEach(function (target) {
          target.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            closeDialog();
          });
        });

        dialog.addEventListener('click', function () {
          closeDialog();
        });

        if (dialogCard) {
          dialogCard.addEventListener('click', function (event) {
            event.stopPropagation();
          });
        }

        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !dialog.hidden) {
            closeDialog();
          }
        });
      }

      document.addEventListener('DOMContentLoaded', initCandidateRequestDialog);
    })();
  </script>
""".strip()


def render_jobs_sitemap(jobs: list[Job]) -> str:
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        f"  <url><loc>{SITE_URL}/tuyen-dung.html</loc><lastmod>{date.today().isoformat()}</lastmod></url>",
    ]
    if EMPLOYER_PAGE.exists():
        lines.append(f"  <url><loc>{SITE_URL}/dang-tin-tuyen-dung.html</loc><lastmod>{date.today().isoformat()}</lastmod></url>")
    for page in RECRUITMENT_PORTAL_PAGES:
        if page.exists():
            lines.append(f"  <url><loc>{SITE_URL}/{page.name}</loc><lastmod>{date.today().isoformat()}</lastmod></url>")
    for job in jobs:
        lastmod = clean_text(job.meta.get("lastReviewedDate") or job.meta.get("publishDate") or date.today().isoformat())
        lines.append(f"  <url><loc>{SITE_URL}/{job.href}</loc><lastmod>{lastmod}</lastmod></url>")
    lines.append("</urlset>")
    return "\n".join(lines) + "\n"


def append_candidates_to_sitemap(xml_text: str, candidates: list[dict[str, Any]]) -> str:
    closing_tag = "</urlset>"
    idx = xml_text.rfind(closing_tag)
    if idx == -1:
        return xml_text
    lines: list[str] = []
    for candidate in candidates:
        path = clean_text(candidate.get("profilePath"))
        if not path:
            continue
        updated = clean_text(candidate.get("updatedDate")) or date.today().isoformat()
        lines.append(f"  <url><loc>{SITE_URL}/{path}</loc><lastmod>{updated}</lastmod></url>")
    if not lines:
        return xml_text
    prefix = xml_text[:idx].rstrip("\n")
    suffix = xml_text[idx:]
    return prefix + "\n" + "\n".join(lines) + "\n" + suffix


def render_sitemap_index() -> str:
    today = date.today().isoformat()
    return "\n".join(
        [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            f"  <sitemap><loc>{SITE_URL}/sitemap.xml</loc><lastmod>{today}</lastmod></sitemap>",
            f"  <sitemap><loc>{SITE_URL}/sitemap-jobs.xml</loc><lastmod>{today}</lastmod></sitemap>",
            "</sitemapindex>",
            "",
        ]
    )


def ensure_jobs_sitemap_in_robots() -> None:
    sitemap_line = f"Sitemap: {SITE_URL}/sitemap-jobs.xml"
    sitemap_index_line = f"Sitemap: {SITE_URL}/sitemap-index.xml"
    if not ROBOTS_FILE.exists():
        ROBOTS_FILE.write_text(f"User-agent: *\nAllow: /\n\nSitemap: {SITE_URL}/sitemap.xml\n{sitemap_line}\n{sitemap_index_line}\n", encoding="utf-8")
        return
    lines = ROBOTS_FILE.read_text(encoding="utf-8").splitlines()
    if sitemap_line not in lines:
        if lines and lines[-1].strip():
            lines.append("")
        lines.append(sitemap_line)
    if sitemap_index_line not in lines:
        lines.append(sitemap_index_line)
    ROBOTS_FILE.write_text("\n".join(lines) + "\n", encoding="utf-8")


def render_list_page(jobs: list[Job], today: date, featured_candidates: list[dict[str, Any]] | None = None) -> str:
    featured_candidates = featured_candidates or []
    active_jobs = [job for job in jobs if job.effective_status == "active"]
    role_options = sorted({(job.meta["roleGroupKey"], job.meta["roleGroupLabel"]) for job in active_jobs}, key=lambda item: item[1])
    location_options = sorted({(job.meta["locationGroupKey"], job.meta["locationGroupLabel"]) for job in active_jobs}, key=lambda item: item[1])
    role_counts: dict[str, int] = {}
    for job in active_jobs:
        role_key = job.meta["roleGroupKey"]
        role_counts[role_key] = role_counts.get(role_key, 0) + 1
    employment_options = sorted({(job.meta["employmentType"], display_employment(job.meta["employmentType"])) for job in active_jobs}, key=lambda item: item[1])
    work_mode_options = sorted({(job.meta["workMode"], display_work_mode(job.meta["workMode"])) for job in active_jobs}, key=lambda item: item[1])
    experience_options = sorted({(job.meta["experienceLevel"], display_experience(job.meta["experienceLevel"])) for job in active_jobs}, key=lambda item: item[1])
    show_employment = len(employment_options) > 1
    show_work_mode = len(work_mode_options) > 1
    all_jobs = "\n".join(
        job_card(job, today, show_employment=show_employment, show_work_mode=show_work_mode) for job in active_jobs
    )
    employment_field = ""
    if show_employment:
        employment_field = f"""
              <label class="jobs-filter-field">
                <span>Hình thức</span>
                <select id="jobsFilterEmployment">
{render_select_options(employment_options, 'Tất cả hình thức')}
                </select>
              </label>
        """.rstrip()
    work_mode_field = ""
    if show_work_mode:
        work_mode_field = f"""
              <label class="jobs-filter-field">
                <span>Cách làm việc</span>
                <select id="jobsFilterWorkMode">
{render_select_options(work_mode_options, 'Tất cả cách làm việc')}
                </select>
              </label>
        """.rstrip()
    featured_candidates_section = render_featured_candidates_section(featured_candidates)

    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tuyển dụng | Kế Toán Diệu Tâm</title>
  <meta name="description" content="Việc làm kế toán, thuế và HCNS dành cho người đang tìm cơ hội phù hợp theo khu vực, vai trò và mức kinh nghiệm.">
  <link rel="canonical" href="{SITE_URL}/tuyen-dung.html">
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/jobs.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="jobs-page jobs-list-page" data-root="" data-nav="tuyen-dung">
  <div id="siteHeader"></div>
  <main>
    <section class="jobs-hero jobs-hero-compact">
      <div class="container">
        <nav class="jobs-breadcrumbs" aria-label="Breadcrumb">
          <a href="index.html">Trang chủ</a>
          <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
          <span>Tuyển dụng</span>
        </nav>
        <div class="jobs-hero-compact-head jobs-hero-minimal-head">
          <h1>Tuyển dụng kế toán</h1>
          <p class="jobs-hero-text">Không gian tuyển dụng dành riêng cho ngành kế toán – Nơi mọi nhu cầu tìm người và tìm việc được khớp nối nhanh và rõ ràng nhất.</p>
        </div>
      </div>
    </section>

    <section class="jobs-section section-padding jobs-section-soft jobs-list-core" id="job-list">
      <div class="container">
        <div id="jobsDiscoverySentinel" aria-hidden="true"></div>
        <div class="jobs-discovery-bar" id="jobsDiscoveryBar">
          <div class="jobs-quick-filters-scroll" id="jobsQuickRoleScroll">
            <button type="button" class="jobs-quick-filters-nav jobs-quick-filters-nav--prev" data-jobs-quick-nav="prev" aria-label="Xem nhóm vai trò trước đó" hidden>
              <i class="fa-solid fa-angle-left" aria-hidden="true"></i>
            </button>
            <div class="jobs-quick-filters" id="jobsQuickFilters" aria-label="Lọc nhanh theo vai trò">
{render_quick_role_filters(role_counts, role_options)}
            </div>
            <button type="button" class="jobs-quick-filters-nav jobs-quick-filters-nav--next" data-jobs-quick-nav="next" aria-label="Xem nhóm vai trò tiếp theo" hidden>
              <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            </button>
          </div>
          <div class="jobs-filter-shell" id="jobsFilterShell">
            <button type="button" class="jobs-filter-mobile-toggle" id="jobsFilterToggle" aria-expanded="false" aria-controls="jobsFilterForm">
              <i class="fa-solid fa-sliders" aria-hidden="true"></i><span>Mở bộ lọc</span>
            </button>
            <form class="jobs-filter-bar" id="jobsFilterForm">
              <div class="jobs-filter-grid">
                <label class="jobs-filter-field">
                  <span>Tìm nhanh</span>
                  <input type="search" id="jobsFilterSearch" placeholder="Tên vị trí, công ty, khu vực...">
                </label>
                <label class="jobs-filter-field">
                  <span>Khu vực</span>
                  <select id="jobsFilterLocation">
{render_select_options(location_options, 'Tất cả khu vực')}
                  </select>
                </label>
                <label class="jobs-filter-field">
                  <span>Vai trò</span>
                  <select id="jobsFilterRole">
{render_select_options(role_options, 'Tất cả vai trò')}
                  </select>
                </label>
{employment_field}
{work_mode_field}
                <label class="jobs-filter-field">
                  <span>Kinh nghiệm</span>
                  <select id="jobsFilterExperience">
{render_select_options(experience_options, 'Tất cả mức kinh nghiệm')}
                  </select>
                </label>
                <label class="jobs-filter-field">
                  <span>Sắp xếp</span>
                  <select id="jobsSortOrder">
                    <option value="publish-desc">Mới nhất</option>
                    <option value="deadline-asc">Gần hết hạn</option>
                    <option value="salary-desc">Lương cao</option>
                    <option value="featured-first">Nổi bật trước</option>
                  </select>
                </label>
              </div>
              <div class="jobs-filter-meta">
                <strong id="jobsFilterCount">{len(active_jobs)} vị trí phù hợp</strong>
                <button type="button" class="jobs-filter-reset" id="jobsFilterReset">Xóa lọc</button>
              </div>
            </form>
          </div>
        </div>
        <div class="jobs-active-filters" id="jobsActiveFilters" hidden></div>
        <div class="jobs-grid jobs-list-grid" id="jobsListGrid">
{all_jobs}
        </div>
        <div class="jobs-empty-state" id="jobsEmptyState" hidden>Chưa có tin tuyển dụng phù hợp với bộ lọc hiện tại.</div>
        <div class="jobs-pagination" id="jobsPagination" hidden>
          <button type="button" class="jobs-pagination-btn" data-page-action="prev">Trang trước</button>
          <div class="jobs-pagination-pages" id="jobsPaginationPages"></div>
          <button type="button" class="jobs-pagination-btn" data-page-action="next">Trang sau</button>
        </div>
      </div>
    </section>

    {featured_candidates_section}

    <section class="jobs-section section-padding">
      <div class="container">
        <div class="section-label center"><span>LỐI VÀO NHANH</span></div>
        <h2 class="jobs-section-title">Bạn là ứng viên hay nhà tuyển dụng?</h2>
        <p class="jobs-section-subtitle">Chọn đúng khu chức năng để thao tác nhanh hơn theo vai trò của bạn.</p>
        <div class="jobs-portal-grid">
          <article class="jobs-portal-card">
            <span class="jobs-kicker">Cho ứng viên</span>
            <h3>Tài khoản ứng viên</h3>
            <p>Tạo hồ sơ, lưu việc làm, theo dõi đơn đã nộp và trạng thái phản hồi.</p>
            <div class="jobs-portal-actions">
              <a href="tai-khoan-ung-vien.html" class="btn-primary-orange">Vào khu ứng viên</a>
              <a href="dang-nhap-tuyen-dung.html" class="btn-outline-brown">Đăng nhập</a>
            </div>
          </article>
          <article class="jobs-portal-card">
            <span class="jobs-kicker">Cho nhà tuyển dụng</span>
            <h3>Khu nhà tuyển dụng</h3>
            <p>Đăng tin mới, quản lý tin đang chạy và theo dõi danh sách ứng viên quan tâm.</p>
            <div class="jobs-portal-actions">
              <a href="nha-tuyen-dung.html" class="btn-primary-orange">Vào khu nhà tuyển dụng</a>
              <a href="dang-tin-viec-lam.html" class="btn-outline-brown">Đăng tin nhanh</a>
            </div>
          </article>
        </div>
      </div>
    </section>
  </main>
  <div id="siteFooter"></div>
  <script src="site-shell.js"></script>
{render_jobs_filter_script()}
</body>
</html>
"""


def render_detail_page(job: Job, related_jobs: list[Job]) -> str:
    meta = job.meta
    role_label = clean_text(meta.get("roleGroupLabel")) or "Kế toán"
    location_group_label = clean_text(meta.get("locationGroupLabel")) or "Đang cập nhật"
    salary_label = meta.get("salaryLabel") or "Liên hệ"
    experience_label = display_experience(meta.get("experienceLevel") or "Đang cập nhật")
    employment_label = display_employment(meta.get("employmentType") or "Đang cập nhật")
    work_mode_label = display_work_mode(meta.get("workMode") or "Đang cập nhật")
    publish_label = format_date_vi(job.publish_date)
    deadline_label = format_date_vi(job.deadline)

    highlight_rows = [
        ("fa-solid fa-location-dot", f"Khu vực: {location_group_label}"),
        ("fa-solid fa-briefcase", f"Hình thức: {employment_label}"),
        ("fa-solid fa-laptop-house", f"Cách làm việc: {work_mode_label}"),
        ("fa-solid fa-user-clock", f"Kinh nghiệm: {experience_label}"),
        ("fa-regular fa-calendar-check", f"Hạn nộp: {deadline_label}"),
    ]
    highlights_html = "".join(
        f'<li><i class="{escape(icon)}" aria-hidden="true"></i><span>{escape(text)}</span></li>'
        for icon, text in highlight_rows
    )

    info_rows = [
        ("Công ty", meta["companyName"]),
        ("Vai trò", role_label),
        ("Khu vực", location_group_label),
        ("Địa điểm", meta["location"]),
        ("Mức lương", salary_label),
        ("Kinh nghiệm", experience_label),
        ("Hình thức", employment_label),
        ("Cách làm việc", work_mode_label),
        ("Ngày đăng", publish_label),
        ("Hạn nộp", deadline_label),
    ]
    info_html_parts = []
    for label, value in info_rows:
        salary_class = ' class="salary-highlight"' if label == "Mức lương" else ""
        info_html_parts.append(
            f'<div class="job-detail-fact"><span>{escape(label)}</span><strong{salary_class}>{escape(value)}</strong></div>'
        )
    info_html = "\n".join(info_html_parts)

    support_box = (
        '<div class="job-detail-box job-detail-support">'
        '<h2>Cần hỗ trợ thêm?</h2>'
        '<p>Nếu bạn cần tư vấn nhanh về vị trí này, có thể nhắn Zalo hoặc email để Diệu Tâm hỗ trợ.</p>'
        '<div class="job-detail-support-actions">'
        '<a href="https://zalo.me/0777315188" target="_blank" rel="noopener" class="btn-outline-brown">Nhắn Zalo hỗ trợ</a>'
        '<a href="mailto:ketoandieutam@gmail.com" class="job-source-link">Gửi email cho Diệu Tâm</a>'
        '</div>'
        '</div>'
    )

    related_section = ""
    if related_jobs:
        related_cards = []
        for related in related_jobs:
            related_cards.append(
                f"""
              <article class="jobs-related-card">
                <span class="job-card-company">{escape(related.meta['companyName'])}</span>
                <h3><a href="../{escape(related.href)}">{escape(related.meta['title'])}</a></h3>
                <p>{escape(related.meta['summary'])}</p>
                <div class="jobs-related-meta">
                  <span>{escape(related.meta.get('locationGroupLabel') or related.meta.get('location') or 'Đang cập nhật')}</span>
                  <span>{escape(related.meta.get('salaryLabel') or 'Liên hệ')}</span>
                </div>
              </article>
                """.strip()
            )
        related_html = "\n".join(related_cards)
        related_section = f"""
          <section class="jobs-related-section">
            <div class="jobs-dashboard-panel-head">
              <h2>Việc làm liên quan</h2>
            </div>
            <div class="jobs-related-grid">
{related_html}
            </div>
            <a href="../tuyen-dung.html#job-list" class="job-source-link">Xem toàn bộ việc làm</a>
          </section>
        """.strip()

    apply_bottom_block = (
        '<div class="job-apply-bottom">'
        '<p>Bạn đã sẵn sàng đồng hành cùng Kế Toán Diệu Tâm?</p>'
        '<div class="job-apply-bottom-actions">'
        '<a href="../ung-tuyen.html" class="btn-primary-orange">Ứng tuyển ngay</a>'
        '<a href="https://zalo.me/0777315188" target="_blank" class="btn-outline-brown">Hỏi thêm về vị trí</a>'
        '</div>'
        '</div>'
    )

    return f"""<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{escape(meta['title'])} | {escape(meta['companyName'])}</title>
  <meta name="description" content="{escape(meta['summary'])}">
  <link rel="canonical" href="{SITE_URL}/{meta['href']}">
  <link rel="stylesheet" href="../assets/css/styles.css">
  <link rel="stylesheet" href="../assets/css/jobs.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="jobs-page job-detail-page" data-root="../" data-nav="tuyen-dung">
  <div id="siteHeader"></div>
  <main>
    <section class="job-detail-hero">
      <div class="container">
        
        <div class="job-detail-hero-nav">
          <nav class="jobs-breadcrumbs" aria-label="Breadcrumb">
            <a href="../index.html">Trang chủ</a>
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            <a href="../tuyen-dung.html">Tuyển dụng</a>
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
            <span>{escape(meta['title'])}</span>
          </nav>
          <a href="../tuyen-dung.html#job-list" class="job-detail-back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Về danh sách việc làm</a>
        </div>

        <div class="job-detail-top">
          <div class="job-detail-main-head">
            
            <div class="job-detail-eyebrow">
              <div class="job-card-badges"><span class="job-badge neutral">{escape(role_label)}</span>{badge_html(job)}</div>
              <span class="job-detail-company">{escape(meta['companyName'])}</span>
            </div>
            
            <h1>{escape(meta['title'])}</h1>
            <p class="job-detail-summary">{escape(meta['summary'])}</p>
            <ul class="job-detail-highlights">
              {highlights_html}
            </ul>
          </div>
          <div class="job-detail-actions">
            <a href="../ung-tuyen.html" class="btn-primary-orange">Ứng tuyển nhanh</a>
            <a href="../viec-lam-da-luu.html" class="btn-outline-brown">Lưu việc làm</a>
          </div>
        </div>
      </div>
    </section>

    <section class="job-detail-section section-padding">
      <div class="container job-detail-grid">
        <article class="job-detail-main">
          <div class="job-detail-prose">
{job.body_html}
          </div>
          {related_section}
          {apply_bottom_block}
        </article>
        <aside class="job-detail-side">
          <div class="job-detail-box">
            <h2>Thông tin nhanh</h2>
            <div class="job-detail-facts">
{info_html}
            </div>
          </div>
          {support_box}
        </aside>
      </div>
    </section>
    <div class="job-detail-mobile-bar" aria-label="Tác vụ nhanh">
      <a href="../ung-tuyen.html" class="btn-primary-orange">Ứng tuyển nhanh</a>
      <a href="../viec-lam-da-luu.html" class="btn-outline-brown">Lưu việc làm</a>
    </div>
  </main>
  <div id="siteFooter"></div>
  <script src="../site-shell.js"></script>
  {render_detail_mobile_bar_script()}
</body>
</html>
"""


def build_json_payload(jobs: list[Job]) -> list[dict[str, Any]]:
    payload: list[dict[str, Any]] = []
    for job in jobs:
        payload.append(
            {
                "id": job.meta["id"],
                "slug": job.meta["slug"],
                "title": job.meta["title"],
                "companyName": job.meta["companyName"],
                "location": job.meta["location"],
                "locationGroupKey": job.meta["locationGroupKey"],
                "locationGroupLabel": job.meta["locationGroupLabel"],
                "roleGroupKey": job.meta["roleGroupKey"],
                "roleGroupLabel": job.meta["roleGroupLabel"],
                "salaryLabel": job.meta.get("salaryLabel") or "",
                "employmentType": job.meta.get("employmentType") or "",
                "employmentTypeLabel": display_employment(job.meta.get("employmentType") or ""),
                "workMode": job.meta.get("workMode") or "",
                "workModeLabel": display_work_mode(job.meta.get("workMode") or ""),
                "experienceLevel": job.meta.get("experienceLevel") or "",
                "experienceLevelLabel": display_experience(job.meta.get("experienceLevel") or ""),
                "deadline": job.meta["deadline"],
                "publishDate": job.meta["publishDate"],
                "status": job.meta["status"],
                "featured": bool(job.meta.get("featured")),
                "urgent": bool(job.meta.get("urgent")),
                "summary": job.meta["summary"],
                "href": job.meta["href"],
                "sourceSite": job.meta["sourceSite"],
                "sourceUrl": job.meta["sourceUrl"],
            }
        )
    return payload


def cleanup_stale_detail_pages(public_jobs: list[Job]) -> None:
    expected = {job.detail_path.name for job in public_jobs}
    if not OUTPUT_DIR.exists():
        return
    for path in OUTPUT_DIR.glob("*.html"):
        if path.name not in expected:
            path.unlink()


def cleanup_stale_candidate_pages(candidates: list[dict[str, Any]]) -> None:
    expected = {f"{clean_text(candidate.get('slug'))}.html" for candidate in candidates if clean_text(candidate.get("slug"))}
    if not CANDIDATE_OUTPUT_DIR.exists():
        return
    for path in CANDIDATE_OUTPUT_DIR.glob("*.html"):
        if path.name not in expected:
            path.unlink()


def update_homepage_recruitment_fallback(payload: list[dict[str, Any]]) -> None:
    if not INDEX_PAGE.exists():
        return
    text = INDEX_PAGE.read_text(encoding="utf-8")
    pattern = re.compile(
        r"(<!-- HOME_RECRUITMENT_FALLBACK_START -->\s*)(.*?)(\s*<!-- HOME_RECRUITMENT_FALLBACK_END -->)",
        re.S,
    )
    if not pattern.search(text):
        return
    teaser_payload = payload[:3]
    replacement_json = json.dumps(teaser_payload, ensure_ascii=False, indent=2)
    INDEX_PAGE.write_text(
        pattern.sub(lambda match: f"{match.group(1)}{replacement_json}{match.group(3)}", text, count=1),
        encoding="utf-8",
    )


def main() -> None:
    today = date.today()
    jobs = load_jobs(today)
    candidates_feed = load_candidates_feed()
    featured_candidates = candidates_feed[:6]
    jobs.sort(
        key=lambda item: (
            0 if item.effective_status == "active" else 1,
            0 if item.meta.get("featured") else 1,
            -int(item.publish_date.strftime("%Y%m%d")),
            int(item.deadline.strftime("%Y%m%d")),
            item.meta["title"],
        )
    )

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    CANDIDATE_OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    FEED_DIR.mkdir(parents=True, exist_ok=True)

    public_jobs = [job for job in jobs if job.effective_status not in {"draft", "closed"}]

    cleanup_stale_detail_pages(public_jobs)
    cleanup_stale_candidate_pages(candidates_feed)

    for job in public_jobs:
        related_jobs = select_related_jobs(job, public_jobs, limit=3)
        job.detail_path.write_text(render_detail_page(job, related_jobs), encoding="utf-8")
    for candidate in candidates_feed:
        slug = clean_text(candidate.get("slug"))
        if not slug:
            continue
        related_candidates = select_related_candidates(candidate, candidates_feed, limit=3)
        detail_path = CANDIDATE_OUTPUT_DIR / f"{slug}.html"
        detail_path.write_text(render_candidate_detail_page(candidate, related_candidates), encoding="utf-8")

    payload = build_json_payload(public_jobs)
    DATA_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    FEED_FILE.write_text(json.dumps(payload[:10], ensure_ascii=False, indent=2), encoding="utf-8")
    update_homepage_recruitment_fallback(payload)
    LIST_PAGE.write_text(render_list_page(public_jobs, today, featured_candidates), encoding="utf-8")
    CANDIDATE_LIST_PAGE.write_text(render_candidate_list_page(candidates_feed), encoding="utf-8")
    RECRUITER_CANDIDATE_PAGE.write_text(render_recruiter_candidate_page(candidates_feed), encoding="utf-8")
    jobs_sitemap_xml = render_jobs_sitemap(public_jobs)
    jobs_sitemap_xml = append_candidates_to_sitemap(jobs_sitemap_xml, candidates_feed)
    JOBS_SITEMAP_FILE.write_text(jobs_sitemap_xml, encoding="utf-8")
    SITEMAP_INDEX_FILE.write_text(render_sitemap_index(), encoding="utf-8")
    ensure_jobs_sitemap_in_robots()

    print(json.dumps(
        {
            "jobs_total": len(jobs),
            "jobs_public": len(public_jobs),
            "jobs_active": sum(1 for job in public_jobs if job.effective_status == "active"),
            "candidates_total": len(candidates_feed),
            "featured_candidates": len(featured_candidates),
            "list_page": str(LIST_PAGE.relative_to(ROOT)),
            "candidate_list_page": str(CANDIDATE_LIST_PAGE.relative_to(ROOT)),
            "candidate_detail_dir": str(CANDIDATE_OUTPUT_DIR.relative_to(ROOT)),
            "detail_dir": str(OUTPUT_DIR.relative_to(ROOT)),
            "data_file": str(DATA_FILE.relative_to(ROOT)),
            "feed_file": str(FEED_FILE.relative_to(ROOT)),
            "candidate_feed_file": str(CANDIDATE_FEED_FILE.relative_to(ROOT)),
            "jobs_sitemap": str(JOBS_SITEMAP_FILE.relative_to(ROOT)),
            "sitemap_index": str(SITEMAP_INDEX_FILE.relative_to(ROOT)),
        },
        ensure_ascii=False,
        indent=2,
    ))


if __name__ == "__main__":
    main()
