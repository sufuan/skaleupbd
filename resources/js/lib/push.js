// Browser Web Push helpers for the signed-in app: register the
// service worker, read the current state, subscribe / unsubscribe
// this browser against the /push endpoints. Used by the topbar
// control in AppLayout.

function csrfHeaders() {
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf[1]) : '',
    };
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function registerPushWorker() {
    if (!pushSupported()) return null;
    try {
        return await navigator.serviceWorker.register('/sw.js');
    } catch {
        return null;
    }
}

// 'unsupported' | 'off' (can ask) | 'on' (subscribed) | 'denied'
export async function pushState() {
    if (!pushSupported()) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    if (Notification.permission !== 'granted') return 'off';
    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        return sub ? 'on' : 'off';
    } catch {
        return 'off';
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

// Turns push on for this browser. Returns 'on' | 'denied' | 'off'
// (prompt dismissed). Every failure THROWS with a seller-readable
// message: the old version swallowed errors and returned 'off', so a
// blocked key fetch or a failed subscribe looked exactly like
// nothing happening at all (Round 2, item 25).
export async function enablePush() {
    const reg = (await registerPushWorker()) || (await navigator.serviceWorker.ready);
    const keyRes = await fetch('/push/public-key', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!keyRes.ok) throw new Error('Could not reach the notification service. Please try again.');
    const { key } = await keyRes.json();
    if (!key) throw new Error('Notifications are not set up on the server yet.');
    const permission = await Notification.requestPermission();
    if (permission === 'denied') return 'denied';
    if (permission !== 'granted') return 'off';
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(key),
        });
    }
    const res = await fetch('/push/subscribe', {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(sub.toJSON()),
    });
    if (!res.ok) throw new Error('Could not save your notification setting. Please try again.');
    return 'on';
}

export async function disablePush() {
    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            await fetch('/push/unsubscribe', {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ endpoint: sub.endpoint }),
            });
            await sub.unsubscribe();
        }
    } catch { /* already off */ }
    return 'off';
}
