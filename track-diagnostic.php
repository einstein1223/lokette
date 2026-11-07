<?php
// ============================================================
//  VERSI DIAGNOSTIK dari track.php — hanya dipakai kalau uji
//  lacak masih gagal, untuk melihat persis jawaban server GES.
//  CARA PAKAI: letakkan file ini di folder yang sama dengan
//  track.php, lalu buka di browser:
//      track-diagnostic.php?resi=YT7643297485802
//  File ini TIDAK dipakai oleh index.php dan TIDAK menggantikan
//  track.php. Kalau hasilnya sudah jelas, hapus file ini lagi.
//  Isi laporan: status halaman /Tracking (sekadar info — sejak
//  perbaikan 2 Nov 2026 langkah pemanasan sesi tidak lagi
//  dipakai track.php karena endpoint terbukti tidak butuh
//  cookie), lalu tiap percobaan ke lacakResi (kode HTTP,
//  error curl, cuplikan jawaban mentah), plus hasil akhir.
//  Kirimkan seluruh output ini ke Muse supaya penyebabnya
//  bisa dibaca langsung, bukan ditebak.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

$resi = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['resi'] ?? ''));
if ($resi === '' || strlen($resi) < 6) {
    http_response_code(422);
    echo json_encode(['status' => 422, 'message' => 'Nomor resi tidak valid.']);
    exit;
}

$started = microtime(true);
$base    = 'https://www.gesbatam.com';
$agent   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

function ges_call($url, $agent)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 25,
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
    return [$body, $code, $err];
}

$diag = ['resi_dicoba' => $resi, 'langkah' => []];

// Info saja: apakah halaman /Tracking bisa dibuka dari server ini?
[$pageBody, $pageCode, $pageErr] = ges_call($base . '/Tracking', $agent);
$diag['langkah']['info_halaman_tracking'] = [
    'http_code' => $pageCode,
    'curl_error' => $pageErr !== '' ? $pageErr : null,
    'isi_diterima' => $pageBody === false ? false : strlen($pageBody) . ' karakter',
    'catatan' => 'Hanya info — track.php tidak lagi bergantung pada langkah ini.',
];

// Panggil endpoint lacakResi langsung, sama seperti track.php.
$url = $base . '/Tracking/lacakResi?noresiTags=' . urlencode($resi);
$diag['langkah']['url_yang_dipanggil'] = $url;
$attempts = [];
$body = false; $code = 0;
for ($try = 1; $try <= 4; $try++) {
    [$body, $code, $err] = ges_call($url, $agent);
    $attempts[] = [
        'percobaan' => $try,
        'http_code' => $code,
        'curl_error' => $err !== '' ? $err : null,
        'jawaban_mentah_cuplikan' => $body === false ? null : mb_substr($body, 0, 1000),
    ];
    if ($body !== false && $code === 200) {
        break;
    }
    if ($try < 4) {
        sleep(2 * $try);
    }
}
$diag['langkah']['percobaan_lacakResi'] = $attempts;

$rows = ($body !== false) ? json_decode($body, true) : null;
$diag['hasil_akhir'] = [
    'http_code_terakhir' => $code,
    'json_bisa_dibaca' => is_array($rows),
    'jumlah_baris' => is_array($rows) ? count($rows) : null,
    'data' => is_array($rows) ? $rows : null,
];
$diag['durasi_detik'] = round(microtime(true) - $started, 1);
echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
