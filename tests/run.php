<?php
declare(strict_types=1);

/**
 * Минимальный test-runner без Composer/PHPUnit.
 * Запуск: php tests/run.php
 */

final class ApiTestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message, $status);
    }
}

function api_bootstrap(): void
{
    // no-op in tests
}

function api_fail(int $code, string $message, array $extra = []): never
{
    throw new ApiTestException($code, $message);
}

function api_ok(array $data): never
{
    throw new RuntimeException('api_ok() не должен вызываться в unit-тестах');
}

function api_require_post_json(): array
{
    throw new RuntimeException('api_require_post_json() не должен вызываться в unit-тестах');
}

function api_rate_limit(string $bucket, int $max, int $windowSec): void
{
    // no-op
}

function api_num(array $data, string $key, float $min, float $max): float
{
    if (!array_key_exists($key, $data)) {
        api_fail(422, "Поле «{$key}» обязательно");
    }

    $v = $data[$key];
    if (is_string($v)) {
        $v = str_replace(',', '.', trim($v));
    }
    if (!is_numeric($v)) {
        api_fail(422, "Поле «{$key}» должно быть числом");
    }

    $n = (float)$v;
    if (!is_finite($n) || $n < $min || $n > $max) {
        api_fail(422, "Поле «{$key}» вне допустимого диапазона");
    }

    return $n;
}

function api_enum(array $data, string $key, array $allowed): string
{
    $v = (string)($data[$key] ?? '');
    if (!in_array($v, $allowed, true)) {
        api_fail(422, "Недопустимое значение «{$key}»");
    }
    return $v;
}

final class TestCase
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $errors = [];

    public function assertTrue(bool $cond, string $msg = ''): void
    {
        if ($cond) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->errors[] = $msg !== '' ? $msg : 'assertTrue failed';
    }

    public function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->errors[] = ($msg !== '' ? $msg . ' — ' : '')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true);
    }

    public function assertEqualsFloat(float $expected, float $actual, float $eps, string $msg = ''): void
    {
        if (abs($expected - $actual) <= $eps) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->errors[] = ($msg !== '' ? $msg . ' — ' : '')
            . "expected {$expected}, got {$actual} (eps={$eps})";
    }

    public function assertThrows(int $status, callable $fn, string $msg = ''): void
    {
        try {
            $fn();
            $this->failed++;
            $this->errors[] = ($msg !== '' ? $msg . ' — ' : '') . 'ожидалось исключение';
        } catch (ApiTestException $e) {
            $this->assertSame($status, $e->status, $msg !== '' ? $msg : 'HTTP status');
        }
    }

    public function summary(): int
    {
        foreach ($this->errors as $err) {
            fwrite(STDERR, "FAIL: {$err}\n");
        }
        $total = $this->passed + $this->failed;
        fwrite(STDOUT, "Tests: {$total}, passed: {$this->passed}, failed: {$this->failed}\n");
        return $this->failed === 0 ? 0 : 1;
    }
}

require dirname(__DIR__) . '/api/gazobeton_calc.php';
require dirname(__DIR__) . '/api/gazobeton_export_lib.php';

$t = new TestCase();

/** Базовый payload формы (коробка + клей). */
function sample_box_payload(array $overrides = []): array
{
    return array_merge([
        'wall_mode' => 'box',
        'width_m' => 10,
        'length_m' => 12,
        'height1_m' => 2.7,
        'height2_m' => 0,
        'block_t_mm' => 300,
        'block_h_mm' => 250,
        'block_l_mm' => 600,
        'waste_pct' => 5,
        'price' => 5500,
        'price_mode' => 'm3',
        'binder_mode' => 'glue',
        'binder_price' => 450,
        'density' => 400,
        'openings' => [
            ['w' => 1.5, 'h' => 1.4, 'n' => 2],
        ],
    ], $overrides);
}

// --- расчёт: коробка ---
$box = gazobeton_compute(sample_box_payload());
$r = $box['result'];
$t->assertSame('box', $r['wall_mode'], 'wall_mode box');
$t->assertEqualsFloat(44.0, (float)$r['perimeter_m'], 1e-9, 'perimeter 2*(10+12)');
$t->assertEqualsFloat(4.2, (float)$r['openings_m2'], 1e-9, 'openings area');
$t->assertEqualsFloat(114.6, (float)$r['wall_area_m2'], 1e-6, 'wall area');
$t->assertEqualsFloat(34.38, (float)$r['volume_net_m3'], 1e-6, 'net volume');
$t->assertEqualsFloat(36.099, (float)$r['volume_m3'], 1e-6, 'volume with waste');
$t->assertSame(803, $r['blocks'], 'blocks ceil');
$t->assertSame(21, $r['pallets'], 'pallets');
$t->assertEqualsFloat(198544.5, (float)$r['blocks_cost'], 1e-6, 'blocks cost m3');
$t->assertEqualsFloat(5500.0, (float)$r['blocks_price_per_m3'], 1e-6, 'price per m3');
// клей по чистому объёму: 34.38 * 25 = 859.5 → 35 мешков
$t->assertEqualsFloat(859.5, (float)$r['binder']['qty'], 1e-6, 'glue qty net m3');
$t->assertSame(35, $r['binder']['packs'], 'glue packs');
$t->assertEqualsFloat(15750.0, (float)$r['binder']['cost'], 1e-6, 'glue cost');
$t->assertEqualsFloat(214294.5, (float)$r['cost'], 1e-6, 'total cost');

