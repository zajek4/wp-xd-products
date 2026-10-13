<?php

// Security: this master importer modifies and may trash WooCommerce products.
// It must only be invoked by a server-side CLI cron job, never by a public URL.
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

ini_set('memory_limit', '-1');
ini_set('max_execution_time', 0);
set_time_limit(0);

require_once __DIR__ . '/wp-load.php';

// Prevent two master imports (or the one-time XD NEW backfill) from competing.
$xdLockFile = ABSPATH . 'cron_files/xd-import.lock';
if (!is_dir(dirname($xdLockFile)) && !wp_mkdir_p(dirname($xdLockFile))) {
    fwrite(STDERR, "Cannot create XD import lock directory.\n");
    exit(1);
}
$xdLockHandle = fopen($xdLockFile, 'c');
if (!$xdLockHandle || !flock($xdLockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another XD import/backfill is currently running.\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
require_once (ABSPATH . 'wp-admin/includes/image.php');
require_once (ABSPATH . 'wp-admin/includes/file.php');
require_once (ABSPATH . 'wp-admin/includes/media.php');

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Xindao import logging
 *
 * Files:
 * - cron_files/xindao_product_data.txt                Working SKU trail
 * - cron_files/logs/xindao-import-YYYY-MM-DD.log     Human-readable import progress/history
 * - cron_files/logs/xindao-errors-YYYY-MM-DD.log     Warnings, errors and native PHP errors
 * - cron_files/logs/xindao-categories-YYYY-MM-DD.log Category mapping/creation trace
 */
function xindao_log_context()
{
    static $context = null;

    if ($context !== null) {
        return $context;
    }

    $logDir = ABSPATH . 'cron_files/logs/';
    if (!is_dir($logDir)) {
        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($logDir);
        } else {
            @mkdir($logDir, 0775, true);
        }
    }

    // Protect detailed logs from direct web access when Apache/LiteSpeed honors .htaccess.
    if (is_dir($logDir) && is_writable($logDir)) {
        $htaccessFile = $logDir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            @file_put_contents($htaccessFile, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX);
        }

        $indexFile = $logDir . 'index.php';
        if (!file_exists($indexFile)) {
            @file_put_contents($indexFile, "<?php\nhttp_response_code(403);\nexit;\n", LOCK_EX);
        }
    }

    // Fallback keeps logging functional even if a dedicated /logs directory
    // cannot be created but cron_files itself is writable.
    if (!is_dir($logDir) || !is_writable($logDir)) {
        $logDir = ABSPATH . 'cron_files/';
    }

    $logDate = function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d');
    $runStamp = function_exists('wp_date') ? wp_date('Ymd-His') : date('Ymd-His');
    $runId = $runStamp . '-' . getmypid();

    $context = array(
        'run_id'     => $runId,
        'directory'  => $logDir,
        'import'     => $logDir . 'xindao-import-' . $logDate . '.log',
        'errors'     => $logDir . 'xindao-errors-' . $logDate . '.log',
        'categories' => $logDir . 'xindao-categories-' . $logDate . '.log',
        'sku'        => ABSPATH . 'cron_files/xindao_product_data.txt',
    );

    return $context;
}

function xindao_log_event($message, $level = 'INFO')
{
    $context = xindao_log_context();
    $level = strtoupper((string) $level);

    if (!is_scalar($message)) {
        $message = print_r($message, true);
    }

    $message = trim((string) $message);
    $timestamp = function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] [' . $level . '] [run ' . $context['run_id'] . '] ' . $message . PHP_EOL;

    @file_put_contents($context['import'], $line, FILE_APPEND | LOCK_EX);

    if (in_array($level, array('WARNING', 'ERROR', 'FATAL'), true)) {
        @file_put_contents($context['errors'], $line, FILE_APPEND | LOCK_EX);
    }
}

function xindao_category_log($message, $level = 'INFO')
{
    $context = xindao_log_context();
    $level = strtoupper((string) $level);
    $timestamp = function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] [' . $level . '] [run ' . $context['run_id'] . '] ' . trim((string) $message) . PHP_EOL;

    @file_put_contents($context['categories'], $line, FILE_APPEND | LOCK_EX);

    if (in_array($level, array('WARNING', 'ERROR', 'FATAL'), true)) {
        xindao_log_event($message, $level);
    }
}

function xindao_append_imported_sku($sku)
{
    $sku = trim((string) $sku);
    if ($sku === '') {
        return;
    }

    $context = xindao_log_context();
    @file_put_contents($context['sku'], $sku . ',', FILE_APPEND | LOCK_EX);
}

function xindao_reset_imported_sku_log()
{
    $context = xindao_log_context();
    @file_put_contents($context['sku'], '', LOCK_EX);
}

function xindao_cleanup_old_logs($days = 30)
{
    $context = xindao_log_context();
    $daySeconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    $cutoff = time() - (max(1, (int) $days) * $daySeconds);

    foreach (glob($context['directory'] . 'xindao-*.log') ?: array() as $logFile) {
        if (is_file($logFile) && filemtime($logFile) < $cutoff) {
            @unlink($logFile);
        }
    }
}

function xindao_initialize_logging()
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $context = xindao_log_context();

    // Native PHP warnings/notices/fatal errors generated during this import
    // are redirected to the Xindao-specific error file.
    ini_set('log_errors', '1');
    ini_set('error_log', $context['errors']);

    xindao_cleanup_old_logs(30);

    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (in_array($error['type'], $fatalTypes, true)) {
            xindao_log_event(
                'Fatal PHP error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
                'FATAL'
            );
        }
    });

    xindao_log_event('Xindao import script invoked.');
    xindao_log_event('Progress log: ' . $context['import']);
    xindao_log_event('Error log: ' . $context['errors']);
    xindao_log_event('Category log: ' . $context['categories']);
}

xindao_initialize_logging();

use PhpOffice\PhpSpreadsheet\IOFactory;

$attributes_for_import = array(
    "Brand" => "8",
    "Material" => "11",
    "Color" => "12",
    "InkColor" => "17",
    "ItemDimensions" => "22",
    "Batteries" => "29",
    "BatteryType" => "30",
    "NrOfBatteries" => "31",
    "PackagingTypeItem" => "36",
    "ItemBoxDiameterCM" => "40",
    "ItemBoxDimensions" => "41",
    "Eco" => "56",
    "Dishwasher safe" => "69",
    "Carry handle" => "70",
    "CarryHandleType" => "71",
    "HasANC" => "72",
    "ANCLevelDB" => "73",
    "HasAutoPair" => "74",
    "ChargingCaseBatteryCapacityMAh" => "75",
    "EarbudBatteryCapacityMAh" => "76",
    "BTVersion" => "77",
    "InputChargingConnectorType" => "78",
    "ChargingTimeHours" => "79",
    "HasENC" => "80",
    "ExtraEarTipsIncluded" => "81",
    "IsFoldable" => "82",
    "HasPouch" => "83",
    "ChargingCableIncluded" => "84",
    "HasIntegratedLight" => "85",
    "HasMicrophone" => "86",
    "OperatingDistanceMeters" => "87",
    "AudioWearMethod" => "88",
    "HasPickupFunction" => "89",
    "PlayTimeHours" => "90",
    "HasSubwoofer" => "91",
    "HasTouchFunction" => "92",
    "HasUSBOutput" => "93",
    "HasVoiceControl" => "94",
    "HasBluetooth" => "95",
    "NumberOfSpeakers" => "96",
    "SpeakerOutputW" => "97",
    "WaterproofLevel" => "98",
    "HasChestStrap" => "99",
    "FitsLaptopTabletSizeInches" => "100",
    "MaximumSupportedCurrent" => "101",
    "SupportsCarPlay" => "102",
    "HasLightningOutput" => "103",
    "HasMicroUSBOutput" => "104",
    "ChargingCableLengthCM" => "105",
    "CanCableSync" => "106",
    "IsMicrowaveSafe" => "107",
    "Leak Prevention" => "108",
    "Insulation" => "109",
    "HoursHot" => "110",
    "HoursCold" => "111",
    "FitsCoffeeMachine" => "112",
    "ContentVolumeLiters" => "113",
    "CircumferenceCM" => "114",
    "HatClosureType" => "115",
    "HatModel" => "116",
    "USBVersion" => "117",
    "USBAPortQty" => "118",
    "USBCPortQty" => "119",
    "LaserClass" => "120",
    "BookCoverType" => "121",
    "NotebookFormat" => "122",
    "PaperWeight" => "123",
    "StationaryPaperColor" => "124",
    "NumberOfPages" => "125",
    "PaperRulingLayout" => "126",
    "USBAChargingOutputSpeed" => "127",
    "USBCChargingOutputSpeed" => "128",
    "PhoneChargeCount" => "129",
    "HasUSBCOutput" => "130",
    "CanChargeTablet" => "131",
    "CanChargeLaptop" => "132",
    "TotalOutputPowerWatt" => "133",
    "HasWirelessChargingInput" => "134",
    "HasPassThroughCharging" => "135",
    "HasMagneticWirelessChargingOutput" => "136",
    "IsAirlineCarryOnApproved" => "137",
    "ChargeSimultaneousDevices" => "138",
    "ChargingIndicator" => "139",
    "HasFastCharging" => "140",
    "ChargingPowerWatt" => "141",
    "WirelessCharging" => "142",
    "PowerbankCapacity" => "143",
    "HasSolarPanel" => "144",
    "SolarInputPower" => "145",
    "HasUV400Filter" => "146",
    "HasPolarizedGlasses" => "147",
    "SunglassCategory" => "148",
    "TextileSize" => "149",
    "TextileStyle" => "150",
    "Care Instruction" => "151",
    "Sleeve Length" => "152",
    "Neckline" => "153",
    "Fit" => "154",
    "Fabric Grams per m2" => "155",
    "Fabric" => "156",
    "Has neck label" => "157",
    "Has Tear-away label" => "158",
    "Undyed" => "159",
    "MeasuringTapeColor" => "160",
    "HasAutoStop" => "161",
    "HasMagnet" => "162",
    "HasSlowRetractFunction" => "163",
    "TapeWidthMM" => "164",
    "NumberOfFunctions" => "165",
    "IsMeasuringTapeDoubleSided" => "166",
    "MeasuringSystem" => "167",
    "BeamDistanceMeters" => "168",
    "NumberOfLightModes" => "169",
    "OperatingTimeHours" => "170",
    "IsRechargable" => "171",
    "Lumen" => "172",
    "TapeLengthMeters" => "173",
    "LightSource" => "174",
    "LightSourceQty" => "175",
    "CanopyDiameterCM" => "176",
    "UmbrellaMechanism" => "177",
    "IsStormProof" => "178",
    "InputSpeedVoltAmpere" => "179",
    "NumberOfWirelessChargingCoils" => "180",
    "PenMechanism" => "181",
    "PenRefillType" => "182",
    "AllPrintCodes" => "224",
    "PrintCodeDefault" => "225",
    "PrintPositionDefault" => "227",
    "MaxPrintAreaDefault" => "230",
    "MaxColorsDefault" => "231",
    "FullColorDefault" => "232",
    "CustomSleevePossible" => "235",
    "GiftWrappingPossible" => "236",
);

$promo_main_categories_dictionary = [
    'Bags & Travel' => 'Bags and travel',
    'Drinkware' => 'Food and drink',
    'Audio' => 'Technology and electronics',
    'Writing Instruments' => 'Office and desk supplies',
    'Portfolios & Notebooks' => 'Office and desk supplies',
    'Outdoor' => 'Sports, recreation, and leisure',
    'Healthy Living & Sport' => 'Sports, recreation, and leisure',
    'Home & Living' => 'Wellness, health, and home',
    'Car & Safety' => 'Keys, tools, and auto accessories',
    'Tools & Torches' => 'Keys, tools, and auto accessories',
    'Phone & Tablet accessories' => 'Technology and electronics',
    'Headwear' => 'Textiles and fashion',
    'Umbrellas' => 'Umbrellas and Rainwear',
    'Textile' => 'Textiles and fashion',
];

