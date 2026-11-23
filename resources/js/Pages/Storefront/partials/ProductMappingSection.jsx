// Goes in: resources/js/Pages/Storefront/partials/ProductMappingSection.jsx (new file).
//
// Renders on the Connect page (or as its own sub-page).
// Shows unmatched external products and lets the seller link each to a local product.
// Also shows a summary (matched / unmatched counts) and an "Auto-match by SKU" button.
//
// Import and render in Connect.jsx:
//   import ProductMappingSection from './partials/ProductMappingSection';
//   <ProductMappingSection t={t} />
//
// Only shown when there is at least one unmatched product — nothing rendered when everything is matched.

import { useCallback, useEffect, useState } from 'react';

const BASE = '/storefront/integrations/products';

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

// Minimal debounce for the local product search input.
function useDebounce(value, ms) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = setTimeout(() => setDebounced(value), ms);
        return () => clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

function ProductPicker({ extId, t, onMatch }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [busy, setBusy] = useState(false);
    const debouncedQ = useDebounce(q, 300);

    useEffect(() => {
        if (debouncedQ.trim() === '') { setResults([]); return; }
        call('GET', '/local?q=' + encodeURIComponent(debouncedQ)).then((r) => {
            if (r.ok) setResults(r.json.products ?? []);
        });
    }, [debouncedQ]);

    const save = async (localId) => {
        setBusy(true);
        await call('POST', '/match', { externalProductId: extId, localProductId: localId });
        setBusy(false);
        onMatch();
    };

    return (
        <div style={{ marginTop: 6 }}>
            <input
                className="fld"
                type="text"
                placeholder={t('Search by name or SKU')}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                style={{ maxWidth: 300 }}
            />
            {results.length > 0 && (
                <div style={{ border: '1px solid #ccc', borderRadius: 4, maxWidth: 300, background: '#fff', marginTop: 2 }}>
                    {results.map((p) => (
                        <div
                            key={p.id}
                            style={{ padding: '6px 10px', cursor: 'pointer', borderBottom: '1px solid #eee' }}
                            onClick={() => !busy && save(p.id)}
                        >
                            <strong>{p.name}</strong>
                            {p.sku && <span style={{ marginLeft: 8, fontSize: 12, color: '#666' }}>SKU: {p.sku}</span>}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function ProductMappingSection({ t }) {
    const [summary, setSummary] = useState(null);
    const [items, setItems] = useState([]);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [openPicker, setOpenPicker] = useState(null); // id of item with open picker

    const loadSummary = useCallback(async () => {
        const r = await call('GET', '/summary');
        if (r.ok) setSummary(r.json);
    }, []);

    const loadItems = useCallback(async (p = 1) => {
        const r = await call('GET', '/unmatched?page=' + p);
        if (r.ok) {
            setItems(r.json.items ?? []);
            setTotal(r.json.total ?? 0);
            setPage(p);
        }
    }, []);

    useEffect(() => {
        loadSummary();
        loadItems(1);
    }, [loadSummary, loadItems]);

    const afterMatch = () => {
        setOpenPicker(null);
        loadSummary();
        loadItems(page);
    };

    const autoMatch = async () => {
        setBusy(true);
        setMessage(null);
        const r = await call('POST', '/auto-match');
        setBusy(false);
        if (r.ok) {
            setMessage(t('Auto-match done.') + ' ' + r.json.matched + ' ' + t('newly matched.') + ' ' + r.json.stillUnmatched + ' ' + t('still need matching.'));
            loadSummary();
            loadItems(1);
        }
    };

    // Only render the section when there are unmatched products.
    if (!summary) return null;
    if (summary.unmatched === 0) return null;

    const perPage = 50;
    const totalPages = Math.ceil(total / perPage);

    return (
        <div className="card" style={{ marginTop: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
                <h3 style={{ margin: 0 }}>{t('Products that need matching')}</h3>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <span style={{ fontSize: 14 }}>
                        <strong>{summary.matched}</strong> {t('matched')},&nbsp;
                        <strong style={{ color: '#411550' }}>{summary.unmatched}</strong> {t('unmatched')}
                    </span>
                    <button className="btn" disabled={busy} onClick={autoMatch}>
                        {t('Auto-match by SKU')}
                    </button>
                </div>
            </div>

            <p style={{ marginTop: 6 }}>
                {t('These products from your website have not been linked to a product in your dashboard. Orders still import normally. Linking is only needed for stock sync.')}
            </p>

            {message && <p style={{ marginTop: 8 }}>{message}</p>}

            <div className="tbl-wrap" style={{ marginTop: 12 }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>{t('From your website')}</th>
                            <th>{t('SKU')}</th>
                            <th>{t('Match to')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={item.id}>
                                <td>
                                    <div>{item.externalId}</div>
                                    {item.variationId && (
                                        <div style={{ fontSize: 12, color: '#666' }}>
                                            {t('Variation')}: {item.variationId}
                                        </div>
                                    )}
                                </td>
                                <td>{item.sku || <span style={{ color: '#aaa' }}>{t('No SKU')}</span>}</td>
                                <td>
                                    {openPicker === item.id ? (
                                        <div>
                                            <ProductPicker extId={item.id} t={t} onMatch={afterMatch} />
                                            <button
                                                className="btn"
                                                style={{ marginTop: 6, fontSize: 12 }}
                                                onClick={() => setOpenPicker(null)}
                                            >
                                                {t('Cancel')}
                                            </button>
                                        </div>
                                    ) : (
                                        <button
                                            className="btn pri"
                                            style={{ padding: '3px 10px', fontSize: 13 }}
                                            onClick={() => setOpenPicker(item.id)}
                                        >
                                            {t('Choose product')}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {totalPages > 1 && (
                <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                    <button className="btn" disabled={page <= 1} onClick={() => loadItems(page - 1)}>
                        {t('Previous')}
                    </button>
                    <span style={{ lineHeight: '32px' }}>{page} / {totalPages}</span>
                    <button className="btn" disabled={page >= totalPages} onClick={() => loadItems(page + 1)}>
                        {t('Next')}
                    </button>
                </div>
            )}
        </div>
    );
}
