# Clariq WooCommerce Plugin

WordPress plugin connecting WooCommerce stores to [Clariq](https://clariq.com) analytics and LLM tools via the Model Context Protocol (MCP).

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

To create a distributable plugin ZIP:
```bash
npm run build --prefix admin-ui
zip -r wc-analytics-mcp.zip . -x ".*" -x "docker/*" -x "tests/*" -x "admin-ui/node_modules/*" -x "vendor/*"
```
