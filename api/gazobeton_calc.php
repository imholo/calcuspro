<?php
declare(strict_types=1);

/**
 * Нормы расхода:
 * - ЦПС, шов ~10–12 мм: ~18 кг/м² сухой смеси, мешок 50 кг
 * - Клей, шов 2–3 мм: ~25 кг/м³, мешок 25 кг
 * - Клей-пена: баллон 750 мл, ~1 шт на 1 м³ кладки
 */
const BINDER_NORMS = [
    'cps' => [
        'label' => 'ЦПС',
        'basis' => 'm2',
        'rate' => 18.0,
        'pack_size' => 50.0,
        'pack_unit' => 'bag',
        'pack_label' => 'мешков по 50 кг',
        'qty_unit' => 'kg',
        'qty_label' => 'кг',
        'note' => 'шов ~10–12 мм, ~18 кг/м²',
        'cylinder_ml' => null,
    ],
    'glue' => [
        'label' => 'Клей',
        'basis' => 'm3',
        'rate' => 25.0,
        'pack_size' => 25.0,
        'pack_unit' => 'bag',
        'pack_label' => 'мешков по 25 кг',
        'qty_unit' => 'kg',
        'qty_label' => 'кг',
        'note' => 'шов 2–3 мм, ~25 кг/м³',
        'cylinder_ml' => null,
    ],
    'foam' => [
        'label' => 'Клей-пена',
        'basis' => 'm3',
        'rate' => 1.0,
        'pack_size' => 1.0,
        'pack_unit' => 'cylinder',
        'pack_label' => 'баллонов по 750 мл',
        'qty_unit' => 'cylinder',
        'qty_label' => 'баллонов 750 мл',
        'note' => '~1 баллон 750 мл на 1 м³ кладки',
        'cylinder_ml' => 750,
    ],
];

/**
 * @param array<string,mixed> $input
 * @return array{result: array<string,mixed>, input: array<string,mixed>}
 */
