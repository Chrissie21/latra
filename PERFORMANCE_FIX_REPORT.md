# Fix Report: 524 Gateway Timeout (Dashboard Performance)

## Problem summary
The system returned HTTP 524 (Cloudflare Gateway Timeout) on initial access. Root cause was the dashboard loading unbounded/expensive queries against large tables (e.g. `message_log` ~404K, `validation_errors` ~132K) without proper indexes and with repeated `IN (SELECT id FROM vehicles WHERE ...)` subqueries.

## Changes made

### 1) Database indexes (biggest win)
Added covering/performance indexes on high-traffic tables:
- message_log: (created_at), (vehicle_id, created_at), (latra_status, created_at), (vehicle_id, latra_status, created_at)
- push_log: (created_at), (vehicle_id, created_at), (response_status, created_at), (vehicle_id, response_status, created_at)
- outbox: (created_at), (vehicle_id, created_at), (status, created_at), (vehicle_id, status, created_at)
- wialon_events: (created_at), (vehicle_id, created_at), (status, created_at), (vehicle_id, status, created_at)
- validation_errors: (created_at), (vehicle_id, created_at)
- vehicles: (last_message_time), (lat,lng)

These allow index scans for time-range counts/filters and for `ORDER BY created_at DESC LIMIT ...`.

### 2) Caching layer
Created `includes/DashboardCache.php` (file-based cache in temp dir, TTL 60s). `index.php` now caches the full dashboard payload per filter set (`area|status|activity`) and serves from cache on subsequent hits.

### 3) Dashboard query optimizations (`index.php`)
- Added `DashboardCache` include.
- `periodTotals()` now bounds "older" to last 30 days (avoids full-table counts) and accepts params for prepared statements.
- Precompute vehicle scope: fetch matching vehicle IDs once into `$vidList`/`$vidParams` (replaced `IN (SELECT id FROM vehicles WHERE $vScope)` with `IN $vidList` across counts/feeds).
- Updated scoped queries (pipeline totals, down equipment, locations, feeds) to use the precomputed ID list.
- Wrapped main computation in cache read/write (TTL 60s).

### 4) Verification
- Syntax check: `php -l index.php` passes.
- Local load test: `/index.php` returns HTTP 200 in ~127ms (vs likely timing out before).

## Expected impact
- Eliminates 524 on first load after warm-up in most cases; cached loads are fast.
- Reduces DB load significantly (fewer full table scans, index-optimized time-range queries).
- Scalable under larger data volumes.

## Short-term (if still behind Cloudflare 100s cap)
If running through Cloudflare Free Tier (100s origin timeout), you can also tune Apache/PHP timeouts (`Timeout`, `max_execution_time`, `memory_limit`) in LAMPP. The above optimizations are the correct long-term fix.

## Files modified
- `/opt/lampp/htdocs/latra-wialon-integration/index.php` (caching, scope precompute, bounded counts)
- `/opt/lampp/htdocs/latra-wialon-integration/includes/DashboardCache.php` (new)

## DB changes
- Multiple `ALTER TABLE ... ADD INDEX IF NOT EXISTS` on affected tables (non-breaking).
