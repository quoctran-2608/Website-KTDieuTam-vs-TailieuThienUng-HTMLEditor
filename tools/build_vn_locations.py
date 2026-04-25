#!/usr/bin/env python3
from __future__ import annotations

import concurrent.futures
import json
import re
import unicodedata
import urllib.request
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT / "data" / "locations"
DATA_FILE = DATA_DIR / "vn-location-options.json"
JS_FILE = ROOT / "assets" / "js" / "vn-location-data.js"

API_BASE = "https://provinces.open-api.vn/api"
V1_URL = f"{API_BASE}/v1/?depth=2"
V2_URL = f"{API_BASE}/v2/?depth=2"
V2_LEGACY_URL = f"{API_BASE}/v2/w/{{code}}/to-legacies/"

PROVINCE_LABEL_OVERRIDES = {
    "Thành phố Hà Nội": "Hà Nội",
    "Thành phố Hồ Chí Minh": "TP.HCM",
    "Thành phố Hải Phòng": "Hải Phòng",
    "Thành phố Huế": "Huế",
    "Thành phố Đà Nẵng": "Đà Nẵng",
    "Thành phố Cần Thơ": "Cần Thơ",
}

PROVINCE_KEY_OVERRIDES = {
    "Thành phố Hà Nội": "ha-noi",
    "Thành phố Hồ Chí Minh": "tp-hcm",
}

PROVINCE_ALIASES = {
    "Thành phố Hồ Chí Minh": ["TP.HCM", "TP HCM", "TPHCM", "Sài Gòn", "Ho Chi Minh", "Hồ Chí Minh"],
    "Thành phố Hà Nội": ["Hà Nội", "Ha Noi"],
}


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "KeToanDieuTam-location-builder/1.0"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def slugify(value: str) -> str:
    text = unicodedata.normalize("NFD", value)
    text = "".join(ch for ch in text if unicodedata.category(ch) != "Mn")
    text = text.replace("đ", "d").replace("Đ", "D").lower()
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return re.sub(r"-{2,}", "-", text)


def short_province_name(name: str) -> str:
    if name in PROVINCE_LABEL_OVERRIDES:
        return PROVINCE_LABEL_OVERRIDES[name]
    return re.sub(r"^(Tỉnh|Thành phố)\s+", "", name).strip()


def short_area_name(name: str) -> str:
    if name.startswith("Thành phố "):
        return "TP. " + name.removeprefix("Thành phố ").strip()
    if name.startswith("Thị xã "):
        return "TX. " + name.removeprefix("Thị xã ").strip()
    return name.strip()


def bare_area_name(name: str) -> str:
    return re.sub(r"^(Quận|Huyện|Thành phố|Thị xã)\s+", "", name).strip()


def legacy_suffix(legacy_province_name: str, current_province_name: str) -> str:
    old_label = short_province_name(legacy_province_name)
    current_label = short_province_name(current_province_name)
    if slugify(old_label) == slugify(current_label):
        return ""
    return f"{old_label} cũ"


def normalize_legacy_rows(v1_rows: list[dict[str, Any]]) -> tuple[dict[int, dict[str, Any]], dict[int, dict[str, Any]]]:
    provinces: dict[int, dict[str, Any]] = {}
    districts: dict[int, dict[str, Any]] = {}
    for province in v1_rows:
        pcode = int(province["code"])
        provinces[pcode] = province
        for district in province.get("districts", []):
            districts[int(district["code"])] = district
    return provinces, districts


def fetch_legacy_wards(ward_code: int) -> list[dict[str, Any]]:
    try:
        return read_json(V2_LEGACY_URL.format(code=ward_code))
    except Exception:
        return []