// --- дробные метры через запятую (как в форме) ---
$comma = gazobeton_compute(sample_box_payload([
    'width_m' => '10,5',
    'length_m' => '11,5',
    'height1_m' => '2,7',
    'openings' => [],
]));
$t->assertEqualsFloat(44.0, (float)$comma['result']['perimeter_m'], 1e-9, 'comma decimals perimeter');

// --- режим периметра ---
$per = gazobeton_compute(sample_box_payload([
    'wall_mode' => 'perimeter',
    'perimeter_m' => 40,
    'width_m' => null,
    'length_m' => null,
    'openings' => [],
]));
unset($per); // width/length not in payload
$per = gazobeton_compute([
    'wall_mode' => 'perimeter',
    'perimeter_m' => 40,
    'height1_m' => 3,
    'height2_m' => 0,
    'block_t_mm' => 300,
    'block_h_mm' => 250,
    'block_l_mm' => 600,
    'waste_pct' => 0,
    'price' => 5000,
    'price_mode' => 'm3',
    'binder_mode' => 'glue',
    'binder_price' => 400,
    'density' => 400,
    'openings' => [],
]);
$t->assertEqualsFloat(40.0, (float)$per['result']['perimeter_m'], 1e-9, 'perimeter mode');
$t->assertEqualsFloat(120.0, (float)$per['result']['wall_area_m2'], 1e-9, 'perimeter wall area');
$t->assertEqualsFloat(36.0, (float)$per['result']['volume_net_m3'], 1e-9, 'perimeter net volume');

// --- ЦПС по площади стен (чистой) ---
$cps = gazobeton_compute(sample_box_payload([
    'binder_mode' => 'cps',
    'binder_price' => 280,
    'waste_pct' => 10,
]));
// wall_area 114.6 * 18 = 2062.8 кг → ceil(2062.8/50) = 42 мешка
$t->assertEqualsFloat(2062.8, (float)$cps['result']['binder']['qty'], 1e-6, 'cps qty m2');
$t->assertSame(42, $cps['result']['binder']['packs'], 'cps packs');
$t->assertSame('мешок', $cps['result']['binder']['unit_label'], 'cps unit label');

// --- пена: 1 баллон на м³ чистого объёма ---
$foam = gazobeton_compute(sample_box_payload([
    'binder_mode' => 'foam',
    'binder_price' => 550,
]));
$t->assertSame(35, $foam['result']['binder']['packs'], 'foam packs = ceil(net m3)');
$t->assertSame('баллон', $foam['result']['binder']['unit_label'], 'foam unit');

// --- цена за палету ---
$pal = gazobeton_compute(sample_box_payload([
    'price_mode' => 'pallet',
    'price' => 10000,
]));
$t->assertEqualsFloat(210000.0, (float)$pal['result']['blocks_cost'], 1e-6, 'pallet pricing');

// --- валидация формы ---
$t->assertThrows(422, fn () => gazobeton_compute(sample_box_payload(['density' => 350])), 'bad density');
$t->assertThrows(422, fn () => gazobeton_compute(sample_box_payload([
    'height1_m' => 0,
    'height2_m' => 0,
])), 'no height');
$t->assertThrows(422, fn () => gazobeton_compute(sample_box_payload([
    'openings' => [['w' => 1, 'h' => 1, 'n' => 1.5]],
])), 'fractional opening count');

// --- экспорт: примечание построчно ---
$rows = gazobeton_export_rows($box);
$labels = array_column($rows, 0);
$noteIdx = array_search('Примечание', $labels, true);
$t->assertTrue($noteIdx !== false, 'note section exists');
$t->assertSame('Периметр', $labels[$noteIdx + 1], 'note line perimeter');
$t->assertSame('Площадь стен', $labels[$noteIdx + 2], 'note line wall area');
$t->assertSame('Проёмы', $labels[$noteIdx + 3], 'note line openings');
$t->assertSame('Норма связующего', $labels[$noteIdx + 4], 'note line binder');
$t->assertTrue(!str_contains($rows[$noteIdx][1], '·'), 'note header has no jammed text');

$xls = gazobeton_build_xls($rows);
$t->assertTrue(str_starts_with($xls, '<?xml'), 'xls is spreadsheetml');
$t->assertTrue(str_contains($xls, 'Примечание'), 'xls has note');
$t->assertTrue(str_contains($xls, 'Площадь стен'), 'xls has wall area row');

exit($t->summary());
