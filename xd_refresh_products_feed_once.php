<?php
/**
 * Standalone XD Products XLSX refresh (NO WordPress load, NO database changes).
 *
 * Default: inspect local files only, do not download or alter anything.
 *   php xd_refresh_products_feed_once.php
 *   php xd_refresh_products_feed_once.php --apply --confirm=YES
 *
 * Reads the private Xindao feed URL from the installed product importer rather
 * than duplicating a supplier token in this one-time utility.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
ini_set('memory_limit', '768M');
set_time_limit(0);

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$confirmed = in_array('--confirm=YES', $args, true);
foreach ($args as $arg) {
    if (!in_array($arg, array('--apply', '--confirm=YES'), true)) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}
if ($confirmed && !$apply) {
    fwrite(STDERR, "--confirm=YES is only valid with --apply.\n");
    exit(1);
}
if ($apply && !$confirmed) {
    fwrite(STDERR, "REFUSED: download requires --apply --confirm=YES.\n");
    exit(1);
}

$root = __DIR__;
$cronDir = $root . '/cron_files';
$path = $cronDir . '/product_data.xlsx';
$importerFile = $root . '/xd_importProducts-v4.php';
$vendorFile = $root . '/vendor/autoload.php';

echo 'PRODUCTS XLSX | ' . $path . "\n";
if (is_readable($path)) {
    $stat = stat($path);
    echo 'CURRENT FILE | present | bytes=' . $stat['size'] .
        ' | modified=' . gmdate('Y-m-d H:i:s', $stat['mtime']) . " UTC\n";
} else {
    echo "CURRENT FILE | missing or unreadable\n";
}
if (!$apply) {
    echo "DRY-RUN ONLY | no download, no WooCommerce changes\n";
    exit(0);
}
if (!is_readable($importerFile)) {
    fwrite(STDERR, "ABORT: installed xd_importProducts-v4.php is missing or unreadable.\n");
    exit(1);
}
if (!is_readable($vendorFile)) {
    fwrite(STDERR, "ABORT: Composer vendor/autoload.php is missing.\n");
    exit(1);
}
if (!is_dir($cronDir) && !mkdir($cronDir, 0750, true)) {
    fwrite(STDERR, "ABORT: cannot create cron_files directory.\n");
    exit(1);
}

// Prevent refresh during a Products or Stock import or a concurrent cleanup.
$lockPath = $cronDir . '/xd-import.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "ABORT: XD importer/cleanup lock is busy.\n");
    exit(1);
}

$importerSource = file_get_contents($importerFile);
if ($importerSource === false || !preg_match(
    '~\$file_url\s*=\s*[\'\"](?P<url>https://feeds\.xindao\.com/Feeds/Download/[^\'\"\s]+\.xlsx)[\'\"]\s*;~',
    $importerSource,
    $matches
)) {
    fwrite(STDERR, "ABORT: cannot safely extract Xindao Products URL from importer.\n");
    exit(1);
}
$url = $matches['url'];
if (parse_url($url, PHP_URL_HOST) !== 'feeds.xindao.com') {
    fwrite(STDERR, "ABORT: feed host mismatch.\n");
    exit(1);
}

// Save the candidate separately. No existing XLSX is replaced until every
// signature, header, data volume and ownership check passes.
$tmp = $path . '.refresh-' . getmypid() . '.xlsx';
register_shutdown_function(static function () use ($tmp) {
    if (is_file($tmp)) { @unlink($tmp); }
});
$context = stream_context_create(array(
    'http' => array('timeout' => 120, 'follow_location' => 1, 'max_redirects' => 3, 'ignore_errors' => false),
    'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
));

echo "DOWNLOAD | fresh supplier feed, no product import\n";
$bytes = @file_get_contents($url, false, $context);
if (!is_string($bytes) || strlen($bytes) < 1024 || strlen($bytes) > 50 * 1024 * 1024 ||
    substr($bytes, 0, 2) !== 'PK') {
    fwrite(STDERR, "ABORT: download failed, is too small/large, or is not an XLSX. Existing cache untouched.\n");
    exit(1);
}
$expectedBytes = strlen($bytes);
if (@file_put_contents($tmp, $bytes, LOCK_EX) !== $expectedBytes) {
    fwrite(STDERR, "ABORT: failed to save complete temporary XLSX. Existing cache untouched.\n");
    exit(1);
}
unset($bytes);
@chmod($tmp, 0600);
echo 'DOWNLOAD OK | bytes=' . $expectedBytes . "\n";

require_once $vendorFile;
try {
    // Validate *all* column layout via listWorksheetInfo, but only materialize
    // six relevant columns in memory for fast 6500-row integrity checks.
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
    $info = $reader->listWorksheetInfo($tmp);
    if (!$info || (int) ($info[0]['totalColumns'] ?? 0) < 237 ||
        (int) ($info[0]['totalRows'] ?? 0) < 5001) {
        throw new RuntimeException('XLSX is missing expected columns or supplier rows.');
    }
    $reader->setReadDataOnly(true);
    $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
        {
            return in_array($columnAddress, array('C', 'D', 'E', 'G', 'J', 'K'), true);
        }
    });
    $spreadsheet = $reader->load($tmp);
    $sheet = $spreadsheet->getActiveSheet();
    foreach (array('C' => 'ModelCode', 'D' => 'ItemCode',
                   'E' => 'ProductLifeCycle', 'G' => 'ItemName',
                   'J' => 'MainCategory', 'K' => 'SubCategory') as $col => $header) {
        if (strcasecmp(trim((string) $sheet->getCell($col . '1')->getValue()), $header) !== 0) {
            throw new RuntimeException('Unexpected supplier header at ' . $col . '1.');
        }
    }
    $count = 0;
    $items = array();
    $models = array();
    for ($r = 2, $maxRow = $sheet->getHighestRow(); $r <= $maxRow; $r++) {
        $model = trim((string) $sheet->getCell('C' . $r)->getValue());
        $item = trim((string) $sheet->getCell('D' . $r)->getValue());
        if ($model === '' || $item === '') { continue; }
        $items[$item] = true;
        $models[$model] = true;
        $count++;
    }
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet, $sheet);
    // Xindao feed previously had 6528 ItemCodes / 1452 ModelCodes.
    // A dramatically shortened feed must not authorize cleanup operations.
    if ($count < 5000 || count($items) < 5000 || count($models) < 1000) {
        throw new RuntimeException('XD Products feed too small for safe cleanup audit.');
    }
    echo 'VALIDATED | rows=' . $count . ' | itemcodes=' . count($items) .
        ' | modelcodes=' . count($models) . ' | columns=' . $info[0]['totalColumns'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: XLSX validation failed: ' . $e->getMessage() .
        ". Previous feed untouched; no WordPress data modified.\n");
    exit(1);
}
if (!@rename($tmp, $path)) {
    fwrite(STDERR, "ABORT: could not atomically promote validated XLSX. Previous feed untouched.\n");
    exit(1);
}
@chmod($path, 0600);
echo "FEED READY | validated Products XLSX retained | WooCommerce products unchanged\n";
