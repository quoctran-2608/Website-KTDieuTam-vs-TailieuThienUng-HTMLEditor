#!/usr/bin/env python3
"""Chặng 6: dry-run gán topicLv3 cho phần còn trống bằng leaf descendant mapping.

Khác với dry-run cũ:
- Không chỉ xét direct-children của lv2.
- Xét toàn bộ leaf descendants dưới node lv2 trong taxonomy.
"""

from __future__ import annotations

import json
import re
import unicodedata
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple


ROOT = Path(__file__).resolve().parent.parent
ARTICLES_PATH = ROOT / "data" / "articles.json"
TAXONOMY_PATH = ROOT / "data" / "taxonomy.json"
IMPORT_LOG_PATH = ROOT / "docs" / "update-800-bai-import-log.json"
OUT_JSON_PATH = ROOT / "docs" / "lv3-stage6-dryrun-remaining.json"
OUT_MD_PATH = ROOT / "docs" / "lv3-stage6-dryrun-remaining.md"
OUT_AUTO_JSON_PATH = ROOT / "docs" / "lv3-stage6-auto-candidates.json"


STOP_WORDS = {
    "va",
    "voi",
    "cua",
    "cho",
    "tren",
    "duoc",
    "khong",
    "theo",
    "tai",
    "cac",
    "tu",
    "den",
    "trong",
    "mot",
    "nhung",
    "la",
    "ve",
    "khi",
    "sao",
    "de",
    "ly",
    "quy",
    "dinh",
    "thong",
    "tu",
    "nghi",
    "dinh",
    "cong",
    "van",
    "mau",
    "so",
}

# Bổ sung hint thủ công cho các leaf key nhạy cảm.
HINTS: Dict[str, List[str]] = {
    "lap-xu-ly-hoa-don": [
        "lap hoa don",
        "viet hoa don",
        "xu ly hoa don",
        "xoa bo hoa don",
        "huy hoa don",
        "dieu chinh hoa don",
        "thay the hoa don",
    ],
    "hoa-don-dien-tu": ["hoa don dien tu", "einvoice", "e invoice"],
    "khau-tru-hoan-thue": ["khau tru", "hoan thue"],
    "ke-khai-gtgt": ["to khai gtgt", "ke khai gtgt"],
    "thue-suat-doi-tuong": ["thue suat", "doi tuong khong chiu thue", "0%", "5%", "10%"],
    "bao-cao-bang-ke": ["bang ke", "bao cao su dung hoa don"],
    "htkk-guide-tncn": ["htkk", "tncn", "05kk", "02kk", "quyet toan tncn"],
    "htkk-guide-tndn": ["htkk", "tndn", "03-tndn", "quyet toan tndn"],
    "htkk-guide-import-bang-ke": ["import bang ke", "tai du lieu tu excel", "ket xuat xml"],
    "cong-van-ke-khai-quan-ly-thue": ["ke khai", "quan ly thue", "hoa don", "gtgt", "tncn", "tndn", "mst"],
    "cong-van-chinh-sach-chung": ["chinh sach", "huong dan chung"],
    "thong-tu-gtgt-hoa-don": ["hoa don", "gtgt"],
    "thong-tu-ke-khai-quan-ly-thue": ["ke khai", "quan ly thue", "dang ky thue", "mst"],
    "thong-tu-tncn": ["tncn", "thu nhap ca nhan", "giam tru gia canh"],
    "thong-tu-tndn": ["tndn", "thu nhap doanh nghiep"],
    "thong-tu-ho-ca-nhan-kinh-doanh": ["ho kinh doanh", "ca nhan kinh doanh"],
    "thong-tu-mst-dang-ky-thue": ["dang ky thue", "ma so thue", "mst"],
    "hoc-va-dao-tao-ke-toan": ["hoc ke toan", "dao tao ke toan", "khoa hoc ke toan"],
    "kinh-nghiem-phong-van-xin-viec": ["xin viec", "phong van", "cv xin viec"],
    "mo-ta-cong-viec-ke-toan": ["cong viec cua", "mo ta cong viec", "ke toan tong hop", "ke toan kho"],
    "hoc-nghe-va-thuc-tap": ["thuc tap", "hoc nghe"],
    "kinh-nghiem-quyet-toan-thue": ["quyet toan thue", "kinh nghiem quyet toan"],
    "excel-cong-cu-khac": ["excel", "ham excel", "subtotal", "file excel"],
    "luat-thue-tndn": ["luat", "bo luat", "qh", "quoc hoi"],
    "qd-cong-doan-doan-phi": ["nghi quyet", "quyet dinh"],
}