$promo_sub_categories_dictionary = [
    'Unisex T-Shirts' => 'T-Shirts',
    'Unisex Polos' => 'Polo Shirts',
    'Unisex Sweatshirts' => 'Hoodies',
    'Unisex Pants' => 'Sportswear',
    'Garden' => 'Gardening',
    'Summer leisure' => 'Beach Products',
    'Wine & Bar' => 'Wine and Bar Sets and Accessories',
    'True wireless' => 'Music and Audio',
    'Speakers' => 'Music and Audio',
    'Sport Accessoires' => 'Sports and Event Gear',
    'Sport accessories' => 'Sports and Event Gear',
    'Water bottles' => 'Bottles and Drink Accessories',
    'Tech drinkware' => 'Bottles and Drink Accessories',
    'Thermos flasks' => 'Bottles and Drink Accessories',
    'Drinkware sets' => 'Bottles and Drink Accessories',
    'Infuser bottles' => 'Bottles and Drink Accessories',
    'Car accessories' => 'Car Accessories and First Aid Kits',
    'USB chargers & hubs' => 'USB Chargers',
    'Hubs' => 'USB Chargers',
    'Car chargers' => 'USB Chargers',
    'Chargers' => 'USB Chargers',
    'Connectors & Cables' => 'Mobile and Tablet Accessories',
    'Mobile Gadgets' => 'Mobile and Tablet Accessories',
    'Stands' => 'Mobile and Tablet Accessories',
    'Laserpointers & -presenters' => 'Computer Accessories',
    'Post-PC accessories' => 'Computer Accessories',
    'Laptop sleeves' => 'Computer Accessories',
    'Headphones' => 'Music and Audio',
    'Earbuds' => 'Music and Audio',
    'Games' => 'Games',
    'Pocket knives' => 'Knives and Tools',
    'Tool pens' => 'Stylus Pens',
    'Multitools' => 'Knives and Tools',
    'Tool sets' => 'Knives and Tools',
    'Tool gifts' => 'Knives and Tools',
    'Wireless charger' => 'Wireless Chargers',
    'Powerbanks' => 'Power banks',
    'Solar chargers' => 'Power banks',
    'Notebooks executive' => 'Notebooks and Notepads',
    'Notebooks basic' => 'Notebooks and Notepads',
    'Travel sets' => 'Travel Accessories',
    'RFID & Anti-skimming' => 'Travel Accessories',
    'Travel toiletry bags' => 'Toaletne torbice',
    'Travel garment bags' => 'Travel Accessories',
    'Drawstring bags' => 'Drawstring Bags',
    'Document exhibition bags' => 'Laptop and Document Bags',
    'Trolley bags' => 'Bags',
    'Cooler bags' => 'Thermo Bags',
    'Carry shopping bags' => 'Beach and Shopping Bags',
    'Carry recycling bags' => 'Beach and Shopping Bags',
    'Backpack trolleys' => 'Backpacks and Shoulder Bags',
    'Backpacks executive' => 'Backpacks and Shoulder Bags',
    'Backpacks outdoor/adventure' => 'Backpacks and Shoulder Bags',
    'Laptop backpacks' => 'Backpacks and Shoulder Bags',
    'Torches' => 'Flashlights',
    'Light' => 'Flashlights',
    'Umbrellas for 2 persons' => 'Windproof Umbrellas',
    'Umbrellas for 1 person' => 'Windproof Umbrellas',
    'Standard umbrellas (≤ 23")' => 'Windproof Umbrellas',
    'Golf umbrellas (> 23")' => 'Windproof Umbrellas',
    'Foldable umbrellas' => 'Folding Umbrellas',
    'Portfolios with zipper' => 'Document Folders',
    'Portfolios deluxe' => 'Document Folders',
    'Portfolios' => 'Document Folders',
    'Rulers & cutters' => 'Erasers, Sharpeners, Rulers, and Bookmarks',
    'Measuring tapes' => 'Measuring Tapes',
    'Laptop bags executive' => 'Laptop and Document Bags',
    'Ceramic mugs' => 'Cups and Glasses',
    'Glass' => 'Cups and Glasses',
    'Tableware' => 'Kitchen Utensils',
    'Table accessories' => 'Kitchen Utensils',
    'Kitchen accessories' => 'Kitchen Utensils',
    'Interior' => 'Home and Lifestyle',
    'interior & accessories' => 'Home Comfort and Atmosphere',
    'Bathroom textiles' => 'Bathroom',
    'Picnic' => 'Camping and Hiking Gear',
    'Outdoor accessories' => 'Camping and Hiking Gear',
    'Barbecue' => 'Camping and Hiking Gear',
    'Weekend sport bags' => 'Sports and Travel Bags',
    'Desk accessories' => 'Other Office Supplies',
    'Scarves' => 'Gloves and Scarves',
    'Hats' => 'Caps and Hats',
    '6 panel caps' => 'Caps and Hats',
    '5 panel caps' => 'Caps and Hats',
    'Eco pens' => 'Eco-Friendly',
    'Pen sets' => 'Pens and Pen Sets',
    'Metal pens' => 'Pens and Pen Sets',
    'Plastic pens' => 'Pens and Pen Sets',
    'Sunglasses' => 'Sunglasses',
    'Backpacks' => 'Backpacks and Shoulder Bags',
    'Pencils' => 'Pens and Pencil Cases',
    'First aid & Home safety' => 'Medical Products and First Aid',
    'Gaming accessoires' => 'Games',
    'Wearables' => 'Watches and Smartwatches',
    'Bike accessories' => 'Sports and Event Gear',
    'Coffee mugs & tumblers' => 'Coffee and Tea Sets and Accessories',
    'Beanies' => 'Caps and Hats',
    'Anti-theft backpacks' => 'Backpacks and Shoulder Bags',
    'Travel Accessories' => 'Sports and Event Gear',
    'Safety vests' => 'Visibility Products',
    'Candles & Fragrance sticks' => 'Home Comfort and Atmosphere',
    'Lunchboxes & Foodflasks' => 'Lunch Boxes and Containers',
    'Blankets' => 'Home Comfort and Atmosphere',
    'Work light' => 'Home Comfort and Atmosphere',
    'Jackets' => 'Jackets',
    'Bodywarmers' => 'Jackets',
    'Women t-shirts' => 'T-Shirts',
    'Kids t-shirts' => 'T-Shirts',
    'Unisex Fleece Jackets' => 'Jackets',
    "Poncho's" => 'Jackets',
    "Mugs and Tumblers" => 'Cups and Glasses',
    "Table lamp" => 'Cups and Glasses',
];
$avoidBrands = array('XD Collection', 'XD Xclusive', 'XD Design');
$avoidPrintCodes = array('Headwear', 'Textile');


/* PRODUCT DATA FEED */
define("NAME", 6);
define("DESC", 7);
define("SKU", 2);
define("SKU2", 3);
define("PRODUCT_LIFECYCLE", 4);
define("STOCK", 237);
define("PRICE1", 238);
define("HEIGHT", 20);
define("WIDTH", 19);
define("LENGTH", 18);
define("WEIGHT", 24);
define("IMAGES", 206);
define("MAIN_IMG_NEUTRAL", 208);
define("SIZE", 149);
define("COLOR", 12);
define("CAT1", 9);
define("CAT2", 10);
define("SKU_PREFIX", "00");
define("ECOAttribute", 56);

