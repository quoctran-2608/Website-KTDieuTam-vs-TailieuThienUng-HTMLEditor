#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import date, datetime
from html import escape
from pathlib import Path
from typing import Any

import yaml

ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "content" / "tuyen-dung"
OUTPUT_DIR = ROOT / "tuyen-dung"
DATA_DIR = ROOT / "data"
FEED_DIR = DATA_DIR / "feeds"
LIST_PAGE = ROOT / "tuyen-dung.html"
EMPLOYER_PAGE = ROOT / "dang-tin-tuyen-dung.html"
RECRUITMENT_PORTAL_PAGES = [
    ROOT / "dang-nhap-tuyen-dung.html",
    ROOT / "tai-khoan-ung-vien.html",
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
        var primaryActions = document.querySelector('.job-detail-actions');
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


def render_list_page(jobs: list[Job], today: date) -> str:
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
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    FEED_DIR.mkdir(parents=True, exist_ok=True)

    public_jobs = [job for job in jobs if job.effective_status not in {"draft", "closed"}]

    cleanup_stale_detail_pages(public_jobs)

    for job in public_jobs:
        related_jobs = select_related_jobs(job, public_jobs, limit=3)
        job.detail_path.write_text(render_detail_page(job, related_jobs), encoding="utf-8")

    payload = build_json_payload(public_jobs)
    DATA_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    FEED_FILE.write_text(json.dumps(payload[:10], ensure_ascii=False, indent=2), encoding="utf-8")
    update_homepage_recruitment_fallback(payload)
    LIST_PAGE.write_text(render_list_page(public_jobs, today), encoding="utf-8")
    JOBS_SITEMAP_FILE.write_text(render_jobs_sitemap(public_jobs), encoding="utf-8")
    SITEMAP_INDEX_FILE.write_text(render_sitemap_index(), encoding="utf-8")
    ensure_jobs_sitemap_in_robots()

    print(json.dumps(
        {
            "jobs_total": len(jobs),
            "jobs_public": len(public_jobs),
            "jobs_active": sum(1 for job in public_jobs if job.effective_status == "active"),
            "list_page": str(LIST_PAGE.relative_to(ROOT)),
            "detail_dir": str(OUTPUT_DIR.relative_to(ROOT)),
            "data_file": str(DATA_FILE.relative_to(ROOT)),
            "feed_file": str(FEED_FILE.relative_to(ROOT)),
            "jobs_sitemap": str(JOBS_SITEMAP_FILE.relative_to(ROOT)),
            "sitemap_index": str(SITEMAP_INDEX_FILE.relative_to(ROOT)),
        },
        ensure_ascii=False,
        indent=2,
    ))


if __name__ == "__main__":
    main()