function gazobeton_compute(array $input): array
{
    $wallMode = api_enum($input, 'wall_mode', ['box', 'perimeter']);
    $height1M = api_num($input, 'height1_m', 0, 30);
    $height2M = api_num($input, 'height2_m', 0, 30);
    $blockT = api_num($input, 'block_t_mm', 50, 600);
    $blockH = api_num($input, 'block_h_mm', 50, 500);
    $blockL = api_num($input, 'block_l_mm', 100, 900);
    $wastePct = api_num($input, 'waste_pct', 0, 30);
    $price = api_num($input, 'price', 0, 1_000_000);
    $priceMode = api_enum($input, 'price_mode', ['m3', 'pallet']);
    $binderMode = api_enum($input, 'binder_mode', ['cps', 'glue', 'foam']);
    $binderPrice = api_num($input, 'binder_price', 0, 1_000_000);
    $density = (int)round(api_num($input, 'density', 200, 800));
    $densityAllowed = [300, 400, 500, 600];
    if (!in_array($density, $densityAllowed, true)) {
        api_fail(422, 'Плотность: выберите D300, D400, D500 или D600');
    }

    if ($height1M + $height2M < 0.01) {
        api_fail(422, 'Укажите высоту хотя бы одного этажа');
    }

    $widthM = null;
    $lengthM = null;
    if ($wallMode === 'box') {
        $widthM = api_num($input, 'width_m', 0.01, 500);
        $lengthM = api_num($input, 'length_m', 0.01, 500);
        $perimeter = 2.0 * ($widthM + $lengthM);
    } else {
        $perimeter = api_num($input, 'perimeter_m', 0.01, 2000);
    }

    $openingsRaw = $input['openings'] ?? [];
    if (!is_array($openingsRaw)) {
        api_fail(422, 'Проёмы должны быть массивом');
    }
    if (count($openingsRaw) > 50) {
        api_fail(422, 'Слишком много проёмов');
    }

    $openings = [];
    $openArea = 0.0;
    foreach ($openingsRaw as $row) {
        if (!is_array($row)) {
            api_fail(422, 'Некорректный проём');
        }
        $ow = api_num($row, 'w', 0.01, 20);
        $oh = api_num($row, 'h', 0.01, 10);
        $on = api_num($row, 'n', 1, 200);
        $nInt = (int)round($on);
        if (abs($on - $nInt) > 1e-9 || $nInt < 1) {
            api_fail(422, 'Количество проёмов должно быть целым числом ≥ 1');
        }
        $openArea += $ow * $oh * $nInt;
        $openings[] = ['w' => $ow, 'h' => $oh, 'n' => $nInt];
    }

    $totalHeight = $height1M + $height2M;
    $wallArea = max(0.0, $perimeter * $totalHeight - $openArea);
    $thicknessM = $blockT / 1000.0;
    $netVolume = $wallArea * $thicknessM;
    $volumeWithWaste = $netVolume * (1.0 + $wastePct / 100.0);

    $blockVolume = ($blockT * $blockH * $blockL) / 1e9;
    if ($blockVolume <= 0) {
        api_fail(422, 'Некорректный размер блока');
    }

    $blocks = (int)ceil($volumeWithWaste / $blockVolume - 1e-9);
    $perPallet = 40;
    $pallets = $blocks > 0 ? (int)ceil($blocks / $perPallet) : 0;
    $massTons = ($volumeWithWaste * $density) / 1000.0;

    if ($priceMode === 'pallet') {
        $blocksCost = $pallets * $price;
    } else {
        $blocksCost = $volumeWithWaste * $price;
    }

    $blocksPricePerM3 = $volumeWithWaste > 0
        ? ($blocksCost / $volumeWithWaste)
        : 0.0;

    $norm = BINDER_NORMS[$binderMode];
    if ($norm['basis'] === 'm2') {
        $binderQty = $wallArea * $norm['rate'];
    } else {
        $binderQty = $netVolume * $norm['rate'];
    }

    if ($norm['pack_unit'] === 'cylinder') {
        $binderPacks = (int)ceil($binderQty - 1e-9);
        $binderQtyRounded = (float)$binderPacks;
    } else {
        $binderQtyRounded = round($binderQty, 1);
        $binderPacks = $norm['pack_size'] > 0
            ? (int)ceil($binderQty / $norm['pack_size'] - 1e-9)
            : 0;
    }

    $binderCost = $binderPacks * $binderPrice;
    $totalCost = $blocksCost + $binderCost;

    return [
        'input' => [
            'wall_mode' => $wallMode,
            'width_m' => $widthM,
            'length_m' => $lengthM,
            'perimeter_m' => round($perimeter, 3),
            'height1_m' => $height1M,
            'height2_m' => $height2M,
            'block_t_mm' => $blockT,
            'block_h_mm' => $blockH,
            'block_l_mm' => $blockL,
            'waste_pct' => $wastePct,
            'price' => $price,
            'price_mode' => $priceMode,
            'binder_mode' => $binderMode,
            'binder_price' => $binderPrice,
            'density' => $density,
            'openings' => $openings,
        ],
        'result' => [
            'wall_mode' => $wallMode,
            'perimeter_m' => round($perimeter, 3),
            'wall_area_m2' => round($wallArea, 3),
            'openings_m2' => round($openArea, 3),
            'volume_net_m3' => round($netVolume, 4),
            'volume_m3' => round($volumeWithWaste, 4),
            'block_volume_m3' => round($blockVolume, 6),
            'blocks' => $blocks,
            'pallets' => $pallets,
            'blocks_per_pallet' => $perPallet,
            'density' => $density,
            'mass_t' => round($massTons, 3),
            'blocks_cost' => round($blocksCost, 2),
            'blocks_price_per_m3' => round($blocksPricePerM3, 2),
            'binder' => [
                'mode' => $binderMode,
                'label' => $norm['label'],
                'note' => $norm['note'],
                'qty' => $binderQtyRounded,
                'qty_unit' => $norm['qty_unit'],
                'qty_label' => $norm['qty_label'],
                'packs' => $binderPacks,
                'pack_label' => $norm['pack_label'],
                'unit_price' => round($binderPrice, 2),
                'unit_label' => $norm['pack_unit'] === 'cylinder' ? 'баллон' : 'мешок',
                'cylinder_ml' => $norm['cylinder_ml'],
                'cost' => round($binderCost, 2),
            ],
            'cost' => round($totalCost, 2),
            'price_mode' => $priceMode,
        ],
    ];
}
