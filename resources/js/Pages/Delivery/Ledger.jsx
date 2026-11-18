import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

const TABS = ['Overview', 'COD Receivable', 'Differences', 'Reconciliation', 'Rate Card', 'Issues'];
const ISSUE_TYPES = ['COD Difference', 'Charge Mismatch', 'Duplicate Tracking', 'Invoice Line Missing', 'Charge Overcharge'];

export default function Ledger({ rows, total, ratecard, receivables, buckets, differences }) {
    const [tab, setTab] = useState('Overview');

    const recon = {};
    receivables.forEach((r) => {
        const k = r.courier || '—';
        recon[k] = recon[k] || { expected: 0, settled: 0 };
        recon[k].expected += r.expected; recon[k].settled += r.settled;
    });
    const issueCounts = {};
    differences.forEach((d) => { issueCounts[d.type] = (issueCounts[d.type] || 0) + 1; });

    return (
        <AppLayout title={t('Courier Ledger')}>
            <div className="led-pg">
                <div className="page-head">
                    <div>
                        <h1>{t('Courier Ledger')}</h1>
                        <p>{t('COD collected, settled to you and still with the courier — per courier')}</p>
                    </div>
                </div>

                <div className="kpi-grid" style={{ gridTemplateColumns: 'repeat(3,1fr)' }}>
                    <div className="card kpi"><div className="lbl">{t('Collected by courier')}</div><div className="val">{fmt(total.collected)}</div><div className="sub">{t('Cash couriers collected from your customers')}</div></div>
                    <div className="card kpi"><div className="lbl">{t('Settled to you')}</div><div className="val">{fmt(total.settled)}</div><div className="sub">{t('Money already paid to you')}</div></div>
                    <div className="card kpi"><div className="lbl">{t('Still with courier')}</div><div className="val">{fmt(total.still)}</div><div className="sub">{t('Collected, but not paid to you yet')}</div></div>
                </div>

                <div className="ptabs">
                    {TABS.map((x) => <span key={x} className={`ptab ${tab === x ? 'act' : ''}`} onClick={() => setTab(x)}>{t(x)}</span>)}
                </div>

                {tab === 'Overview' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Courier')}</th><th>{t('Delivered')}</th><th>{t('Collected')}</th><th>{t('Settled to you')}</th><th>{t('Still with courier')}</th><th>{t('Status')}</th></tr></thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.courier}>
                                        <td className="b">{r.company}{r.manual && <span className="muted-sm"> ({t('manual')})</span>}</td>
                                        <td>{r.delivered}</td><td>{fmt(r.collected)}</td><td>{fmt(r.settled)}</td><td>{fmt(r.still)}</td>
                                        <td>{differences.some((d) => d.courier === r.courier)
                                            ? <span className="pill amber">{t('Difference Found')}</span>
                                            : r.still <= 0.01
                                                ? <span className="pill green">{t('Settled')}</span>
                                                : <span className="muted-sm">—</span>}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No couriers configured yet')}</td></tr>}
                            </tbody>
                        </table></div>
                        <p className="empty-note" style={{ marginTop: 12 }}>{t('Committed charge is estimated from the rate card: zone Dhaka means Inside, anything else Outside; weight assumed up to 0.5 kg. Issue types tracked: COD Difference, Charge Mismatch, Duplicate Tracking, Invoice Line Missing, Charge Overcharge.')}</p>
                    </div>
                )}

                {tab === 'COD Receivable' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="card-sub">{t('Money the couriers have collected on delivered parcels but not paid you yet, by how long it has been waiting')}</div>
                        <div className="flex" style={{ gap: 22, flexWrap: 'wrap', margin: '10px 0 16px' }}>
                            {Object.entries(buckets).map(([k, v]) => (
                                <div key={k}><div className="b" style={{ fontSize: 20 }}>{fmt(v)}</div><div className="sm mut">{k} {t('days')}</div></div>
                            ))}
                        </div>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('COD')}</th><th>{t('Committed Charge')}</th><th>{t('Expected')}</th><th>{t('Settled')}</th><th>{t('Difference')}</th><th>{t('Age')}</th></tr></thead>
                            <tbody>
                                {receivables.map((r, i) => (
                                    <tr key={i}><td className="b">{r.invoice}</td><td>{r.courier}</td><td>{fmt(r.cod)}</td><td>{fmt(r.committed_charge)}</td>
                                        <td>{fmt(r.expected)}</td><td>{fmt(r.settled)}</td>
                                        <td style={{ color: r.difference < 0 ? 'var(--bad)' : 'inherit' }}>{fmt(r.difference)}</td><td>{r.bucket}</td></tr>
                                ))}
                                {receivables.length === 0 && <tr><td colSpan={8} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('Nothing is owed right now')}</td></tr>}
                            </tbody>
                        </table></div>
                    </div>
                )}

                {tab === 'Differences' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="card-sub">{t('Parcels that were paid less than expected. Each one also raises a task in the Work Inbox — nothing passes silently.')}</div>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('Type')}</th><th>{t('Severity')}</th><th>{t('Expected')}</th><th>{t('Actual')}</th><th>{t('Difference')}</th></tr></thead>
                            <tbody>
                                {differences.map((d, i) => (
                                    <tr key={i}><td className="b">{d.invoice}</td><td>{d.courier}</td><td>{t(d.type)}</td>
                                        <td><span className={`pill ${d.severity === 'High' ? 'red' : d.severity === 'Medium' ? 'amber' : ''}`}>{t(d.severity)}</span></td>
                                        <td>{fmt(d.expected)}</td><td>{fmt(d.actual)}</td><td style={{ color: 'var(--bad)' }}>{fmt(d.difference)}</td></tr>
                                ))}
                                {differences.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('No differences — every parcel matches')}</td></tr>}
                            </tbody>
                        </table></div>
                    </div>
                )}

                {tab === 'Reconciliation' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="card-sub">{t('Expected uses the rate card in Settings (Dhaka → Inside charge, anywhere else → Outside charge, weight up to 0.5 kg)')}</div>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Courier')}</th><th>{t('Expected')}</th><th>{t('Settled')}</th><th>{t('Difference')}</th></tr></thead>
                            <tbody>
                                {Object.entries(recon).map(([k, v]) => (
                                    <tr key={k}><td className="b">{k}</td><td>{fmt(v.expected)}</td><td>{fmt(v.settled)}</td>
                                        <td style={{ color: v.settled - v.expected < 0 ? 'var(--bad)' : 'inherit' }}>{fmt(v.settled - v.expected)}</td></tr>
                                ))}
                                {Object.keys(recon).length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('Nothing to reconcile yet')}</td></tr>}
                            </tbody>
                        </table></div>
                    </div>
                )}

                {tab === 'Rate Card' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="card-sub">{t('The delivery charge each courier takes, from Settings → Courier. The committed charge on a parcel is estimated from this card.')}</div>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Courier')}</th><th>{t('Class')}</th><th>{t('Inside Dhaka')}</th><th>{t('Outside Dhaka')}</th></tr></thead>
                            <tbody>
                                {ratecard.map((m) => (
                                    <tr key={m.id}><td className="b">{m.label}</td><td>{m.class}</td><td>{fmt(m.charge_inside)}</td><td>{fmt(m.charge_outside)}</td></tr>
                                ))}
                                {ratecard.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('No couriers configured yet')}</td></tr>}
                            </tbody>
                        </table></div>
                    </div>
                )}

                {tab === 'Issues' && (
                    <div className="card" style={{ padding: 24 }}>
                        <div className="card-sub">{t('Open money issues with the couriers, grouped by type. The details are in the Differences tab.')}</div>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Issue Type')}</th><th>{t('Open')}</th></tr></thead>
                            <tbody>
                                {ISSUE_TYPES.map((x) => (
                                    <tr key={x}><td className="b">{t(x)}</td><td>{issueCounts[x] || 0}</td></tr>
                                ))}
                            </tbody>
                        </table></div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
