<?php
declare(strict_types=1);
$base = $argv[1] ?? 'http://127.0.0.1:8080';
$ch = curl_init($base . '/orders');
curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode(['sku' => 'GAME-100', 'customer' => 'race@test']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => 1]);
$order = json_decode(curl_exec($ch), true);
curl_close($ch);
$mh = curl_multi_init();
$hs = [];
for ($i = 0; $i < 20; $i++) {
    $h = curl_init($base . '/webhooks/payment');
    curl_setopt_array($h, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode(['order_id' => $order['id'], 'payment_id' => 'race-payment', 'status' => 'succeeded']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => 1]);
    curl_multi_add_handle($mh, $h);
    $hs[] = $h;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running);
foreach ($hs as $h) curl_multi_remove_handle($mh, $h);
curl_multi_close($mh);
$final = json_decode(file_get_contents($base . '/orders/' . $order['id']), true);
$db = new PDO('sqlite:' . dirname(__DIR__) . '/var/store.sqlite');
$reservations = (int)$db->query("SELECT COUNT(*) FROM supplier_reservations WHERE idempotency_key='delivery:{$order['id']}'")->fetchColumn();
echo json_encode(['order_id' => $order['id'], 'status' => $final['status'], 'delivery_code' => $final['delivery_code'], 'supplier_reservations' => $reservations], JSON_PRETTY_PRINT) . PHP_EOL;
if ($final['status'] !== 'delivered' || $reservations !== 1) exit(1);
