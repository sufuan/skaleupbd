// Goes in: resources/js/Pages/Storefront/partials/ApiKeySection.jsx (new file).
// Extends Connect.jsx. Import and render below the existing key area and above WebhookSection:
//   import ApiKeySection from './partials/ApiKeySection';
//   ...render <ApiKeySection t={t} />
//
// Displays all active keys (last4, scopes, last used), lets the seller create a new scoped key,
// and revoke existing ones. The full raw key is shown ONCE after creation.

import { useCallback, useEffect, useState } from 'react';

const BASE = '/storefront/integrations/keys';

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

const SCOPE_LABELS = {
    'orders.read':    'Read orders',
    'orders.write':   'Create orders',
    'products.write': 'Push products',
    'products.read':  'Read products',
    'stock.read':     'Check stock',
};

const ALL_SCOPES = Object.keys(SCOPE_LABELS);

function when(iso) {
    return iso ? new Date(iso).toLocaleString() : null;
}

export default function ApiKeySection({ t }) {
    const [keys, setKeys] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [newKey, setNewKey] = useState(null);
    const [newKeyCopied, setNewKeyCopied] = useState(false);
    const [creating, setCreating] = useState(false);
    const [selectedScopes, setSelectedScopes] = useState(['orders.read', 'orders.write', 'products.write', 'stock.read']);

    const load = useCallback(async () => {
        const r = await call('GET', '/');
        if (r.ok) setKeys(r.json.keys ?? []);
    }, []);

    useEffect(() => { load(); }, [load]);

    const run = async (fn) => {
        setBusy(true);
        setMessage(null);
        const r = await fn();
        setBusy(false);
        if (!r.ok) {
            setMessage(r.json.message ? t(r.json.message) : t('Something went wrong. Please try again.'));
            return null;
        }
        return r;
    };

    const toggleScope = (scope) => {
        setSelectedScopes((cur) =>
            cur.includes(scope) ? cur.filter((s) => s !== scope) : [...cur, scope]
        );
    };

    const create = async () => {
        if (selectedScopes.length === 0) return;
        const r = await run(() => call('POST', '/', { scopes: selectedScopes }));
        if (!r) return;
        setNewKey(r.json.key);
        setNewKeyCopied(false);
        setCreating(false);
        await load();
    };

    const revoke = async (id) => {
        if (!window.confirm(t('Revoke this key? Any site using it will lose access immediately.'))) return;
        const r = await run(() => call('DELETE', '/' + id));
        if (r) await load();
    };

    const copyKey = async () => {
        try {
            await navigator.clipboard.writeText(newKey);
            setNewKeyCopied(true);
        } catch (e) {
            setNewKeyCopied(false);
        }
    };

    if (keys === null) return null;

    return (
        <div className="card" style={{ marginTop: 16 }}>
            <h3 style={{ margin: 0 }}>{t('API keys')}</h3>
            <p style={{ marginTop: 6 }}>
                {t('Each key lets your website send orders and check stock. Give each integration its own key so you can revoke just that one.')}
            </p>

            {newKey && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, marginBottom: 16 }}>
                    <div className="lbl">{t('Your new key')}</div>
                    <code style={{ display: 'block', wordBreak: 'break-all', fontSize: 15, margin: '6px 0 10px' }}>{newKey}</code>
                    <p style={{ margin: '0 0 10px' }}>
                        {t('Copy it now and save it somewhere safe. We cannot show it again.')}
                    </p>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button className="btn pri" onClick={copyKey}>
                            {newKeyCopied ? t('Copied') : t('Copy key')}
                        </button>
                        <button className="btn" onClick={() => setNewKey(null)}>
                            {t('I saved it')}
                        </button>
                    </div>
                </div>
            )}

            {message && <p style={{ marginTop: 10 }}>{message}</p>}

            {keys.length > 0 && (
                <div className="tbl-wrap" style={{ marginTop: 12 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>{t('Key ends in')}</th>
                                <th>{t('Permissions')}</th>
                                <th>{t('Last used')}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {keys.map((k) => (
                                <tr key={k.id}>
                                    <td><code>...{k.last4}</code></td>
                                    <td>
                                        {(k.scopes ?? ['*']).includes('*')
                                            ? t('Full access')
                                            : (k.scopes ?? []).map((s) => t(SCOPE_LABELS[s] ?? s)).join(', ')}
                                    </td>
                                    <td>{when(k.lastUsedAt) || t('Never')}</td>
                                    <td>
                                        <button
                                            className="btn"
                                            disabled={busy}
                                            onClick={() => revoke(k.id)}
                                        >
                                            {t('Revoke')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!creating && (
                <button
                    className="btn pri"
                    style={{ marginTop: 12 }}
                    disabled={busy}
                    onClick={() => setCreating(true)}
                >
                    {t('Create a new key')}
                </button>
            )}

            {creating && (
                <div style={{ border: '1px solid #411550', borderRadius: 8, padding: 14, marginTop: 12 }}>
                    <div className="lbl" style={{ marginBottom: 10 }}>{t('Choose what this key can do')}</div>
                    {ALL_SCOPES.map((scope) => (
                        <label key={scope} style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8, cursor: 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={selectedScopes.includes(scope)}
                                onChange={() => toggleScope(scope)}
                            />
                            {t(SCOPE_LABELS[scope])}
                        </label>
                    ))}
                    <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                        <button
                            className="btn pri"
                            disabled={busy || selectedScopes.length === 0}
                            onClick={create}
                        >
                            {t('Create key')}
                        </button>
                        <button className="btn" disabled={busy} onClick={() => setCreating(false)}>
                            {t('Cancel')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