// Use one canonical slug for print-free XD variations. Existing paid WooCommerce
// variations use -bez-tiska; the legacy English suffix would create a second,
// empty-price variation for the same ItemCode. Do not rename other print methods.
function xindao_canonical_print_code($printCode) {
    $printCode = trim((string) $printCode);
    if (in_array(sanitize_title($printCode), array('without-printing', 'bez-tiska'), true)) {
        return 'Bez tiska';
    }
    return $printCode;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Fail early, BEFORE the importer downloads files or imports print prices.
    // The NEW Hub remains the sole owner of the WooCommerce category "new".
    if (!function_exists('michel_new_hub_set_source_state')) {
        xindao_log_event('XD import blocked: Michel NEW Hub API is unavailable.', 'ERROR');
        exit(1);
    }
    if (!get_term_by('slug', 'new', 'product_cat')) {
        xindao_log_event('XD import blocked: WooCommerce category slug "new" is missing.', 'ERROR');
        exit(1);
    }

    $dateImport = intval(date("j"));
    $timeImport = intval(date("G"));

    // if($dateImport !== 1 && $timeImport > 3){
    //     die();
    // }

    $lastImportDate = get_option('xd_last_master_import');
    if($lastImportDate == date('Y-m-d')){
        xindao_log_event('Products already imported on this date. Import skipped.');
        die();
    }

    function get_product_data_from_endpoint()
    {
        $file_url = 'https://feeds.xindao.com/Feeds/Download/1614-41z0UxIbub9P8Zv81_EJZDDTT3bHArI71hTk1m5yVHw5D9KUerBF1ORqXsDOG08r1wVFm8oT0smmWhJ26_lFJnDB/Xindao.V5.Products-en-gb-C35322.xlsx';
        $save_path = ABSPATH . 'cron_files/';
        $file_name = 'product_data.xlsx';
        $save_file_path = $save_path . $file_name;

        if (!is_dir($save_path) && !wp_mkdir_p($save_path)) {
            xindao_log_event('Cannot create XD feed download directory.', 'ERROR');
            exit(1);
        }

        // IMPORTANT: the previous importer kept reusing product_data.xlsx forever.
        // Always download a fresh daily feed to a separate file. Only promote it
        // after the XLSX header and column count pass validation below.
        $tmp_file_path = $save_file_path . '.download-' . getmypid() . '.xlsx';
        xindao_log_event('Downloading fresh Xindao Products XLSX to a temporary file.');
        $context = stream_context_create(array('http' => array('timeout' => 120)));
        $file_content = @file_get_contents($file_url, false, $context);
        if ($file_content === false || strlen($file_content) < 1024
            || substr($file_content, 0, 2) !== 'PK') {
            xindao_log_event('ABORT: Products XLSX download failed or did not return a ZIP-based XLSX. Existing cached feed retained. No cleanup executed.', 'ERROR');
            exit(1);
        }
        $save_result = @file_put_contents($tmp_file_path, $file_content, LOCK_EX);
        if ($save_result !== strlen($file_content)) {
            @unlink($tmp_file_path);
            xindao_log_event('ABORT: incomplete temporary Products XLSX write. Existing feed retained. No cleanup executed.', 'ERROR');
            exit(1);
        }
        unset($file_content);
        xindao_log_event('Fresh Products XLSX downloaded into temporary file: ' . $save_result . ' bytes.');
        return $tmp_file_path;
    }

    $path = get_product_data_from_endpoint();

    if(!$path){
        xindao_log_event('Something went wrong while downloading Product Data', 'ERROR');
        die();
    }

    try {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $dataArray = $sheet->toArray();
    } catch (Throwable $e) {
        @unlink($path);
        xindao_log_event('ABORT: downloaded Products XLSX cannot be parsed: ' . $e->getMessage() . '. No cleanup executed.', 'ERROR');
        exit(1);
    }

    // Validate the complete expected Xindao layout BEFORE touching WooCommerce data.
    // A wrong/partial workbook must never be interpreted as discontinued products.
    $xdFeedHeaderRow = isset($dataArray[0]) && is_array($dataArray[0]) ? $dataArray[0] : array();
    $xdRequiredFeedHeaders = array(
        SKU => 'ModelCode',
        SKU2 => 'ItemCode',
        PRODUCT_LIFECYCLE => 'ProductLifeCycle',
        NAME => 'ItemName',
        CAT1 => 'MainCategory',
        CAT2 => 'SubCategory',
    );
    // The Xindao V5 Products feed may end at IC (zero-based index 236).
    // The old check required IE/PRICE1 (index 238), even though the master
    // importer never reads either STOCK (237) or PRICE1 (238). Stock is owned
    // by the separate Xindao Stock feed, and printing prices by their importer.
    // Validate the highest column ACTUALLY read, calculated from every mapped
    // attribute and every explicit Product-feed column, rather than unused ones.
    $xdRequiredDataColumn = max(array_merge(
        array_map('intval', array_values($attributes_for_import)),
        array(NAME, DESC, SKU, SKU2, PRODUCT_LIFECYCLE, HEIGHT, WIDTH, LENGTH,
              WEIGHT, IMAGES, MAIN_IMG_NEUTRAL, SIZE, COLOR, CAT1, CAT2, ECOAttribute)
    ));
    $xdHeaderCount = count($xdFeedHeaderRow);
    $xdFeedValid = $xdHeaderCount > $xdRequiredDataColumn && count($dataArray) > 1;
    $xdInvalidHeaders = array();
    foreach ($xdRequiredFeedHeaders as $xdColumn => $xdExpectedHeader) {
        $xdActualHeader = trim((string) ($xdFeedHeaderRow[$xdColumn] ?? ''));
        if (strcasecmp($xdActualHeader, $xdExpectedHeader) !== 0) {
            $xdFeedValid = false;
            $xdInvalidHeaders[] = 'column ' . ($xdColumn + 1) .
                ': expected ' . $xdExpectedHeader . ', got ' . var_export($xdActualHeader, true);
        }
    }
    if (!$xdFeedValid) {
        @unlink($path);
        xindao_log_event(
            'Xindao import ABORTED: invalid Products XLSX layout. Found ' . $xdHeaderCount .
            ' columns; minimum required: ' . ($xdRequiredDataColumn + 1) .
            '; rows including header: ' . count($dataArray) .
            ($xdInvalidHeaders ? '; invalid headers: ' . implode('; ', $xdInvalidHeaders) : '') .
            '. Cached good XLSX retained. No WooCommerce import or cleanup was executed.',
            'ERROR'
        );
        exit(1);
    }
    xindao_log_event('Xindao Products XLSX schema OK: ' . $xdHeaderCount .
        ' columns, last used zero-based index ' . $xdRequiredDataColumn . '.');

    // Promote validated feed atomically. A failed/short supplier download must
    // never replace a good XLSX or trigger the discontinued-product Trash pass.
    $canonicalProductFeedPath = ABSPATH . 'cron_files/product_data.xlsx';
    if (!@rename($path, $canonicalProductFeedPath)) {
        @unlink($path);
        xindao_log_event('ABORT: could not promote validated Products XLSX. Existing XLSX retained. No cleanup executed.', 'ERROR');
        exit(1);
    }
    xindao_log_event('Validated fresh Xindao Products XLSX promoted to ' . $canonicalProductFeedPath . '.');

    array_shift($dataArray);
    xindao_log_event('Xindao feed loaded and validated. Product rows: ' . count($dataArray) . '.');

    require_once __DIR__ . '/xd_importPrintPrices.php';
    xindao_log_event('Print prices import started.');
    importPrintPrices();
    xindao_log_event('Print prices import finished.');

    $global_attributes = ['Size', 'Color', 'color-group', 'Printing Technique Group'];
    foreach ($global_attributes as $global_attribute) {
        wc_create_attribute([
            'name' => $global_attribute,
            'type' => 'text',
        ]);
    }
    // wc_create_attribute() updates the database, but a newly created product
    // attribute taxonomy is normally registered only on the next WordPress request.
    // Register it for this import run as well so terms can be created immediately.
    if (!taxonomy_exists('pa_printing-technique-group')) {
        register_taxonomy(
            'pa_printing-technique-group',
            array('product'),
            array(
                'hierarchical'      => false,
                'show_ui'           => false,
                'show_in_nav_menus' => false,
                'query_var'         => true,
                'rewrite'           => false,
                'public'            => false,
            )
        );
    }

    foreach($attributes_for_import as $key => $id){
        wc_create_attribute([
            'name' => $key,
            'type' => 'text',
        ]);
    }

    wc_create_attribute([
        'name' => 'Specifications',
        'type' => 'text',
    ]);


    // Xindao feed contains one row per ItemCode/variation. Group all rows by ModelCode (SKU)
    // before the WooCommerce parent-product loop so one ModelCode can create/update only one parent.
    // The original rows remain available in $xindaoGroupRowsByIndex for variation creation.
    $xindaoGroupedProducts = array();
    $xindaoMissingSkuRows = array();

    foreach ($dataArray as $feedRowIndex => $feedRow) {
        $feedParentSku = isset($feedRow[SKU]) ? trim((string) $feedRow[SKU]) : '';

        if ($feedParentSku === '') {
            $xindaoMissingSkuRows[] = $feedRow;
            continue;
        }

        if (!isset($xindaoGroupedProducts[$feedParentSku])) {
            $xindaoGroupedProducts[$feedParentSku] = array(
                'representative' => $feedRow,
                'rows'           => array(),
            );
        }

        $xindaoGroupedProducts[$feedParentSku]['rows'][] = $feedRow;
    }

    $xindaoAggregateGroupColumn = static function (array $rows, $columnIndex) {
        $values = array();

        foreach ($rows as $row) {
            $rawValue = isset($row[$columnIndex]) ? trim((string) $row[$columnIndex]) : '';
            if ($rawValue === '') {
                continue;
            }

            foreach (explode(',', $rawValue) as $value) {
                $value = trim($value);
                if ($value !== '' && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return implode(',', $values);
    };

    $groupedDataArray = array();
    $xindaoGroupRowsByIndex = array();

    foreach ($xindaoGroupedProducts as $feedParentSku => $groupData) {
        $representativeRow = $groupData['representative'];
        $groupRows = $groupData['rows'];

        // These values belong to the complete ModelCode, not only to its first ItemCode row.
        // Aggregate them before the existing attribute mapping/import logic runs.
        // ProductLifeCycle is also aggregated so a parent is supplier-NEW when
        // at least one ItemCode in the ModelCode group is marked New.
        $representativeRow[PRODUCT_LIFECYCLE] = $xindaoAggregateGroupColumn($groupRows, PRODUCT_LIFECYCLE);
        $representativeRow[COLOR] = $xindaoAggregateGroupColumn($groupRows, COLOR);
        $representativeRow[SIZE] = $xindaoAggregateGroupColumn($groupRows, SIZE);
        $representativeRow[(int) $attributes_for_import['Material']] = $xindaoAggregateGroupColumn(
            $groupRows,
            (int) $attributes_for_import['Material']
        );
        $representativeRow[(int) $attributes_for_import['AllPrintCodes']] = $xindaoAggregateGroupColumn(
            $groupRows,
            (int) $attributes_for_import['AllPrintCodes']
        );

        $groupedDataArray[] = $representativeRow;
        $xindaoGroupRowsByIndex[] = $groupRows;
    }

    // Keep invalid rows visible to the existing skip/logging branch.
    foreach ($xindaoMissingSkuRows as $missingSkuRow) {
        $groupedDataArray[] = $missingSkuRow;
        $xindaoGroupRowsByIndex[] = array($missingSkuRow);
    }

    $dataArray = $groupedDataArray;
    $lenght = count($dataArray);

    xindao_log_event(
        'Feed grouped by ModelCode. Raw rows: ' .
        (array_sum(array_map(static function ($groupData) { return count($groupData['rows']); }, $xindaoGroupedProducts)) + count($xindaoMissingSkuRows)) .
        ', parent groups: ' . count($xindaoGroupedProducts) .
        ', rows without ModelCode: ' . count($xindaoMissingSkuRows) . '.'
    );

    // Build the authoritative list of Xindao parent SKUs directly from the complete feed.
    // Cleanup must never depend on the runtime import log because a resumed/partial run
    // can legitimately contain only a subset of the catalog.
    $currentFeedParentSkus = array();
    foreach ($dataArray as $feedRow) {
        $feedParentSku = isset($feedRow[SKU]) ? trim((string) $feedRow[SKU]) : '';
        if ($feedParentSku !== '') {
            $currentFeedParentSkus[] = SKU_PREFIX . $feedParentSku;
        }
    }
    $currentFeedParentSkus = array_values(array_unique($currentFeedParentSkus));

    $groupedResumeMode = 'modelcode-grouped-new-hub-v2';
    $savedResumeMode = (string) get_option('xd_last_master_import_mode', '');
    $index = intval(get_option('xd_last_master_import_index', 0));

    // An index saved by an older row-by-row importer does not represent a grouped parent index.
    // Reset it once when switching modes; subsequent interrupted grouped runs can resume normally.
    if ($savedResumeMode !== $groupedResumeMode) {
        if ($index > 0) {
            xindao_log_event(
                'Resetting legacy row-based resume index ' . $index . ' before first grouped ModelCode import.',
                'WARNING'
            );
        }
        $index = 0;
        update_option('xd_last_master_import_index', 0);
        update_option('xd_last_master_import_mode', $groupedResumeMode);
    }

    $singleProducts = array();
    $printPrices = get_option('xd_printprices');

    // NEW Hub is the single authority for the effective WooCommerce NEW state.
    // The importer only reports Xindao's ProductLifeCycle signal.
    $newHubAvailable = function_exists('michel_new_hub_set_source_state');
    if (!$newHubAvailable) {
        xindao_log_event(
            'CRITICAL: Michel NEW Hub supplier API is missing. Import stopped to prevent creating products without required NEW state.',
            'ERROR'
        );
        exit(1);
    }

    $xindaoImportStartedAt = microtime(true);
    $xindaoImportStats = array(
        'processed' => 0,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
    );

    if ($index === 0) {
        xindao_reset_imported_sku_log();
    } else {
        xindao_log_event('Resuming interrupted import from saved index ' . $index . '.');
    }

    xindao_log_event('Products import started. Index: ' . $index . ' of ' . $lenght . '. Feed parent SKUs: ' . count($currentFeedParentSkus));

    while ($index < $lenght) {
        switch (true) {
            case ( $dataArray[$index][SKU] ): {

                $product_id = wc_get_product_id_by_sku(SKU_PREFIX . $dataArray[$index][SKU]);
                $is_new_product = ($product_id === 0);
                $createdOrUpdated = $is_new_product ? 'Created' : 'Updated';
                // Parse the grouped parent lifecycle BEFORE saving. Needed to persist
                // a retry marker together with a newly created supplier-NEW product.
                $xdLifeCycles = array_values(array_filter(array_map(
                    static function ($value) { return strtolower(trim((string) $value)); },
                    explode(',', isset($dataArray[$index][PRODUCT_LIFECYCLE])
                        ? (string) $dataArray[$index][PRODUCT_LIFECYCLE] : '')
                ), 'strlen'));
                $xdIsNew = in_array('new', $xdLifeCycles, true);
                $product = new WC_Product_Variable($product_id);
                $is_variable = true;
                
                $featuredPhoto = null;
                $attach_ids = null;
                
                // Images are imported only when a product is created.
                // Existing products must retain all current images and gallery data.
                if ($is_new_product) {
                    $img_links = explode(',', $dataArray[$index][IMAGES]);
                    if ($img_links) {
                        $attach_ids = [];
                        foreach ($img_links as $img_link_index => $img_link) {
                            $img_link = trim($img_link);
                            $image_name = 'x' . SKU_PREFIX . $dataArray[$index][SKU] . '-' . $dataArray[$index][SKU2] . '-' . $img_link_index;
                            $attach_ids[] = api_upload_from_url('', $img_link, $image_name, $product_id, true);
                        }
                        $featuredPhoto = $attach_ids[1] ?? null;
                        if (isset($attach_ids[1])) {
                            unset($attach_ids[1]);
                        }
                    }
                    $product->set_image_id($featuredPhoto);
                    $product->set_gallery_image_ids($attach_ids ?: []);
                }

                if($is_new_product){
                    $product->set_name($dataArray[$index][NAME]);
                    $product->set_description($dataArray[$index][DESC]);
                    $product->set_manage_stock(false);
                    $product->update_meta_data('api_imported_from', 'xindao-api');

                    // Set specifications
                    $product->set_gallery_image_ids($attach_ids);
                    $product->set_height($dataArray[$index][HEIGHT]);
                    $product->set_width($dataArray[$index][WIDTH]);
                    $product->set_weight($dataArray[$index][WEIGHT]);
                }

                if ($is_new_product) {
                    $addEcoCategory = $dataArray[$index][ECOAttribute] == 'TRUE';
                    handle_setting_categories($product, $promo_main_categories_dictionary, $promo_sub_categories_dictionary, $dataArray[$index][CAT1], $dataArray[$index][CAT2], $addEcoCategory, SKU_PREFIX . $dataArray[$index][SKU]);
                    $product->set_sku(SKU_PREFIX . $dataArray[$index][SKU]);
                }

                // Import or update attributes
                $variationTerms = array();
                $printCodes = array();
                $defaultAttribute = array();
                $specificationsTerms = array();
                $editAttributes = $is_variable ? $product->get_attributes('edit') : array();

                // New products receive attributes aggregated from every ItemCode row of the ModelCode.
                // Existing products retain all current attributes, except Material and AllPrintCodes,
                // which are refreshed from the complete grouped feed data. Existing pa_color is never changed.
                // The grouped raw Color feed values are only read below to rebuild pa_color-group.
                // Printing Technique Group is rebuilt separately from pa_allprintcodes.
                $attributes = $is_new_product ? array() : $editAttributes;
                $replaceableAttributes = ['Material', 'AllPrintCodes'];

                foreach($attributes_for_import as $attributeName => $attributeKey){
                    if (!$is_new_product && !in_array($attributeName, $replaceableAttributes, true)) {
                        continue;
                    }

                    if($attributeName == 'Brand' && in_array($dataArray[$index][$attributeKey], $avoidBrands)){
                        xindao_log_event('Skipping Brand ' . $dataArray[$index][$attributeKey]);
                        continue;
                    }
                    
                    if($dataArray[$index][$attributeKey] == 'TRUE'){
                        $specificationsTerms[] = $attributeName;
                    }

                    $attributeNameSlug = 'pa_' . sanitize_title($attributeName);
                    $attributeValues = $dataArray[$index][$attributeKey];

                    // Only Material is standardized.
                    // Color keeps its source value; AllPrintCodes normalizes only the no-print alias to bez-tiska.
                    if ($attributeValues && $attributeName === 'Material') {
                        $productSkuForLog = SKU_PREFIX . $dataArray[$index][SKU];
                        $mainCategory = delete_new_line_chars($dataArray[$index][CAT1]);
                        $isTextileProduct = in_array($mainCategory, ['Textile', 'Headwear'], true);
                        $attributeValues = map_xindao_material_values($attributeValues, $isTextileProduct, $productSkuForLog);
                    }

                    if($attributeName == 'AllPrintCodes' && $attributeValues && !in_array(delete_new_line_chars($dataArray[$index][CAT1]),$avoidPrintCodes)){

                        $printCodes = explode(',', $attributeValues);
                        $printCodes = array_unique($printCodes);

                        if($printCodes){
                            $printCodes[] = 'Bez tiska';
                        }

                    }

                    $mappedVariationValue = '';

                    if($attributeValues){

                        $attributeValues = array_values(array_unique(array_filter(array_map('trim', explode(',', $attributeValues)), 'strlen')));
                        if ($attributeName === 'AllPrintCodes') {
                            $attributeValues = array_values(array_unique(array_map('xindao_canonical_print_code', $attributeValues)));
                        }

                        if(($attributeName == 'TextileSize' || $attributeName == 'Color') && !empty($attributeValues)){
                            $mappedVariationValue = $attributeValues[0];
                        }

                        if($attributeName == 'AllPrintCodes' && $attributeValues){
                            $attributeValues[] = 'Bez tiska';
                            // Transitional compatibility: on existing parents preserve the legacy
                            // English term IF it was already offered. We must not orphan live
                            // -without-printing variations before the separate, audited cleanup.
                            // After cleanup removes that option, future imports will not re-add it.
                            $oldPrintAttribute = $editAttributes[$attributeNameSlug] ?? null;
                            if (!$is_new_product && $oldPrintAttribute instanceof WC_Product_Attribute) {
                                foreach ($oldPrintAttribute->get_options() as $oldPrintOption) {
                                    $oldPrintTerm = is_numeric($oldPrintOption)
                                        ? get_term((int) $oldPrintOption, $attributeNameSlug)
                                        : get_term_by('slug', (string) $oldPrintOption, $attributeNameSlug);
                                    if ($oldPrintTerm && !is_wp_error($oldPrintTerm)
                                        && $oldPrintTerm->slug === 'without-printing') {
                                        $attributeValues[] = $oldPrintTerm->name;
                                        break;
                                    }
                                }
                            }
                            $attributeValues = array_values(array_unique($attributeValues));
                        }

                        $attributeTermIds = array();
                        foreach($attributeValues as $value){
                            $newTerm = get_or_create_term_by_slug($value, $value, $attributeNameSlug);
                            if($newTerm){
                                $attributeTermIds[] = $newTerm;
                            }
                        }
                        $attributeValues = array_values(array_unique($attributeTermIds));
                        if($is_variable){
                            // Refreshed feed attributes are fully recalculated from the current feed.
                            // Do not retain obsolete values from previous imports.
                            if(!in_array($attributeName, ['Material', 'Color', 'AllPrintCodes'], true) && array_key_exists($attributeNameSlug, $editAttributes)){
                                $oldAttributes = $editAttributes[$attributeNameSlug];
                                $oldAttributes = $oldAttributes->get_options();
                                $attributeValues = array_merge($oldAttributes, $attributeValues);
                                $attributeValues = array_unique($attributeValues);
                            }
                        }

                        $WcProductAttribute = new WC_Product_Attribute();
                        $WcProductAttribute->set_id(wc_attribute_taxonomy_id_by_name(sanitize_title($attributeName)));
                        $WcProductAttribute->set_name($attributeNameSlug);
                        $WcProductAttribute->set_options($attributeValues);
                        $WcProductAttribute->set_visible(true);

                        if($attributeName == 'AllPrintCodes' && !in_array(delete_new_line_chars($dataArray[$index][CAT1]),$avoidPrintCodes)){
                            $WcProductAttribute->set_variation(true);
                            $WcProductAttribute->set_visible(false);
                            if($dataArray[$index][$attributeKey]){
                                $defaultAttribute[$attributeNameSlug] = sanitize_title('Bez tiska');
                            }
                        }

                        if($attributeName == 'TextileSize' || $attributeName == 'Color'){
                            $WcProductAttribute->set_variation(true);
                            $WcProductAttribute->set_visible(false);

                            if($mappedVariationValue !== ''){
                                $mappedVariationSlug = sanitize_title($mappedVariationValue);
                                $variationTerms['attribute_' . $attributeNameSlug] = $mappedVariationSlug;
                                $defaultAttribute[$attributeNameSlug] = $mappedVariationSlug;
                            }
                        }

                        $attributes[$attributeNameSlug] = $WcProductAttribute;
                    }

                }

                // Build a separate non-variation color-group attribute from the ORIGINAL Color feed value.
                // pa_color itself remains untouched/original and continues to drive variations.
                $rawColorValues = trim((string) $dataArray[$index][COLOR]);
                if ($rawColorValues !== '') {
                    $productSkuForLog = SKU_PREFIX . $dataArray[$index][SKU];
                    $colorGroupValues = map_xindao_color_group_values($rawColorValues, $productSkuForLog);

                    if ($colorGroupValues !== '') {
                        $colorGroupTermIds = array();
                        foreach (array_values(array_unique(array_filter(array_map('trim', explode(',', $colorGroupValues)), 'strlen'))) as $colorGroupValue) {
                            $newTerm = get_or_create_term_by_slug($colorGroupValue, $colorGroupValue, 'pa_color-group');
                            if ($newTerm) {
                                $colorGroupTermIds[] = $newTerm;
                            }
                        }

                        if ($colorGroupTermIds) {
                            $colorGroupAttribute = new WC_Product_Attribute();
                            $colorGroupAttribute->set_id(wc_attribute_taxonomy_id_by_name('color-group'));
                            $colorGroupAttribute->set_name('pa_color-group');
                            $colorGroupAttribute->set_options(array_values(array_unique($colorGroupTermIds)));
                            $colorGroupAttribute->set_visible(true);
                            $colorGroupAttribute->set_variation(false);
                            $attributes['pa_color-group'] = $colorGroupAttribute;
                        }
                    }
                }

                // Build a separate non-variation Printing Technique Group for every product
                // that actually has pa_allprintcodes. Raw/new techniques stay in pa_allprintcodes.
                // Only techniques present in the mapping are added to pa_printing-technique-group;
                // unmapped techniques are logged and are NOT added to the grouped attribute.
                unset($attributes['pa_printing-technique-group']);
                if (
                    isset($attributes['pa_allprintcodes']) &&
                    $attributes['pa_allprintcodes'] instanceof WC_Product_Attribute
                ) {
                    $printingTechniqueGroupSourceValues = array();
                    foreach ($attributes['pa_allprintcodes']->get_options() as $printingTechniqueOption) {
                        if (is_numeric($printingTechniqueOption)) {
                            $printingTechniqueTerm = get_term((int) $printingTechniqueOption, 'pa_allprintcodes');
                            if ($printingTechniqueTerm && !is_wp_error($printingTechniqueTerm)) {
                                $printingTechniqueGroupSourceValues[] = $printingTechniqueTerm->name;
                            }
                        } elseif (is_scalar($printingTechniqueOption)) {
                            $printingTechniqueOption = trim((string) $printingTechniqueOption);
                            if ($printingTechniqueOption !== '') {
                                $printingTechniqueGroupSourceValues[] = $printingTechniqueOption;
                            }
                        }
                    }

                    $printingTechniqueGroupSourceValues = array_values(array_unique($printingTechniqueGroupSourceValues));
                    $rawPrintingTechniqueValues = implode(',', $printingTechniqueGroupSourceValues);

                    if ($rawPrintingTechniqueValues !== '') {
                        $printingTechniqueGroupValues = map_xindao_printing_technique_group_values(
                            $rawPrintingTechniqueValues,
                            SKU_PREFIX . $dataArray[$index][SKU]
                        );

                        if ($printingTechniqueGroupValues !== '') {
                            $printingTechniqueGroupTermIds = array();
                            foreach (
                                array_values(
                                    array_unique(
                                        array_filter(
                                            array_map('trim', explode(',', $printingTechniqueGroupValues)),
                                            'strlen'
                                        )
                                    )
                                ) as $printingTechniqueGroupValue
                            ) {
                                $newTerm = get_or_create_term_by_slug(
                                    $printingTechniqueGroupValue,
                                    $printingTechniqueGroupValue,
                                    'pa_printing-technique-group'
                                );
                                if ($newTerm) {
                                    $printingTechniqueGroupTermIds[] = $newTerm;
                                }
                            }

                            if ($printingTechniqueGroupTermIds) {
                                $printingTechniqueGroupAttribute = new WC_Product_Attribute();
                                $printingTechniqueGroupAttribute->set_id(
                                    wc_attribute_taxonomy_id_by_name('printing-technique-group')
                                );
                                $printingTechniqueGroupAttribute->set_name('pa_printing-technique-group');
                                $printingTechniqueGroupAttribute->set_options(
                                    array_values(array_unique($printingTechniqueGroupTermIds))
                                );
                                $printingTechniqueGroupAttribute->set_visible(true);
                                $printingTechniqueGroupAttribute->set_variation(false);
                                $attributes['pa_printing-technique-group'] = $printingTechniqueGroupAttribute;
                            }
                        }
                    }
                }

                if($is_new_product && $specificationsTerms){

                    foreach($specificationsTerms as $key => $value){
                        $newTerm = get_or_create_term_by_slug($value, $value, 'pa_specifications');
                        $specificationsTerms[] = $newTerm ? $newTerm : $value;
                        unset($specificationsTerms[$key]);
                    }

                    $WcProductAttribute = new WC_Product_Attribute();
                    $WcProductAttribute->set_id(wc_attribute_taxonomy_id_by_name('specifications'));
                    $WcProductAttribute->set_name('pa_specifications');
                    $WcProductAttribute->set_options($specificationsTerms);
                    $WcProductAttribute->set_visible(false);
                    $attributes['pa_specifications'] = $WcProductAttribute;
                }

                // An existing XD model can receive NEW ItemCodes with NEW colors or sizes.
                // Extend (never replace) those two parent variation attributes so newly
                // created variation combinations remain selectable in WooCommerce.
                if (!$is_new_product) {
                    $sourceGroupRows = isset($xindaoGroupRowsByIndex[$index])
                        ? $xindaoGroupRowsByIndex[$index] : array($dataArray[$index]);
                    foreach (array(COLOR => 'pa_color', SIZE => 'pa_textilesize') as $sourceColumn => $taxonomy) {
                        $termsToAdd = array();
                        foreach ($sourceGroupRows as $sourceGroupRow) {
                            $sourceValues = array_values(array_filter(array_map('trim',
                                explode(',', (string) ($sourceGroupRow[$sourceColumn] ?? ''))), 'strlen'));
                            if (!$sourceValues) { continue; }
                            $termId = get_or_create_term_by_slug($sourceValues[0], $sourceValues[0], $taxonomy);
                            if ($termId) { $termsToAdd[] = (int) $termId; }
                        }
                        if (!$termsToAdd) { continue; }
                        if (isset($attributes[$taxonomy]) && $attributes[$taxonomy] instanceof WC_Product_Attribute) {
                            $variationAttribute = $attributes[$taxonomy];
                            $oldTermIds = (array) $variationAttribute->get_options();
                        } else {
                            $variationAttribute = new WC_Product_Attribute();
                            $variationAttribute->set_id(wc_attribute_taxonomy_id_by_name(substr($taxonomy, 3)));
                            $variationAttribute->set_name($taxonomy);
                            $variationAttribute->set_visible(false);
                            $variationAttribute->set_variation(true);
                            $oldTermIds = array();
                        }
                        // Existing taxonomy options are normally term IDs; some older
                        // imports may store slugs. Resolve them instead of intval('slug')=0.
                        $resolvedOldTermIds = array();
                        $unresolvedOldOption = false;
                        foreach ($oldTermIds as $oldOption) {
                            if (is_numeric($oldOption) && (int) $oldOption > 0) {
                                $resolvedOldTermIds[] = (int) $oldOption;
                            } else {
                                $term = get_term_by('slug', (string) $oldOption, $taxonomy);
                                if ($term && !is_wp_error($term)) {
                                    $resolvedOldTermIds[] = (int) $term->term_id;
                                } else {
                                    $unresolvedOldOption = true;
                                }
                            }
                        }
                        if ($unresolvedOldOption) {
                            xindao_log_event('Preserving parent variation attribute unchanged due to an unknown existing option: '
                                . $taxonomy . ' | SKU ' . SKU_PREFIX . $dataArray[$index][SKU], 'WARNING');
                            continue;
                        }
                        $mergedTermIds = array_values(array_unique(array_merge($resolvedOldTermIds, $termsToAdd)));
                        if ($mergedTermIds !== array_values(array_unique($resolvedOldTermIds))) {
                            $variationAttribute->set_options($mergedTermIds);
                            $attributes[$taxonomy] = $variationAttribute;
                        }
                    }
                }

                $product->set_attributes($attributes);

                if ($is_new_product && $xdIsNew) {
                    // Recover safely if the NEW Hub write fails after this product save.
                    $product->update_meta_data('_xindao_new_hub_pending', '1');
                }

                xindao_append_imported_sku(SKU_PREFIX . $dataArray[$index][SKU]);

                $product_id = $product->save();

                /**
                 * Xindao NEW synchronization with Michel NEW Hub:
                 * - Enable the supplier source only for a newly created XD NEW product,
                 *   or retry an unsuccessful initial source write (existing behavior).
                 * - If an EXISTING XD product is no longer New in ProductLifeCycle,
                 *   disable its Xindao source and let the Hub remove category "new".
                 * - Never remove product_cat "new" directly: the Hub must preserve it
                 *   if another supplier, Permanent, Until or automatic NEW is active.
                 * - Also reconcile stale category-only states on non-NEW XD products.
                 *
                 * All other product categories remain untouched.
                 */
                $xdPendingNewSync = get_post_meta($product_id, '_xindao_new_hub_pending', true) === '1';
                $needsXdNewEnable = $xdIsNew && ($is_new_product || $xdPendingNewSync);
                $needsXdNewDisable = false;
                $xdHadNewCategory = false;

                if (!$xdIsNew && !$is_new_product) {
                    $xdCurrentSources = (array) get_post_meta($product_id, '_michel_new_hub_supplier_new', false);
                    $xdHadNewCategory = has_term('new', 'product_cat', $product_id);
                    // Reconcile only XD products that may still have a NEW state.
                    $needsXdNewDisable = in_array('xindao', $xdCurrentSources, true) || $xdHadNewCategory;
                }

                if ($needsXdNewEnable || $needsXdNewDisable) {
                    $newHubResult = michel_new_hub_set_source_state(
                        $product_id,
                        'xindao',
                        $needsXdNewEnable
                    );

                    if (is_wp_error($newHubResult) || $newHubResult !== true) {
                        xindao_log_event(
                            'NEW Hub Xindao ' . ($needsXdNewEnable ? 'enable' : 'disable') . ' failed for ' .
                            SKU_PREFIX . $dataArray[$index][SKU] .
                            ': ' . (is_wp_error($newHubResult) ? $newHubResult->get_error_message() : 'unknown error') .
                            '. Will retry at next import.',
                            'ERROR'
                        );
                    } else {
                        delete_post_meta($product_id, '_xindao_new_hub_pending');

                        if ($needsXdNewDisable) {
                            $xdHasNewCategoryAfter = has_term('new', 'product_cat', $product_id);
                            if ($xdHadNewCategory && !$xdHasNewCategoryAfter) {
                                xindao_log_event(
                                    'Removed category new after Xindao ProductLifeCycle changed away from New. SKU: ' .
                                    SKU_PREFIX . $dataArray[$index][SKU] . ', ID: ' . $product_id
                                );
                            } elseif ($xdHasNewCategoryAfter) {
                                xindao_log_event(
                                    'Xindao NEW source disabled; category new retained by another active Hub NEW reason. SKU: ' .
                                    SKU_PREFIX . $dataArray[$index][SKU] . ', ID: ' . $product_id
                                );
                            }
                        }
                    }
                } elseif (!$xdIsNew && $xdPendingNewSync) {
                    // The feed no longer says New: cancel any unfinished initial enable.
                    delete_post_meta($product_id, '_xindao_new_hub_pending');
                }

                $importedFeat = false;
                // Reconcile missing ItemCodes / printing variations for new AND existing parent models.
                // Existing variations (their prices, images, attributes) are never overwritten here.
                // Newly created variations await the separate XD stock importer.
                if ($product_id > 0) {
                    $groupRows = isset($xindaoGroupRowsByIndex[$index]) && is_array($xindaoGroupRowsByIndex[$index])
                        ? $xindaoGroupRowsByIndex[$index]
                        : array($dataArray[$index]);
                    $mainCategoryForVariations = delete_new_line_chars($dataArray[$index][CAT1]);
                    $usePrintCodeVariations = !in_array($mainCategoryForVariations, $avoidPrintCodes, true);
                    $createdVariationCount = 0;

                    foreach ($groupRows as $variationRow) {
                        $rowSku2Raw = isset($variationRow[SKU2]) ? trim((string) $variationRow[SKU2]) : '';
                        if ($rowSku2Raw === '') {
                            xindao_log_event(
                                'Variation skipped because ItemCode/SKU2 is empty for parent ' . SKU_PREFIX . $dataArray[$index][SKU] . '.',
                                'WARNING'
                            );
                            continue;
                        }

                        $rowVariationTerms = array();

                        $rowColorValues = array_values(array_filter(array_map(
                            'trim',
                            explode(',', isset($variationRow[COLOR]) ? (string) $variationRow[COLOR] : '')
                        ), 'strlen'));
                        if ($rowColorValues) {
                            $rowVariationTerms['attribute_pa_color'] = sanitize_title($rowColorValues[0]);
                        }

                        $rowSizeValues = array_values(array_filter(array_map(
                            'trim',
                            explode(',', isset($variationRow[SIZE]) ? (string) $variationRow[SIZE] : '')
                        ), 'strlen'));
                        if ($rowSizeValues) {
                            $rowVariationTerms['attribute_pa_textilesize'] = sanitize_title($rowSizeValues[0]);
                        }

                        if (!$rowVariationTerms) {
                            // Preserve the previous behavior: only rows that expose a variation attribute
                            // become WooCommerce variations.
                            continue;
                        }

                        $rowPrintCodes = array();
                        if ($usePrintCodeVariations) {
                            $rawRowPrintCodes = isset($variationRow[(int) $attributes_for_import['AllPrintCodes']])
                                ? trim((string) $variationRow[(int) $attributes_for_import['AllPrintCodes']])
                                : '';

                            if ($rawRowPrintCodes !== '') {
                                $rowPrintCodes = array_values(array_unique(array_filter(array_map(
                                    'xindao_canonical_print_code',
                                    explode(',', $rawRowPrintCodes)
                                ), 'strlen')));

                                if ($rowPrintCodes) {
                                    $rowPrintCodes[] = 'Bez tiska';
                                    $rowPrintCodes = array_values(array_unique($rowPrintCodes));
                                }
                            }
                        }

                        // Define expected SKUs before doing any media requests. On a normal daily
                        // import the existing variations are NOT touched (including their images).
                        $variationDefinitions = array();
                        if ($rowPrintCodes) {
                            foreach ($rowPrintCodes as $rowPrintCode) {
                                $termsForVariation = $rowVariationTerms;
                                $termsForVariation['attribute_pa_allprintcodes'] = sanitize_title($rowPrintCode);
                                $variationDefinitions[] = array(
                                    'sku' => SKU_PREFIX . $rowSku2Raw . '-' . sanitize_title($rowPrintCode),
                                    'attributes' => $termsForVariation,
                                );
                            }
                        } else {
                            $variationDefinitions[] = array(
                                'sku' => SKU_PREFIX . $rowSku2Raw,
                                'attributes' => $rowVariationTerms,
                            );
                        }

                        $missingDefinitions = array();
                        foreach ($variationDefinitions as $variationDefinition) {
                            $sku = $variationDefinition['sku'];
                            $variationID = (int) wc_get_product_id_by_sku($sku);
                            if ($variationID === 0) {
                                // A mixed old catalog may have a priced -without-printing
                                // variation instead of -bez-tiska. Do NOT create a second
                                // no-price no-printing child. Reconcile this separately.
                                if (substr($sku, -strlen('-bez-tiska')) === '-bez-tiska') {
                                    $legacySku = substr($sku, 0, -strlen('-bez-tiska')) . '-without-printing';
                                    $legacyId = (int) wc_get_product_id_by_sku($legacySku);
                                    if ($legacyId && get_post_type($legacyId) === 'product_variation'
                                        && (int) wp_get_post_parent_id($legacyId) === (int) $product_id
                                        && in_array(get_post_status($legacyId), array('publish', 'private'), true)) {
                                        xindao_log_event('Canonical no-print SKU blocked by existing legacy sibling; manual review required: '
                                            . $sku . ' | legacy ' . $legacySku . ' (ID ' . $legacyId . ')', 'WARNING');
                                        continue;
                                    }
                                }
                                $missingDefinitions[] = $variationDefinition;
                            } else {
                                // SKU collisions must not silently be treated as healthy XD variations.
                                if (get_post_type($variationID) !== 'product_variation' ||
                                    (int) wp_get_post_parent_id($variationID) !== (int) $product_id ||
                                    get_post_meta($variationID, 'api_imported_from', true) !== 'xindao-api') {
                                    xindao_log_event('Existing SKU collision prevents XD variation import: ' .
                                        $sku . ', ID: ' . $variationID . ', expected parent ID: ' . $product_id, 'ERROR');
                                }
                            }
                            xindao_append_imported_sku($sku);
                        }
                        if (!$missingDefinitions) {
                            continue;
                        }

                        // Only download images for ItemCodes with genuinely missing variations.
                        $variationFeaturedPhoto = null;
                        $variationAttachIds = array();
                        $variationImageLinks = array_values(array_filter(array_map(
                            'trim',
                            explode(',', isset($variationRow[IMAGES]) ? (string) $variationRow[IMAGES] : '')
                        ), 'strlen'));
                        if ($variationImageLinks) {
                            foreach ($variationImageLinks as $variationImageIndex => $variationImageLink) {
                                $variationImageName = 'x' . SKU_PREFIX . $dataArray[$index][SKU]
                                    . '-' . $rowSku2Raw . '-' . $variationImageIndex;
                                $variationImageId = api_upload_from_url(
                                    '',
                                    $variationImageLink,
                                    $variationImageName,
                                    $product_id,
                                    true
                                );
                                if ($variationImageId) {
                                    $variationAttachIds[] = $variationImageId;
                                }
                            }
                            $variationFeaturedPhoto = $variationAttachIds[1] ?? ($variationAttachIds[0] ?? null);
                            if (isset($variationAttachIds[1])) {
                                unset($variationAttachIds[1]);
                            } elseif (isset($variationAttachIds[0]) && $variationFeaturedPhoto === $variationAttachIds[0]) {
                                unset($variationAttachIds[0]);
                            }
                            $variationAttachIds = array_values(array_filter($variationAttachIds));
                        }

                        foreach ($missingDefinitions as $variationDefinition) {
                            $sku = $variationDefinition['sku'];
                            $variation = new WC_Product_Variation();
                            $variation->set_parent_id($product_id);
                            $variation->set_attributes($variationDefinition['attributes']);
                            $parentFeaturedPhoto = (int) $product->get_image_id();
                            $variation->set_image_id($variationFeaturedPhoto ?: ($featuredPhoto ?: $parentFeaturedPhoto));
                            $variation->update_meta_data(
                                'wd_additional_variation_images_data',
                                implode(',', $variationFeaturedPhoto
                                    ? $variationAttachIds
                                    : (is_array($attach_ids) ? $attach_ids : array()))
                            );
                            $variation->set_sku($sku);
                            $variation->update_meta_data('xindao_api_sku', SKU_PREFIX . $rowSku2Raw);
                            $variation->set_manage_stock(false);
                            $variation->set_stock_status('outofstock');
                            $variation->update_meta_data('api_imported_from', 'xindao-api');
                            $variation->save();
                            $createdVariationCount++;
                        }
                    }

                    if ($createdVariationCount > 0) {
                        WC_Product_Variable::sync((int) $product_id);
                        if (function_exists('wc_delete_product_transients')) {
                            wc_delete_product_transients((int) $product_id);
                        }
                    }
                    xindao_log_event(
                        'Grouped variations processed for parent ' . SKU_PREFIX . $dataArray[$index][SKU] .
                        '. Source ItemCode rows: ' . count($groupRows) .
                        ', newly created variations: ' . $createdVariationCount . '.'
                    );
                }

                $product = new WC_Product_Variable($product_id);
                if ($is_new_product) {
                    if ($defaultAttribute) {
                        $product->set_default_attributes($defaultAttribute);
                    }
                    $product->set_image_id($featuredPhoto);
                    $product->save();
                }

                $singleProducts[SKU_PREFIX . $dataArray[$index][SKU2]]['id'] = $product_id;
                $singleProducts[SKU_PREFIX . $dataArray[$index][SKU2]]['index'] = $index;

                $xindaoImportStats['processed']++;
                if ($is_new_product) {
                    $xindaoImportStats['created']++;
                } else {
                    $xindaoImportStats['updated']++;
                }

                $xindaoProgressPercent = $lenght > 0 ? round((($index + 1) / $lenght) * 100, 2) : 100;
                xindao_log_event(
                    'Product ' . ($index + 1) . '/' . $lenght .
                    ' (' . $xindaoProgressPercent . '%)' .
                    ' | SKU: ' . SKU_PREFIX . $dataArray[$index][SKU] .
                    ' | ' . strtoupper($createdOrUpdated) .
                    ' | ID: ' . $product_id
                );

                $index++;
                update_option('xd_last_master_import_index', $index);
                break;
            }

            default: {
                $xindaoImportStats['skipped']++;
                xindao_log_event('Proizvod ne može biti importan: ' . $dataArray[$index][SKU], 'WARNING');
                $index++;
                update_option('xd_last_master_import_index', $index);
                break;
            }
        }
    }
} else {
    xindao_log_event('Xindao import ABORTED: WooCommerce is not active. No cleanup was executed.', 'ERROR');
    exit(1);
}

xindao_log_event('Started checking products missing from the current feed.');

// Scan ONLY Xindao parent products, fetching IDs rather than full WP_Post objects.
// Cleanup uses the FULL XLSX feed, never a partial import log / resume index.
$xdProductIds = get_posts(array(
    'post_type'      => 'product',
    'post_status'    => array('publish', 'private', 'pending', 'draft'),
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => 'api_imported_from',
    'meta_value'     => 'xindao-api',
));

$products_list = array();
foreach ((array) $xdProductIds as $xdProductId) {
    $xdProductId = (int) $xdProductId;
    $xdProductSku = trim((string) get_post_meta($xdProductId, '_sku', true));
    if ($xdProductId > 0 && $xdProductSku !== '') {
        $products_list[$xdProductId] = $xdProductSku;
    }
}
unset($xdProductIds);

$existingProductCount = count($products_list);
$feedProductCount = isset($currentFeedParentSkus) && is_array($currentFeedParentSkus)
    ? count($currentFeedParentSkus)
    : 0;

// Empty or structurally invalid supplier data is never a valid reason to trash products.
if ($feedProductCount === 0) {
    xindao_log_event('Xindao cleanup ABORTED: feed contains zero valid parent SKUs. No products were moved to Trash.', 'ERROR');
    exit(1);
}

// Hash lookup avoids an expensive array_diff comparison for large catalogues.
$xdFeedSkuLookup = array_fill_keys($currentFeedParentSkus, true);
$productsToDelete = array();
foreach ($products_list as $xdProductId => $xdProductSku) {
    if (!isset($xdFeedSkuLookup[$xdProductSku])) {
        $productsToDelete[$xdProductId] = $xdProductSku;
    }
}
unset($products_list);

$deleteCount = count($productsToDelete);
$deleteRatio = $existingProductCount > 0 ? $deleteCount / $existingProductCount : 0;
$xdTrashedCount = 0;
$xdTrashFailedIds = array();

// Each batch handles at most 50 products (and never more than 20% of the
// original XD catalogue); the TOTAL can exceed 20% without a hard stop.
$xdTrashBatchSize = min(50, max(1, (int) floor($existingProductCount * 0.20)));

if ($deleteCount > 0) {
    // With EMPTY_TRASH_DAYS=0 WordPress may permanently delete instead of trashing.
    // That would violate the requirement that products must remain recoverable in Trash.
    if (defined('EMPTY_TRASH_DAYS') && (int) EMPTY_TRASH_DAYS <= 0) {
        xindao_log_event('Xindao cleanup ABORTED: EMPTY_TRASH_DAYS=0 would allow permanent deletion instead of Trash.', 'ERROR');
        exit(1);
    }

    // A large feed/catatalogue delta is audited and warned about, never suppressed.
    // There is deliberately NO total percentage cap: legitimate supplier removals
    // above 20% still proceed to recoverable Trash in small batches.
    if ($existingProductCount > 0 && $deleteRatio > 0.20) {
        xindao_log_event(
            'XD LARGE REMOVAL WARNING: ' . $deleteCount . ' of ' . $existingProductCount .
            ' existing XD products (' . round($deleteRatio * 100, 2) . '%) are missing from the valid feed. ' .
            'Proceeding to WooCommerce Trash in controlled batches.',
            'WARNING'
        );
    }

    // Write a complete pre-trash manifest: each target ID/SKU is recorded BEFORE
    // anything is changed. .php + __halt_compiler makes direct browser access safe
    // even when nginx ignores .htaccess in cron_files/logs.
    $xdManifestPath = xindao_log_context()['directory'] .
        'xindao-trash-manifest-' . (function_exists('wp_date') ? wp_date('Ymd-His') : date('Ymd-His')) .
        '-' . getmypid() . '.php';
    $xdManifest = @fopen($xdManifestPath, 'xb');
    if ($xdManifest === false) {
        xindao_log_event('XD cleanup ABORTED: cannot create pre-trash SKU manifest ' . $xdManifestPath, 'ERROR');
        exit(1);
    }
    $xdManifestOk = fwrite($xdManifest, "<?php http_response_code(403); exit; __halt_compiler();\n") !== false;
    if ($xdManifestOk) {
        $xdManifestOk = fwrite($xdManifest, "product_id\tsku\n") !== false;
    }
    foreach ($productsToDelete as $xdManifestId => $xdManifestSku) {
        if (!$xdManifestOk) { break; }
        $xdManifestLine = (int) $xdManifestId . "\t" . str_replace(array("\r", "\n", "\t"), ' ', $xdManifestSku) . "\n";
        if (fwrite($xdManifest, $xdManifestLine) !== strlen($xdManifestLine)) {
            $xdManifestOk = false;
        }
    }
    if (!fflush($xdManifest)) { $xdManifestOk = false; }
    fclose($xdManifest);
    if (!$xdManifestOk) {
        xindao_log_event('XD cleanup ABORTED: incomplete pre-trash manifest ' . $xdManifestPath, 'ERROR');
        exit(1);
    }
    xindao_log_event('XD pre-trash manifest saved: ' . $xdManifestPath .
        ' | XD feed model count: ' . $feedProductCount .
        ' | candidates: ' . $deleteCount .
        ' | existing XD products: ' . $existingProductCount);

    // Stream batches instead of array_chunk(), which duplicates the entire
    // list of deletion candidates in PHP memory on large catalogues.
    $xdTrashTotalBatches = (int) ceil($deleteCount / $xdTrashBatchSize);
    $xdGenerateTrashBatches = static function (array $candidates, int $batchSize): Generator {
        $batch = array();
        foreach ($candidates as $id => $sku) {
            $batch[$id] = $sku;
            if (count($batch) >= $batchSize) {
                yield $batch;
                $batch = array();
            }
        }
        if ($batch) {
            yield $batch;
        }
    };
    xindao_log_event(
        'Moving ' . $deleteCount . ' missing Xindao products to Trash in ' .
        $xdTrashTotalBatches . ' batches (up to ' . $xdTrashBatchSize . ' products per batch).'
    );

    foreach ($xdGenerateTrashBatches($productsToDelete, $xdTrashBatchSize) as $xdTrashBatchIndex => $xdTrashBatch) {
        $xdBatchNumber = $xdTrashBatchIndex + 1;
        $xdBatchTrashed = 0;
        xindao_log_event('Xindao Trash batch ' . $xdBatchNumber . '/' . $xdTrashTotalBatches . ' started (' . count($xdTrashBatch) . ' products).');

        foreach ($xdTrashBatch as $product_id => $sku) {
            try {
                // Re-check before writing: avoid trashing a product whose SKU or ownership
                // changed after the initial snapshot was built.
                $productToDelete = wc_get_product((int) $product_id);
                if (!$productToDelete) {
                    throw new RuntimeException('WooCommerce product could not be loaded.');
                }
                if (get_post_status((int) $product_id) === 'trash') {
                    continue;
                }
                if (get_post_meta((int) $product_id, 'api_imported_from', true) !== 'xindao-api') {
                    xindao_log_event('Skipped Trash: product is no longer owned by Xindao. ID: ' . $product_id . ', SKU: ' . $sku, 'WARNING');
                    continue;
                }
                $xdLiveSku = trim((string) $productToDelete->get_sku());
                if (isset($xdFeedSkuLookup[$xdLiveSku])) {
                    xindao_log_event('Skipped Trash: current SKU exists in Xindao feed. ID: ' . $product_id . ', SKU: ' . $xdLiveSku, 'WARNING');
                    continue;
                }
                if ($xdLiveSku !== $sku) {
                    throw new RuntimeException('Product SKU changed during cleanup; safe to retry on the next run.');
                }

                // Remove only the supplier-specific NEW reason. The Hub preserves
                // other suppliers, Permanent NEW, Until and automatic NEW state.
                try {
                    $newHubResult = michel_new_hub_set_source_state((int) $product_id, 'xindao', false);
                    if (is_wp_error($newHubResult) || $newHubResult !== true) {
                        xindao_log_event(
                            'NEW Hub Xindao source cleanup warning for SKU ' . $sku . ': ' .
                            (is_wp_error($newHubResult) ? $newHubResult->get_error_message() : 'Unexpected result'),
                            'WARNING'
                        );
                    }
                } catch (Throwable $xdHubException) {
                    // A separate Hub issue must not silently prevent the requested Trash operation.
                    xindao_log_event('NEW Hub cleanup warning for SKU ' . $sku . ': ' . $xdHubException->getMessage(), 'WARNING');
                }

                // false = WordPress/WooCommerce Trash, never force-delete.
                $productToDelete->delete(false);
                if (get_post_status((int) $product_id) !== 'trash') {
                    throw new RuntimeException('WooCommerce did not confirm post_status=trash after delete(false).');
                }

                $xdTrashedCount++;
                $xdBatchTrashed++;
                xindao_log_event('Moved missing Xindao product to Trash. ID: ' . $product_id . ', SKU: ' . $sku);
            } catch (Throwable $xdTrashException) {
                $xdTrashFailedIds[] = (int) $product_id;
                xindao_log_event(
                    'Xindao Trash FAILED. ID: ' . $product_id . ', SKU: ' . $sku .
                    ', reason: ' . $xdTrashException->getMessage(),
                    'ERROR'
                );
            }
        }

        xindao_log_event(
            'Xindao Trash batch ' . $xdBatchNumber . '/' . $xdTrashTotalBatches .
            ' completed: ' . $xdBatchTrashed . ' products moved to Trash.'
        );
        unset($xdTrashBatch);
        gc_collect_cycles();
        // Release request-local WP object cache if supported. Never flush a persistent cache globally.
        if (function_exists('wp_cache_supports') && function_exists('wp_cache_flush_runtime') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
        // Brief pause between batches to ease database/cache load; no separate web requests.
        if ($xdBatchNumber < $xdTrashTotalBatches) {
            usleep(200000);
        }
    }
    unset($productsToDelete);

    if ($xdTrashFailedIds) {
        // Do not mark today complete: the next CLI cron run recomputes the missing
        // SKU set from the saved validated feed and excludes already-trashed IDs.
        xindao_log_event(
            'Xindao cleanup INCOMPLETE: ' . $xdTrashedCount . ' trashed, ' .
            count($xdTrashFailedIds) . ' failed. IDs: ' . implode(',', $xdTrashFailedIds) .
            '. Import remains unfinished so the next CLI run can resume.',
            'ERROR'
        );
        exit(1);
    }

    xindao_log_event('Xindao cleanup complete: ' . $xdTrashedCount . ' products moved to Trash across batches.');
} else {
    xindao_log_event('Xindao cleanup complete: no products are missing from the current feed.');
}
update_option('xd_last_master_import', date('Y-m-d'));
update_option('xd_last_master_import_index', 0);
// Stock cron uses this high-resolution completion marker to detect when new XD
// variations were created AFTER the day's previous stock import. It then runs
// another stock sync rather than incorrectly skipping because of the date.
// Only write after the product import AND Trash cleanup completed successfully.
update_option('xd_last_master_import_finished_at', (string) microtime(true));
xindao_log_event('XD product import completion timestamp updated for stock-sync ordering.');
$productDataLog = xindao_log_context()['sku'];
if (is_file($productDataLog)) {
    unlink($productDataLog);
}

// Keep the validated Products XLSX for independent supplier-ownership audits
// (xd_fix_without_printing.php) and troubleshooting. The XLSX was downloaded
// to a PID-specific temporary file, validated, and atomically renamed to
// cron_files/product_data.xlsx before processing began. Never remove that
// canonical feed on success.

$xindaoDuration = isset($xindaoImportStartedAt) ? round(microtime(true) - $xindaoImportStartedAt, 2) : 0;
if (isset($xindaoImportStats)) {
    xindao_log_event(
        'Import completed. Processed this run: ' . $xindaoImportStats['processed'] .
        ', created: ' . $xindaoImportStats['created'] .
        ', updated: ' . $xindaoImportStats['updated'] .
        ', skipped: ' . $xindaoImportStats['skipped'] .
        ', moved to Trash: ' . $xdTrashedCount .
        ', duration: ' . $xindaoDuration . ' s.'
    );
}

xindao_log_event('Script ended');
die();


function map_xindao_filter_values($rawValues, $mapping, $attributeLabel, $productSku = '')
{
    $mappedValues = [];
    $sourceValues = array_filter(array_map('trim', explode(',', (string) $rawValues)));

    foreach ($sourceValues as $sourceValue) {
        $normalizedSource = sanitize_title($sourceValue);
        if (isset($mapping[$normalizedSource])) {
            $valuesToAdd = is_array($mapping[$normalizedSource])
                ? $mapping[$normalizedSource]
                : array($mapping[$normalizedSource]);

            foreach ($valuesToAdd as $mappedValue) {
                if (!in_array($mappedValue, $mappedValues, true)) {
                    $mappedValues[] = $mappedValue;
                }
            }
        } else {
            xindao_log_event('Unmapped Xindao ' . $attributeLabel . ' value "' . $sourceValue . '" for product ' . $productSku, 'WARNING');
        }
    }

    return implode(',', array_values(array_unique($mappedValues)));
}

function map_xindao_color_group_values($rawValues, $productSku = '')
{
    $colorMap = [
        '4-color-printing' => 'Multicolour',
        'anthracite' => 'Grey',
        'aqua' => 'Blue',
        'atoll' => 'Blue',
        'bamboo' => 'Beige/Natur',
        'beige' => 'Beige/Natur',
        'black' => 'Black',
        'black-black' => 'Multicolour',
        'black-blue' => 'Multicolour',
        'black-orange' => 'Multicolour',
        'black-purple' => 'Multicolour',
        'black-red' => 'Multicolour',
        'black-silver' => 'Multicolour',
        'black-white' => 'Multicolour',
        'black-yellow-red' => 'Multicolour',
        'blue' => 'Blue',
        'blue-black' => 'Multicolour',
        'blue-silver' => 'Multicolour',
        'blue-white' => 'Multicolour',
        'blue-white-red' => 'Multicolour',
        'brass-coloured' => 'Gold',
        'bronze' => 'Gold',
        'brown' => 'Brown',
        'brown-black' => 'Multicolour',
        'burgundy' => 'Red',
        'champagne' => 'Gold',
        'cherry-red' => 'Red',
        'choice-of-23-colors' => 'Multicolour',
        'choice-of-30-colors' => 'Multicolour',
        'classic-royal-blue' => 'Blue',
        'cloud-pink' => 'Pink',
        'cobalt-blue' => 'Blue',
        'colored' => 'Multicolour',
        'cream-yellow' => 'Yellow',
        'crushed-mint' => 'Green',
        'custom-multicolor' => 'Multicolour',
        'dark-blue' => 'Blue',
        'dark-brown' => 'Brown',
        'dark-green' => 'Green',
        'dark-grey' => 'Grey',
        'dark-navy' => 'Blue',
        'desert' => 'Beige/Natur',
        'emerald' => 'Green',
        'fluor-orange' => 'Orange',
        'fluor-yellow' => 'Yellow',
        'forest-green' => 'Green',
        'fuchsia' => 'Pink',
        'gold' => 'Gold',
        'golden' => 'Gold',
        'gray' => 'Grey',
        'green' => 'Green',
        'green-silver' => 'Multicolour',
        'green-white' => 'Multicolour',
        'greige' => 'Beige/Natur',
        'grey' => 'Grey',
        'gun-metal' => 'Silver',
        'heather-anthracite' => 'Grey',
        'heather-blue' => 'Blue',
        'heather-brown' => 'Brown',
        'heather-green' => 'Green',
        'heather-grey' => 'Grey',
        'heather-navy' => 'Blue',
        'iceberg-green' => 'Green',
        'ivory-white' => 'White',
        'khaki' => 'Beige/Natur',
        'lavender' => 'Purple',
        'light-blue' => 'Blue',
        'light-brown' => 'Brown',
        'light-green' => 'Green',
        'light-grey' => 'Grey',
        'light-heather-anthracite' => 'Grey',
        'light-heather-blue' => 'Blue',
        'light-heather-brown' => 'Brown',
        'light-heather-green' => 'Green',
        'light-heather-grey' => 'Grey',
        'light-heather-navy' => 'Blue',
        'lilac' => 'Purple',
        'lime' => 'Green',
        'luscious-red' => 'Red',
        'magenta' => 'Pink',
        'matt-silver' => 'Silver',
        'medium-blue' => 'Blue',
        'mint' => 'Green',
        'moss' => 'Green',
        'multicolored' => 'Multicolour',
        'natural' => 'Beige/Natur',
        'natural-raw' => 'Beige/Natur',
        'navy' => 'Blue',
        'navy-blue' => 'Blue',
        'navy-natural' => 'Multicolour',
        'neutral' => 'Beige/Natur',
        'ochre-yellow' => 'Yellow',
        'off-white' => 'White',
        'olive-green' => 'Green',
        'orange' => 'Orange',
        'orange-silver' => 'Multicolour',
        'pastel-blue' => 'Blue',
        'pastel-green' => 'Green',
        'pastel-pink' => 'Pink',
        'peach' => 'Orange',
        'peach-nectar' => 'Orange',
        'pearl-white' => 'White',
        'pink' => 'Pink',
        'purple' => 'Purple',
        'purple-black' => 'Multicolour',
        'recycled-white' => 'White',
        'red' => 'Red',
        'red-silver' => 'Multicolour',
        'red-white' => 'Multicolour',
        'red-white-blue' => 'Multicolour',
        'red-yellow' => 'Multicolour',
        'reflecting' => 'Silver',
        'rose-gold' => 'Gold',
        'royal-blue' => 'Blue',
        'sage-blue' => 'Blue',
        'sand' => 'Beige/Natur',
        'silver' => 'Silver',
        'silver-grey' => 'Silver',
        'silver-white' => 'Multicolour',
        'sky-blue' => 'Blue',
        'stainless' => 'Silver',
        'steel' => 'Silver',
        'storm-grey' => 'Grey',
        'sundial-orange' => 'Orange',
        'tan' => 'Brown',
        'taupe' => 'Brown',
        'terracotta' => 'Brown',
        'tranquil-blue' => 'Blue',
        'transparent' => 'White',
        'turquoise' => 'Blue',
        'various' => 'Multicolour',
        'verdigris' => 'Green',
        'violet' => 'Purple',
        'vulcano-heather-grey' => 'Grey',
        'warm-brown' => 'Brown',
        'white' => 'White',
        'white-black' => 'Multicolour',
        'white-blue' => 'Multicolour',
        'white-green' => 'Multicolour',
        'white-light-blue' => 'Multicolour',
        'white-orange' => 'Multicolour',
        'white-red' => 'Multicolour',
        'white-silver' => 'Multicolour',
        'white-yellow' => 'Multicolour',
        'wood' => 'Brown',
        'yellow' => 'Yellow',
        'yellow-silver' => 'Multicolour',
    ];
    return map_xindao_filter_values($rawValues, $colorMap, 'color-group', $productSku);
}

function xindao_build_attribute_map($rules)
{
    $lookup = array();

    foreach ($rules as $targetValue => $sourceValues) {
        foreach ($sourceValues as $sourceValue) {
            $sourceKey = sanitize_title(trim((string) $sourceValue));

            if ($sourceKey === '') {
                continue;
            }

            if (!isset($lookup[$sourceKey])) {
                $lookup[$sourceKey] = array();
            }

            if (!in_array($targetValue, $lookup[$sourceKey], true)) {
                $lookup[$sourceKey][] = $targetValue;
            }
        }
    }

    return $lookup;
}

function map_xindao_printing_technique_group_values($rawValues, $productSku = '')
{
    static $printingTechniqueGroupMap = null;

    if ($printingTechniqueGroupMap === null) {
        $printingTechniqueGroupMapRules = array(
            'Digital transfer' => array(
                'digital-super-transfer',
                'digital-transfer',
                'digital-transfer-os',
                'digital-transfer-sp',
            ),
            'Transfer printing' => array(
                'screen-transfer-os',
                'transfer-printing',
                'transfer-printing-silk-printing',
                'digital-printing-4-color-transfer-printing-without-advertising-imprint',
            ),
            'Screenprint' => array(
                'flexography',
                'silk-printing',
                'silkscreen',
                'silkscreen-round',
                'silkscreen-textile',
                'printing-on-carafe-and-glasses',
                'screen-printing-1-color-without-branding',
                'without-advertising-imprint-screen-printing-1-color-screen-printing-2-color',
                'without-advertising-screen-printing-1-color',
                'without-branding-screen-printing-1-color-screen-printing-2-color',
            ),
            'Sublimation' => array(
                'sublimation',
            ),
            'Without printing' => array(
                'bez-tiska',
                'without-advertising-imprint',
                'without-branding',
                'without-printing',
            ),
            'Padprint' => array(
                'pad-print',
                'pad-print-drinkware',
                'pad-print-low',
                'pad-printing',
            ),
            'Engraving' => array(
                'co2-engraving-1',
                'co2-engraving-2',
                'co2-engraving-3',
                'laser',
                'laser-engraving-1',
                'laser-engraving-2',
                'laser-engraving-3',
                'laser-round-360',
                'laser-engraving',
                'laser-engraving-without-branding-screen-printing-1-color',
                'laser-engraving-without-branding-screen-printing-1-color-digital-printing-4-color-blind-embossing-foil-stamping',
                'laser-engraving-without-branding-screen-printing-4-color',
                'digital-printing-4-color-laser-engraving',
                'digital-printing-4-color-laser-engraving-4-color-printing-for-card',
            ),
            'Epoxy doming' => array(
                'doming',
            ),
            'Embroidery' => array(
                'embroidery',
                'embroidery-3d',
                'embroidery-badge',
            ),
            'UV LED printing' => array(
                'digital-print',
                'digital-print-1',
                'digital-print-2',
                'digital-print-3',
                'digital-round-360-1',
                'digital-round-360-2',
            ),
            'Digital print' => array(
                'custom-card-b',
                'ukiyo-card',
                '4-color-printing-on-the-card',
                '4-color-printing-on-the-pencil-case',
                'digital-printing-4-color-2',
                'digital-printing-4-color-laser-engraving',
                'digital-printing-4-color-laser-engraving-4-color-printing-for-cards',
                'digital-printing-4-color',
                'digital-printing-4-color-without-advertising-imprint',
            ),
            'CreaBox custom box' => array(
                'full-surface-printing-on-the-packaging',
            ),
            'Inkjet overprinting' => array(
            ),
            'Vinyl sticker' => array(
                'digital-sticker',
                'printed-badge',
            ),
            'Debossing' => array(
                'debossing',
                'hot-stamping',
                'leather-badge',
                'pu-label-balti-black',
                'pu-label-balti-green',
                'pu-label-balti-navy',
                'pu-label-brendon',
                'pu-label-sortino',
                'laser-engraving-fire-stamping',
            ),
        );
        $printingTechniqueGroupMap = xindao_build_attribute_map($printingTechniqueGroupMapRules);
    }

    return map_xindao_filter_values(
        $rawValues,
        $printingTechniqueGroupMap,
        'printing-technique-group',
        $productSku
    );
}

function map_xindao_material_values($rawValues, $isTextileProduct, $productSku = '')
{
    $generalMaterialMap = [
        '100-recycled-polyester' => 'Recycled plastic',
        '1680d-pvc-polyester' => 'Polyester & microfibers',
        '20-nylon-80-polyester-fiber' => 'Polyester & microfibers',
        '230g-recycled-paper' => 'Recycled paper',
        '30-recycled-70-organic-cotton' => 'Cotton',
        '50-recycled-50-organic-cotton' => 'Cotton',
        '600d-eva-polyester' => 'Polyester & microfibers',
        '80-cotton-17-polyamide-3-elastane' => 'Cotton',
        '900d-nylon-polyester' => 'Polyester & microfibers',
        '91-recycled-polyester' => 'Recycled plastic',
        'abs' => 'Plastic',
        'ac' => 'Composite materials',
        'acacia-wood' => 'Wood & natural materials',
        'acrylonitril-butadieen-styrene-abs' => 'Plastic',
        'alluminium-alloy-with-chrome-plated' => 'Metal',
        'aluminium' => 'Metal',
        'aluminium-foil' => 'Metal',
        'aluminium-paper' => 'Metal',
        'bagasse-paper' => 'Paper',
        'bamboo' => 'Wood & natural materials',
        'beech-wood' => 'Wood & natural materials',
        'birch-wood' => 'Wood & natural materials',
        'bonded-leather' => 'Leather & imitation leather',
        'borosilicate-glass' => 'Glass & ceramics',
        'borosilicate-glass-lid-made-of-pp' => 'Glass & ceramics',
        'borosilicate-glass-lid-made-of-pp-or-tpe-recycled-cotton-canvas' => 'Glass & ceramics',
        'borosilicate-glass-lid-made-of-tpe' => 'Glass & ceramics',
        'brass' => 'Metal',
        'canvas' => 'Cotton',
        'carbon-fibre' => 'Composite materials',
        'card-white-cardboard-fresh-food-safe-plastic' => 'Paper',
        'cardboard' => 'Paper',
        'cardboard-1200-gsm' => 'Paper',
        'cardboard-250-gsm' => 'Paper',
        'cardboard-2mm' => 'Paper',
        'cardboard-350-gsm' => 'Paper',
        'cardboard-600-gsm' => 'Paper',
        'cast-iron' => 'Metal',
        'ceramic' => 'Glass & ceramics',
        'compostable-foil-transparent' => 'Plastic',
        'compostable-foil-white' => 'Plastic',
        'copper' => 'Metal',
        'cork' => 'Wood & natural materials',
        'cotton' => 'Cotton',
        'cotton-140-150-gsm' => 'Cotton',
        'cotton-145-gsm' => 'Cotton',
        'cotton-160-gsm' => 'Cotton',
        'cotton-180-gsm' => 'Cotton',
        'cotton-250-gsm' => 'Cotton',
        'cover-sheet-cardboard-white-inlay-compostable-according-to-en-13432' => 'Paper',
        'cover-sheet-cardboard-white-inlay-creme-white-compostable-according-to-en-13432' => 'Paper',
        'cow-leather' => 'Leather & imitation leather',
        'cup-cardboard-promotional-inlay-white-paper' => 'Paper',
        'denim' => 'Cotton',
        'dolomite' => 'Glass & ceramics',
        'earthenware' => 'Glass & ceramics',
        'egg-carton-cardboard-label-white-paper' => 'Paper',
        'elastane' => 'Textiles',
        'ethylene-vinyl-acetate-eva' => 'Plastic',
        'eva' => 'Plastic',
        'fibreglass' => 'Composite materials',
        'firewood' => 'Wood & natural materials',
        'foam' => 'Plastic',
        'foil-transparent' => 'Plastic',
        'foil-white' => 'Plastic',
        'fsc-bamboo' => 'Wood & natural materials',
        'fsc-cork' => 'Wood & natural materials',
        'fsc-wood' => 'Wood & natural materials',
        'glas' => 'Glass & ceramics',
        'glass' => 'Glass & ceramics',
        'graphite' => 'Graphite',
        'gucci-nylon-1200d' => 'Polyester & microfibers',
        'hemp' => 'Textiles',
        'honey-glass-wood-cardboard' => 'Glass & ceramics',
        'imitation-leather' => 'Leather & imitation leather',
        'iron' => 'Metal',
        'jacquard-50d-polyester' => 'Polyester & microfibers',
        'jute' => 'Wood & natural materials',
        'leather' => 'Leather & imitation leather',
        'lime-soda-silica-glass' => 'Glass & ceramics',
        'linen' => 'Textiles',
        'lyocell' => 'Textiles',
        'maple-wood' => 'Wood & natural materials',
        'mdf' => 'Wood & natural materials',
        'medium-density-fiberboard' => 'Wood & natural materials',
        'metal' => 'Metal',
        'microfiber-200-gsm' => 'Polyester & microfibers',
        'microfibre' => 'Polyester & microfibers',
        'microfibre-160-gsm' => 'Polyester & microfibers',
        'microplush' => 'Polyester & microfibers',
        'neoprene' => 'Rubber',
        'newsprint-paper' => 'Paper',
        'nickel' => 'Metal',
        'nickel-alloy' => 'Metal',
        'non-woven' => 'Polyester & microfibers',
        'non-woven-80-gsm' => 'Polyester & microfibers',
        'non-woven-90-gsm' => 'Polyester & microfibers',
        'non-woven-laminated' => 'Polyester & microfibers',
        'nylon' => 'Polyester & microfibers',
        'nylon-190t' => 'Polyester & microfibers',
        'nylon-210d' => 'Polyester & microfibers',
        'nylon-420d' => 'Polyester & microfibers',
        'nylon-600d' => 'Polyester & microfibers',
        'nylon-70d' => 'Polyester & microfibers',
        'oakwood' => 'Wood & natural materials',
        'organic-cotton' => 'Cotton',
        'pa' => 'Polyester & microfibers',
        'pakkawood' => 'Wood & natural materials',
        'paper' => 'Paper',
        'paper-128-gsm' => 'Paper',
        'paper-140-gsm' => 'Paper',
        'paper-300-gsm' => 'Paper',
        'paper-600-gsm' => 'Paper',
        'paper-70-gsm' => 'Paper',
        'paper-80-gsm' => 'Paper',
        'paper-can-compostable-label-paper' => 'Paper',
        'paper-grass' => 'Paper',
        'paper-sleeve-white' => 'Paper',
        'paper-sleeve-white-chocolate-wrapped-in-glassine-paper' => 'Paper',
        'pc' => 'Plastic',
        'pc-abs' => 'Plastic',
        'pcb' => 'Plastic',
        'pcv' => 'Plastic',
        'pe' => 'Plastic',
        'pe-002mm' => 'Plastic',
        'pet' => 'Plastic',
        'petg' => 'Plastic',
        'peva' => 'Plastic',
        'pinewood' => 'Wood & natural materials',
        'plastic' => 'Plastic',
        'plastic-crystal-1840' => 'Plastic',
        'plywood' => 'Wood & natural materials',
        'poliuretan-foam' => 'Plastic',
        'poly-cotton' => 'Cotton',
        'polycanvas-300d' => 'Polyester & microfibers',
        'polycanvas-600d' => 'Polyester & microfibers',
        'polycarbonate' => 'Plastic',
        'polyester' => 'Polyester & microfibers',
        'polyester-100' => 'Polyester & microfibers',
        'polyester-1680d' => 'Polyester & microfibers',
        'polyester-170t' => 'Polyester & microfibers',
        'polyester-190-g-m2' => 'Polyester & microfibers',
        'polyester-190t' => 'Polyester & microfibers',
        'polyester-210d' => 'Polyester & microfibers',
        'polyester-210t' => 'Polyester & microfibers',
        'polyester-300d' => 'Polyester & microfibers',
        'polyester-420d' => 'Polyester & microfibers',
        'polyester-600d' => 'Polyester & microfibers',
        'polyester-70d' => 'Polyester & microfibers',
        'polyester-canvas' => 'Polyester & microfibers',
        'polyester-lycra' => 'Polyester & microfibers',
        'polyethylene' => 'Plastic',
        'polyethylene-terephthalate' => 'Plastic',
        'polyethylene-terephthalate-glycol' => 'Plastic',
        'polyfoam' => 'Plastic',
        'polymere' => 'Plastic',
        'polypropylene' => 'Plastic',
        'polystyrene' => 'Plastic',
        'polythylene-terephthalate' => 'Plastic',
        'polyuretan-foam' => 'Plastic',
        'polyurethane' => 'Plastic',
        'pongee' => 'Polyester & microfibers',
        'pongee-190t' => 'Polyester & microfibers',
        'pongee-75d' => 'Polyester & microfibers',
        'poplar' => 'Wood & natural materials',
        'porcelain' => 'Glass & ceramics',
        'pp' => 'Plastic',
        'promotional-box-white-cardboard-stay-fresh-seal-compostable-foil' => 'Paper',
        'promotional-card-white-cardboard' => 'Paper',
        'promotional-case-bunny-ears-white-cardboard-inlay-plastic' => 'Paper',
        'promotional-case-cardboard-white-content-recyclable-tetra-pak' => 'Paper',
        'promotional-case-christmas-tree-white-cardboard-inlay-plastic' => 'Paper',
        'promotional-case-santa-cap-white-cardboard-inlay-plastic' => 'Paper',
        'promotional-case-white-cardboard' => 'Paper',
        'promotional-case-white-cardboard-bag-foil-white' => 'Paper',
        'promotional-case-white-cardboard-bunny-wrapped-in-foil' => 'Paper',
        'promotional-case-white-cardboard-chocolate-in-compostable-foil' => 'Paper',
        'promotional-case-white-cardboard-flowpack-foil-transparent' => 'Paper',
        'promotional-case-white-cardboard-inlay-plastic' => 'Paper',
        'ps' => 'Plastic',
        'pu' => 'Leather & imitation leather',
        'pvc' => 'Plastic',
        'pvc-0-15mm' => 'Plastic',
        'pvc-0-17mm' => 'Plastic',
        'pvc-0-20mm' => 'Plastic',
        'pvc-0-30mm' => 'Plastic',
        'pvc-0-36mm' => 'Plastic',
        'pvc-0-70mm' => 'Plastic',
        'pvc-58c' => 'Plastic',
        'pvc-backing' => 'Plastic',
        'recycable-paper-white' => 'Recycled paper',
        'recyclable-paper-white' => 'Recycled paper',
        'recyclable-paper-white-2' => 'Recycled paper',
        'recycled-abs' => 'Recycled plastic',
        'recycled-aluminium' => 'Metal',
        'recycled-aluminium-alloy' => 'Metal',
        'recycled-brass' => 'Metal',
        'recycled-cotton' => 'Cotton',
        'recycled-denim' => 'Cotton',
        'recycled-glass' => 'Glass & ceramics',
        'recycled-leather' => 'Leather & imitation leather',
        'recycled-non-woven' => 'Polyester & microfibers',
        'recycled-paper' => 'Recycled paper',
        'recycled-polycarbonate' => 'Recycled plastic',
        'recycled-polyester' => 'Recycled plastic',
        'recycled-polyethylene-terephthalate' => 'Recycled plastic',
        'recycled-stainless-steel' => 'Metal',
        'recycled-tpe' => 'Rubber',
        'recycled-tpu' => 'Rubber',
        'recycled-zinc-alloy' => 'Metal',
        'rosewood' => 'Wood & natural materials',
        'rpet' => 'Recycled plastic',
        'rpet-felt' => 'Recycled plastic',
        'rubberwood' => 'Wood & natural materials',
        'satin' => 'Textiles',
        'silicon' => 'Silikon',
        'silicone' => 'Silikon',
        'silk' => 'Textiles',
        'silver-plated' => 'Metal',
        'solid-cardboard-cover-grass-paper-inlay-compostable-according-to-en-13432' => 'Paper',
        'solid-cardboard-cover-grass-paper-inlay-paper' => 'Paper',
        'solid-cardboard-cover-grass-paper-landscapeformat-cover-and-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-grass-paper-portraiformat-cover-and-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-made-of-grasspaper-cover-and-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-white-cover-and-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-white-inlay-compostable-according-to-en-13432' => 'Paper',
        'solid-cardboard-cover-white-inlay-paper' => 'Paper',
        'solid-cardboard-cover-white-landscapeformat-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-white-portraitformat-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-white-standard-design-landscapeformat-cover-and-inlay-recyclable' => 'Paper',
        'solid-cardboard-cover-white-standard-design-portraitformat-cover-and-inlay-recyclable' => 'Paper',
        'spandex' => 'Textiles',
        'split-leather' => 'Leather & imitation leather',
        'stainless-steel' => 'Metal',
        'stainless-steel-14-1' => 'Metal',
        'stainless-steel-18-0' => 'Metal',
        'stainless-steel-201' => 'Metal',
        'stainless-steel-202' => 'Metal',
        'stainless-steel-2cr13' => 'Metal',
        'stainless-steel-301' => 'Metal',
        'stainless-steel-304' => 'Metal',
        'stainless-steel-3cr13' => 'Metal',
        'stainless-steel-420' => 'Metal',
        'stainless-steel-430' => 'Metal',
        'steel' => 'Metal',
        'steel-60c' => 'Metal',
        'steel-a3' => 'Metal',
        'stone-paper' => 'Paper',
        'stoneware' => 'Glass & ceramics',
        'suede' => 'Leather & imitation leather',
        'sweet-jar-plastic-lid-doming-label' => 'Glass & ceramics',
        'sweet-jar-plastic-lid-white-paper-label' => 'Glass & ceramics',
        'tc-fabric' => 'Cotton',
        'thermoplastic-elastomer-tpe' => 'Rubber',
        'thermoplastic-polyurethane-tpu' => 'Rubber',
        'tpe' => 'Rubber',
        'tpr' => 'Rubber',
        'tpu' => 'Rubber',
        'tritan' => 'Glass & ceramics',
        'tungsten-carbide' => 'Metal',
        'velcro' => 'Polyester & microfibers',
        'velvet' => 'Textiles',
        'vinyl' => 'Plastic',
        'wheat-straw' => 'Wood & natural materials',
        'wheatstraw' => 'Wood & natural materials',
        'white-foil-with-approx-50-paper-content' => 'Plastic',
        'wood' => 'Wood & natural materials',
        'wool' => 'Textiles',
        'wrapper-compostable-foil-white' => 'Plastic',
        'zinc' => 'Metal',
        'zinc-alloy' => 'Metal',
    ];

    $textileMaterialMap = [
        '1680d-pvc-polyester' => 'Polyester & microfibers',
        '20-nylon-80-polyester-fiber' => 'Polyester & microfibers',
        '30-recycled-70-organic-cotton' => 'Organic cotton',
        '50-recycled-50-organic-cotton' => 'Organic cotton',
        '600d-eva-polyester' => 'Polyester & microfibers',
        '80-cotton-17-polyamide-3-elastane' => 'Cotton',
        '900d-nylon-polyester' => 'Polyester & microfibers',
        'bonded-leather' => 'Leather & imitation leather',
        'canvas' => 'Cotton',
        'cotton' => 'Cotton',
        'cotton-140-150-gsm' => 'Cotton',
        'cotton-145-gsm' => 'Cotton',
        'cotton-160-gsm' => 'Cotton',
        'cotton-180-gsm' => 'Cotton',
        'cotton-250-gsm' => 'Cotton',
        'cow-leather' => 'Leather & imitation leather',
        'denim' => 'Cotton',
        'elastane' => 'Elastane/Spandex',
        'gucci-nylon-1200d' => 'Elastane/Spandex',
        'hemp' => 'Hemp, jute',
        'imitation-leather' => 'Leather & imitation leather',
        'jacquard-50d-polyester' => 'Polyester & microfibers',
        'jute' => 'Hemp, jute',
        'leather' => 'Leather & imitation leather',
        'linen' => 'Linen',
        'lyocell' => 'Lyocell',
        'microfiber-200-gsm' => 'Polyester & microfibers',
        'microfibre' => 'Polyester & microfibers',
        'microfibre-160-gsm' => 'Polyester & microfibers',
        'microplush' => 'Polyester & microfibers',
        'non-woven' => 'Polyester & microfibers',
        'non-woven-80-gsm' => 'Polyester & microfibers',
        'non-woven-90-gsm' => 'Polyester & microfibers',
        'non-woven-laminated' => 'Polyester & microfibers',
        'nylon' => 'Elastane/Spandex',
        'nylon-190t' => 'Elastane/Spandex',
        'nylon-210d' => 'Elastane/Spandex',
        'nylon-420d' => 'Elastane/Spandex',
        'nylon-600d' => 'Elastane/Spandex',
        'nylon-70d' => 'Elastane/Spandex',
        'organic-cotton' => 'Organic cotton',
        'pa' => 'Elastane/Spandex',
        'poly-cotton' => 'Cotton',
        'polycanvas-300d' => 'Polyester & microfibers',
        'polycanvas-600d' => 'Polyester & microfibers',
        'polyester' => 'Polyester & microfibers',
        'polyester-100' => 'Polyester & microfibers',
        'polyester-1680d' => 'Polyester & microfibers',
        'polyester-170t' => 'Polyester & microfibers',
        'polyester-190-g-m2' => 'Polyester & microfibers',
        'polyester-190t' => 'Polyester & microfibers',
        'polyester-210d' => 'Polyester & microfibers',
        'polyester-210t' => 'Polyester & microfibers',
        'polyester-300d' => 'Polyester & microfibers',
        'polyester-420d' => 'Polyester & microfibers',
        'polyester-600d' => 'Polyester & microfibers',
        'polyester-70d' => 'Polyester & microfibers',
        'polyester-canvas' => 'Polyester & microfibers',
        'polyester-lycra' => 'Polyester & microfibers',
        'pongee' => 'Elastane/Spandex',
        'pongee-190t' => 'Elastane/Spandex',
        'pongee-75d' => 'Elastane/Spandex',
        'pu' => 'Leather & imitation leather',
        'recycled-cotton' => 'Recycled cotton',
        'recycled-denim' => 'Recycled cotton',
        'recycled-leather' => 'Leather & imitation leather',
        'recycled-non-woven' => 'Polyester & microfibers',
        'satin' => 'Silk',
        'silk' => 'Silk',
        'spandex' => 'Elastane/Spandex',
        'split-leather' => 'Leather & imitation leather',
        'suede' => 'Leather & imitation leather',
        'tc-fabric' => 'Cotton',
        'velcro' => 'Elastane/Spandex',
        'velvet' => 'Velvet',
        'wheat-straw' => 'Wheatstraw',
        'wheatstraw' => 'Wheatstraw',
        'wool' => 'Wool',
    ];

    $sourceValues = array_filter(array_map('trim', explode(',', (string) $rawValues)));
    $mappedValues = [];

    foreach ($sourceValues as $sourceValue) {
        $normalizedSource = sanitize_title($sourceValue);
        $mappedValue = null;

        // Textile products receive the more specific textile value when available.
        if ($isTextileProduct && isset($textileMaterialMap[$normalizedSource])) {
            $mappedValue = $textileMaterialMap[$normalizedSource];
        } elseif (isset($generalMaterialMap[$normalizedSource])) {
            // General mapping is also the fallback for non-textile components
            // found on textile products, such as metal or plastic.
            $mappedValue = $generalMaterialMap[$normalizedSource];
        }

        if ($mappedValue !== null && $mappedValue !== '') {
            $mappedValues[] = $mappedValue;
            continue;
        }

        // Preserve unmapped supplier values instead of dropping them.
        // This guarantees that every Material value from the Xindao feed remains on the product,
        // while the warning log still tells us which values should be added to the mapping later.
        $mappedValues[] = $sourceValue;

        xindao_log_event(
            'Unmapped Xindao material kept as original value'
            . ($productSku ? ' for SKU ' . $productSku : '')
            . ': ' . $sourceValue,
            'WARNING'
        );
    }

    $mappedValues = array_values(array_unique($mappedValues));

    return implode(',', $mappedValues);
}

function handle_images(WC_Product $product, string $img_str)
{
    $img_links = explode(', ', $img_str);
    $gallery_attachment_ids = [];
    foreach ($img_links as $img_link) {
        $img_link = trim($img_link);
        $attachment_id = media_sideload_image($img_link, $product->get_id(), null, 'id');
        $gallery_attachment_ids[] = $attachment_id;
    }
    $main_image_id = array_shift($gallery_attachment_ids);
    $product->set_image_id($main_image_id);
    $product->set_gallery_image_ids($gallery_attachment_ids);
    $product->save();
}

function print_info_to_terminal($message)
{
    xindao_log_event($message);
}

function get_or_create_term_by_slug($name, $slug, $taxonomy) {
	$name = (empty($name)) ? sanitize_user($slug) : $name;
	$slug = (empty($slug)) ? sanitize_title($name) : sanitize_title($slug);
	$slug = sanitize_key($slug);
	$taxonomy = str_replace('/', '-', $taxonomy);
	$slug = str_replace(' ', '-', $slug);
	$name = ucfirst($name);

	$get_term = get_term_by('slug', $slug, $taxonomy);
	if($get_term == false){
		$new_term = wp_insert_term( $name, $taxonomy, 
			array(
				'slug' => $slug,
			)
		);
		if( is_wp_error($new_term) == false ) {
			return $new_term['term_id'];
		}
	} else {
		return $get_term->term_id;
	}
}

function get_or_create_term($value, $taxonomy): WP_Term
{
    $term = get_term_by('name', $value, $taxonomy);
    if ($term === false) {
        wp_insert_term($value, $taxonomy);
        $term = get_term_by('name', $value, $taxonomy);
    }
    return $term;
}

function delete_new_line_chars($originalString)
{
    return str_replace(array("\r\n", "\r", "\n"), '', $originalString);
}

function handle_setting_categories($product, $promo_main_categories_dictionary, $promo_sub_categories_dictionary, $main_category_external, $subcategory_external, $addEcoCategory, $productSku = '')
{
    $mainExternal = delete_new_line_chars($main_category_external);
    $subExternal = delete_new_line_chars($subcategory_external);
    $skuLabel = $productSku !== '' ? 'SKU ' . $productSku . ' | ' : '';

    if (!array_key_exists($mainExternal, $promo_main_categories_dictionary)) {
        xindao_category_log($skuLabel . 'Main category mapping missing: "' . $mainExternal . '".', 'WARNING');
        return $product;
    }

    if (!array_key_exists($subExternal, $promo_sub_categories_dictionary)) {
        xindao_category_log($skuLabel . 'Subcategory mapping missing: "' . $subExternal . '".', 'WARNING');
        return $product;
    }

    $mappedMain = $promo_main_categories_dictionary[$mainExternal];
    $mappedSub = $promo_sub_categories_dictionary[$subExternal];

    $category = get_term_by('name', $mappedMain, 'product_cat');
    $subcategory = get_term_by('name', $mappedSub, 'product_cat');

    if ($category == null) {
        $parent_new_term = wp_insert_term($mappedMain, 'product_cat', array(
            'parent' => 238
        ));

        if (is_wp_error($parent_new_term)) {
            xindao_category_log(
                $skuLabel . 'Failed creating main category "' . $mappedMain . '": ' . $parent_new_term->get_error_message(),
                'ERROR'
            );
            return $product;
        }

        $category_id = $parent_new_term['term_id'];
        xindao_category_log($skuLabel . 'Created main category "' . $mappedMain . '" (ID ' . $category_id . ').');
    } else {
        $category_id = $category->term_id;
    }

    if ($subcategory == null) {
        $child_new_term = wp_insert_term($mappedSub, 'product_cat', array(
            'parent' => $category_id
        ));

        if (is_wp_error($child_new_term)) {
            xindao_category_log(
                $skuLabel . 'Failed creating subcategory "' . $mappedSub . '": ' . $child_new_term->get_error_message(),
                'ERROR'
            );
            return $product;
        }

        $subcategory_id = $child_new_term['term_id'];
        xindao_category_log($skuLabel . 'Created subcategory "' . $mappedSub . '" (ID ' . $subcategory_id . ').');
    } else {
        $subcategory_id = $subcategory->term_id;
    }

    $categories = array($category_id, $subcategory_id);
    if ($addEcoCategory) {
        $categories[] = 167;
    }

    $product->set_category_ids($categories);

    xindao_category_log(
        $skuLabel . 'Mapped "' . $mainExternal . ' > ' . $subExternal . '" to "' .
        $mappedMain . ' > ' . $mappedSub . '" | IDs: ' . implode(', ', $categories)
    );

    return $product;
}

function handle_multiple_colors($color_options_array)
{
    $color_options = [];
    foreach ($color_options_array as $color_option) {

        $colors = array_map('trim', explode(',', $color_option));
        foreach ($colors as $color) {
            $color_options[] = $color;
        }
    }

    $sanitized_colors = array_map('sanitize_title', array_unique($color_options));

    return $sanitized_colors;
}

//
// Upload from URL
function api_upload_from_url( $existing_id, $url, $title = null , $post_id = null, $square = false) {
    
	// Get the filename and extension ("photo.png" => "photo", "png")
	$filename = pathinfo($url, PATHINFO_FILENAME);
	$filename = preg_replace('/[^A-Za-z0-9\-]/', '', $filename);
    if($title){
        $filename = sanitize_title($title);
    }

	$extension = pathinfo($url, PATHINFO_EXTENSION);
	$extension = preg_replace('/[^A-Za-z0-9\-]/', '', $extension);


	// Get existing meta data if set.
	if($existing_id !== false && $existing_id > 0){
	$get_existing_meta = wp_get_attachment_metadata($existing_id);
		$e_filename = pathinfo($get_existing_meta['file'], PATHINFO_FILENAME) ?? 0;
		$e_extension = pathinfo($get_existing_meta['file'], PATHINFO_EXTENSION) ?? 0;
	} else {
		//Check in database for file
		global $wpdb;
		$existing_id = intval($wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_title = '$title'"));
		if($existing_id){
            return $existing_id;
        }
	}

    // if($square == true){
    //     $url = $url . '/size_900x900.jpg';
    // } else {
    //     $url = $url . '/size_1024x1024.jpg';
    // }

    $url = str_replace('/size_500x500.jpg', '/size_1024x1024.jpg', $url);

    // Download url to a temp file
    $tmp = download_url( $url );
    if ( is_wp_error( $tmp ) ) return false;
    
    if($square == true){
        resizeImage($tmp, $tmp, 1024, 1024);
    }

    // An extension is required or else WordPress will reject the upload
    if ( ! $extension ) {
        // Look up mime type, example: "/photo.png" -> "image/png"
        $mime = mime_content_type( $tmp );
        $mime = is_string($mime) ? sanitize_mime_type( $mime ) : false;
        
        // Only allow certain mime types because mime types do not always end in a valid extension (see the .doc example below)
        $mime_extensions = array(
            // mime_type         => extension (no period)
            'text/plain'         => 'txt',
            'text/csv'           => 'csv',
            'application/msword' => 'doc',
            'image/jpg'          => 'jpg',
            'image/jpeg'         => 'jpeg',
            'image/gif'          => 'gif',
            'image/png'          => 'png',
            'video/mp4'          => 'mp4',
        );
        
        if ( isset( $mime_extensions[$mime] ) ) {
            // Use the mapped extension
            $extension = $mime_extensions[$mime];
        }else{
            // Could not identify extension
            @unlink($tmp);
            return false;
        }
    }
    
    
    
    // Upload by "sideloading": "the same way as an uploaded file is handled by media_handle_upload"
    $args = array(
        'name' => "$filename.$extension",
        'tmp_name' => $tmp,
    );
    
    // Do the upload
    $attachment_id = media_handle_sideload( $args, $post_id, $title);
    
    // Cleanup temp file
    @unlink($tmp);

    // Step 1: Get the attachment ID and path of the image
    $image_path = get_attached_file($attachment_id);

    if( is_wp_error( $attachment_id ) ) {
        xindao_log_event($attachment_id->get_error_message(), 'ERROR');
        return;
    }

    if($attachment_id){
        xindao_log_event('Nova fotografija: ' . intval($attachment_id));
    }

    // Success, return attachment ID (int)
    return (int) $attachment_id;
}

// Função GET Cor Hexadecima e Retorna em RGB
function hexrgb($hexstr, $rgb) {
    $int = hexdec($hexstr);
    switch($rgb) {
            case "r":
            return 0xFF & $int >> 0x10;
                break;
            case "g":
            return 0xFF & ($int >> 0x8);
                break;
            case "b":
            return 0xFF & $int;
            break;
            default:
            return array(
                "r" => 0xFF & $int >> 0x10,
                "g" => 0xFF & ($int >> 0x8),
                "b" => 0xFF & $int
                );
            break;
    }    
}

function resizeImage($source_image, $destination, $tn_w = 1024, $tn_h = 1024, $inner_w = 924, $inner_h = 924, $quality = 70) {
    $info = getimagesize($source_image);
    if (!$info) {
        return false;
    }

    $imgtype = image_type_to_mime_type($info[2]);

    // Load image based on type
    switch ($imgtype) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($source_image);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($source_image);
            break;
        case 'image/png':
            $source = imagecreatefrompng($source_image);
            break;
        default:
            return false; // Invalid image type
    }

    // Original dimensions
    $src_w = imagesx($source);
    $src_h = imagesy($source);

    // Resize keeping aspect ratio within 824x824
    $x_ratio = $inner_w / $src_w;
    $y_ratio = $inner_h / $src_h;

    if (($src_w <= $inner_w) && ($src_h <= $inner_h)) {
        $new_w = $src_w;
        $new_h = $src_h;
    } elseif (($x_ratio * $src_h) < $inner_h) {
        $new_w = $inner_w;
        $new_h = ceil($x_ratio * $src_h);
    } else {
        $new_w = ceil($y_ratio * $src_w);
        $new_h = $inner_h;
    }

    // Create blank image with white background (1024x1024)
    $final = imagecreatetruecolor($tn_w, $tn_h);
    $backgroundColor = imagecolorallocate($final, 255, 255, 255);
    imagefill($final, 0, 0, $backgroundColor);

    // Preserve PNG transparency
    if ($imgtype == 'image/png') {
        imagecolortransparent($final, $backgroundColor);
        imagealphablending($final, false);
        imagesavealpha($final, true);
    }

    // Resize original image to 824x824
    $resized = imagecreatetruecolor($new_w, $new_h);
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);

    // Center resized image inside 1024x1024 canvas
    $x_offset = ($tn_w - $new_w) / 2;
    $y_offset = ($tn_h - $new_h) / 2;
    imagecopy($final, $resized, $x_offset, $y_offset, 0, 0, $new_w, $new_h);

    // Save image
    switch ($imgtype) {
        case 'image/jpeg':
            imagejpeg($final, $destination, $quality);
            break;
        case 'image/png':
            imagepng($final, $destination);
            break;
        case 'image/gif':
            imagegif($final, $destination);
            break;
    }

    imagedestroy($source);
    imagedestroy($resized);
    imagedestroy($final);

    return true;
}