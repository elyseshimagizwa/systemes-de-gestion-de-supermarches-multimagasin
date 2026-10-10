<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('session.save_path', sys_get_temp_dir());
session_start();
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
});
require_once __DIR__ . '/../includes/fonctions.php';

function expectSaleQuantities(array $actual, array $expected, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export($actual, true));
    }
    echo "PASS $label\n";
}

function expectInvalidSaleItems(array $items, string $label): void
{
    try {
        normalizeSaleQuantities($items);
    } catch (InvalidArgumentException $exception) {
        echo "PASS $label\n";
        return;
    }
    throw new RuntimeException($label . ': invalid sale items accepted');
}

try {
    expectSaleQuantities(
        normalizeSaleQuantities([
            ['id' => 7, 'qty' => 1.125],
            ['id' => 7, 'qty' => 0.375],
            ['id' => 12, 'qty' => 2],
        ]),
        [7 => 1.5, 12 => 2.0],
        'duplicate products are merged'
    );

    expectInvalidSaleItems([], 'empty basket rejected');
    expectInvalidSaleItems([['id' => '1x', 'qty' => 1]], 'invalid product id rejected');
    expectInvalidSaleItems([['id' => 1, 'qty' => 0]], 'zero quantity rejected');
    expectInvalidSaleItems([['id' => 1, 'qty' => INF]], 'infinite quantity rejected');
    expectInvalidSaleItems([['id' => 1, 'qty' => 10000], ['id' => 1, 'qty' => 0.001]], 'duplicate quantity cap enforced');

    if (!preg_match('/^TK-[0-9]{14}-[A-F0-9]{8}$/', generateTicketNumber())) {
        throw new RuntimeException('ticket number format is invalid');
    }
    echo "PASS ticket number format\nRESULT=PASS\n";
} catch (Throwable $exception) {
    echo 'RESULT=FAIL: ' . $exception->getMessage() . "\n";
    exit(1);
}
