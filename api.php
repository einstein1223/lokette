<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Private-Network: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

$resi = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['resi'] ?? ''));
if ($resi === '' || strlen($resi) < 6) {
    http_response_code(422);
    echo json_encode(['status' => 422, 'message' => 'Nomor resi tidak valid (minimal 6 karakter alfanumerik).']);
    exit;
}

$base  = 'https://www.gesbatam.com';
$agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
$url   = $base . '/Tracking/lacakResi?noresiTags=' . urlencode($resi);

$body = false;
$code = 0;
$err  = '';

for ($try = 1; $try <= 2; $try++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $agent,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.gesbatam.com/Tracking',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        break;
    }
    if ($try < 2) {
        sleep(1);
    }
}

if ($body === false || $code !== 200) {
    echo json_encode([
        'status'  => 502,
        'message' => 'Gagal mengambil data dari server GES Batam. Coba lagi beberapa saat.',
        'detail'  => trim("HTTP $code " . $err),
    ]);
    exit;
}

$rows = json_decode($body, true);
if (!is_array($rows)) {
    echo json_encode(['status' => 502, 'message' => 'Format jawaban server GES Batam tidak dikenal.']);
    exit;
}

if (count($rows) === 0) {
    echo json_encode(['status' => 404, 'message' => 'Nomor resi tidak ditemukan di sistem tracking GES Batam. Periksa kembali nomor resimu.']);
    exit;
}

echo json_encode(['status' => 200, 'data' => $rows]);
