import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { StatusPill, SuccessCell, fmt } from './ui';

const TABS = ['All', 'Pending', 'Processing', 'Shipped', 'Delivered', 'Returned', 'Cancelled', 'Incomplete', 'Pending Return', 'Pending Cancel', 'Lost'];

const I = {
    search: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.3-4.3" /></svg>,
    call: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" /></svg>,
    wa: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" /></svg>,
    copy: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>,
    dl: <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>,
    open: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><path d="M15 3h6v6" /><path d="M10 14L21 3" /></svg>,
    plus: <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 5v14" /><path d="M5 12h14" /></svg>,
};

export default function Index({ rows, counts, filters, pagination, pendingCount, filterOptions }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilter, setShowFilter] = useState(false);
    const [adv, setAdv] = useState({ from: filters.from || '', to: filters.to || '', source: filters.source || '', processed_by: filters.processed_by || '', product_code: filters.product_code || '', tag: filters.tag || '' });
    const advCount = Object.values(adv).filter((v) => v !== '').length;
    const base = { status: filters.status, search: filters.search, per_page: filters.per_page, from: filters.from || '', to: filters.to || '', source: filters.source || '', processed_by: filters.processed_by || '', product_code: filters.product_code || '', tag: filters.tag || '' };
    const go = (patch) => router.get('/orders', { ...base, ...patch }, { preserveState: true, replace: true });
    const exportHref = '/orders/export?' + new URLSearchParams(Object.fromEntries(Object.entries({ ...base, search }).filter(([, v]) => v !== '' && v !== undefined))).toString();
    const timer = useRef(null);
    const onSearch = (v) => {
        setSearch(v);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => go({ search: v, page: 1 }), 500);
    };
    return (
        <AppLayout>
            <Head title={t('Orders')} />
            <div className="ord-list">
                <div className="page-head">
                    <div>
                        <h1>{t('Orders')}</h1>
                        <p>{t('All web orders in one list - confirm, process and hand them to delivery.')}</p>
                    </div>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                        <span className="wol-search">{I.search}
                            <input value={search} placeholder={t('Search invoice, phone or name')} onChange={(e) => onSearch(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter') { clearTimeout(timer.current); go({ search: e.target.value, page: 1 }); } }} />
                        </span>
                        <button className="open-next" disabled={!pendingCount} onClick={() => pendingCount && router.get(`/orders/next?from=${filters.status}`)}>
                            {t('Open Next Order')} ({pendingCount})
                        </button>
                    </span>
                </div>

                <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start', flexWrap: 'wrap', marginBottom: 20 }}>
                    <div className="view-tabs" style={{ marginBottom: 0 }}>
                        {TABS.map((s) => (
                            <button key={s} className={filters.status === s ? 'active' : ''} onClick={() => go({ status: s, page: 1 })}>
                                {t(s)} <span className="cnt">{counts[s] ?? 0}</span>
                            </button>
                        ))}
                    </div>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                        <button className="btn" onClick={() => setShowFilter(true)}>{t('Filter Orders')}{advCount > 0 ? ` (${advCount})` : ''}</button>
                        <a className="btn" href={exportHref}>{t('Export CSV')}</a>
                    </span>
                </div>

                <div className="list-card">
                    <div className="tbl-wrap">
                        <table className="tbl ord-tbl">
                            <colgroup>
                                <col style={{ width: 114 }} /><col style={{ width: 52 }} /><col style={{ width: 176 }} />
                                <col style={{ width: 88 }} /><col style={{ width: 112 }} /><col style={{ width: 74 }} /><col style={{ width: 150 }} />
                                <col style={{ width: 94 }} /><col style={{ width: 110 }} /><col style={{ width: 92 }} /><col style={{ width: 128 }} />
                            </colgroup>
                            <thead><tr>
                                <th>{t('Invoice')}</th><th>{t('Auto Call')}</th><th>{t('Customer')}</th><th>{t('Order Items')}</th>
                                <th>{t('Success Rate')}</th><th>{t('Tags')}</th><th>{t('Site')}</th><th>{t('Status')}</th>
                                <th>{t('PBX Calls')}</th><th>{t('Processed By')}</th><th>{t('Actions')}</th>
                            </tr></thead>
                            <tbody>
                                {rows.map((o) => (
                                    <tr key={o.id}>
                                        <td><b style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{o.invoice}</b><br />
                                            <span style={{ fontSize: 12, color: '#64748B', whiteSpace: 'nowrap' }}>{o.created.replace(/ \d{4}$/, '')}, {o.created_time.replace(/^0/, '').toLowerCase()}</span>
                                            <span className="ord-m-total">{fmt(o.total)}</span></td>
                                        <td>{o.auto_call && o.auto_call !== 'off'
                                            ? <span className={`pill ${o.auto_call === 'answered' ? 'green' : 'amber'}`}>{t(o.auto_call === 'answered' ? 'Answered' : 'No Answer')}</span>
                                            : <span style={{ color: '#64748B' }}>-</span>}</td>
                                        <td>
                                            <b>{o.phone}</b>
                                            <span className="icon-btns">
                                                <a className="mini-btn" title={t('Call customer')} href={`tel:${o.phone}`}>{I.call}</a>
                                                <a className="mini-btn" title={t('Message customer on WhatsApp')} href={`https://wa.me/${o.phone.replace(/\D/g, '')}`} target="_blank" rel="noreferrer">{I.wa}</a>
                                                <button className="mini-btn" title={t('Copy customer details')} onClick={() => navigator.clipboard?.writeText(`${o.name} · ${o.phone} · ${o.address}`)}>{I.copy}</button>
                                            </span><br />
                                            {o.name}<br />
                                            <span className="addr" style={{ fontSize: 12, color: '#64748B' }}>{o.address}</span>
                                        </td>
                                        <td>{(o.items || []).slice(0, 3).map((it, i) => (
                                            <span key={i} style={{ display: 'flex', gap: 6, alignItems: 'center', marginBottom: 4 }}>
                                                <span className="thumb" /><span className="iname" style={{ minWidth: 0 }}>{it.name}</span><span style={{ flexShrink: 0, whiteSpace: 'nowrap', fontSize: 12.5 }}>{it.qty}x {fmt(it.price)}</span>
                                            </span>
                                        ))}{(o.items || []).length > 3 && <span style={{ fontSize: 12, color: '#64748B' }}>+{o.items.length - 3} {t('more')}</span>}</td>
                                        <td><SuccessCell fraud={o.fraud} /></td>
                                        <td>{(o.tags || []).length > 0
                                            ? <span style={{ display: 'inline-flex', gap: 4, flexWrap: 'wrap', alignItems: 'center' }}>
                                                {o.tags.map((tg) => <span key={tg} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, background: '#FCE7F3', color: '#BE185D', borderRadius: 999, padding: '3px 9px', fontSize: 12, fontWeight: 600 }}>{tg}</span>)}
                                                <Link className="mini-btn" title={t('Add Tag')} href={`/orders/${o.id}`}>{I.plus}</Link>
                                              </span>
                                            : <Link className="mini-btn" title={t('Add Tag')} href={`/orders/${o.id}`}>{I.plus}</Link>}</td>
                                        <td><span className="site-cell">{o.site_label || o.site || o.source}</span></td>
                                        <td><StatusPill status={o.status} />{o.is_preorder && <div className="sm mut">{t('Preorder')}</div>}</td>
                                        <td>{o.pbx_calls > 0 ? o.pbx_calls : <span style={{ color: '#64748B' }}>-</span>}</td>
                                        <td>{o.processed_by || <span style={{ color: '#64748B' }}>-</span>}</td>
                                        <td><span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
                                            <a className="mini-btn" title={t('Download Invoice')} href={`/orders/${o.id}/invoice`}>{I.dl}</a>
                                            <Link className="open-btn" href={`/orders/${o.id}`}>{t('Open')} {I.open}</Link>
                                        </span></td>
                                    </tr>
                                ))}
                                {rows.length === 0 && <tr><td colSpan={11} className="mut" style={{ textAlign: 'center', padding: 26 }}>{t('No orders here')} - {t('try another tab or search.')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '12px 6px 4px', flexWrap: 'wrap' }}>
                        <span style={{ fontSize: 13.5 }}>{t('Page')} {pagination.current}</span>
                        <span style={{ fontSize: 13.5, color: 'var(--tgl-ink)' }}>{t('Rows per page')}
                            <select value={filters.per_page} onChange={(e) => go({ per_page: e.target.value, page: 1 })}
                                style={{ marginLeft: 8, height: 30, border: '1px solid var(--track-line)', borderRadius: 6, background: 'var(--field-bg)', color: 'var(--ink)', fontFamily: 'inherit' }}>
                                {[20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </span>
                        <span style={{ marginLeft: 'auto', display: 'inline-flex', gap: 14, alignItems: 'center', fontSize: 13.5 }}>
                            <b>{t('Page')} {pagination.current} {t('of')} {pagination.last}</b>
                            <button className="mini-btn" style={{ width: 'auto', height: 'auto', border: 'none', background: 'transparent', fontSize: 13.5, color: pagination.current <= 1 ? 'var(--faint-ink)' : 'var(--pg-ink)', fontWeight: 500 }}
                                disabled={pagination.current <= 1} onClick={() => go({ page: pagination.current - 1 })}><span aria-hidden="true">‹ </span>{t('Previous')}</button>
                            <button className="mini-btn" style={{ width: 'auto', height: 'auto', border: 'none', background: 'transparent', fontSize: 13.5, color: pagination.current >= pagination.last ? 'var(--faint-ink)' : 'var(--pg-ink)', fontWeight: 500 }}
                                disabled={pagination.current >= pagination.last} onClick={() => go({ page: pagination.current + 1 })}>{t('Next')}<span aria-hidden="true"> ›</span></button>
                        </span>
                    </div>
                </div>
            </div>

            {showFilter && (
                <div className="overlay" onClick={() => setShowFilter(false)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <h3>{t('Filter Orders')}</h3>
                        <div className="frow mt16"><label className="fl">{t('Date From')}</label>
                            <input className="inp" type="date" value={adv.from} onChange={(e) => setAdv({ ...adv, from: e.target.value })} /></div>
                        <div className="frow"><label className="fl">{t('Date To')}</label>
                            <input className="inp" type="date" value={adv.to} onChange={(e) => setAdv({ ...adv, to: e.target.value })} /></div>
                        <div className="frow"><label className="fl">{t('Source')}</label>
                            <select className="inp" value={adv.source} onChange={(e) => setAdv({ ...adv, source: e.target.value })}>
                                <option value="">-</option>
                                {(filterOptions?.sources || []).map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
                            </select></div>
                        <div className="frow"><label className="fl">{t('Processed By')}</label>
                            <select className="inp" value={adv.processed_by} onChange={(e) => setAdv({ ...adv, processed_by: e.target.value })}>
                                <option value="">-</option>
                                {(filterOptions?.staff || []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select></div>
                        <div className="frow"><label className="fl">{t('Product Code')}</label>
                            <input className="inp" value={adv.product_code} onChange={(e) => setAdv({ ...adv, product_code: e.target.value })} placeholder="SKU…" /></div>
                        <div className="frow"><label className="fl">{t('Tag')}</label>
                            <input className="inp" value={adv.tag} onChange={(e) => setAdv({ ...adv, tag: e.target.value })} /></div>
                        <div className="flex mt16" style={{ justifyContent: 'flex-end' }}>
                            <button className="btn" onClick={() => { const cleared = { from: '', to: '', source: '', processed_by: '', product_code: '', tag: '' }; setAdv(cleared); setShowFilter(false); go({ ...cleared, page: 1 }); }}>{t('Clear Filters')}</button>
                            <button className="btn" onClick={() => setShowFilter(false)}>{t('Cancel')}</button>
                            <button className="btn pri" onClick={() => { setShowFilter(false); go({ ...adv, page: 1 }); }}>{t('Apply Filter')}</button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
