# Clariq WooCommerce Plugin

WordPress plugin connecting WooCommerce stores to [Clariq](https://clariqapp.com) analytics and LLM tools via the Model Context Protocol (MCP).

## Features

- **Dual Connection Modes**:
  - `cloud_sync`: Real-time order hooks and scheduled Action Scheduler delta-sync to the Clariq Cloud Warehouse.
  - `local_bridge`: Privacy-first, HMAC-signed local REST bridge (`/wp-json/mcp-bridge/v1/analytics`) querying WooCommerce data directly.
- **HPOS Compatible**: Fully compatible with WooCommerce High-Performance Order Storage (HPOS).
- **Admin UI**: React-based settings page integrated directly within `WooCommerce > Analytics MCP`.

## Requirements

- PHP 8.1+
- WordPress 6.4+
- WooCommerce 8.0+

## Local Development Setup

### 1. Start Docker Environment
```bash
docker compose up -d
```
- WordPress: `http://localhost:8888` (Credentials: `admin` / `password`)
- MySQL: `localhost:3306`

### 2. Configure Environment
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Add your signing key in `.env`.

### 3. Build Admin UI
```bash
cd admin-ui
npm install
npm run build
```

### 4. Run Unit Tests
```bash
composer install
./vendor/bin/phpunit
```

## Production Packaging

Release artifacts are built from an explicit allowlist rather than from the working tree. They contain the runtime PHP, GPL and WordPress documentation, the admin UI source and build inputs, and the built admin UI assets. They exclude development dependencies, tests, Docker files, environment files, caches, and all other paths. Bundled Instrument Sans, Instrument Serif, and JetBrains Mono fonts retain their SIL Open Font License notices in `admin-ui/src/fonts/`.

```bash
# One-time per checkout: activate the versioned pre-push guard and secret scan.
npm run hooks:enable

# Resolve the locked admin dependencies, build, verify versions, package, and inspect.
npm ci --prefix admin-ui
npm run release:build
```

The resulting deterministic artifacts are `dist/clariq-analytics-mcp-<version>.zip`, the byte-identical `dist/clariq-analytics-mcp.zip`, and their SHA-256 checksums. The archive has one `clariq-analytics-mcp/` root directory and uses normalized timestamps and ordered paths, so repeated packaging of unchanged inputs yields the same archive. Publish the draft GitHub Release to make the stable asset available at `https://github.com/clariqapp/plugin/releases/latest/download/clariq-analytics-mcp.zip`.

Before a push, the hook runs `gitleaks` against the working tree and blocks direct pushes to `main`. To investigate an already-reviewed scanner false positive locally, `CLARIQ_SKIP_SECRET_SCAN=1` skips only that hook scan; it never bypasses CI or the direct-main safeguard. Do not add broad scanner allowlists—scope any exception to the individual fixture that requires it.
