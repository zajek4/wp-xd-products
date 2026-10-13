<?php
/**
 * XD one-time / audit-only-by-default cleanup for duplicate -without-printing variations.
 * Only verified, unpriced English duplicates are Trashed; priced -bez-tiska variations are never deleted.
 * Usage: php xd_fix_without_printing.php --parent=00101032
 *        php xd_fix_without_printing.php --parent=00101032 --apply --confirm=YES
 *        php xd_fix_without_printing.php --all --apply --confirm=YES --limit=50
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (!defined('IMUNIFY_AI_BOT_PROTECTION')) {
    define('IMUNIFY_AI_BOT_PROTECTION', false);
}
ini_set('memory_limit', '768M');
set_time_limit(0);
require_once __DIR__ . '/wp-load.php';
if (!function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce is unavailable.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$confirm = in_array('--confirm=YES', $arguments, true);
$all = in_array('--all', $arguments, true);
$parentSku = null;
$limit = 50;
foreach ($arguments as $argument) {
    if (strpos($argument, '--parent=') === 0) {
        $parentSku = trim(substr($argument, strlen('--parent=')));
        if ($parentSku === '') { fwrite(STDERR, "Empty --parent SKU.\n"); exit(1); }
    } elseif (strpos($argument, '--limit=') === 0) {
        $rawLimit = substr($argument, strlen('--limit='));
        if (!ctype_digit($rawLimit) || (int) $rawLimit < 1 || (int) $rawLimit > 50) {
            fwrite(STDERR, "--limit must be between 1 and 50.\n"); exit(1);
        }
        $limit = (int) $rawLimit;
    } elseif (!in_array($argument, array('--apply', '--confirm=YES', '--all'), true)) {
        fwrite(STDERR, "Unknown argument: " . $argument . "\n");
        exit(1);
    }
}
if ($apply && !$confirm) {
    fwrite(STDERR, "Cannot apply without --confirm=YES. Create a database backup first.\n");
    exit(1);
}
// Never allow an accidentally unscoped write: --parent=SKU is the safe default;
// --all must be explicitly requested after a successful single-product test.
if ($apply && (($parentSku === null && !$all) || ($parentSku !== null && $all))) {
    fwrite(STDERR, "For APPLY choose exactly one of --parent=SKU or --all.\n");
    exit(1);
}
if (!$apply && $parentSku !== null && $all) {
    fwrite(STDERR, "Choose either --parent=SKU or --all, not both.\n");
    exit(1);
}
if ($apply && defined('EMPTY_TRASH_DAYS') && (int) EMPTY_TRASH_DAYS <= 0) {
    fwrite(STDERR, "ABORT: EMPTY_TRASH_DAYS=0 would permanently delete posts instead of Trash.\n");
    exit(1);
}

$lockPath = ABSPATH . 'cron_files/xd-import.lock';
if (!is_dir(dirname($lockPath)) && !wp_mkdir_p(dirname($lockPath))) {
    fwrite(STDERR, "Cannot create lock directory.\n"); exit(1);
}
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "XD lock is busy. Stop the XD crons and wait for import to finish.\n"); exit(1);
}

// Cross-check both XD identifiers against the original supplier Products XLSX.
// The historical catalog may have variations assigned to an unexpected parent.
// Matching a priced WooCommerce sibling alone is not proof of XD ModelCode ownership.
$feedPath = ABSPATH . 'cron_files/product_data.xlsx';
if (!is_readable($feedPath)) {
    fwrite(STDERR, "ABORT: validated XD Products XLSX is unavailable; no changes made.\n");
    exit(1);
}
if ($apply && @filemtime($feedPath) < time() - 7 * 86400) {
    fwrite(STDERR, "ABORT: XD Products XLSX is older than 7 days; do not clean using stale ownership data.\n");
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
try {
    // C=ModelCode, D=ItemCode; load only these columns (not the 235 other columns).
    $feedReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($feedPath);
    $feedReader->setReadDataOnly(true);
    $feedReader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
            return $columnAddress === 'C' || $columnAddress === 'D';
        }
    });
    $feedSpreadsheet = $feedReader->load($feedPath);
    $feedSheet = $feedSpreadsheet->getActiveSheet();
    if (trim((string) $feedSheet->getCell('C1')->getValue()) !== 'ModelCode' ||
        trim((string) $feedSheet->getCell('D1')->getValue()) !== 'ItemCode' ||
        $feedSheet->getHighestRow() < 2) {
        throw new \RuntimeException('C/D feed headers invalid or no rows.');
    }
    $feedParentsByItem = array();
    $feedModelRows = 0;
    for ($r = 2, $lastRow = $feedSheet->getHighestRow(); $r <= $lastRow; $r++) {
        $model = trim((string) $feedSheet->getCell('C' . $r)->getValue());
        $item = trim((string) $feedSheet->getCell('D' . $r)->getValue());
        if ($model === '' || $item === '') { continue; }
        $feedParentsByItem['00' . $item]['00' . $model] = true;
        $feedModelRows++;
    }
    $feedSpreadsheet->disconnectWorksheets();
    unset($feedSpreadsheet, $feedSheet);
    if ($feedModelRows < 100 || count($feedParentsByItem) < 100) {
        throw new \RuntimeException('Unexpectedly small XD Products feed.');
    }
    echo 'SOURCE CHECK | Products XLSX rows=' . $feedModelRows .
        ' | distinct XD ItemCodes=' . count($feedParentsByItem) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "ABORT: cannot verify XD supplier ModelCode/ItemCode links: " . $e->getMessage() . "\n");
    exit(1);
}

global $wpdb;
$orderMetaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
if ($apply) {
    $existingOrderMetaTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orderMetaTable));
    if ($existingOrderMetaTable !== $orderMetaTable) {
        fwrite(STDERR, "ABORT: cannot verify WooCommerce order item table.\n"); exit(1);
    }
}

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.ID AS variation_id, p.post_parent AS parent_id, pm.meta_value AS sku
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'product_variation'
           AND p.post_status IN ('publish', 'private')
           AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s
         ORDER BY p.post_parent, p.ID",
        '%' . $wpdb->esc_like('-without-printing')
    ),
    ARRAY_A
);
if ($wpdb->last_error) {
    fwrite(STDERR, "Database error reading XD variations: " . $wpdb->last_error . "\n"); exit(1);
}

$counters = array('scanned' => 0, 'eligible' => 0, 'skip_unrelated' => 0,
    'skip_canonical' => 0, 'skip_price' => 0, 'skip_orders' => 0, 'skip_meta' => 0,
    'skip_db' => 0, 'skip_feed' => 0, 'skip_attributes' => 0, 'trashed' => 0, 'failed' => 0);
$eligible = array();
$printSamples = 0;
foreach ($rows as $row) {
    $sku = (string) $row['sku'];
    if (substr($sku, -strlen('-without-printing')) !== '-without-printing') {
        continue;
    }
    $parentId = (int) $row['parent_id'];
    $id = (int) $row['variation_id'];
    $parent = wc_get_product($parentId);
    if (!$parent || !$parent->is_type('variable')) { $counters['skip_unrelated']++; continue; }
    if ($parentSku !== null && $parent->get_sku() !== $parentSku) { continue; }
    $counters['scanned']++;
    $variation = wc_get_product($id);
    if (!$variation || !$variation->is_type('variation') ||
        get_post_meta($id, 'api_imported_from', true) !== 'xindao-api' ||
        get_post_meta($parentId, 'api_imported_from', true) !== 'xindao-api') {
        $counters['skip_unrelated']++;
        continue;
    }
    $duplicateAttributes = $variation->get_attributes('edit');
    if (($duplicateAttributes['pa_allprintcodes'] ?? '') !== 'without-printing') {
        $counters['skip_meta']++;
        continue;
    }
    if ((string) $variation->get_price('edit') !== '' ||
        (string) $variation->get_regular_price('edit') !== '' ||
        (string) $variation->get_sale_price('edit') !== '') {
        $counters['skip_price']++;
        continue;
    }
    $canonicalSku = substr($sku, 0, -strlen('-without-printing')) . '-bez-tiska';
    $canonicalId = (int) wc_get_product_id_by_sku($canonicalSku);
    $canonical = $canonicalId ? wc_get_product($canonicalId) : false;
    if (!$canonical || !$canonical->is_type('variation') ||
        (int) $canonical->get_parent_id() !== $parentId ||
        (string) $canonical->get_price('edit') === '' ||
        !in_array(get_post_status($canonicalId), array('publish', 'private'), true)) {
        $counters['skip_canonical']++;
        continue;
    }
    $canonicalAttributes = $canonical->get_attributes('edit');
    if (($canonicalAttributes['pa_allprintcodes'] ?? '') !== 'bez-tiska') {
        $counters['skip_attributes']++;
        continue;
    }
    // Every non-print attribute must match, so we cannot confuse distinct color/size choices.
    $duplicateComparable = $duplicateAttributes;
    $canonicalComparable = $canonicalAttributes;
    unset($duplicateComparable['pa_allprintcodes'], $canonicalComparable['pa_allprintcodes']);
    ksort($duplicateComparable);
    ksort($canonicalComparable);
    if ($duplicateComparable !== $canonicalComparable) {
        $counters['skip_attributes']++;
        continue;
    }
    $source = trim((string) get_post_meta($id, 'xindao_api_sku', true));
    $canonicalSource = trim((string) get_post_meta($canonicalId, 'xindao_api_sku', true));
    $sourceExpectedBySku = substr($sku, 0, -strlen('-without-printing'));
    if ($source === '' || $canonicalSource !== $source || $sourceExpectedBySku !== $source) {
        $counters['skip_meta']++;
        continue;
    }
    // Verify the supplier feed maps this ItemCode to precisely this ModelCode.
    $parentSkuFromWoo = trim((string) $parent->get_sku());
    $feedOwners = $feedParentsByItem[$source] ?? array();
    if (count($feedOwners) !== 1 || !isset($feedOwners[$parentSkuFromWoo])) {
        $counters['skip_feed']++;
        if ($printSamples < 30) {
            echo 'REVIEW_FEED | parent=' . $parentSkuFromWoo . ' | ItemCode=' . $source .
                ' | feed ModelCodes=' . implode(',', array_keys($feedOwners)) . "\n";
            $printSamples++;
        }
        continue;
    }
    // Never trash a variation that is recorded on a WooCommerce order.
    $inOrder = $wpdb->get_var($wpdb->prepare(
        "SELECT order_item_id FROM {$orderMetaTable}
         WHERE meta_key = '_variation_id' AND meta_value = %s LIMIT 1",
        (string) $id
    ));
    if ($wpdb->last_error) { $counters['skip_db']++; continue; }
    if ($inOrder) { $counters['skip_orders']++; continue; }

    $eligible[] = array('id' => $id, 'sku' => $sku, 'canonical_id' => $canonicalId,
        'canonical_sku' => $canonicalSku, 'parent_id' => $parentId, 'parent_sku' => $parent->get_sku());
    $counters['eligible']++;
    if ($printSamples < 30) {
        echo 'MATCH | parent=' . $parent->get_sku() . ' | duplicate=' . $sku . ' (ID ' . $id .
            ') | priced counterpart=' . $canonicalSku . ' (ID ' . $canonicalId . ")\n";
        $printSamples++;
    }
}

echo 'MODE=' . ($apply ? 'APPLY' : 'DRY-RUN') . ' | candidates=' . $counters['scanned'] .
    ' | eligible=' . $counters['eligible'] . ' | skipped_wrong_source=' . $counters['skip_unrelated'] .
    ' | skipped_no_priced_counterpart=' . $counters['skip_canonical'] .
    ' | skipped_has_price=' . $counters['skip_price'] .
    ' | skipped_used_in_orders=' . $counters['skip_orders'] .
    ' | skipped_meta_mismatch=' . $counters['skip_meta'] .
    ' | skipped_attribute_mismatch=' . $counters['skip_attributes'] .
    ' | skipped_feed_mismatch=' . $counters['skip_feed'] .
    ' | skipped_db_error=' . $counters['skip_db'] . "\n";
if (!$apply) {
    echo "DRY-RUN ONLY. Nothing changed.\n";
    exit(0);
}
if ($counters['skip_db'] > 0) {
    fwrite(STDERR, "ABORT: order history check failed for at least one candidate.\n"); exit(1);
}
if (!$eligible) {
    echo "Nothing eligible for Trash.\n";
    exit(0);
}

$logDir = ABSPATH . 'cron_files/logs/';
if (!is_dir($logDir) && !wp_mkdir_p($logDir)) { fwrite(STDERR, "Cannot create audit log directory.\n"); exit(1); }
$deny = $logDir . '.htaccess';
if (!file_exists($deny)) {
    @file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
$manifest = $logDir . 'xd-without-printing-trash-' . gmdate('Ymd-His') . '-' . getmypid() . '.json';
$batch = array_slice($eligible, 0, $limit);
if (file_put_contents($manifest, json_encode(array('generated_at' => gmdate('c'),
    'backup_required' => true, 'items' => $batch), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    fwrite(STDERR, "Cannot save pre-trash manifest; no posts changed.\n"); exit(1);
}
echo 'PRE-TRASH MANIFEST=' . $manifest . "\n";
$changedParents = array();
foreach ($batch as $item) {
    $result = wp_trash_post($item['id']);
    if ($result instanceof WP_Post && get_post_status($item['id']) === 'trash') {
        $counters['trashed']++;
        $changedParents[$item['parent_id']] = true;
        echo 'TRASHED | ' . $item['sku'] . ' | variation ID=' . $item['id'] . "\n";
    } else {
        $counters['failed']++;
        echo 'FAILED | ' . $item['sku'] . ' | variation ID=' . $item['id'] . "\n";
    }
}
// Restore canonical print-free term on affected parents. Remove the English
// attribute choice ONLY when that parent has no active English variations left.
foreach (array_keys($changedParents) as $parentId) {
    $parent = wc_get_product($parentId);
    if (!$parent || !$parent->is_type('variable')) { continue; }
    if (taxonomy_exists('pa_allprintcodes')) {
        $term = get_term_by('slug', 'bez-tiska', 'pa_allprintcodes');
        if (!$term) {
            $made = wp_insert_term('Bez tiska', 'pa_allprintcodes', array('slug' => 'bez-tiska'));
            if (!is_wp_error($made)) { $term = get_term((int) $made['term_id'], 'pa_allprintcodes'); }
        }
        if ($term && !is_wp_error($term)) {
            $attrs = $parent->get_attributes('edit');
            $att = $attrs['pa_allprintcodes'] ?? null;
            if ($att instanceof WC_Product_Attribute && $att->is_taxonomy()) {
                $options = $att->get_options();
                $hasCanonical = false;
                foreach ($options as $option) {
                    $optionTerm = is_numeric($option) ? get_term((int) $option, 'pa_allprintcodes')
                        : get_term_by('slug', (string) $option, 'pa_allprintcodes');
                    if ($optionTerm && !is_wp_error($optionTerm) && $optionTerm->slug === 'bez-tiska') { $hasCanonical = true; }
                }
                if (!$hasCanonical) { $options[] = (int) $term->term_id; }
                // Search by ACTUAL variation attribute, not its SKU: a differently
                // named priced variation may legitimately use the legacy term.
                $activeEnglish = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
                     WHERE p.post_parent=%d AND p.post_type='product_variation' AND p.post_status IN ('publish','private')
                     AND pm.meta_key='attribute_pa_allprintcodes' AND pm.meta_value=%s",
                    (int) $parentId, 'without-printing'
                ));
                if (!$activeEnglish && !$wpdb->last_error) {
                    $options = array_values(array_filter($options, static function ($option) {
                        $t = is_numeric($option) ? get_term((int) $option, 'pa_allprintcodes')
                            : get_term_by('slug', (string) $option, 'pa_allprintcodes');
                        return !$t || is_wp_error($t) || $t->slug !== 'without-printing';
                    }));
                    $defaults = $parent->get_default_attributes('edit');
                    if (($defaults['pa_allprintcodes'] ?? '') === 'without-printing') {
                        $hasRealCanonicalChoice = false;
                        foreach ($batch as $batchItem) {
                            if ((int) $batchItem['parent_id'] !== (int) $parentId) { continue; }
                            $canonicalForDefault = wc_get_product((int) $batchItem['canonical_id']);
                            if ($canonicalForDefault && $canonicalForDefault->is_type('variation')
                                && ($canonicalForDefault->get_attributes('edit')['pa_allprintcodes'] ?? '') === 'bez-tiska') {
                                $hasRealCanonicalChoice = true;
                                break;
                            }
                        }
                        if ($hasRealCanonicalChoice) {
                            $defaults['pa_allprintcodes'] = 'bez-tiska';
                            $parent->set_default_attributes($defaults);
                        }
                    }
                }
                $att->set_options(array_values(array_unique($options)));
                $attrs['pa_allprintcodes'] = $att;
                $parent->set_attributes($attrs);
                $parent->save();
            }
        }
    }
    WC_Product_Variable::sync((int) $parentId);
    wc_delete_product_transients((int) $parentId);
}
echo 'APPLY COMPLETE | trashed=' . $counters['trashed'] . ' | failed=' . $counters['failed'] .
    ' | synced_parents=' . count($changedParents) . ' | remaining_eligible_before_next_run=' .
    max(0, $counters['eligible'] - count($batch)) . "\n";
if ($counters['failed']) { exit(1); }
