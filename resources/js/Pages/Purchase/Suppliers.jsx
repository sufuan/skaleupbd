import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { GuideBtn, PageHead, PayModal, fmt, fmtDate } from './shared';

// Suppliers — EcomDrive's Suppliers page with Supplier Ledger merged
// in: the same table also carries Oldest due date and the Pay button,
// and the stat cards are the Ledger's summary figures.
function SupplierFormModal({ supplier, onClose }) {
    const form = useForm({
        name: supplier?.name || '', phone: supplier?.phone || '', email: supplier?.email || '',
        address: supplier?.address || '', terms_days: supplier?.terms_days ?? 30, note: supplier?.note || '',
    });
    const submit = (e) => {
        e.preventDefault();
        if (supplier) form.patch(`/suppliers/${supplier.id}`, { onSuccess: onClose });
        else form.post('/suppliers', { onSuccess: onClose });
    };
    return (
        <div className="overlay" onClick={onClose}>
            <div className="modal" onClick={(e) => e.stopPropagation()}>
                <div className="b" style={{ fontSize: 16, marginBottom: 14 }}>{supplier ? t('Edit Supplier') : t('Add Supplier')}</div>
                <form onSubmit={submit}>
                    <div className="fld"><label>{t('Name')} *</label>
                        <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                    <div className="fld"><label>{t('Phone')}</label>
                        <input className="inp" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></div>
                    <div className="fld"><label>{t('Email')}</label>
                        <input className="inp" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></div>
                    <div className="fld"><label>{t('Address')}</label>
                        <textarea className="inp" rows={2} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} /></div>
                    <div className="fld"><label>{t('Terms (days)')}</label>
                        <input className="inp" type="number" min="0" max="365" value={form.data.terms_days} onChange={(e) => form.setData('terms_days', e.target.value)} /></div>
                    <div className="fld"><label>{t('Note')}</label>
                        <textarea className="inp" rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></div>
                    <div className="flex" style={{ gap: 8 }}>
                        <span className="spacer" />
                        <button type="button" className="btn" onClick={onClose}>{t('Cancel')}</button>
                        <button type="submit" className="btn pri" disabled={form.processing}>{t('Save')}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function Suppliers({ tab, q, stats, rows, reorderRows, reportRows, reportFrom, reportTo, accounts, today }) {
    const [search, setSearch] = useState(q || '');
    const [formFor, setFormFor] = useState(undefined); // undefined=closed, null=add, obj=edit
    const [payFor, setPayFor] = useState(null);
    const [from, setFrom] = useState(reportFrom);
    const [to, setTo] = useState(reportTo);
    const go = (patch) => router.get('/suppliers', { tab, q: q || undefined, from: reportFrom, to: reportTo, ...patch }, { preserveState: true, replace: true });

    const del = async (row) => {
        if (await confirmDialog(t('Delete this supplier?'))) {
            router.delete(`/suppliers/${row.id}`, { preserveScroll: true });
        }
    };

    const TABS = [['suppliers', 'Suppliers'], ['reorder', 'Reorder'], ['report', 'Purchase Report']];

    return (
        <AppLayout>
            <Head title={t('Suppliers')} />
            <div className="pur">
                <PageHead title={t('Suppliers')}>
                    <GuideBtn />
                    <button type="button" className="btn pri" onClick={() => setFormFor(null)}>{t('Add Supplier')}</button>
                </PageHead>

                <div className="stat-grid c3" style={{ marginBottom: 18 }}>
                    <div className="stat-card2">
                        <div className="lb">{t('Overdue suppliers')}</div>
                        <div className="vl red">{stats.overdueSuppliers}</div>
                        <div className="sc-sub">{fmt(stats.overdueAmount)} {t('overdue')}</div>
                    </div>
                    <button type="button" className="stat-card2" style={{ textAlign: 'left', cursor: 'pointer', fontFamily: 'inherit' }} onClick={() => go({ tab: 'reorder' })}>
                        <div className="lb">{t('Products to reorder')}</div>
                        <div className="vl">{stats.reorderCount}</div>
                        <div className="sc-sub">{t('At or below reorder point')}</div>
                    </button>
                    <div className="stat-card2">
                        <div className="lb">{t('Total due (bills)')}</div>
                        <div className="vl">{fmt(stats.totalDue)}</div>
                        <div className="sc-sub">{stats.suppliersWithDue} {t('suppliers with due')}</div>
                    </div>
                </div>

                <div className="ptabs">
                    {TABS.map(([k, l]) => (
                        <span key={k} className={`ptab ${tab === k ? 'act' : ''}`} onClick={() => go({ tab: k })}>{t(l)}</span>
                    ))}
                </div>

                {tab === 'suppliers' && (
                    <>
                        <form onSubmit={(e) => { e.preventDefault(); go({ q: search || undefined }); }} style={{ marginBottom: 14 }}>
                            <input className="inp" style={{ maxWidth: 420, width: '100%' }} placeholder={t('Search name or phone')} aria-label={t('Search suppliers')} value={search} onChange={(e) => setSearch(e.target.value)} />
                        </form>
                        <div className="card">
                            <div className="tbl-wrap">
                                <table className="tbl">
                                    <thead><tr>
                                        <th>{t('Supplier')}</th><th>{t('Phone')}</th><th>{t('Terms')}</th>
                                        <th>{t('Purchases')}</th><th>{t('Total purchased')}</th><th>{t('Due (bills)')}</th>
                                        <th>{t('Oldest due date')}</th><th>{t('Last purchase')}</th><th>{t('Needs attention')}</th><th></th>
                                    </tr></thead>
                                    <tbody>
                                        {rows.map((r) => (
                                            <tr key={r.id}>
                                                <td><span className="flex" style={{ gap: 8 }}><span className="ava">{r.initials}</span><span className="b">{r.name}</span></span></td>
                                                <td>{r.phone || '—'}</td>
                                                <td>{r.terms_days}d</td>
                                                <td>{r.purchases}</td>
                                                <td>{fmt(r.total_purchased)}</td>
                                                <td className="b">{r.due > 0 ? fmt(r.due) : '—'}</td>
                                                <td>{r.due > 0 ? fmtDate(r.oldest_due_date) : '—'}</td>
                                                <td>{fmtDate(r.last_purchase)}</td>
                                                <td>{r.overdue > 0 ? <span className="pill red">{t('Overdue')} {fmt(r.overdue)}</span> : '—'}</td>
                                                <td>
                                                    <div className="flex" style={{ gap: 6, flexWrap: 'wrap' }}>
                                                        {r.due > 0 && <button type="button" className="btn" style={{ padding: '6px 12px' }} onClick={() => setPayFor(r)}>{t('Pay')}</button>}
                                                        <button type="button" className="mini-link" onClick={() => setFormFor(r)}>{t('Edit')}</button>
                                                        {!r.has_purchases && <button type="button" className="mini-link" onClick={() => del(r)}>{t('Delete')}</button>}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {rows.length === 0 && <tr><td colSpan={10} className="mut">{t('No suppliers yet.')}</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                            <div className="sm mut" style={{ marginTop: 10 }}>{t('Showing')} {rows.length} {t('suppliers')}</div>
                        </div>
                    </>
                )}

                {tab === 'reorder' && (
                    <div className="card">
                        <div className="tbl-wrap">
                            <table className="tbl">
                                <thead><tr><th>{t('Product')}</th><th>{t('Stock')}</th><th>{t('Reorder point')}</th><th>{t('Supplier')}</th><th>{t('Last cost')}</th><th>{t('Suggested qty')}</th></tr></thead>
                                <tbody>
                                    {reorderRows.map((r) => (
                                        <tr key={r.id}>
                                            <td><span className="b">{r.name}</span><span className="sm mut" style={{ display: 'block' }}>{t('SKU:')} {r.sku}</span></td>
                                            <td className="b" style={{ color: 'var(--bad)' }}>{r.stock}</td>
                                            <td>{r.alert_qty}</td>
                                            <td>{r.supplier || '—'}</td>
                                            <td>{fmt(r.last_cost)}</td>
                                            <td className="b">{r.suggested}</td>
                                        </tr>
                                    ))}
                                    {reorderRows.length === 0 && <tr><td colSpan={6} className="mut">{t('Nothing to reorder right now.')}</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {tab === 'report' && (
                    <>
                        <form onSubmit={(e) => { e.preventDefault(); go({ from, to }); }} className="flex" style={{ marginBottom: 14, flexWrap: 'wrap' }}>
                            <input className="inp" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            <input className="inp" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            <button type="submit" className="btn">{t('Show')}</button>
                        </form>
                        <div className="card">
                            <div className="tbl-wrap">
                                <table className="tbl">
                                    <thead><tr><th>{t('Supplier')}</th><th>{t('Purchased')}</th><th>{t('Paid')}</th><th>{t('Due (bills)')}</th></tr></thead>
                                    <tbody>
                                        {reportRows.map((r) => (
                                            <tr key={r.id}>
                                                <td className="b">{r.name}</td>
                                                <td>{fmt(r.purchased)}</td>
                                                <td>{fmt(r.paid)}</td>
                                                <td className="b">{r.due > 0 ? fmt(r.due) : '—'}</td>
                                            </tr>
                                        ))}
                                        {reportRows.length === 0 && <tr><td colSpan={4} className="mut">{t('No purchases in this period.')}</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}

                {formFor !== undefined && <SupplierFormModal supplier={formFor} onClose={() => setFormFor(undefined)} />}
                {payFor && (
                    <PayModal title={`${t('Pay')} — ${payFor.name}`} due={payFor.due} accounts={accounts} today={today}
                        action={`/suppliers/${payFor.id}/pay`} onClose={() => setPayFor(null)} />
                )}
            </div>
        </AppLayout>
    );
}
