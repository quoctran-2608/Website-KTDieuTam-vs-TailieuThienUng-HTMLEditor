# Admin Editor PHP (MVP v1)

Thư mục này chứa code admin panel cho luồng:

- đăng nhập nội bộ
- xem list bài `Thư viện / Bản tin`
- sửa từng bài theo contract v1
- publish an toàn với backup + rollback

## Trạng thái hiện tại

Đang ở **Phase 4**:

- đã hoàn thiện auth shell:
  - `admin/login.php`
  - `admin/dashboard.php`
  - `admin/logout.php`
  - `admin/includes/*`
- đã mở module list bài:
  - `admin/articles.php`
  - `admin/includes/article_index.php`
  - `admin/article.php`
- đã có parser-safe module:
  - `admin/includes/article_parser.php`
  - parser audit toàn kho + parser detail từng bài tại `admin/article.php`
- đã mở draft workflow:
  - `admin/includes/article_draft.php`
  - form edit tại `admin/article.php`
  - save draft + before/after diff + preview render
- UI admin đẹp, responsive, tối ưu trải nghiệm lọc và rà soát bài
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
  articles.php
  article.php
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
    article_index.php
    article_parser.php
    article_draft.php
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

## Healthcheck Phase 4

```bash
php admin/includes/healthcheck.php
```

Script này kiểm tra:

- storage init
- seed user
- auth helper load
- source `data/articles.json`
- trạng thái index cache bài viết
- parser audit safe/fail
- draft storage + draft payload
- trạng thái cơ bản để bắt đầu Phase 5

## Ghi chú môi trường hiện tại

- Workspace hiện tại chưa có `php` CLI, nên chưa chạy được lint/runtime trực tiếp tại đây.
- Mã đã được viết theo PHP 8+ syntax, có healthcheck để bạn tự verify nhanh khi chạy ở máy có PHP.
