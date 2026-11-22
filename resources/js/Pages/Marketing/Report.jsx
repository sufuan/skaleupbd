import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Verdict, fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

const PRESETS = [['today', 'Today'], ['7d', 'Last 7 days'], ['month', 'This month'], ['custom', 'Custom dates']];

export default function Report({ rows, totals, window_days, preset, from, to }) {
    const [win, setWin] = useState(window_days);
    const [cFrom, setCFrom] = useState(from);
    const [cTo, setCTo] = useState(to);
    const go = (p, f = cFrom, tt = cTo) => router.get('/marketing/report', p === 'custom' ? { preset: p, from: f, to: tt } : { preset: p }, { preserveScroll: true });
    const saveWin = () => { if (Number(win) !== window_days) router.post('/marketing/report/window', { days: Number(win) }, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title={t('Marketing - SMS Report')} />
            <div className="mkt">
                <div style={{ marginBottom: 14 }}>
                    <h3 style={{ fontSize: 20 }}>{t('SMS Report')}</h3>
                    <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('How much orders grew by sending SMS - campaigns and automation, all accounts in one place. Which SMS is earning money and which is going to waste is clear right here.')}</p>
                </div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 16 }}>
                    <div className="ptabs" style={{ marginBottom: 0, borderBottom: 'none' }}>
                        {PRESETS.map(([p, label]) => (
                            <button key={p} className={`ptab${preset === p ? ' act' : ''}`} onClick={() => go(p)}>{t(label)}</button>
                        ))}
                    </div>
                    {preset === 'custom' && (
                        <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
                            <input type="date" value={cFrom} onChange={(e) => setCFrom(e.target.value)} style={{ height: 34, border: '1px solid #E2E8F0', borderRadius: 8, padding: '0 8px', fontFamily: 'inherit' }} />
                            <input type="date" value={cTo} onChange={(e) => setCTo(e.target.value)} style={{ height: 34, border: '1px solid #E2E8F0', borderRadius: 8, padding: '0 8px', fontFamily: 'inherit' }} />
                            <button className="btn-s" onClick={() => go('custom')}>{t('View')}</button>
                        </span>
                    )}
                    <div className="flow-time" style={{ marginTop: 0, marginLeft: 'auto' }}>
                        <span className="ft-lab">{t('Order counting rule:')}</span> {t('After the SMS')}
                        <input type="number" className="ft-in" min="1" max="90" value={win} onChange={(e) => setWin(e.target.value)} onBlur={saveWin} /> {t('days, an order is counted in the name of that SMS')}
                    </div>
                </div>
                <div className="mk-strip">
                    <div className="mk-kpi"><b>{fmt(totals.sms_cost)}</b><span>{t('Total SMS cost in this period')}</span></div>
                    <div className="mk-kpi"><b>{t(':count orders', { count: fmtNum(totals.orders) })} · {fmt(totals.sales)}</b><span>{t('Sales that came from SMS')}</span></div>
                    <div className="mk-kpi"><b style={{ color: totals.profit >= 0 ? '#16A34A' : '#DC2626' }}>{fmt(totals.profit)}</b><span>{t('Net profit after SMS (order profit − SMS cost)')}</span></div>
                </div>
                <table className="mk-table">
                    <thead><tr><th>{t('SMS')}</th><th>{t('Type')}</th><th>{t('Was sent')}</th><th>{t('Orders came')}</th><th>{t('Sales')}</th><th>{t('SMS cost')}</th><th>{t('Net profit')}</th><th>{t('Verdict')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.type + ':' + r.key}>
                                <td style={{ fontWeight: 600 }}>{r.label}</td>
                                <td>{r.type === 'campaign' ? t('Campaign') : t('Automation')}</td>
                                <td>{t(':count people', { count: fmtNum(r.sent) })}</td>
                                <td>{t(':count pcs', { count: fmtNum(r.orders) })}</td>
                                <td>{fmt(r.sales)}</td>
                                <td>{fmt(r.sms_cost)}</td>
                                <td style={r.profit < 0 ? { color: '#DC2626' } : undefined}>{r.profit < 0 ? '−' + fmt(Math.abs(r.profit)) : fmt(r.profit)}</td>
                                <td><Verdict profit={r.profit} /></td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="8" style={{ color: '#94A3B8' }}>{t('No marketing SMS was sent in this period.')}</td></tr>}
                    </tbody>
                </table>
                <p style={{ fontSize: 13, color: '#94A3B8', marginTop: 12, lineHeight: 1.7 }}>{t('Rule: if a customer orders within :days days of getting the SMS, the order is counted in the name of that SMS. If several SMS went in this time, the last one gets it. Order-news SMS (thank-you · courier-sent news · delivered thank-you) are not for growing orders - they are not counted in this report. Net profit = the profit of those orders (counted as delivered) − SMS cost.', { days: fmtNum(window_days) })}</p>
            </div>
        </AppLayout>
    );
}
