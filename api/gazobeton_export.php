<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gazobeton_calc.php';
require __DIR__ . '/gazobeton_export_lib.php';

api_bootstrap();
api_rate_limit('gazobeton_export', 30, 60);

$input = api_require_post_json();
$format = strtolower((string)($input['format'] ?? ''));
if (!in_array($format, ['xls', 'pdf'], true)) {
    api_fail(422, 'Укажите format: xls или pdf');
}

$computed = gazobeton_compute($input);
$rows = gazobeton_export_rows($computed);
$stamp = date('Y-m-d_H-i');
$basename = 'gazobeton_' . $stamp;

if ($format === 'xls') {
    $body = gazobeton_build_xls($rows);
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.xls"');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

$body = gazobeton_build_pdf($rows);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $basename . '.pdf"');
header('Cache-Control: no-store');
echo $body;
exit;
