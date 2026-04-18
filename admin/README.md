# Admin Editor PHP (MVP v1)

Thư mục này chứa code admin panel cho luồng:

- đăng nhập nội bộ
- xem list bài `Thư viện / Bản tin`
- sửa từng bài theo contract v1
- publish an toàn với backup + rollback

## Trạng thái hiện tại

Đang ở **Phase 1**:

- đã hoàn thiện auth shell:
  - `admin/login.php`
  - `admin/dashboard.php`
  - `admin/logout.php`
  - `admin/includes/*`
- đã có UI admin đẹp, responsive, tập trung UX cho vận hành nội bộ
- đã chốt scope + checklist ở:
  - `docs/editor-php/mvp-scope-v1.md`
  - `docs/editor-php/phase-checklists.md`

## Cấu trúc thư mục

```text
admin/
  index.php
  login.php
  dashboard.php
  logout.php
  assets/
    css/
      admin.css
    js/
      admin.js
  includes/
    bootstrap.php
    helpers.php
    storage.php
    auth.php
    layout.php
    healthcheck.php
  storage/
```

## Chạy local (khuyến nghị)

```bash
php -S 127.0.0.1:8080 -t .
```

Truy cập:

- `http://127.0.0.1:8080/admin/`
- login mặc định dev: `admin / admin123`

## Healthcheck Phase 1

```bash
php admin/includes/healthcheck.php
```

Script này kiểm tra:

- storage init
- seed user
- auth helper load
- trạng thái cơ bản để bắt đầu Phase 2

## Ghi chú môi trường hiện tại

- Workspace hiện tại chưa có `php` CLI, nên chưa chạy được lint/runtime trực tiếp tại đây.
- Mã đã được viết theo PHP 8+ syntax, có healthcheck để bạn tự verify nhanh khi chạy ở máy có PHP.
