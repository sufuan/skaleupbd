import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { fmt } from './ui';

export default function Dashboard({ filters, hasFilter, result, sources, statuses }) {
    const [f, setF] = useState({ source: '', status: '', from: '', to: '', search: '', district: '', taken_by: '', ...filters });
    const apply = () => router.get('/orders/dashboard', f, { preserveState: true });
    const field = (label, el) => <div className="frow"><label className="fl">{t(label)}</label>{el}</div>;

    return (
        <AppLayout>
            <Head title={t('Orders Dashboard')} />
            <h1 className="page-h">{t('Orders Dashboard')}</h1>
            <div className="page-sub">{t('Filter first — results appear after you choose a filter.')}</div>

            <div className="card mb12">
                <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(190px,1fr))' }}>
                    {field('Source', <select className="inp" value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}><option value="">—</option>{sources.map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}</select>)}
                    {field('Status', <select className="inp" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}><option value="">—</option>{statuses.map((s) => <option key={s} value={s}>{t(s)}</option>)}</select>)}
                    {field('From Date', <input className="inp" type="date" value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />)}
                    {field('To Date', <input className="inp" type="date" value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />)}
                    {field('Invoice Search', <input className="inp" value={f.search || ''} onChange={(e) => setF({ ...f, search: e.target.value })} placeholder="OB…" />)}
                    {field('District', <input className="inp" value={f.district || ''} onChange={(e) => setF({ ...f, district: e.target.value })} />)}
                    {field('Taken By', <input className="inp" value={f.taken_by || ''} onChange={(e) => setF({ ...f, taken_by: e.target.value })} />)}
                </div>
                <div className="flex">
                    <button className="btn pri" onClick={apply}>{t('Show Results')}</button>
                    <button className="btn" onClick={() => router.get('/orders/dashboard')}>{t('Reset')}</button>
                    <span className="spacer" />
                    <Link href="/orders" className="btn">{t('Open New All Orders List')}</Link>
                </div>
            </div>

            {!hasFilter && (
                <div className="empty-box">
                    <div className="b" style={{ color: 'var(--ink)' }}>{t('No Filters Applied')}</div>
                    <div className="sm" style={{ marginTop: 4 }}>{t('Choose at least one filter above to see the orders report.')}</div>
                </div>
            )}
            {hasFilter && result && (
                <div className="card">
                    <div className="flex" style={{ gap: 14, flexWrap: 'wrap' }}>
                        <div className="stat-box" style={{ minWidth: 150 }}><div className="sm mut">{t('Total Orders')}</div><div style={{ fontSize: 26, fontWeight: 800, color: '#5b2a86' }}>{result.total}</div></div>
                        <div className="stat-box alt" style={{ minWidth: 170 }}><div className="sm mut">{t('Revenue')}</div><div style={{ fontSize: 26, fontWeight: 800 }}>{fmt(result.revenue)}</div></div>
                        <div style={{ flex: 1 }}>
                            {Object.entries(result.breakdown || {}).map(([s, c]) => (
                                <div className="kv" key={s}><span>{t(s)}</span><b>{c}</b></div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
