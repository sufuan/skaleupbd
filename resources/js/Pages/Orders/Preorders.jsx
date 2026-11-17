import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill, fmt } from './ui';

const TABS = [['all', 'All'], ['notified', 'Notified'], ['pending', 'Pending Notification']];

export default function Preorders({ orders, counts, tab, reasons, filters }) {
    const [reason, setReason] = useState(filters?.reason || '');
    const [code, setCode] = useState(filters?.code || '');
    const goTab = (k) => router.get('/orders/preorders', { tab: k, reason, code }, { preserveState: true, replace: true });
    return (
        <AppLayout>
            <Head title={t('Preorders')} />
            <h1 className="page-h">{t('Preorders')}</h1>
            <div className="page-sub">{t('Orders taken before the product is in stock — notify the customer when it arrives.')}</div>

            <div className="ptabs">
                {TABS.map(([k, label]) => (
                    <span key={k} className={`ptab ${tab === k ? 'act' : ''}`} onClick={() => goTab(k)}>
                        {t(label)} <span className="pill" style={{ marginLeft: 4 }}>{counts[k]}</span>
                    </span>
                ))}
                <span className="spacer" />
                <button className="btn" style={{ alignSelf: 'center' }} onClick={() => router.reload()}>{t('Refresh Stock')}</button>
            </div>

            <div className="card mb12">
                <div className="flex" style={{ flexWrap: 'wrap' }}>
                    <div className="frow" style={{ margin: 0 }}><label className="fl">{t('Reason')}</label>
                        <select className="inp" style={{ width: 190 }} value={reason} onChange={(e) => setReason(e.target.value)}>
                            <option value="">—</option>
                            {(reasons || []).map((r) => <option key={r} value={r}>{r}</option>)}
                        </select></div>
                    <div className="frow" style={{ margin: 0 }}><label className="fl">{t('Product Code')}</label>
                        <input className="inp" style={{ width: 190 }} value={code} onChange={(e) => setCode(e.target.value)} placeholder="SKU…" /></div>
                    <button className="btn pri" onClick={() => router.get('/orders/preorders', { tab, reason, code }, { preserveState: true, replace: true })}>{t('Apply Filter')}</button>
                </div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Invoice')}</th><th>{t('Customer')}</th><th>{t('Items')}</th><th>{t('Reason')}</th><th>{t('Notification Date')}</th><th>{t('Status')}</th><th>{t('Total')}</th><th>{t('Actions')}</th></tr></thead>
                        <tbody>
                            {orders.map((o) => (
                                <tr key={o.id}>
                                    <td className="b">{o.invoice}</td>
                                    <td><div className="b">{o.name}</div><div className="sm mut">{o.phone}</div></td>
                                    <td>{o.items_text}</td><td>{o.reason || '—'}</td><td>{o.notify_date || '—'}</td>
                                    <td><StatusPill status={o.status} /></td><td>{fmt(o.total)}</td>
                                    <td style={{ whiteSpace: 'nowrap' }}>
                                        <Link href={`/orders/${o.id}`} className="mini-link">{t('Open')}</Link>{' '}
                                        {!o.notified && <button className="mini-link" onClick={() => router.post(`/orders/preorders/${o.id}/notify`, {}, { preserveScroll: true })}>{t('Notify Customer')}</button>}
                                        {o.notified && <span className="pill green">{t('Notified')}</span>}
                                    </td>
                                </tr>
                            ))}
                            {orders.length === 0 && <tr><td colSpan="8" style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No preorders here')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
