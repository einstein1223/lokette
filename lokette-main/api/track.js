// Vercel Serverless Function: Proxy Lacak Resi GES Batam
// Digunakan secara otomatis saat di-deploy ke Vercel (https://lokette.web.id)

export default async function handler(req, res) {
    // Header CORS agar bisa diakses dari domain lokette.web.id maupun sub-domain
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    res.setHeader('Access-Control-Allow-Private-Network', 'true');
    res.setHeader('Content-Type', 'application/json; charset=utf-8');

    if (req.method === 'OPTIONS') {
        return res.status(204).end();
    }

    const rawResi = (req.query.resi || (req.body && req.body.resi) || '').toString();
    const cleanResi = rawResi.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    if (!cleanResi || cleanResi.length < 6) {
        return res.status(422).json({
            status: 422,
            message: 'Nomor resi tidak valid (minimal 6 karakter alfanumerik).'
        });
    }

    const targetUrl = `https://www.gesbatam.com/Tracking/lacakResi?noresiTags=${encodeURIComponent(cleanResi)}`;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);

    try {
        const gesResponse = await fetch(targetUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                'Referer': 'https://www.gesbatam.com/Tracking',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 LoketteTracking/1.0'
            },
            signal: controller.signal
        });

        clearTimeout(timeout);

        if (!gesResponse.ok) {
            return res.status(502).json({
                status: 502,
                message: `Server ekspedisi GES Batam memberikan respon HTTP ${gesResponse.status}. Coba beberapa saat lagi.`
            });
        }

        const data = await gesResponse.json();

        if (!Array.isArray(data)) {
            return res.status(502).json({
                status: 502,
                message: 'Format jawaban dari server ekspedisi GES Batam tidak dikenali.'
            });
        }

        if (data.length === 0) {
            return res.status(404).json({
                status: 404,
                message: 'Nomor resi tidak ditemukan di sistem tracking GES Batam. Periksa kembali nomor resimu.'
            });
        }

        return res.status(200).json({
            status: 200,
            data: data
        });
    } catch (err) {
        clearTimeout(timeout);
        const isTimeout = err.name === 'AbortError';
        return res.status(502).json({
            status: 502,
            message: isTimeout 
                ? 'Koneksi ke server GES Batam mengalami timeout (lebih dari 12 detik). Silakan coba lagi.'
                : 'Gagal terhubung ke server ekspedisi GES Batam: ' + err.message
        });
    }
}
