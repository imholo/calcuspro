<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gazobeton_calc.php';

api_bootstrap();
api_rate_limit('gazobeton', 60, 60);

$input = api_require_post_json();
$computed = gazobeton_compute($input);
api_ok(['result' => $computed['result']]);
