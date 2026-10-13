<?php
/**
 * XD stock sync v2.2 — automatic internal batching — WooCommerce / Xindao, CLI only.
 * Place in the WP root beside wp-load.php and vendor/autoload.php.
 *
 * CRON:    php xd_importStock.php                  (apply fresh supplier feed)
 * PREVIEW: php xd_importStock.php --dry-run
 * TEST:    php xd_importStock.php --dry-run --sku=00MODEL_OR_ITEM_SKU
 * FILE:    php xd_importStock.php --dry-run --file=/absolute/stock.xlsx
 *
 * The backfill wrapper defaults to PREVIEW and requires --apply --confirm=YES.
 * Requires matching XD product importer using cron_files/xd-import.lock.
 * Does not create missing variations or populate prices.
 * Applies an entire feed in ONE command, internally batching variation writes
 * and parent syncs to avoid large uninterrupted write bursts.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// Imunify AI Bot Management is for incoming HTTP bot traffic, not an
// authenticated server-side CLI import. Disable only this bot-limiter for
// these CLI-only scripts; website requests continue to be protected.
if (PHP_SAPI === 'cli' && !defined('IMUNIFY_AI_BOT_PROTECTION')) {
    define('IMUNIFY_AI_BOT_PROTECTION', false);
}

// All XD entrypoints are CLI-only. Show the real fatal error instead of the
// generic WordPress critical-error HTML page. A shutdown reserve also helps
// expose PHP memory exhaustion when PHP cannot otherwise allocate the message.
if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
    define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
}
$xdEmergencyMemory = str_repeat('x', 262144);
register_shutdown_function(static function () use (&$xdEmergencyMemory): void {
    $failure = error_get_last();
    if (!$failure || !in_array($failure['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $xdEmergencyMemory = null;
    $msg = '[XD Stock FATAL] ' . $failure['message'] . ' in ' . $failure['file'] . ':' . $failure['line'] .
        ' | peak_memory_mb=' . round(memory_get_peak_usage(true) / 1048576, 1) .
        ' | memory_limit=' . ini_get('memory_limit') . PHP_EOL;
    @fwrite(STDERR, $msg);
});
// Stock sheets can be substantially larger than the products backfill. Raise
// low CLI-only memory limits, but never lower a higher/unlimited server limit.
$xdStockMemorySetting = (string) ini_get('memory_limit');
if ($xdStockMemorySetting !== '-1' && preg_match('/^(\d+)\s*([KMG]?)B?$/i', $xdStockMemorySetting, $xdMemoryParts)) {
    $xdStockMemoryBytes = (int) $xdMemoryParts[1] * (['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($xdMemoryParts[2])] ?? 1);
    if ($xdStockMemoryBytes < 768 * 1048576) {
        @ini_set('memory_limit', '768M');
    }
}
@ini_set('max_execution_time', '0');
@set_time_limit(0);

$args = getopt('', ['dry-run', 'apply', 'confirm:', 'file:', 'sku:', 'limit:', 'help']);
if (isset($args['help'])) {
    echo "CRON: php xd_importStock.php\n";
    echo "PREVIEW: php xd_stock_backfill_once.php [--file=/full/stock.xlsx] [--sku=00SKU]\n";
    echo "BACKFILL ALL IN ONE STEP: php xd_stock_backfill_once.php --apply --confirm=YES\n";
    echo "BATCH TEST: php xd_stock_backfill_once.php --apply --confirm=YES --limit=500\n";
    echo "INTERNAL CHUNKS: 500 variations per chunk, automatic in full runs\n";
    exit(0);
}

$isBackfill = defined('XD_STOCK_BACKFILL_MODE') && XD_STOCK_BACKFILL_MODE === true;
$preview = $isBackfill || isset($args['dry-run']);
if (isset($args['apply'])) {
    if (!isset($args['confirm']) || $args['confirm'] !== 'YES') {
        fwrite(STDERR, "An explicit manual apply requires --apply --confirm=YES.\n");
        exit(2);
    }
    if (isset($args['dry-run'])) {
        fwrite(STDERR, "Choose either --dry-run or --apply, not both.\n");
        exit(2);
    }
    $preview = false;
}
if ($isBackfill && !$preview && !isset($args['apply'])) {
    fwrite(STDERR, "Backfill requires --apply --confirm=YES.\n");
    exit(2);
}
// The only silent write mode is the argument-free, scheduled cron invocation.
// A manually filtered/file-based/batch run must be explicit about preview vs apply.
if (!$isBackfill && (isset($args['sku']) || isset($args['file']) || isset($args['limit'])) &&
    !isset($args['dry-run']) && !isset($args['apply'])) {
    fwrite(STDERR, "Manual --sku/--file requires --dry-run or --apply --confirm=YES.\n");
    exit(2);
}
// A manual one-time backfill can be capped to a finite amount of write work.
// The ordinary, argument-free 15-minute stock cron remains FULL-CATALOG.
$batchLimit = null;
if (isset($args['limit'])) {
    $rawLimit = (string) $args['limit'];
    if (!preg_match('/^[1-9][0-9]*$/', $rawLimit) || strlen($rawLimit) > 4 || (int) $rawLimit > 1000) {
        fwrite(STDERR, "Invalid --limit. Use a positive integer from 1 to 1000 (recommended 500).\n");
        exit(2);
    }
    $batchLimit = (int) $rawLimit;
}
$skuFilter = trim((string) ($args['sku'] ?? ''));
if ($skuFilter !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/', $skuFilter)) {
    fwrite(STDERR, "Invalid --sku. Supply one exact XD parent, ItemCode or variation SKU.\n");
    exit(2);
}

$root = __DIR__ . DIRECTORY_SEPARATOR;
foreach ([$root . 'wp-load.php', $root . 'vendor/autoload.php'] as $dependency) {
    if (!is_file($dependency)) {
        fwrite(STDERR, "Missing dependency: {$dependency}\n");
        exit(2);
    }
}
fwrite(STDERR, '[XD Stock BOOT] PHP=' . PHP_VERSION . ' | memory_limit=' . ini_get('memory_limit') .
    ' | backfill=' . ((defined('XD_STOCK_BACKFILL_MODE') && XD_STOCK_BACKFILL_MODE) ? 'yes' : 'no') . PHP_EOL);
$xdStockMissingExtensions = array_values(array_filter(['zip', 'xmlreader', 'mbstring'],
    static function (string $extension): bool { return !extension_loaded($extension); }));
if ($xdStockMissingExtensions) {
    fwrite(STDERR, '[XD Stock BOOT] Missing CLI PHP extensions: ' . implode(', ', $xdStockMissingExtensions) . PHP_EOL);
    exit(1);
}
fwrite(STDERR, '[XD Stock BOOT] Loading WordPress...' . PHP_EOL);
require_once $root . 'wp-load.php';
fwrite(STDERR, '[XD Stock BOOT] WordPress loaded; loading Composer...' . PHP_EOL);
require_once $root . 'vendor/autoload.php';
fwrite(STDERR, '[XD Stock BOOT] Composer loaded.' . PHP_EOL);

if (!class_exists('WooCommerce') || !class_exists('WC_Product_Variable')) {
    fwrite(STDERR, "WooCommerce is unavailable.\n");
    exit(1);
}
fwrite(STDERR, '[XD Stock BOOT] WooCommerce ready.' . PHP_EOL);

global $wpdb;
$log = static function (string $message): void {
    $line = '[' . (function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s')) . '] [XD Stock v2] ' . $message;
    echo $line . PHP_EOL;
};

// Master importer, stock cron and both backfills MUST use the same advisory lock.
$lockPath = ABSPATH . 'cron_files/xd-import.lock';
if (!is_dir(dirname($lockPath)) && !wp_mkdir_p(dirname($lockPath))) {
    fwrite(STDERR, "Could not create cron_files.\n");
    exit(1);
}
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('SKIPPED: Another XD import/backfill/stock sync is running.');
    exit($isBackfill ? 1 : 0);
}

// The original feed URL is kept unchanged; stock never comes from the Products workbook.
$stockUrl = 'https://feeds.xindao.com/Feeds/Download/1614-Zzhkx4LFCHFeiMbl3XIBXJN2S9VHSEFrv4zwhIhNXtyhIAa1J3sTv8eb76-d9w4Jb9ZDjicmmCG-TgFHmUHKCuZR/Xindao.V2.Stock-en-gb-C35322.xlsx';
$tmp = null;
try {
    if (isset($args['file'])) {
        $file = realpath((string) $args['file']);
        if ($file === false || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('The --file path is not a readable XLSX.');
        }
        $hours = round((time() - filemtime($file)) / 3600, 1);
        if ($hours > 24) {
            $log('WARNING: Manually provided stock XLSX is ' . $hours . ' hours old.');
        }
    } else {
        $tmp = ABSPATH . 'cron_files/xd-stock-' . getmypid() . '.xlsx';
        $log('Downloading fresh XD Stock XLSX.');
        $context = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]);
        $input = @fopen($stockUrl, 'rb', false, $context);
        if (!$input) {
            throw new RuntimeException('Could not open the supplier stock feed.');
        }
        $output = @fopen($tmp, 'wb');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Could not create a temporary stock XLSX.');
        }
        $bytes = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($bytes === false || $bytes < 1000) {
            throw new RuntimeException('Stock feed download is empty/incomplete.');
        }
        $file = $tmp;
        $log('Stock XLSX downloaded: ' . $bytes . ' bytes.');
    }

    // Reject web error pages before using an XLSX parser.
    $handle = fopen($file, 'rb');
    $magic = fread($handle, 2);
    fclose($handle);
    if ($magic !== 'PK') {
        throw new RuntimeException('Source is not a valid XLSX ZIP container.');
    }

    // Restrict PhpSpreadsheet to ModelCode=B, ItemCode=C, Quantity=E, Upcoming=F.
    if (!class_exists('XdStockReadFilterV2', false)) {
        final class XdStockReadFilterV2 implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool {
                return in_array($columnAddress, ['B', 'C', 'E', 'F'], true);
            }
        }
    }
    $log('XLSX signature OK; opening spreadsheet. memory_mb=' . round(memory_get_usage(true) / 1048576, 1));
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
    $reader->setReadDataOnly(true);
    $reader->setReadFilter(new XdStockReadFilterV2());
    $book = $reader->load($file);
    $sheet = $book->getActiveSheet();
    $log('Spreadsheet loaded. memory_mb=' . round(memory_get_usage(true) / 1048576, 1) .
        '; rows=' . $sheet->getHighestRow());
    $norm = static function ($value): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value)));
    };
    $qtyHeader = $norm($sheet->getCell('E1')->getValue());
    if ($norm($sheet->getCell('B1')->getValue()) !== 'modelcode' ||
        $norm($sheet->getCell('C1')->getValue()) !== 'itemcode' ||
        !preg_match('/(quantity|stock|qty)/', $qtyHeader)) {
        throw new RuntimeException('Unexpected XLSX headers. Expected B=ModelCode C=ItemCode E=Quantity.');
    }
    $feed = [];
    $feedByParent = [];
    $stats = ['rows' => 0, 'duplicates' => 0, 'matched_items' => 0, 'matched_variations' => 0,
        'missing_parent' => 0, 'missing_variations' => 0, 'xd_variations_not_in_feed' => 0,
        'supplier_positive_qty' => 0, 'supplier_zero_qty' => 0, 'supplier_blank_qty' => 0,
        'need_change' => 0, 'no_change' => 0, 'missing_price' => 0, 'parent_repairs' => 0,
        'updated' => 0, 'synced_parents' => 0, 'errors' => 0];
    $highest = (int) $sheet->getHighestRow();
    for ($r = 2; $r <= $highest; $r++) {
        $model = trim((string) $sheet->getCell('B' . $r)->getValue());
        $item = trim((string) $sheet->getCell('C' . $r)->getValue());
        if ($model === '' && $item === '') {
            continue;
        }
        if ($model === '' || $item === '') {
            throw new RuntimeException("Missing ModelCode or ItemCode on stock row {$r}.");
        }
        $raw = $sheet->getCell('E' . $r)->getValue();
        $qty = ($raw === null || trim((string) $raw) === '') ? 0 : $raw;
        if (!is_numeric($qty) || (float) $qty < 0 || floor((float) $qty) != (float) $qty || (float) $qty > PHP_INT_MAX) {
            throw new RuntimeException("Invalid nonnegative integer Quantity on stock row {$r}.");
        }
        $parent = '00' . $model;
        $itemSku = '00' . $item;
        $key = $parent . '|' . $itemSku;
        $upcoming = trim((string) ($sheet->getCell('F' . $r)->getValue() ?? ''));
        if (isset($feed[$key])) {
            if ($feed[$key]['qty'] !== (int) $qty || $feed[$key]['upcoming'] !== $upcoming) {
                throw new RuntimeException("Conflicting duplicate stock values for {$key} on row {$r}.");
            }
            $stats['duplicates']++;
            continue;
        }
        if ($raw === null || trim((string) $raw) === '') {
            $stats['supplier_blank_qty']++;
        }
        if ((int) $qty > 0) {
            $stats['supplier_positive_qty']++;
        } else {
            $stats['supplier_zero_qty']++;
        }
        $feed[$key] = ['parent' => $parent, 'item' => $itemSku, 'qty' => (int) $qty, 'upcoming' => $upcoming];
        $feedByParent[$parent][$itemSku] = $key;
        $stats['rows']++;
    }
    $book->disconnectWorksheets();
    unset($sheet, $book);
    $log('XLSX rows validated. feed_items=' . count($feed) .
        '; memory_mb=' . round(memory_get_usage(true) / 1048576, 1));
    if (!$feed) {
        throw new RuntimeException('No valid ItemCode stock records. Refusing to edit WooCommerce.');
    }
    // Protect production against a supplier incident returning all-zero inventory.
    // A filtered repair remains available for deliberate exceptions.
    $sourcePositiveRows = 0;
    foreach ($feed as $stockRecord) {
        if ($stockRecord['qty'] > 0) { $sourcePositiveRows++; }
    }
    if (!$preview && $skuFilter === '' && count($feed) >= 50 && $sourcePositiveRows === 0) {
        throw new RuntimeException('Safety stop: supplier feed contains 50+ items but zero positive quantities. No changes made.');
    }

    // Map ONLY products and variations explicitly attributed to the XD importer.
    $log('Reading existing WooCommerce XD products...');
    $parentsRows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID AS id, sku.meta_value AS sku FROM {$wpdb->posts} p " .
        "INNER JOIN {$wpdb->postmeta} src ON src.post_id=p.ID AND src.meta_key=%s AND src.meta_value=%s " .
        "INNER JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key=%s " .
        "WHERE p.post_type=%s AND p.post_status IN ('publish','private','draft','pending')",
        'api_imported_from', 'xindao-api', '_sku', 'product'
    ));
    if ($wpdb->last_error) {
        throw new RuntimeException('Database error reading XD parents: ' . $wpdb->last_error);
    }
    $parents = [];
    $parentsById = [];
    foreach ($parentsRows as $p) {
        $parents[(string) $p->sku] = (int) $p->id;
        $parentsById[(int) $p->id] = (string) $p->sku;
    }
    unset($parentsRows);

    $log('XD parent products located: ' . count($parents) . '. Reading variations...');
    $variationRows = $wpdb->get_results($wpdb->prepare(
        "SELECT v.ID AS id, v.post_parent AS parent_id, sku.meta_value AS sku, original.meta_value AS original_sku " .
        "FROM {$wpdb->posts} v " .
        "INNER JOIN {$wpdb->postmeta} src ON src.post_id=v.ID AND src.meta_key=%s AND src.meta_value=%s " .
        "LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=v.ID AND sku.meta_key=%s " .
        "LEFT JOIN {$wpdb->postmeta} original ON original.post_id=v.ID AND original.meta_key=%s " .
        "WHERE v.post_type=%s AND v.post_status IN ('publish','private')",
        'api_imported_from', 'xindao-api', '_sku', 'xindao_api_sku', 'product_variation'
    ));
    if ($wpdb->last_error) {
        throw new RuntimeException('Database error reading XD variations: ' . $wpdb->last_error);
    }
    $log('XD variation rows loaded: ' . count($variationRows) .
        '. memory_mb=' . round(memory_get_usage(true) / 1048576, 1));
    $planned = [];
    $linkedPairs = [];
    $parentWithPositiveStock = [];
    $sample = 0;
    foreach ($variationRows as $v) {
        $id = (int) $v->id;
        $parentId = (int) $v->parent_id;
        if (!isset($parentsById[$parentId])) {
            continue;
        }
        $parentSku = $parentsById[$parentId];
        $variationSku = (string) $v->sku;
        $sourceSku = trim((string) ($v->original_sku ?? ''));
        $key = null;
        if ($sourceSku !== '') {
            $candidate = $parentSku . '|' . $sourceSku;
            if (isset($feed[$candidate])) {
                $key = $candidate;
            }
        } elseif (isset($feedByParent[$parentSku])) {
            // Legacy variations without xindao_api_sku: match strict ItemCode boundaries.
            foreach ($feedByParent[$parentSku] as $itemSku => $candidate) {
                if ($variationSku === $itemSku || strncmp($variationSku, $itemSku . '-', strlen($itemSku) + 1) === 0) {
                    $key = $candidate;
                    break;
                }
            }
        }
        if ($skuFilter !== '' && $skuFilter !== $parentSku && $skuFilter !== $variationSku && $skuFilter !== $sourceSku &&
            ($key === null || $skuFilter !== $feed[$key]['item'])) {
            continue;
        }
        if ($key === null) {
            $stats['xd_variations_not_in_feed']++;
            if ($sample < 20) {
                $log("NO_FEED_MATCH | parent={$parentSku} | variation={$variationSku} | original={$sourceSku}");
                $sample++;
            }
            continue;
        }
        $linkedPairs[$key] = true;
        $stats['matched_variations']++;
        $row = $feed[$key];
        if ($row['qty'] > 0) {
            $parentWithPositiveStock[$parentId] = true;
        }
        $variation = wc_get_product($id);
        if (!$variation || !$variation->is_type('variation') || (int) $variation->get_parent_id() !== $parentId) {
            $stats['errors']++;
            $log("ERROR invalid variation {$id} for {$parentSku}");
            continue;
        }
        if ($variation->get_price('edit') === '') {
            $stats['missing_price']++;
            if ($sample < 20) {
                $log("MISSING_PRICE | variation={$variationSku} | qty={$row['qty']} | WooCommerce may not offer this variation for purchase");
                $sample++;
            }
        }
        // Always manage stock at VARIATION level. Parent stock is disabled below.
        $currentQty = $variation->get_stock_quantity('edit');
        $currentStatus = $variation->get_stock_status('edit');
        $currentUpcoming = trim((string) $variation->get_meta('upcoming_stock_date', true, 'edit'));
        $desiredStatus = $row['qty'] > 0 ? 'instock' : 'outofstock';
        $changed = !$variation->get_manage_stock('edit') || (int) $currentQty !== $row['qty'] ||
            $currentQty === null || $currentStatus !== $desiredStatus || $currentUpcoming !== $row['upcoming'];
        if ($changed) {
            $planned[$id] = ['parent_id' => $parentId, 'variation_sku' => $variationSku, 'qty' => $row['qty'],
                'status' => $desiredStatus, 'upcoming' => $row['upcoming']];
            $stats['need_change']++;
            if ($sample < 20) {
                $log(($preview ? 'WOULD_CHANGE' : 'CHANGE') . " | {$variationSku} | ID={$id} | qty=" .
                    var_export($currentQty, true) . "->{$row['qty']} | status={$currentStatus}->{$desiredStatus}");
                $sample++;
            }
        } else {
            $stats['no_change']++;
        }
    }
    unset($variationRows);

    // Identify supplier records whose ItemCodes are absent from existing catalog.
    $missingSample = 0;
    foreach ($feed as $key => $row) {
        if ($skuFilter !== '' && !in_array($skuFilter, [$row['parent'], $row['item']], true)) {
            continue;
        }
        if (isset($linkedPairs[$key])) {
            $stats['matched_items']++;
        } elseif (!isset($parents[$row['parent']])) {
            $stats['missing_parent']++;
        } else {
            $stats['missing_variations']++;
            if ($missingSample < 10) {
                $log("MISSING_VARIATION | parent={$row['parent']} | item={$row['item']} | feed_qty={$row['qty']}");
                $missingSample++;
            }
        }
    }
    // A tiny result against a substantial existing XD catalog points to a partial
    // supplier export, broken mapping or a changed feed contract.
    if (!$preview && $skuFilter === '' && count($parentsById) >= 100 &&
        $stats['matched_items'] < min(20, (int) ceil(count($parentsById) * 0.10))) {
        throw new RuntimeException('Safety stop: suspiciously low feed/catalog coverage. No changes made.');
    }
    if ($stats['matched_variations'] === 0) {
        throw new RuntimeException('No matching XD variations. Check SKU mapping or --sku filter; no stock was changed.');
    }
    if ($stats['errors'] > 0) {
        throw new RuntimeException('Invalid WooCommerce variations found; aborting before stock writes.');
    }

    $parentsToSync = [];
    foreach (array_keys($parentWithPositiveStock) as $parentId) {
        $parentProduct = wc_get_product((int) $parentId);
        if ($parentProduct && ($parentProduct->get_stock_status('edit') === 'outofstock' || $parentProduct->get_manage_stock('edit'))) {
            $parentsToSync[$parentId] = true;
        }
    }
    foreach ($planned as $change) {
        $parentsToSync[$change['parent_id']] = true;
    }
    $stats['parent_repairs'] = count($parentsToSync);

    // Batch applies touch at most --limit stock variation/parent work units,
    // with all touched variation parents synced in the same run. A parent may
    // need syncing after its variations changed, so we always reserve that work.
    // Pending work is rebuilt on the next invocation from current Woo data.
    $applyPlan = $planned;
    $syncPlan = $parentsToSync;
    if ($batchLimit !== null) {
        $applyPlan = array_slice($planned, 0, $batchLimit, true);
        $syncPlan = [];
        foreach ($applyPlan as $change) {
            $syncPlan[$change['parent_id']] = true;
        }
        // Use the remaining budget to fix orphan parent statuses too.
        $remainingSlots = max(0, $batchLimit - count($applyPlan));
        foreach ($parentsToSync as $parentId => $_) {
            if (isset($syncPlan[$parentId])) { continue; }
            if ($remainingSlots === 0) { break; }
            $syncPlan[$parentId] = true;
            $remainingSlots--;
        }
    }
    $batchIsPartial = count($applyPlan) < count($planned) || count($syncPlan) < count($parentsToSync);
    $log(($preview ? 'DRY RUN' : 'APPLY') . " | FeedItems={$stats['rows']} | XDParents=" . count($parents) .
        " | matchedVariations={$stats['matched_variations']} | changes={$stats['need_change']} | parentsToSync={$stats['parent_repairs']}" .
        ($batchLimit !== null ? ' | limit=' . $batchLimit . ' | batch_variations=' . count($applyPlan) .
            ' | batch_parents=' . count($syncPlan) : ''));
    if (!$preview) {
        // AUTOMATIC BATCH ENGINE: one WordPress bootstrap + one validated feed,
        // then a sequence of bounded write/sync chunks within THIS process.
        // --limit still caps the entire invocation for cautious staging tests.
        $internalChunkSize = 500;
        // On a 15-minute stock cron, leave time for the next run. If a very
        // large catalog needs longer, next cron rebuilds the remaining work
        // from real WooCommerce state and continues safely (no fragile cursor).
        // Interactive backfill --apply has no time budget: one invocation can
        // complete all chunks, subject to any host-level process limits.
        $cronTimeBudget = (!$isBackfill && !isset($args['apply']) && !isset($args['dry-run']) &&
            $batchLimit === null) ? 720 : 0;
        $startedWorkAt = microtime(true);
        $timedOutSafely = false;
        $processedInRun = 0;
        $batchNumber = 0;
        $planSize = count($applyPlan);
        $batchChanges = [];
        $syncedParentIds = [];

        $syncParent = static function (int $parentId) use (&$stats, $log): void {
            try {
                $parent = wc_get_product($parentId);
                if (!$parent || !$parent->is_type('variable') ||
                    get_post_meta($parentId, 'api_imported_from', true) !== 'xindao-api') {
                    throw new RuntimeException('Parent is no longer an XD variable product.');
                }
                if ($parent->get_manage_stock('edit')) {
                    $parent->set_manage_stock(false);
                    $parent->save();
                }
                WC_Product_Variable::sync($parentId);
                wc_delete_product_transients($parentId);
                $stats['synced_parents']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $log('ERROR parent ID=' . $parentId . ' ' . $e->getMessage());
            }
        };

        $applyChunk = static function (array $chunk) use (
            &$stats, &$batchNumber, &$processedInRun, &$syncedParentIds,
            $syncParent, $log, $internalChunkSize, $planSize
        ): void {
            if (!$chunk) {
                return;
            }
            $batchNumber++;
            $chunkParents = [];
            $changedThisChunk = 0;
            $log('CHUNK START | number=' . $batchNumber . ' | variations=' . count($chunk) .
                ' | size_limit=' . $internalChunkSize);
            foreach ($chunk as $id => $change) {
                try {
                    $v = wc_get_product((int) $id);
                    if (!$v || !$v->is_type('variation') || (int) $v->get_parent_id() !== $change['parent_id'] ||
                        get_post_meta((int) $id, 'api_imported_from', true) !== 'xindao-api') {
                        throw new RuntimeException('Variation or XD ownership changed during sync.');
                    }
                    $v->set_manage_stock(true);
                    $v->set_stock_quantity($change['qty']);
                    $v->set_stock_status($change['status']);
                    if ($change['upcoming'] === '') {
                        $v->delete_meta_data('upcoming_stock_date');
                    } else {
                        $v->update_meta_data('upcoming_stock_date', $change['upcoming']);
                    }
                    if ((int) $v->save() !== (int) $id) {
                        throw new RuntimeException('WooCommerce did not confirm variation save().');
                    }
                    $stats['updated']++;
                    $changedThisChunk++;
                    $chunkParents[(int) $change['parent_id']] = true;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $log('ERROR variation ID=' . $id . ' ' . $e->getMessage());
                } finally {
                    $processedInRun++;
                }
            }
            // Flush parent syncs now, even if a future chunk fails or the host
            // terminates the process. A retry will skip already-correct stock.
            foreach (array_keys($chunkParents) as $parentId) {
                $syncParent((int) $parentId);
                $syncedParentIds[(int) $parentId] = true;
            }
            $log('CHUNK COMPLETE | number=' . $batchNumber . ' | updated=' . $changedThisChunk .
                ' | processed_total=' . $processedInRun . '/' . $planSize . ' | total_updates=' . $stats['updated'] .
                ' | synced_parents_total=' . $stats['synced_parents'] .
                ' | errors=' . $stats['errors'] .
                ' | memory_mb=' . round(memory_get_usage(true) / 1048576, 1));
            // Reclaim temporary cyclic references without flushing WP object cache
            // or invalidating shared WooCommerce caches mid-process.
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        };

        foreach ($applyPlan as $id => $change) {
            $batchChanges[$id] = $change;
            if (count($batchChanges) >= $internalChunkSize) {
                $applyChunk($batchChanges);
                $batchChanges = [];
                if ($stats['errors'] > 0) {
                    $log('STOPPING: A chunk reported errors. Next invocation will reassess saved stock.');
                    break;
                }
                if ($cronTimeBudget > 0 && $processedInRun < $planSize &&
                    microtime(true) - $startedWorkAt >= $cronTimeBudget) {
                    $timedOutSafely = true;
                    $log('CRON TIME BUDGET REACHED: completed current chunk; remaining work deferred to next cron.');
                    break;
                }
            }
        }
        if ($batchChanges && $stats['errors'] === 0 && !$timedOutSafely) {
            $applyChunk($batchChanges);
        }
        unset($batchChanges);

        // Some parent products require a repair even though no child quantity
        // changed. Sync them in separate bounded chunks as well.
        if ($stats['errors'] === 0 && !$timedOutSafely) {
            $extraParentCount = 0;
            foreach (array_keys($syncPlan) as $parentId) {
                if (isset($syncedParentIds[(int) $parentId])) {
                    continue;
                }
                $syncParent((int) $parentId);
                $extraParentCount++;
                if (($extraParentCount % $internalChunkSize) === 0) {
                    $log('PARENT SYNC PROGRESS | extra_parents=' . $extraParentCount .
                        ' | total_synced=' . $stats['synced_parents'] . ' | errors=' . $stats['errors']);
                    if ($stats['errors'] > 0) {
                        break;
                    }
                    if ($cronTimeBudget > 0 && microtime(true) - $startedWorkAt >= $cronTimeBudget) {
                        $timedOutSafely = true;
                        $log('CRON TIME BUDGET REACHED while syncing additional parents.');
                        break;
                    }
                }
            }
        }
        $batchIsPartial = $batchIsPartial || $timedOutSafely || $processedInRun < count($applyPlan) ||
            $stats['errors'] > 0;
        // Only mark the catalog fully synchronized when no outstanding batch work exists.
        if ($stats['errors'] === 0 && $skuFilter === '' && !$batchIsPartial) {
            update_option('xd_last_productstock_import', function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d'));
            update_option('xd_last_productstock_import_finished_at', (string) microtime(true));
            update_option('xd_last_productstock_import_index', 0); // obsolete v1 cursor
        }
    }
    foreach ($stats as $name => $value) {
        $log($name . ': ' . $value);
    }
    if ($preview) {
        $log('PREVIEW COMPLETE. WooCommerce data unchanged.');
    } elseif ($stats['errors'] > 0) {
        $log('APPLY INCOMPLETE: errors found; investigate and retry.');
    } elseif ($batchIsPartial) {
        $log('APPLY PARTIAL. Manual --limit or cron time budget left work; future run automatically resumes based on actual WooCommerce state.');
    } else {
        $log('APPLY COMPLETE. Matched XD variation stock updated using internal batches of 500; missing variations/prices remain a separate task.');
    }
    $exitCode = $stats['errors'] === 0 ? 0 : 1;
} catch (Throwable $e) {
    $log('ABORTED: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    if ($tmp !== null && is_file($tmp)) {
        @unlink($tmp);
    }
}
exit($exitCode);
