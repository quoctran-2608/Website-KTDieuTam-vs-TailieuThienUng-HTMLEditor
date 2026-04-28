# Imported internal link repair

## Root cause

- `tools/import_stage1_20.py` built `all_lookup` from legacy source files first.
- Manifest rows contained the real `target_path`, but the code only appended manifest rows when a source key was missing.
- Because every legacy `.htm` source already existed in `SRC_ROOT`, manifest metadata was shadowed by stub metadata with no `target_path`.
- Result: links to articles outside the current import batch were rewritten to hub search URLs such as `../thu-vien.html?q=...`; links to legacy URLs missing from the manifest fell back to `../index.html`.

## Code fix

- `tools/import_stage1_20.py`: manifest lookup now overrides source-file stubs with `all_lookup.update(manifest_lookup)`.
- Added `tools/repair_imported_internal_links.py` for repeatable post-import repairs:
  - exact slug repair for `thu-vien.html?q=` / `ban-tin.html?q=`;
  - source-manifest repair for `index.html` fallbacks;
  - semantic/manual alias repair for removed legacy targets;
  - moved-path repair after taxonomy reclassification.

## Applied repairs

- Initial fallback repair: `1,051` links.
  - `hub_q_exact_slug`: `752`
  - `index_source_href_manifest`: `222`
  - `index_semantic`: `77`
- Manual correction after review: `2` links.
  - `Thủ tục hoàn thuế TNCN` now points to `../thu-vien/thu-tuc-hoan-thue-thu-nhap-ca-nhan-online-moi-nhat-2025.html`.
- Moved direct-path repair after taxonomy changes: `6` links.
- Article HTML files changed: `391`.

## Final verification

- Imported current files checked: `745`
- Remaining `thu-vien.html?q=` / `ban-tin.html?q=` fallback links: `0`
- Direct article links checked: `1,259`
- Missing direct article targets: `0`
- Remaining `index.html` links are homepage/brand links such as `Công ty TNHH Tư vấn & Đào tạo Diệu Tâm`.

## Verified examples

- `Hướng dẫn đăng ký người phụ thuộc` → `../thu-vien/thu-tuc-dang-ky-nguoi-phu-thuoc-giam-tru-gia-canh.html`
- `Cách đăng ký mã số thuế cá nhân` → `../thu-vien/cach-dang-ky-ma-so-thue-ca-nhan-qua-mang-tren-tncn-2-5.html`
- `Thủ tục hoàn thuế TNCN` → `../thu-vien/thu-tuc-hoan-thue-thu-nhap-ca-nhan-online-moi-nhat-2025.html`