def fold(text: str) -> str:
    text = (text or "").lower()
    text = "".join(ch for ch in unicodedata.normalize("NFD", text) if unicodedata.category(ch) != "Mn")
    return text.replace("đ", "d")


def tokenize(text: str) -> List[str]:
    tokens = re.findall(r"[a-z0-9]+", fold(text))
    return [tok for tok in tokens if len(tok) >= 3 and tok not in STOP_WORDS]


def build_taxonomy_maps(taxonomy: Dict) -> Tuple[Dict[str, Dict], Dict[str, str]]:
    nodes: Dict[str, Dict] = {}
    parents: Dict[str, str] = {}
    stack: List[Tuple[Dict, str]] = []

    for root in taxonomy.get("roots", []):
        key = root.get("key")
        if key:
            nodes[key] = root
        for child in root.get("children") or []:
            stack.append((child, key))

    while stack:
        node, parent_key = stack.pop()
        key = node.get("key")
        if not key:
            continue
        nodes[key] = node
        parents[key] = parent_key
        for child in node.get("children") or []:
            stack.append((child, key))
    return nodes, parents


def leaf_descendants(node: Dict) -> List[Dict]:
    children = node.get("children") or []
    if not children:
        return [node]
    leaves: List[Dict] = []
    for child in children:
        leaves.extend(leaf_descendants(child))
    return leaves


def path_to_root(key: str, nodes: Dict[str, Dict], parents: Dict[str, str]) -> List[Dict]:
    out: List[Dict] = []
    cur = key
    while cur and cur in nodes:
        out.append(nodes[cur])
        cur = parents.get(cur) or ""
    return list(reversed(out))


def build_candidate_token_map(
    lv2_key: str,
    leaf_keys: List[str],
    nodes: Dict[str, Dict],
    parents: Dict[str, str],
) -> Dict[str, set]:
    out: Dict[str, set] = {}
    for leaf_key in leaf_keys:
        node = nodes[leaf_key]
        tokens = set(tokenize(leaf_key.replace("-", " ")))
        tokens.update(tokenize(node.get("label") or ""))
        # Thêm tokens từ path (trừ root + lv2 để tăng khả năng phân biệt leaf)
        path_nodes = path_to_root(leaf_key, nodes, parents)
        path_keys = [n.get("key") for n in path_nodes]
        for n in path_nodes:
            key = n.get("key") or ""
            if key in {"thu-vien", "ban-tin", lv2_key}:
                continue
            tokens.update(tokenize(n.get("label") or ""))
            tokens.update(tokenize(key.replace("-", " ")))
        for phrase in HINTS.get(leaf_key, []):
            tokens.update(tokenize(phrase))
        out[leaf_key] = {tok for tok in tokens if tok}
    return out


def score_candidate(article_tokens: set, article_text_folded: str, candidate_key: str, candidate_tokens: set) -> int:
    overlap = len(article_tokens & candidate_tokens)
    bonus = 0
    for phrase in HINTS.get(candidate_key, []):
        if fold(phrase) in article_text_folded:
            bonus += 2
    return overlap + bonus


