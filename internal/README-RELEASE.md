# Release ZIP (GitHub Actions)

This repository publishes a Magento Marketplace-ready ZIP as a GitHub Release asset.

## What the ZIP contains

The ZIP root must contain:

- `composer.json`
- `registration.php`
- `etc/module.xml`
- module source folders (e.g., `Api/`, `Block/`, `Model/`, `view/`, etc.)

No top-level repo directory inside the ZIP.  
No `app/code/...` prefix inside the ZIP.

## How to create a release

1. Ensure `composer.json` version matches the intended tag (e.g. `1.1.0`).
2. Create and push a tag in the form `vX.Y.Z`:

```bash
git tag v1.1.0
git push origin v1.1.0
```

3. GitHub Actions will:

- build `paypercut-magento2-1.1.0.zip`
- create a GitHub Release for `v1.1.0`
- attach the ZIP to the release

## Workflow

See: `.github/workflows/release-zip.yml`

The workflow:

- checks that `composer.json`, `registration.php`, `etc/module.xml` exist
- zips the module root (no extra directory)
- excludes common junk (`.git/`, `vendor/`, `node_modules/`, IDE files, generated/static dirs)
- uploads the ZIP as a release asset

## Notes

- Marketplace validation is strict; keep `composer.json` minimal and avoid custom `repositories`.
- PHP requirement should match Marketplace’s current PHP matrix (e.g. `~8.4.0`).
