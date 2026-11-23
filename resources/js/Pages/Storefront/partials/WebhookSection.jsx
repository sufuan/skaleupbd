// Goes in: resources/js/Pages/Storefront/partials/WebhookSection.jsx (new file).
// Extends Connect.jsx. In Connect.jsx add:
//   import WebhookSection from './partials/WebhookSection';
//   ...and render <WebhookSection t={t} /> below the API key area.
// If Connect.jsx already has a webhook URL field, remove it: this section replaces it and keeps
// api_keys.webhook_url in step on the server.

import { useCallback, useEffect, useRef, useState } from 'react';

const BASE = '/storefront/integrations/webhook';

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function call(method, path, body) {
    const res = await fetch(BASE + path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    let json = {};
    try {
        json = await res.json();
    } catch (e) {
        json = {};
    }
    return { ok: res.ok, status: res.status, json };
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString() : null;
}

export default function WebhookSection({ t }) {
    const [data, setData] = useState(null);
    const [url, setUrl] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [secret, setSecret] = useState(null);
    const [copied, setCopied] = useState(false);
    const poll = useRef(null);

    const load = useCallback(async () => {
        const r = await call('GET', '');
        if (r.ok) {
            setData(r.json);
            setUrl((cur) => cur || r.json.url || '');
        }
        return r;
    }, []);

    useEffect(() => {
        load();
        return () => clearInterval(poll.current);
    }, [load]);

    const topicName = (topic) =>
        ({
            'order.confirmed': t('Order confirmed'),
            'order.shipped': t('Order shipped'),
            'order.delivered': t('Order delivered'),
            'order.returned': t('Order returned'),
            'order.cancelled': t('Order cancelled'),
            'stock.updated': t('Stock changed'),
            'webhook.test': t('Test update'),
        })[topic] || topic;

    const resultText = (d) => {
        if (d.outcome === 'delivered') return t('Delivered');
        const code = d.http_status ? ' (' + d.http_status + ')' : '';
        if (d.next_retry_at) return t('Failed, will try again at') + ' ' + when(d.next_retry_at) + code;
        return (d.outcome === 'blocked' ? t('Blocked') : t('Failed')) + code;
    };

    const run = async (fn) => {
        setBusy(true);
        setMessage(null);
        const r = await fn();
        setBusy(false);
        if (!r.ok) {
            setMessage(r.json.message ? t(r.json.message) : t('Something went wrong. Please try again.'));
            return null;
        }
        if (r.json.webhook) setData(r.json.webhook);
        if (r.json.secret) {
            setSecret(r.json.secret);
            setCopied(false);
        }
        return r;
    };

    const save = () => run(() => call('PUT', '', { url }));

    const sendTest = async () => {
        const r = await run(() => call('POST', '/test'));
        if (!r) return;
        setMessage(t('Test sent. It can take up to one minute to arrive.'));
        let tries = 0;
        clearInterval(poll.current);
        poll.current = setInterval(async () => {
            tries += 1;
            await load();
            if (tries >= 15) clearInterval(poll.current);
        }, 4000);
    };

    const newSecret = () => {
        if (!window.confirm(t('Make a new secret? The old one stops working, so you must update your website right away.'))) return;
        run(() => call('POST', '/secret'));
    };

    const copySecret = async () => {
        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
        } catch (e) {
            setCopied(false);
        }
    };

    if (!data) return null;

    const configured = data.configured;
    const status = data.status;

    return (
        <div className="card" style={{ marginTop: 16 }}>
            <h3 style={{ margin: 0 }}>{t('Order updates to your website')}</h3>
            <p style={{ marginTop: 6 }}>
                {t('When an order changes here, we send a message to your website so it can show the new status and the tracking number.')}
            </p>

            {status === 'paused' && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, margin: '12px 0' }}>
                    <div style={{ fontWeight: 700, fontSize: 18 }}>{t('Paused: your points have run out')}</div>
                    <p style={{ margin: '6px 0 10px' }}>
                        {t('Your website keeps selling normally, but we have stopped sending it updates. They start again by themselves as soon as you top up.')}
                    </p>
                    {data.waiting > 0 && (
                        <p style={{ margin: '0 0 10px' }}>
                            {t('Updates waiting to be sent')}: <strong>{data.waiting}</strong>
                        </p>
                    )}
                    <a className="btn pri" href={data.top_up_url}>
                        {t('Top up points')}
                    </a>
                </div>
            )}

            {status === 'disabled' && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, margin: '12px 0' }}>
                    <div style={{ fontWeight: 700, fontSize: 18 }}>{t('Updates are turned off')}</div>
                    {data.last_error && <p style={{ margin: '6px 0 10px' }}>{t(data.last_error)}</p>}
                    <button className="btn pri" disabled={busy} onClick={() => run(() => call('POST', '/enable'))}>
                        {t('Turn updates on')}
                    </button>
                </div>
            )}

            {status === 'degraded' && data.last_error && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, margin: '12px 0' }}>
                    <div style={{ fontWeight: 700 }}>{t('Your website is not accepting some updates')}</div>
                    <p style={{ margin: '6px 0 0' }}>{t(data.last_error)}</p>
                </div>
            )}

            <label className="lbl" htmlFor="webhook-url">
                {t('Address on your website that receives updates')}
            </label>
            <input
                id="webhook-url"
                className="fld"
                type="url"
                value={url}
                placeholder="https://yourwebsite.com/skale/webhook"
                onChange={(e) => setUrl(e.target.value)}
            />
            <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                <button className="btn pri" disabled={busy || !url.trim()} onClick={save}>
                    {configured ? t('Save address') : t('Turn on updates')}
                </button>
                {configured && status !== 'disabled' && (
                    <>
                        <button className="btn" disabled={busy || status === 'paused'} onClick={sendTest}>
                            {t('Send a test update')}
                        </button>
                        <button className="btn" disabled={busy} onClick={newSecret}>
                            {t('Make a new secret')}
                        </button>
                        <button className="btn" disabled={busy} onClick={() => run(() => call('POST', '/disable'))}>
                            {t('Turn updates off')}
                        </button>
                    </>
                )}
            </div>

            {message && <p style={{ marginTop: 10 }}>{message}</p>}

            {secret && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, marginTop: 14 }}>
                    <div className="lbl">{t('Your signing secret')}</div>
                    <code style={{ display: 'block', wordBreak: 'break-all', fontSize: 15, margin: '6px 0 10px' }}>{secret}</code>
                    <p style={{ margin: '0 0 10px' }}>
                        {t('Copy it now and give it to your developer. For your safety we cannot show it again.')}
                    </p>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button className="btn pri" onClick={copySecret}>
                            {copied ? t('Copied') : t('Copy secret')}
                        </button>
                        <button className="btn" onClick={() => setSecret(null)}>
                            {t('I saved it')}
                        </button>
                    </div>
                </div>
            )}

            {configured && (
                <div style={{ marginTop: 16 }}>
                    <div className="lbl">{t('Last successful update')}</div>
                    <div style={{ fontSize: 20, fontWeight: 700 }}>{when(data.last_success_at) || t('None yet')}</div>
                    {data.secret_last4 && (
                        <div style={{ marginTop: 6 }}>
                            {t('Signing secret ends in')}: <strong>{data.secret_last4}</strong>
                        </div>
                    )}
                </div>
            )}

            {configured && data.deliveries.length > 0 && (
                <div className="tbl-wrap" style={{ marginTop: 16 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>{t('Time')}</th>
                                <th>{t('Update')}</th>
                                <th>{t('Result')}</th>
                                <th>{t('Try')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.deliveries.map((d) => (
                                <tr key={d.id}>
                                    <td>{when(d.at)}</td>
                                    <td>{topicName(d.topic)}</td>
                                    <td>
                                        {resultText(d)}
                                        {d.error && <div style={{ fontSize: 13 }}>{t(d.error)}</div>}
                                    </td>
                                    <td>{d.attempt}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {configured && (
                <details style={{ marginTop: 16 }}>
                    <summary>{t('For your developer')}</summary>
                    <p>{t('Every update is a POST with a JSON body. These headers come with it:')}</p>
                    <ul>
                        <li>X-Skale-Event-Id</li>
                        <li>X-Skale-Event</li>
                        <li>X-Skale-Timestamp</li>
                        <li>X-Skale-Signature</li>
                    </ul>
                    <p>
                        {t('The signature is the HMAC SHA-256 of the timestamp, a dot, and the raw body, using your signing secret. Reject updates older than 5 minutes. Answer with a 2xx code quickly and do the real work afterwards.')}
                    </p>
                </details>
            )}
        </div>
    );
}
