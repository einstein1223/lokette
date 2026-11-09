<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Private-Network: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

$rawResi = $_GET['resi'] ?? '';
$resi = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawResi));

if ($resi === '' || strlen($resi) < 4) {
    http_response_code(422);
    echo json_encode(['status' => 422, 'message' => 'Nomor resi tidak valid (minimal 4 karakter alfanumerik).']);
    exit;
}

// Mapping cerdas: Hubungkan DO / Marking ke resi resmi GES Batam
if ($resi === '09P826K' || $resi === '09P8' || strpos($resi, 'EINSTEIN') !== false || $resi === 'LKTWH2026' || $resi === 'WH2026') {
    $resi = 'YT7643297485802';
}

$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ges_session_cookie.txt';
$tokenFile  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ges_token_cache.txt';
$agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

function fetchGesToken($cookieFile, $agent) {
    $ch = curl_init('https://www.gesbatam.com/Tracking');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $agent,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        ]
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (preg_match('/<meta\s+name=[\'"]tracking-token[\'"]\s+content=[\'"]([^\'"]+)[\'"]/i', $html, $m) ||
        preg_match('/<meta\s+content=[\'"]([^\'"]+)[\'"]\s+name=[\'"]tracking-token[\'"]/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

$token = null;
if (file_exists($tokenFile) && (time() - filemtime($tokenFile) < 1800)) {
    $token = trim(@file_get_contents($tokenFile));
}

if (!$token) {
    $token = fetchGesToken($cookieFile, $agent);
    if ($token) {
        @file_put_contents($tokenFile, $token);
    }
}

if (!$token) {
    http_response_code(502);
    echo json_encode(['status' => 502, 'message' => 'Gagal mengambil security token dari sistem GES Batam.']);
    exit;
}

function queryGes($resi, $token, $cookieFile, $agent) {
    $url = 'https://www.gesbatam.com/Tracking/lacakResi?noresiTags=' . urlencode($resi);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $agent,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.gesbatam.com/Tracking',
            'X-Tracking-Token: ' . $token,
        ]
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

$result = queryGes($resi, $token, $cookieFile, $agent);

// Jika 401 token expired, refresh token sekali lagi
if ($result['code'] === 401) {
    $token = fetchGesToken($cookieFile, $agent);
    if ($token) {
        @file_put_contents($tokenFile, $token);
        $result = queryGes($resi, $token, $cookieFile, $agent);
    }
}

if ($result['code'] !== 200) {
    http_response_code($result['code'] ?: 502);
    echo json_encode([
        'status' => $result['code'] ?: 502,
        'message' => 'Server GES Batam merespon dengan status HTTP ' . $result['code']
    ]);
    exit;
}

$data = json_decode($result['body'], true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['status' => 502, 'message' => 'Format jawaban dari server ekspedisi GES Batam tidak dikenali.']);
    exit;
}

if (count($data) === 0) {
    http_response_code(404);
    echo json_encode(['status' => 404, 'message' => 'Nomor resi tidak ditemukan di sistem tracking GES Batam.']);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 200,
    'data'   => $data
]);
