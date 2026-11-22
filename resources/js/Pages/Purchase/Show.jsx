import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { PageHead, PayModal, dhakaToday, fmt, fmtDate } from './shared';

// Purchase detail — minimal view in the same language: items with
// their received state, payments so far, and the bill attachments.
export default function Show({ purchase, accounts }) {
    const [paying, setPaying] = useState(false);
    return (
        <AppLayout>
            <Head title={t('Purchase')} />
            <div className="pur">
                <PageHead title={`${t('Purchase')} #${purchase.id}`}>
                    <Link href="/purchases" className="btn">{t('Purchase List')}</Link>
                    {purchase.due > 0 && <button type="button" className="btn pri" onClick={() => setPaying(true)}>{t('Pay')}</button>}
                </PageHead>

                <div className="stat-grid c3" style={{ marginBottom: 16 }}>
                    <div className="stat-card2"><div className="lb">{t('Amount')}</div><div className="vl">{fmt(purchase.total)}</div></div>
                    <div className="stat-card2"><div className="lb">{t('Paid')}</div><div className="vl">{fmt(purchase.paid)}</div></div>
                    <div className="stat-card2"><div className="lb">{t('Due')}</div><div className="vl red">{fmt(purchase.due)}</div></div>
                </div>

                <div className="card">
                    <div className="check-row">
                        <span className="mut">{t('Supplier')}</span><span className="spacer" />
                        <span className="b">{purchase.kind === 'quick' ? t('Quick Stock Increase') : `${purchase.supplier?.name || ''}${purchase.supplier?.phone ? ' · ' + purchase.supplier.phone : ''}`}</span>
                    </div>
                    <div className="check-row">
                        <span className="mut">{t('Date')}</span><span className="spacer" /><span className="b">{fmtDate(purchase.date)}</span>
                    </div>
                    <div className="check-row">
                        <span className="mut">{t('Status')}</span><span className="spacer" />
                        <span className={`pill ${purchase.status === 'Received' ? 'green' : 'amber'}`}>{t(purchase.status)}</span>
                    </div>
                    {purchase.note && <div className="check-row"><span className="mut">{t('Note')}</span><span className="spacer" /><span>{purchase.note}</span></div>}
                    {purchase.tags && <div className="check-row"><span className="mut">{t('Tags')}</span><span className="spacer" /><span>{purchase.tags}</span></div>}
                </div>

                <div className="sec-t">{t('Items')}</div>
                <div className="card">
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Product')}</th><th>{t('Ordered')}</th><th>{t('Received')}</th><th>{t('Damaged / missing')}</th><th>{t('Left')}</th><th>{t('Unit cost')}</th><th>{t('Line total')}</th></tr></thead>
                            <tbody>
                                {purchase.items.map((i) => (
                                    <tr key={i.id}>
                                        <td><span className="b">{i.product}</span><span className="sm mut" style={{ display: 'block' }}>{t('SKU:')} {i.sku}</span></td>
                                        <td>{i.ordered}</td><td>{i.received}</td><td>{i.damaged}</td><td className="b">{i.left}</td>
                                        <td>{fmt(i.unit_cost)}</td><td className="b">{fmt(i.line_total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {purchase.payments.length > 0 && (
                    <>
                        <div className="sec-t">{t('Payments')}</div>
                        <div className="card">
                            {purchase.payments.map((p) => (
                                <div key={p.id} className="check-row">
                                    <span>{fmtDate(p.date)}</span><span className="spacer" /><span className="b">{fmt(p.amount)}</span>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {purchase.attachments.length > 0 && (
                    <>
                        <div className="sec-t">{t('Attachments (Optional)')}</div>
                        <div className="card">
                            {purchase.attachments.map((a) => (
                                <div key={a.id} className="check-row">
                                    <a href={a.url} target="_blank" rel="noreferrer" className="mini-link">{a.name}</a>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {paying && (
                    <PayModal title={`${t('Pay')} — ${purchase.supplier?.name || ''}`} due={purchase.due} accounts={accounts} today={dhakaToday()}
                        action={`/purchases/${purchase.id}/pay`} onClose={() => setPaying(false)} />
                )}
            </div>
        </AppLayout>
    );
}
