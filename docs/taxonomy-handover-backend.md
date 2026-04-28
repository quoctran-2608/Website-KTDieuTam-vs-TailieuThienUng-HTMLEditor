# Taxonomy Handover for Backend

- Updated: `2026-04-28`
- Scope: kết nối taxonomy frontend hiện tại với backend `Cloudflare Workers + Supabase`

---

## 1) Mục tiêu backend

1. Lưu taxonomy + article metadata làm nguồn sự thật API.
2. Cho phép query theo `section/kind/lv1/lv2/lv3`.
3. Đảm bảo output API khớp dữ liệu static hiện tại (`data/articles.json`, `content-index.js`).
4. Giữ chế độ freeze: không cho toggle tự động.

---

## 2) Data model đề xuất (Supabase)

## 2.1 Table `articles`

```sql
create table if not exists articles (
  id bigserial primary key,
  href text unique not null,
  title text not null,
  excerpt text,
  image text,
  section text not null,              -- thu-vien | ban-tin
  section_label text,
  section_href text,
  library_kind_key text,              -- huong-dan | bieu-mau | cong-cu | van-ban | ''
  library_kind_label text,
  card_badge_label text,
  topic_lv1_key text,
  topic_lv1_label text,
  topic_lv2_key text,
  topic_lv2_label text,
  topic_lv3_key text,
  topic_lv3_label text,
  card_topic_label text,
  publish_date date,
  modified_date timestamptz,
  author_name text,
  author_type text,
  tags jsonb default '[]'::jsonb,
  classification_reasons jsonb default '{}'::jsonb,
  updated_at timestamptz default now()
);
```

## 2.2 Table `taxonomy_keys` (optional nhưng nên có)

```sql
create table if not exists taxonomy_keys (
  id bigserial primary key,
  level text not null,                -- lv1 | lv2 | lv3 | kind
  key text not null,
  label text not null,
  active boolean default true,
  unique(level, key)
);
```

## 2.3 Index quan trọng

```sql
create index if not exists idx_articles_section on articles(section);
create index if not exists idx_articles_kind on articles(library_kind_key);
create index if not exists idx_articles_lv1 on articles(topic_lv1_key);
create index if not exists idx_articles_lv2 on articles(topic_lv2_key);
create index if not exists idx_articles_lv3 on articles(topic_lv3_key);
create index if not exists idx_articles_section_kind_lv1_lv2_lv3
  on articles(section, library_kind_key, topic_lv1_key, topic_lv2_key, topic_lv3_key);
```

---

## 3) Ingestion flow (từ static sang DB)

1. Read `data/articles.json`.
2. Upsert theo `href`.
3. Upsert taxonomy key/label vào `taxonomy_keys`.
4. Log run metadata (rows inserted/updated/skipped).

Pseudo CLI:

```bash
node tools/sync-articles-to-supabase.mjs \
  --input data/articles.json \
  --dry-run=false
```

---

## 4) API contract (Cloudflare Worker)

## 4.1 `GET /api/articles`

Query params:

- `section`
- `kind` (`library_kind_key`)
- `lv1`, `lv2`, `lv3`
- `q` (search title/excerpt)
- `page`, `page_size`

Response:

```json
{
  "page": 1,
  "page_size": 20,
  "total": 2694,
  "items": [
    {
      "href": "thu-vien/....html",
      "title": "...",
      "section": "thu-vien",
      "library_kind_key": "bieu-mau",
      "topic_lv1_key": "thue",
      "topic_lv2_key": "mau-bieu-thue",
      "topic_lv3_key": "mau-thue-gtgt-hoa-don"
    }
  ]
}
```

## 4.2 `GET /api/articles/:slug`

- map `slug` -> `href`
- trả về full metadata dùng render detail page.

## 4.3 `GET /api/taxonomy`

- trả tree (`kind -> lv1 -> lv2 -> lv3`) có count.

---

## 5) Freeze guard ở backend

Tạo Worker endpoint nội bộ: `POST /internal/reclassify/apply`

Rules:

1. Chặn nếu payload chứa action toggle.
2. Chặn nếu target key không active trong `taxonomy_keys`.
3. Chặn nếu preflight không có `candidate_count`, `noop_count`.
4. Chỉ cho phép khi có `x-admin-token`.

---

## 6) RLS / quyền truy cập

- Public: chỉ `select` từ `articles` và `taxonomy_keys`.
- Admin/service role: mới được `insert/update`.
- Worker dùng service key trong secret env, không expose lên client.

---

## 7) Mapping từ frontend hiện tại

Nguồn field chính từ static:

- `section`, `sectionLabel`, `sectionHref`
- `libraryKindKey`, `libraryKindLabel`
- `topicLv1Key`, `topicLv1Label`
- `topicLv2Key`, `topicLv2Label`
- `topicLv3Key`, `topicLv3Label`
- `cardBadgeLabel`, `cardTopicLabel`
- `classificationReasons`

Đây là bộ field tối thiểu để frontend prototype chuyển sang API mà không đổi UI logic.

---

## 8) Cutover plan

1. Sync DB từ snapshot hiện tại.
2. Worker trả API read-only.
3. Frontend đổi source: static JSON -> `/api/articles`.
4. So sánh kết quả page list/detail với static hiện tại.
5. Bật freeze guard endpoint nội bộ.

