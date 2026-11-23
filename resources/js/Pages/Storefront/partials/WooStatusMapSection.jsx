// Goes in: resources/js/Pages/Storefront/partials/WooStatusMapSection.jsx (new file).
//
// Lets the seller view and edit the WooCommerce status push map (our status -> WC status).
// Rendered inside the WooCommerce connection area of the Connect page, only when active.
// Also shows the inbound status map (WC status -> our status) for reference.
//
// Import and render inside WooConnectSection.jsx when status === 'active':
//   import WooStatusMapSection from './WooStatusMapSection';
//   <WooStatusMapSection t={t} />

import { Fragment, useCallback, useEffect, useState } from 'react';

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

// Our statuses that can be pushed back to WooCommerce.
// "Processing" is not shown because we do not echo it back (we received it from WC).
const PUSH_STATUSES = ['Shipped', 'Delivered', 'Returned', 'Cancelled'];
const WC_STATUS_OPTIONS = ['', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];

export default function WooStatusMapSection({ t }) {
    const [pushMap, setPushMap] = useState(null);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [message, setMessage] = useState(null);

    const load = useCallback(async () => {
        const r = await call('GET', '/status-map');
        if (r.ok) setPushMap(r.json.pushMap ?? {});
    }, []);

    useEffect(() => { load(); }, [load]);

    const setEntry = (ourStatus, wooStatus) => {
        setPushMap((m) => ({ ...m, [ourStatus]: wooStatus || null }));
        setSaved(false);
    };

    const save = async () => {
        setBusy(true);
        setMessage(null);
        const r = await call('PUT', '/status-map', { pushMap });
        setBusy(false);
        if (r.ok) {
            setSaved(true);
            setMessage(t('Status map saved.'));
        } else {
            setMessage(r.json.message || t('Something went wrong. Please try again.'));
        }
    };

    if (!pushMap) return null;

    return (
        <details style={{ marginTop: 16 }}>
            <summary style={{ cursor: 'pointer', fontWeight: 600 }}>
                {t('Status updates to WooCommerce')}
            </summary>

            <p style={{ margin: '10px 0 6px' }}>
                {t('When an order changes here, we update the status on your WooCommerce store. Choose what WooCommerce status to set for each change.')}
            </p>
            <p style={{ margin: '0 0 12px', fontSize: 13, color: '#555' }}>
                {t('Leave blank to send no update for that status.')}
            </p>

            <div style={{ display: 'grid', gridTemplateColumns: 'auto auto', gap: '8px 16px', alignItems: 'center', maxWidth: 400 }}>
                {PUSH_STATUSES.map((ourStatus) => (
                    <Fragment key={ourStatus}>
                        <label className="lbl" style={{ margin: 0 }}>
                            {t(ourStatus)}
                        </label>
                        <select
                            className="fld"
                            style={{ padding: '4px 8px' }}
                            value={pushMap[ourStatus] ?? ''}
                            onChange={(e) => setEntry(ourStatus, e.target.value)}
                        >
                            {WC_STATUS_OPTIONS.map((opt) => (
                                <option key={opt} value={opt}>
                                    {opt === '' ? t('(no update)') : opt}
                                </option>
                            ))}
                        </select>
                    </Fragment>
                ))}
            </div>

            <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
                <button className="btn pri" disabled={busy} onClick={save}>
                    {t('Save')}
                </button>
                {saved && !message && <span style={{ fontSize: 13 }}>{t('Saved')}</span>}
                {message && <span style={{ fontSize: 13 }}>{message}</span>}
            </div>
        </details>
    );
}