def confidence_from_scores(top: int, second: int, candidate_count: int) -> Tuple[str, str]:
    if candidate_count == 1:
        return "auto-single-leaf", "lv2 chỉ có 1 leaf descendant"
    if top <= 0:
        return "none", "không match token/hint"
    if top >= 6 and top - second >= 2:
        return "high", f"top {top}, cách biệt {top-second}"
    if top >= 4 and top - second >= 1:
        return "high", f"top {top}, cách biệt {top-second}"
    if top >= 3 and top > second:
        return "medium", f"top {top}, lựa chọn 2 = {second}"
    if top >= 2 and top > second:
        return "medium", f"top {top}, lựa chọn 2 = {second}"
    return "low", f"top {top}, sát lựa chọn 2 = {second}"


def main() -> None:
    articles = json.loads(ARTICLES_PATH.read_text(encoding="utf-8"))
    taxonomy = json.loads(TAXONOMY_PATH.read_text(encoding="utf-8"))
    import_log = json.loads(IMPORT_LOG_PATH.read_text(encoding="utf-8"))

    imported_hrefs = {item["target_path"] for batch in import_log.get("batches", []) for item in batch.get("imported", [])}
    imported_rows = [a for a in articles if a.get("href") in imported_hrefs]

    nodes, parents = build_taxonomy_maps(taxonomy)
    lv2_to_leaves: Dict[str, List[str]] = {}
    for key, node in nodes.items():
        # Chỉ map các node có child.
        if not (node.get("children") or []):
            continue
        leaves = [leaf.get("key") for leaf in leaf_descendants(node) if leaf.get("key")]
        if leaves:
            lv2_to_leaves[key] = leaves

    targets = [
        a
        for a in imported_rows
        if a.get("section") == "thu-vien"
        and not (a.get("topicLv3Key") or "").strip()
        and (a.get("topicLv2Key") or "").strip()
    ]

    records: List[Dict] = []
    for art in targets:
        href = art["href"]
        title = art.get("title") or ""
        lv2_key = art.get("topicLv2Key") or ""
        lv2_label = art.get("topicLv2Label") or ""
        leaves = lv2_to_leaves.get(lv2_key, [])

        base = {
            "href": href,
            "title": title,
            "topicLv1Key": art.get("topicLv1Key") or "",
            "topicLv1Label": art.get("topicLv1Label") or "",
            "topicLv2Key": lv2_key,
            "topicLv2Label": lv2_label,
            "candidateCount": len(leaves),
            "suggestedTopicLv3Key": "",
            "suggestedTopicLv3Label": "",
            "confidence": "none",
            "score": 0,
            "secondScore": 0,
            "rationale": "",
            "topCandidates": [],
        }

        if not leaves:
            base["confidence"] = "no-leaf-under-lv2"
            base["rationale"] = "lv2 không tìm thấy leaf descendant trong taxonomy"
            records.append(base)
            continue

        if len(leaves) == 1:
            only = leaves[0]
            base["suggestedTopicLv3Key"] = only
            base["suggestedTopicLv3Label"] = nodes[only].get("label") or ""
            base["confidence"] = "auto-single-leaf"
            base["score"] = 999
            base["secondScore"] = 0
            base["rationale"] = "lv2 chỉ có 1 leaf descendant"
            base["topCandidates"] = [{"key": only, "label": base["suggestedTopicLv3Label"], "score": 999}]
            records.append(base)
            continue

        article_text = " ".join(
            [
                title,
                art.get("excerpt") or "",
                " ".join(art.get("tags") or []),
                Path(href).name.replace(".html", "").replace("-", " "),
            ]
        )
        article_fold = fold(article_text)
        article_tokens = set(tokenize(article_text))

        token_map = build_candidate_token_map(lv2_key, leaves, nodes, parents)
        scored = []
        for leaf_key in leaves:
            sc = score_candidate(article_tokens, article_fold, leaf_key, token_map.get(leaf_key, set()))
            scored.append((sc, leaf_key))
        scored.sort(key=lambda x: x[0], reverse=True)

        top_score, top_key = scored[0]
        second_score = scored[1][0] if len(scored) > 1 else 0
        conf, reason = confidence_from_scores(top_score, second_score, len(leaves))

        base["suggestedTopicLv3Key"] = top_key
        base["suggestedTopicLv3Label"] = nodes[top_key].get("label") or ""
        base["confidence"] = conf
        base["score"] = top_score
        base["secondScore"] = second_score
        base["rationale"] = reason
        base["topCandidates"] = [
            {"key": key, "label": nodes[key].get("label") or "", "score": score}
            for score, key in scored[:5]
        ]
        records.append(base)

    confidence_counter = Counter(r["confidence"] for r in records)
    by_lv2_conf = defaultdict(Counter)
    for row in records:
        by_lv2_conf[row["topicLv2Key"]][row["confidence"]] += 1

    auto_rows = [r for r in records if r["confidence"] in {"auto-single-leaf", "high"}]
    review_rows = [r for r in records if r["confidence"] in {"medium", "low", "none", "no-leaf-under-lv2"}]

    out_payload = {
        "generatedAt": datetime.now().isoformat(),
        "scope": {
            "imported745Total": len(imported_rows),
            "targetsWithoutLv3ThuVien": len(targets),
        },
        "summary": {
            "confidence": dict(confidence_counter),
            "autoCandidates": len(auto_rows),
            "reviewCandidates": len(review_rows),
        },
        "records": records,
    }
    OUT_JSON_PATH.write_text(json.dumps(out_payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    OUT_AUTO_JSON_PATH.write_text(
        json.dumps(
            {
                "generatedAt": datetime.now().isoformat(),
                "count": len(auto_rows),
                "records": auto_rows,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )

    lines = [
        "# Chặng 6 - Dry-run mới (leaf descendant) cho phần LV3 còn trống",
        "",
        f"- Thời gian chạy: `{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`",
        f"- Targets (Thu viện, imported, lv3 trống): **{len(targets)}**",
        f"- Auto candidates (high + auto-single-leaf): **{len(auto_rows)}**",
        f"- Review candidates: **{len(review_rows)}**",
        "",
        "## Phân bố confidence",
        "",
    ]
    for key, count in confidence_counter.items():
        lines.append(f"- `{key}`: **{count}**")

    lines += ["", "## Top LV2 theo confidence", ""]
    for lv2_key, counter in sorted(by_lv2_conf.items(), key=lambda item: sum(item[1].values()), reverse=True):
        parts = ", ".join(f"{k}:{v}" for k, v in counter.items())
        lines.append(f"- `{lv2_key}` → {parts}")

    lines += [
        "",
        "## Mẫu 30 auto candidates",
        "",
        "| # | href | lv2 | gợi ý lv3 | confidence | score | rationale |",
        "|---:|---|---|---|---|---:|---|",
    ]
    for i, row in enumerate(auto_rows[:30], 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['suggestedTopicLv3Key']}` | "
            f"`{row['confidence']}` | {row['score']} | {row['rationale']} |"
        )

    lines += [
        "",
        "## Mẫu 30 review candidates",
        "",
        "| # | href | lv2 | gợi ý lv3 | confidence | score | second | rationale |",
        "|---:|---|---|---|---|---:|---:|---|",
    ]
    for i, row in enumerate(review_rows[:30], 1):
        lines.append(
            f"| {i} | `{row['href']}` | `{row['topicLv2Key']}` | `{row['suggestedTopicLv3Key'] or '-'}` | "
            f"`{row['confidence']}` | {row['score']} | {row['secondScore']} | {row['rationale']} |"
        )

    OUT_MD_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(
        json.dumps(
            {
                "targets": len(targets),
                "autoCandidates": len(auto_rows),
                "reviewCandidates": len(review_rows),
                "confidence": dict(confidence_counter),
                "outJson": str(OUT_JSON_PATH.relative_to(ROOT)),
                "outMd": str(OUT_MD_PATH.relative_to(ROOT)),
                "autoJson": str(OUT_AUTO_JSON_PATH.relative_to(ROOT)),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
