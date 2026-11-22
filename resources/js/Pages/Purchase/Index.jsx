import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { GuideBtn, PageHead, PayModal, dhakaToday, fmt, fmtDate } from './shared';

// Purchases - EcomDrive's Purchase List with its four tabs; the
// "To receive" tab swaps in Purchase Receive's quantity columns.
export default function Index({ tab, q, counts, rows, lineRows, pager, accounts }) {
    const [search, setSearch] = useState(q || '');
    const [payFor, setPayFor] = useState(null);
    const go = (patch) => router.get('/purchases', { tab, q: q || undefined, ...patch }, { preserveState: true, replace: true });

    const TABS = [
        ['all', `${t('All')} ${counts.all}`],
        ['receive', `${t('To receive')} ${counts.receive}`],
        ['pay', `${t('To pay')} ${counts.pay} · ${fmt(counts.payDue)}`],
        ['quick', `${t('Quick Stock')} ${counts.quick}`],
    ];

    const del = async (row) => {
        if (await confirmDialog(t('Delete this purchase?'))) {
            router.delete(`/purchases/${row.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title={t('Purchase List')} />
            <div className="pur">
                <PageHead title={t('Purchase List')}>
                    <GuideBtn />
                    <Link href="/purchases/quick" className="btn">{t('Quick Stock')}</Link>
                    <Link href="/purchases/create" className="btn pri">{t('New Purchase')}</Link>
                </PageHead>

                <div className="ptabs">
                    {TABS.map(([k, l]) => (
                        <span key={k} className={`ptab ${tab === k ? 'act' : ''}`} onClick={() => go({ tab: k, page: undefined })}>{l}</span>
                    ))}
                </div>

                <form onSubmit={(e) => { e.preventDefault(); go({ q: search || undefined, page: undefined }); }} style={{ marginBottom: 14 }}>
                    <input className="inp" style={{ maxWidth: 420, width: '100%' }} placeholder={t('Search supplier, product or SKU')} aria-label={t('Search purchases')} value={search} onChange={(e) => setSearch(e.target.value)} />
                </form>

                {tab === 'receive' ? (
                    <div className="card">
                        <div className="tbl-wrap">
                            <table className="tbl">
                                <thead><tr>
                                    <th>{t('Ordered on')}</th><th>{t('Supplier')}</th><th>{t('Items')}</th>
                                    <th>{t('Ordered')}</th><th>{t('Received')}</th><th>{t('Damaged / missing')}</th>
                                    <th>{t('Left')}</th><th>{t('Status')}</th><th>{t('Actions')}</th>
                                </tr></thead>
                                <tbody>
                                    {lineRows.map((r) => (
                                        <tr key={r.item_id}>
                                            <td>{fmtDate(r.ordered_on)}<span className="sm mut" style={{ display: 'block' }}>{t('Waiting')} {r.waiting_days} {r.waiting_days === 1 ? t('day') : t('days')}</span></td>
                                            <td>{r.quick ? <><span className="b">{t('Quick Stock Increase')}</span></> : <span className="b">{r.supplier}</span>}</td>
                                            <td>{r.product}</td>
                                            <td>{r.ordered}</td>
                                            <td>{r.received}</td>
                                            <td>{r.damaged}</td>
                                            <td className="b">{r.left}</td>
                                            <td><span className="pill amber">{t(r.status)}</span></td>
                                            <td><Link href={`/purchases/${r.purchase_id}/receive`} className="btn" style={{ padding: '6px 12px' }}>{t('Receive')}</Link></td>
                                        </tr>
                                    ))}
                                    {lineRows.length === 0 && <tr><td colSpan={9} className="mut">{t('Nothing waiting to be received.')}</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : (
                    <div className="card">
                        <div className="tbl-wrap">
                            <table className="tbl">
                                <thead><tr>
                                    <th>{t('Date')}</th><th>{t('Supplier')}</th><th>{t('Items')}</th>
                                    <th>{t('Amount')}</th><th>{t('Due')}</th><th>{t('Status')}</th><th>{t('Actions')}</th>
                                </tr></thead>
                                <tbody>
                                    {rows.map((r) => (
                                        <tr key={r.id}>
                                            <td>{fmtDate(r.date)}</td>
                                            <td>
                                                {r.kind === 'quick'
                                                    ? <><span className="b">{t('Quick Stock Increase')}</span><span className="sm mut" style={{ display: 'block' }}>{t('No supplier bill')}</span></>
                                                    : <><span className="b">{r.supplier?.name}</span>{r.supplier?.phone && <span className="sm mut" style={{ display: 'block' }}>{r.supplier.phone}</span>}</>}
                                            </td>
                                            <td>{r.items}</td>
                                            <td className="b">{fmt(r.amount)}</td>
                                            <td>{r.kind === 'quick' || r.due <= 0 ? (r.paid ? <span className="pill green">{t('Paid')}</span> : '-') : <span className="b" style={{ color: 'var(--bad)' }}>{fmt(r.due)}</span>}</td>
                                            <td><span className={`pill ${r.status === 'Received' ? 'green' : 'amber'}`}>{t(r.status)}</span></td>
                                            <td>
                                                <div className="flex" style={{ gap: 6, flexWrap: 'wrap' }}>
                                                    <Link href={`/purchases/${r.id}`} className="mini-link">{t('View')}</Link>
                                                    {r.can_receive && <Link href={`/purchases/${r.id}/receive`} className="btn" style={{ padding: '6px 12px' }}>{t('Receive')}</Link>}
                                                    {r.can_pay && <button type="button" className="btn" style={{ padding: '6px 12px' }} onClick={() => setPayFor(r)}>{t('Pay')}</button>}
                                                    {r.can_delete && <button type="button" className="mini-link" onClick={() => del(r)}>{t('Delete')}</button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && <tr><td colSpan={7} className="mut">{t('No purchases found.')}</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {pager && pager.pages > 1 && (
                    <div className="flex" style={{ marginTop: 12 }}>
                        <span className="sm mut">{t('Page')} {pager.page} {t('of')} {pager.pages}</span>
                        <span className="spacer" />
                        {pager.page > 1 && <button type="button" className="btn" onClick={() => go({ page: pager.page - 1 })}>{t('Prev')}</button>}
                        {pager.page < pager.pages && <button type="button" className="btn" onClick={() => go({ page: pager.page + 1 })}>{t('Next')}</button>}
                    </div>
                )}

                {payFor && (
                    <PayModal
                        title={`${t('Pay')} - ${payFor.supplier?.name || ''}`}
                        due={payFor.due} accounts={accounts} today={dhakaToday()}
                        action={`/purchases/${payFor.id}/pay`}
                        onClose={() => setPayFor(null)}
                    />
                )}
            </div>
        </AppLayout>
    );
}
