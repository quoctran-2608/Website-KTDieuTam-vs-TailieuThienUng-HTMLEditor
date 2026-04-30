# Release Hygiene Summary

## Root artefact cleanup

Archived 5 extensionless root artefacts that should not be deployed as public URLs:

- `Công`
- `DN`
- `Doanh`
- `HTTK`
- `Phần`

Archive:

- `.m/release-hygiene/20260430T1205-release-hygiene/root-extensionless-artefacts`

Manifest:

- `docs/release-hygiene-root-artefacts.json`

## Verification

Run:

```bash
python3 tools/verify_release_hygiene.py
python3 tools/verify_root_url_migration.py
python3 tools/verify_admin_public_taxonomy.py
```

Latest result:

- Release hygiene: OK
- Root URL migration: OK
- Admin/public taxonomy: OK