def build_payload() -> dict[str, Any]:
    v1_rows = read_json(V1_URL)
    v2_rows = read_json(V2_URL)
    legacy_provinces, legacy_districts = normalize_legacy_rows(v1_rows)
    ward_items = [
        (int(province["code"]), province["name"], int(ward["code"]))
        for province in v2_rows
        for ward in province.get("wards", [])
    ]

    province_area_codes: dict[int, set[int]] = defaultdict(set)
    area_ward_aliases: dict[tuple[int, int], set[str]] = defaultdict(set)

    def resolve(item: tuple[int, str, int]) -> tuple[int, list[dict[str, Any]]]:
        province_code, _, ward_code = item
        return province_code, fetch_legacy_wards(ward_code)

    with concurrent.futures.ThreadPoolExecutor(max_workers=24) as executor:
        for province_code, legacy_wards in executor.map(resolve, ward_items):
            for legacy_ward in legacy_wards:
                district_code = int(legacy_ward.get("district_code") or 0)
                if district_code not in legacy_districts:
                    continue
                province_area_codes[province_code].add(district_code)
                alias_key = (province_code, district_code)
                for value in [
                    legacy_ward.get("name", ""),
                    legacy_ward.get("codename", "").replace("_", " "),
                ]:
                    if value:
                        area_ward_aliases[alias_key].add(str(value).strip())

    province_rows: list[dict[str, Any]] = []
    area_assignment_count = 0

    for province in v2_rows:
        province_name = province["name"]
        province_code = int(province["code"])
        province_label = short_province_name(province_name)
        province_key = PROVINCE_KEY_OVERRIDES.get(province_name) or slugify(province_label)
        province_aliases = [province_name, province_label] + PROVINCE_ALIASES.get(province_name, [])

        area_rows: list[dict[str, Any]] = []
        for district_code in sorted(province_area_codes.get(province_code, set())):
            district = legacy_districts[district_code]
            legacy_province = legacy_provinces[int(district["province_code"])]
            area_name = district["name"]
            area_label = short_area_name(area_name)
            suffix = legacy_suffix(legacy_province["name"], province_name)
            display_name = f"{area_label} ({suffix})" if suffix else area_label
            search_aliases = sorted(
                {
                    area_name,
                    area_label,
                    bare_area_name(area_name),
                    area_name.replace("Thành phố ", "TP. "),
                    area_name.replace("Thị xã ", "TX. "),
                    *area_ward_aliases.get((province_code, district_code), set()),
                }
            )
            area_rows.append(
                {
                    "key": f"{slugify(area_name)}-{district_code}",
                    "legacyCode": district_code,
                    "name": area_label,
                    "fullName": area_name,
                    "displayName": display_name,
                    "type": district.get("division_type", ""),
                    "legacyProvinceCode": int(district["province_code"]),
                    "legacyProvinceName": legacy_province["name"],
                    "aliases": search_aliases,
                }
            )

        area_assignment_count += len(area_rows)
        province_rows.append(
            {
                "key": province_key,
                "code": province_code,
                "name": province_label,
                "fullName": province_name,
                "type": province.get("division_type", ""),
                "aliases": sorted(set(province_aliases)),
                "areas": area_rows,
            }
        )

    counts = {
        "provinces": len(province_rows),
        "areas": area_assignment_count,
        "sourceLegacyProvinces": len(v1_rows),
        "sourceLegacyDistricts": len(legacy_districts),
        "sourceCurrentWards": len(ward_items),
    }

    return {
        "version": "vn-admin-2025-v2-with-legacy-district-aliases",
        "generatedAt": date.today().isoformat(),
        "source": {
            "currentApi": V2_URL,
            "legacyApi": V1_URL,
            "legacyMappingApi": f"{API_BASE}/v2/w/{{code}}/to-legacies/",
            "note": "Dữ liệu tỉnh/xã hiện hành v2, quận/huyện là alias trước 2025 để phục vụ UX tuyển dụng.",
        },
        "counts": counts,
        "provinces": province_rows,
    }


def write_outputs(payload: dict[str, Any]) -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    JS_FILE.parent.mkdir(parents=True, exist_ok=True)
    DATA_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Frontend only needs selectable province/area labels. Keep the heavier
    # legacy ward aliases in the JSON source for build-time inference.
    frontend_payload = dict(payload)
    frontend_payload["provinces"] = []
    for province in payload["provinces"]:
        frontend_province = dict(province)
        frontend_province["areas"] = []
        for area in province.get("areas", []):
            frontend_area = dict(area)
            frontend_area["aliases"] = sorted(
                {
                    bare_area_name(str(area.get("fullName", ""))),
                    re.sub(r"\s*\(.+?\)\s*$", "", str(area.get("displayName", ""))).replace("TP. ", "").replace("TX. ", "").strip(),
                }
                - {""}
            )
            frontend_province["areas"].append(frontend_area)
        frontend_payload["provinces"].append(frontend_province)

    js = (
        "window.VN_LOCATION_DATA = "
        + json.dumps(frontend_payload, ensure_ascii=False, separators=(",", ":"))
        + ";\n"
    )
    JS_FILE.write_text(js, encoding="utf-8")


def main() -> None:
    payload = build_payload()
    write_outputs(payload)
    print(
        json.dumps(
            {
                "data_file": str(DATA_FILE.relative_to(ROOT)),
                "js_file": str(JS_FILE.relative_to(ROOT)),
                "counts": payload["counts"],
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
