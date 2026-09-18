<?php
declare(strict_types=1);

/**
 * @param array{input: array<string,mixed>, result: array<string,mixed>} $computed
 * @return list<array{0:string,1:string}>
 */
function gazobeton_export_rows(array $computed): array
{
    $in = $computed['input'];
    $r = $computed['result'];
    $b = $r['binder'];

    $wallModeLabel = $in['wall_mode'] === 'box' ? 'Коробка (Ш×Д)' : 'Периметр';
    $priceModeLabel = $in['price_mode'] === 'pallet' ? 'за палету' : 'за м³';

    $rows = [
        ['CalcusPro — стоимость газобетона', ''],
        ['Дата', date('d.m.Y H:i')],
        ['', ''],
        ['Исходные данные', ''],
        ['Режим стен', $wallModeLabel],
    ];

    if ($in['wall_mode'] === 'box') {
        $rows[] = ['Ширина, м', gazobeton_fmt_num((float)$in['width_m'], 2)];
        $rows[] = ['Длина, м', gazobeton_fmt_num((float)$in['length_m'], 2)];
    }
    $rows[] = ['Периметр, м', gazobeton_fmt_num((float)$in['perimeter_m'], 2)];
    $rows[] = ['Высота 1 этажа, м', gazobeton_fmt_num((float)$in['height1_m'], 2)];
    $rows[] = ['Высота 2 этажа, м', gazobeton_fmt_num((float)$in['height2_m'], 2)];
    $rows[] = ['Блок Т×В×Д, мм', sprintf(
        '%s×%s×%s',
        gazobeton_fmt_num((float)$in['block_t_mm'], 0),
        gazobeton_fmt_num((float)$in['block_h_mm'], 0),
        gazobeton_fmt_num((float)$in['block_l_mm'], 0)
    )];
    $rows[] = ['Запас, %', gazobeton_fmt_num((float)$in['waste_pct'], 1)];
    $rows[] = ['Плотность', 'D' . (int)$in['density']];
    $rows[] = ['Цена блоков', gazobeton_fmt_money((float)$in['price']) . ' ' . $priceModeLabel];
    $rows[] = ['Связующее', (string)$b['label']];
    $rows[] = ['Цена связующего за шт', gazobeton_fmt_money((float)$in['binder_price'])];

    if ($in['openings'] !== []) {
        $rows[] = ['', ''];
        $rows[] = ['Проёмы (Ш×В×кол-во)', ''];
        foreach ($in['openings'] as $i => $op) {
            $rows[] = [
                'Проём ' . ($i + 1),
                sprintf(
                    '%s×%s м × %d',
                    gazobeton_fmt_num((float)$op['w'], 2),
                    gazobeton_fmt_num((float)$op['h'], 2),
                    (int)$op['n']
                ),
            ];
        }
    }

    $rows[] = ['', ''];
    $rows[] = ['Итого с запасом', ''];
    $rows[] = ['Стоимость', gazobeton_fmt_money((float)$r['cost'])];
    $rows[] = ['Объём с запасом, м³', gazobeton_fmt_num((float)$r['volume_m3'], 2)];
    $rows[] = ['Чистый объём, м³', gazobeton_fmt_num((float)$r['volume_net_m3'], 2)];
    $rows[] = ['Блоки, шт', (string)(int)$r['blocks']];
    $rows[] = ['Палеты', (string)(int)$r['pallets']];
    $rows[] = ['Масса, т', gazobeton_fmt_num((float)$r['mass_t'], 2)];
    $rows[] = ['Цена блоков за м³', gazobeton_fmt_money((float)$r['blocks_price_per_m3'])];
    $rows[] = ['Блоки, сумма', gazobeton_fmt_money((float)$r['blocks_cost'])];
    $rows[] = [
        $b['label'] . ', расход',
        gazobeton_fmt_num((float)$b['qty'], $b['qty_unit'] === 'cylinder' ? 0 : 1) . ' ' . $b['qty_label'],
    ];
    $rows[] = [$b['pack_label'], (string)(int)$b['packs']];
    $rows[] = ['Цена за ' . $b['unit_label'], gazobeton_fmt_money((float)$b['unit_price'])];
    $rows[] = [$b['label'] . ', сумма', gazobeton_fmt_money((float)$b['cost'])];
    $rows[] = ['', ''];
    $rows[] = ['Примечание', ''];
    $rows[] = ['Периметр', gazobeton_fmt_num((float)$r['perimeter_m'], 2) . ' м'];
    $rows[] = ['Площадь стен', gazobeton_fmt_num((float)$r['wall_area_m2'], 2) . ' м²'];
    $rows[] = ['Проёмы', gazobeton_fmt_num((float)$r['openings_m2'], 2) . ' м²'];
    if ((string)$b['note'] !== '') {
        $rows[] = ['Норма связующего', (string)$b['note']];
    }

    return $rows;
}

function gazobeton_fmt_num(float $v, int $decimals): string
{
    return number_format($v, $decimals, ',', ' ');
}

