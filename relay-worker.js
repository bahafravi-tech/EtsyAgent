// relay-worker.js — کد Cloudflare Worker برای عبور از فیلترینگ ایران
//
// نکته‌ی مهم: اگه برای فی‌چاپ از قبل یه Worker با همین ساختار داری، لازم نیست یکی جدید بسازی —
// همین یکی رو با همون RELAY_URL/RELAY_SECRET توی config.php این پروژه هم استفاده کن،
// فقط مطمئن شو ALLOWED_HOSTS پایین شامل این ۴ دامنه هست (که برای فی‌چاپ هم همینا لازم بودن،
// پس اگه فی‌چاپ کار می‌کنه، این پروژه هم بدون تغییر توی Worker کار می‌کنه).

const ALLOWED_HOSTS = [
  'graph.facebook.com',
  'api.pinterest.com',
  'generativelanguage.googleapis.com',
  'api.telegram.org',
];

// این رو با یه رشته‌ی تصادفی طولانی عوض کن، و همون رو توی config.php به‌عنوان RELAY_SECRET بذار
const RELAY_SECRET = 'REPLACE_WITH_YOUR_SECRET';

export default {
  async fetch(request) {
    if (request.method !== 'POST') {
      return new Response('Method not allowed', { status: 405 });
    }

    const secret = request.headers.get('X-Relay-Secret');
    if (secret !== RELAY_SECRET) {
      return new Response('Unauthorized', { status: 401 });
    }

    let payload;
    try {
      payload = await request.json();
    } catch {
      return new Response('Bad JSON', { status: 400 });
    }

    const { url, method = 'GET', headers = {}, body } = payload;

    let target;
    try {
      target = new URL(url);
    } catch {
      return new Response('Bad URL', { status: 400 });
    }

    if (!ALLOWED_HOSTS.includes(target.hostname)) {
      return new Response('Host not allowed: ' + target.hostname, { status: 403 });
    }

    const fetchOptions = { method, headers };
    if (method !== 'GET' && method !== 'HEAD' && body !== undefined && body !== null) {
      fetchOptions.body = typeof body === 'string' ? body : JSON.stringify(body);
    }

    const resp = await fetch(target.toString(), fetchOptions);
    const text = await resp.text();
    return new Response(text, {
      status: resp.status,
      headers: { 'Content-Type': resp.headers.get('Content-Type') || 'text/plain; charset=utf-8' },
    });
  },
};
