// Goes in: resources/js/Pages/Storefront/partials/WooConnectSection.jsx (new file).
// Extends Connect.jsx. Import and render it inside the platform chooser on the Connect page.
// In Connect.jsx, add:
//   import WooConnectSection from './partials/WooConnectSection';
//   ...render <WooConnectSection t={t} /> in the WooCommerce branch of the platform selector.
//
// UX flow:
//   Not configured → show 3-field form (URL, consumer key, consumer secret) + link to WC docs
//   Pending → spinner + "Testing connection..." poll
//   Failed  → error message, plain fix text, try again button
//   Active  → health card (last event, last success, pending events count)
//   Paused  → paused banner + top-up button
//   Disabled → reconnect prompt
//
// No "Done / To do" checklist. Only what needs action is shown.

import { useCallback, useEffect, useRef, useState } from 'react';

const BASE = '/storefront/integrations/woo';

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
    try { json = await res.json(); } catch (e) { json = {}; }
    return { ok: res.ok, status: res.status, json };
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString() : null;
}

export default function WooConnectSection({ t }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState({ site_url: '', consumer_key: '', consumer_secret: '' });
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [events, setEvents] = useState(null);
    const [hasPoints, setHasPoints] = useState(null);
    const poll = useRef(null);

    const load = useCallback(async () => {
        const r = await call('GET', '');
        if (r.ok) setData(r.json);
        return r;
    }, []);

    const loadEvents = useCallback(async () => {
        const r = await call('GET', '/events');
        if (r.ok) {
            setEvents(r.json.events ?? []);
            setHasPoints(r.json.has_points ?? null);
        }
    }, []);

    useEffect(() => {
        load();
        // The event list loads on its own (Round 2 finding 25): a
        // seller checking a stalled import must see whether the event
        // arrived without having to discover a Load button first.
        loadEvents();
        return () => clearInterval(poll.current);
    }, [load, loadEvents]);

    const run = async (fn) => {
        setBusy(true);
        setMessage(null);
        const r = await fn();
        setBusy(false);
        if (!r.ok) {
            setMessage(r.json.message ? t(r.json.message) : t('Something went wrong. Please try again.'));
            return null;
        }
        if (r.json.connection) setData(r.json.connection);
        return r;
    };

    const connect = async () => {
        if (!form.site_url || !form.consumer_key || !form.consumer_secret) return;
        const r = await run(() => call('POST', '', form));
        if (!r) return;
        // Poll while pending
        clearInterval(poll.current);
        poll.current = setInterval(async () => {
            const p = await load();
            if (p.ok && (p.json.connection?.status ?? p.json.status) !== 'pending') {
                clearInterval(poll.current);
            }
        }, 3000);
    };

    const disconnect = async () => {
        if (!window.confirm(t('Remove the WooCommerce connection? We will delete the webhooks on your store.'))) return;
        await run(() => call('DELETE', ''));
    };

    const topicLabel = (topic) => ({
        'order.created': t('Order created'),
        'order.updated': t('Order updated'),
        'product.created': t('Product created'),
        'product.updated': t('Product updated'),
    })[topic] || topic;

    const statusLabel = (status) => ({
        'processed': t('Imported'),
        'received':  t('Waiting'),
        'failed':    t('Failed'),
        'skipped':   t('Skipped'),
    })[status] || status;

    if (!data) return null;

    const cfg   = data.configured;
    const status = data.status;

    return (
        <div className="card" style={{ marginTop: 16 }}>
            <h3 style={{ margin: 0 }}>{t('WooCommerce')}</h3>
            <p style={{ marginTop: 6 }}>
                {t('Connect your WooCommerce store. We receive your orders automatically and keep stock in step.')}
            </p>

            {/* Paused banner */}
            {status === 'paused' && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, margin: '12px 0' }}>
                    <div style={{ fontWeight: 700, fontSize: 18 }}>{t('Paused: your points have run out')}</div>
                    <p style={{ margin: '6px 0 10px' }}>
                        {t('Orders from your store are not being imported. They will be recovered automatically when you top up.')}
                    </p>
                    <a className="btn pri" href={data.top_up_url}>{t('Top up points')}</a>
                </div>
            )}

            {/* Failed banner */}
            {status === 'failed' && data.last_error && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, margin: '12px 0' }}>
                    <div style={{ fontWeight: 700 }}>{t('Connection failed')}</div>
                    <p style={{ margin: '6px 0 10px' }}>{t(data.last_error)}</p>
                </div>
            )}

            {/* Pending: testing */}
            {status === 'pending' && (
                <div style={{ padding: '14px 0', fontStyle: 'italic' }}>
                    {t('Testing connection to your store. This can take up to one minute.')}
                </div>
            )}

            {/* Connected health card */}
            {cfg && status === 'active' && (
                <div style={{ marginTop: 12 }}>
                    <div style={{ display: 'flex', gap: 32, flexWrap: 'wrap' }}>
                        <div>
                            <div className="lbl">{t('Store')}</div>
                            <div style={{ fontWeight: 700 }}>{data.site_url}</div>
                        </div>
                        <div>
                            <div className="lbl">{t('Last order received')}</div>
                            <div style={{ fontWeight: 700, fontSize: 20 }}>{when(data.last_event_at) || t('None yet')}</div>
                        </div>
                        <div>
                            <div className="lbl">{t('Last success')}</div>
                            <div>{when(data.last_success_at) || t('None yet')}</div>
                        </div>
                        {data.pending_in > 0 && (
                            <div>
                                <div className="lbl">{t('Orders waiting to import')}</div>
                                <div style={{ fontWeight: 700, fontSize: 20 }}>{data.pending_in}</div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Connect form: not configured OR failed (allow re-submit) */}
            {(!cfg || status === 'failed' || status === 'disabled') && (
                <div style={{ marginTop: 16 }}>
                    <div style={{ marginBottom: 10 }}>
                        <label className="lbl" htmlFor="woo-url">{t('Your WooCommerce store address')}</label>
                        <input
                            id="woo-url"
                            className="fld"
                            type="url"
                            placeholder="https://yourstore.com"
                            value={form.site_url}
                            onChange={(e) => setForm((f) => ({ ...f, site_url: e.target.value }))}
                        />
                    </div>
                    <div style={{ marginBottom: 10 }}>
                        <label className="lbl" htmlFor="woo-ck">{t('Consumer key')}</label>
                        <input
                            id="woo-ck"
                            className="fld"
                            type="text"
                            placeholder="ck_..."
                            value={form.consumer_key}
                            onChange={(e) => setForm((f) => ({ ...f, consumer_key: e.target.value }))}
                            autoComplete="off"
                        />
                    </div>
                    <div style={{ marginBottom: 10 }}>
                        <label className="lbl" htmlFor="woo-cs">{t('Consumer secret')}</label>
                        <input
                            id="woo-cs"
                            className="fld"
                            type="password"
                            placeholder="cs_..."
                            value={form.consumer_secret}
                            onChange={(e) => setForm((f) => ({ ...f, consumer_secret: e.target.value }))}
                            autoComplete="new-password"
                        />
                    </div>
                    <p style={{ fontSize: 13, margin: '0 0 12px' }}>
                        {t('Create a key in WooCommerce: Settings > Advanced > REST API. Set Read/Write access.')}
                    </p>
                    <button
                        className="btn pri"
                        disabled={busy || !form.site_url || !form.consumer_key || !form.consumer_secret}
                        onClick={connect}
                    >
                        {t('Connect store')}
                    </button>
                </div>
            )}

            {message && <p style={{ marginTop: 10 }}>{message}</p>}

            {/* Disconnect button (only when active or degraded) */}
            {cfg && (status === 'active' || status === 'degraded') && (
                <div style={{ marginTop: 16 }}>
                    <button className="btn" disabled={busy} onClick={disconnect}>
                        {t('Remove connection')}
                    </button>
                </div>
            )}

            {/* Event inbox */}
            {cfg && (
                <div style={{ marginTop: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <div style={{ fontWeight: 600 }}>{t('Recent events from your store')}</div>
                        <button className="btn" style={{ padding: '2px 10px', fontSize: 13 }} onClick={() => { loadEvents(); }}>
                            {t('Refresh')}
                        </button>
                    </div>

                    {events === null && (
                        <button className="btn" style={{ marginTop: 8 }} onClick={loadEvents}>
                            {t('Load events')}
                        </button>
                    )}

                    {events !== null && events.length === 0 && (
                        <p style={{ marginTop: 8, fontStyle: 'italic' }}>{t('No events yet.')}</p>
                    )}

                    {events !== null && events.length > 0 && (
                        <div className="tbl-wrap" style={{ marginTop: 10 }}>
                            <table className="tbl">
                                <thead>
                                    <tr>
                                        <th>{t('Time')}</th>
                                        <th>{t('Event')}</th>
                                        <th>{t('Status')}</th>
                                        <th>{t('Note')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {events.map((e) => (
                                        <tr key={e.id}>
                                            <td>{when(e.at)}</td>
                                            <td>{topicLabel(e.topic)}</td>
                                            <td>{statusLabel(e.status)}</td>
                                            <td style={{ fontSize: 13 }}>
                                                {e.error && e.error !== 'paused_no_points' ? t(e.error) : ''}
                                                {e.error === 'paused_no_points' && hasPoints ? t('Waiting to be processed') : ''}
                                                {e.error === 'paused_no_points' && !hasPoints ? t('Waiting for points top-up') : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