function gazobeton_fmt_money(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' ₽';
}

function gazobeton_xml_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param list<array{0:string,1:string}> $rows
 */
function gazobeton_build_xls(array $rows): string
{
    $cells = '';
    foreach ($rows as $row) {
        $cells .= '<Row>'
            . '<Cell><Data ss:Type="String">' . gazobeton_xml_escape($row[0]) . '</Data></Cell>'
            . '<Cell><Data ss:Type="String">' . gazobeton_xml_escape($row[1]) . '</Data></Cell>'
            . '</Row>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<?mso-application progid="Excel.Sheet"?>' . "\n"
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Worksheet ss:Name="Расчёт">'
        . '<Table>' . $cells . '</Table>'
        . '</Worksheet></Workbook>';
}

/**
 * @param list<array{0:string,1:string}> $rows
 */
function gazobeton_build_pdf(array $rows): string
{
    if (!function_exists('imagecreatetruecolor')) {
        api_fail(500, 'PDF недоступен на сервере (нет GD)');
    }

    $lineH = 22;
    $padX = 36;
    $padY = 40;
    $width = 794;
    $height = max(1123, $padY * 2 + count($rows) * $lineH + 60);

    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        api_fail(500, 'Не удалось создать PDF');
    }

    $bg = imagecolorallocate($im, 255, 255, 255);
    $fg = imagecolorallocate($im, 24, 24, 27);
    $muted = imagecolorallocate($im, 82, 82, 91);
    $accent = imagecolorallocate($im, 13, 148, 136);
    $line = imagecolorallocate($im, 228, 228, 231);
    imagefill($im, 0, 0, $bg);

    $ttf = gazobeton_find_ttf();
    $y = $padY;

    foreach ($rows as $i => $row) {
        $label = $row[0];
        $value = $row[1];
        $isTitle = ($i === 0);
        $isSection = ($value === '' && $label !== '');

        if ($label === '' && $value === '') {
            $y += (int)($lineH * 0.45);
            continue;
        }

        if ($ttf !== null) {
            $size = $isTitle ? 16.0 : ($isSection ? 12.0 : 10.0);
            $color = ($isTitle || $isSection) ? $accent : $fg;
            imagettftext($im, $size, 0, $padX, $y, $color, $ttf, $label);
            if ($value !== '') {
                imagettftext($im, $size, 0, (int)($width * 0.48), $y, $fg, $ttf, $value);
            }
        } else {
            $text = gazobeton_to_latin($label . ($value !== '' ? ': ' . $value : ''));
            $color = ($isTitle || $isSection) ? $accent : $fg;
            imagestring($im, 4, $padX, $y - 12, $text, $color);
        }

        if (!$isTitle && !$isSection && $value !== '') {
            imageline($im, $padX, $y + 6, $width - $padX, $y + 6, $line);
        }

        $y += $lineH;
        if ($isTitle) {
            $y += 8;
            if ($ttf !== null) {
                imagettftext($im, 9.0, 0, $padX, $y, $muted, $ttf, 'calcuspro.ru');
            } else {
                imagestring($im, 2, $padX, $y - 14, 'calcuspro.ru', $muted);
            }
            $y += 12;
        }
    }

    ob_start();
    imagejpeg($im, null, 92);
    $jpeg = ob_get_clean();
    imagedestroy($im);
    if ($jpeg === false || $jpeg === '') {
        api_fail(500, 'Не удалось сформировать PDF');
    }

    return gazobeton_pdf_from_jpeg($jpeg, $width, $height);
}

function gazobeton_find_ttf(): ?string
{
    $candidates = [
        __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
        'C:\\Windows\\Fonts\\segoeui.ttf',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function gazobeton_to_latin(string $s): string
{
    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y',
        'К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F',
        'Х'=>'H','Ц'=>'C','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
        'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
        'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        '₽'=>'RUB','×'=>'x','·'=>'-','—'=>'-','–'=>'-',
    ];
    return strtr($s, $map);
}

function gazobeton_pdf_from_jpeg(string $jpeg, int $imgW, int $imgH): string
{
    $pageW = 595.28;
    $pageH = 841.89;
    $scale = min($pageW / $imgW, $pageH / $imgH);
    $drawW = $imgW * $scale;
    $drawH = $imgH * $scale;
    $offX = ($pageW - $drawW) / 2;
    $offY = ($pageH - $drawH) / 2;

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[3] = sprintf(
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>',
        $pageW,
        $pageH
    );
    $content = sprintf("q\n%.4F 0 0 %.4F %.4F %.4F cm\n/Im0 Do\nQ\n", $drawW, $drawH, $offX, $offY);
    $objects[4] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
    $objects[5] = sprintf(
        '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>' . "\nstream\n%s\nendstream",
        $imgW,
        $imgH,
        strlen($jpeg),
        $jpeg
    );

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xref}\n%%EOF";
    return $pdf;
}
