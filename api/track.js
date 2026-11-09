// Vercel Serverless Function: Proxy Lacak Resi Live GES Batam
// Digunakan secara otomatis saat di-deploy ke Vercel

let tokenCache = {
    token: null,
    cookies: null,
    expiresAt: 0
};

async function getGesToken() {
    const now = Date.now();
    if (tokenCache.token && tokenCache.cookies && now < tokenCache.expiresAt) {
        return { token: tokenCache.token, cookies: tokenCache.cookies };
    }

    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    const pageResp = await fetch('https://www.gesbatam.com/Tracking', {
        headers: {
            'User-Agent': userAgent,
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7'
        }
    });

    let cookieStr = '';
    if (typeof pageResp.headers.getSetCookie === 'function') {
        cookieStr = pageResp.headers.getSetCookie().map(c => c.split(';')[0]).join('; ');
    } else {
        const raw = pageResp.headers.get('set-cookie') || '';
        cookieStr = raw.split(';')[0];
    }

    const html = await pageResp.text();
    const tokenMatch = html.match(/<meta\s+name=["']tracking-token["']\s+content=["']([^"']+)["']/i) ||
                       html.match(/<meta\s+content=["']([^"']+)["']\s+name=["']tracking-token["']/i);

    if (!tokenMatch || !tokenMatch[1]) {
        throw new Error('Gagal mendapatkan security tracking token dari server GES Batam.');
    }

    tokenCache = {
        token: tokenMatch[1],
        cookies: cookieStr,
        expiresAt: now + (30 * 60 * 1000) // cache 30 menit
    };

    return { token: tokenCache.token, cookies: tokenCache.cookies };
}

export default async function handler(req, res) {
    // Header CORS agar bisa diakses dari frontend mana pun
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    res.setHeader('Access-Control-Allow-Private-Network', 'true');
    res.setHeader('Content-Type', 'application/json; charset=utf-8');

    if (req.method === 'OPTIONS') {
        return res.status(204).end();
    }

    const rawResi = (req.query.resi || (req.body && req.body.resi) || '').toString().trim();
    let cleanResi = rawResi.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    if (!cleanResi || cleanResi.length < 4) {
        return res.status(422).json({
            status: 422,
            message: 'Nomor resi tidak valid (minimal 4 karakter alfanumerik).'
        });
    }

    // Mapping cerdas: Jika user memasukkan nomor DO atau Marking paket Lokette, hubungkan ke resi resminya di GES Batam
    if (cleanResi === '09P826K' || cleanResi === '09P8' || cleanResi.includes('EINSTEIN') || cleanResi === 'LKTWH2026' || cleanResi === 'WH2026') {
        cleanResi = 'YT7643297485802';
    }

    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    try {
        // Ambil token keamanan dan cookies resmi dari GES Batam
        const { token, cookies } = await getGesToken();

        const targetUrl = `https://www.gesbatam.com/Tracking/lacakResi?noresiTags=${encodeURIComponent(cleanResi)}`;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);

        let gesResponse = await fetch(targetUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                'Referer': 'https://www.gesbatam.com/Tracking',
                'User-Agent': userAgent,
                'X-Tracking-Token': token,
                'Cookie': cookies
            },
            signal: controller.signal
        });

        clearTimeout(timeout);

        // Jika token kadaluarsa (401), invalidate cache dan coba refresh token 1 kali lagi
        if (gesResponse.status === 401) {
            tokenCache.expiresAt = 0;
            const fresh = await getGesToken();
            gesResponse = await fetch(targetUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json, text/javascript, */*; q=0.01',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Referer': 'https://www.gesbatam.com/Tracking',
                    'User-Agent': userAgent,
                    'X-Tracking-Token': fresh.token,
                    'Cookie': fresh.cookies
                }
            });
        }

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
        const isTimeout = err.name === 'AbortError';
        return res.status(502).json({
            status: 502,
            message: isTimeout 
                ? 'Koneksi ke server GES Batam mengalami timeout (lebih dari 12 detik). Silakan coba lagi.'
                : 'Gagal terhubung ke server ekspedisi GES Batam: ' + err.message
        });
    }
}
