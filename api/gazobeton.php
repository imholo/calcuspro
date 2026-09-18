<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

api_bootstrap();
api_rate_limit('gazobeton', 60, 60);

$input = api_require_post_json();

$mode = api_enum($input, 'mode', ['perimeter', 'box']);
$height = api_num($input, 'height_m', 0.5, 30);
$thicknessMm = api_num($input, 'thickness_mm', 50, 600);
$openings = api_num($input, 'openings_m2', 0, 5000);
$blockL = api_num($input, 'block_l_mm', 100, 900);
$blockW = api_num($input, 'block_w_mm', 50, 600);
$blockH = api_num($input, 'block_h_mm', 50, 500);
$reservePct = api_num($input, 'reserve_pct', 0, 30);
$price = api_num($input, 'price', 0, 1_000_000);
$priceUnit = api_enum($input, 'price_unit', ['m3', 'piece']);
$glueKgPerM2 = api_num($input, 'glue_kg_m2', 0, 10);
$density = api_num($input, 'density', 200, 800);

if ($mode === 'perimeter') {
    $perimeter = api_num($input, 'perimeter_m', 1, 2000);
} else {
    $len = api_num($input, 'length_m', 1, 500);
    $width = api_num($input, 'width_m', 1, 500);
    $perimeter = 2.0 * ($len + $width);
}

$grossArea = $perimeter * $height;
$netArea = $grossArea - $openings;
if ($netArea <= 0) {
    api_fail(422, 'Площадь проёмов больше или равна площади стен');
}

$thicknessM = $thicknessMm / 1000.0;
$volume = $netArea * $thicknessM;

$blockVolume = ($blockL / 1000.0) * ($blockW / 1000.0) * ($blockH / 1000.0);
if ($blockVolume <= 0) {
    api_fail(422, 'Некорректный размер блока');
}

$blocksExact = $volume / $blockVolume;
$blocks = (int)ceil($blocksExact);
$blocksReserve = (int)ceil($blocks * (1.0 + $reservePct / 100.0));
$volumeReserve = $volume * (1.0 + $reservePct / 100.0);
$blocksPerM3 = 1.0 / $blockVolume;

if ($priceUnit === 'm3') {
    $cost = $volumeReserve * $price;
} else {
    $cost = $blocksReserve * $price;
}

$glueKg = $netArea * $glueKgPerM2 * (1.0 + $reservePct / 100.0);
$massTons = ($volumeReserve * $density) / 1000.0;

api_ok([
    'result' => [
        'perimeter_m' => round($perimeter, 3),
        'wall_area_gross_m2' => round($grossArea, 3),
        'wall_area_net_m2' => round($netArea, 3),
        'volume_m3' => round($volume, 4),
        'volume_with_reserve_m3' => round($volumeReserve, 4),
        'block_volume_m3' => round($blockVolume, 6),
        'blocks_per_m3' => round($blocksPerM3, 2),
        'blocks' => $blocks,
        'blocks_with_reserve' => $blocksReserve,
        'glue_kg' => round($glueKg, 2),
        'mass_t' => round($massTons, 3),
        'cost' => round($cost, 2),
        'price_unit' => $priceUnit,
    ],
]);
