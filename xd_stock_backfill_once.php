<?php
/**
 * XD stock backfill — safe preview by default, one-time repair by confirmation.
 * Place next to xd_importStock.php in the WordPress root.
 * PREVIEW: php xd_stock_backfill_once.php [--sku=00ITEM_OR_MODEL] [--limit=500]
 * APPLY:   php xd_stock_backfill_once.php --apply --confirm=YES [--sku=00ITEM_OR_MODEL] [--limit=500]
 * Optional: --file=/absolute/path/to/a/fresh/Xindao.Stock.xlsx
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
fwrite(STDERR, '[XD Backfill] Starting stock backfill (DRY RUN by default; APPLY requires --apply --confirm=YES)...' . PHP_EOL);
define('XD_STOCK_BACKFILL_MODE', true);
require __DIR__ . '/xd_importStock.php';
