import AppLayout from '@/Layouts/AppLayout';
import { fmt } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

export default function Ledger({ rows, total, settlements }) {
    return (
        <AppLayout title={t('Courier Ledger')}>
            <div className="page-head">
                <div>
                    <h1>{t('Courier Ledger')}</h1>
                    <div className="mut sm">{t('What each courier collected for you, what it has paid you, and what it still holds')}</div>
                </div>
            </div>

            <div className="grid3" style={{ marginBottom: 14 }}>
                <div className="card"><div className="sm mut">{t('Collected by courier')}</div><div className="b" style={{ fontSize: 26 }}>{fmt(total.collected)}</div></div>
                <div className="card"><div className="sm mut">{t('Settled to you')}</div><div className="b" style={{ fontSize: 26 }}>{fmt(total.settled)}</div></div>
                <div className="card"><div className="sm mut">{t('Still with courier')}</div><div className="b" style={{ fontSize: 26 }}>{fmt(total.still)}</div></div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden', marginBottom: 14 }}>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('Courier')}</th><th>{t('Collected by courier')}</th><th>{t('Settled to you')}</th><th>{t('Still with courier')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.courier}><td className="b">{r.courier} <span className="sm mut">({t('Class')} {r.class})</span></td>
                                <td>{fmt(r.collected)}</td><td>{fmt(r.settled)}</td><td className="b">{fmt(r.still)}</td></tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No couriers configured yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div style={{ padding: '14px 18px' }} className="b">{t('Settlements')}</div>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('Date')}</th><th>{t('Courier')}</th><th>{t('Reference')}</th><th>{t('Amount')}</th><th>{t('Parcels it covers')}</th></tr></thead>
                    <tbody>
                        {settlements.map((s) => (
                            <tr key={s.id}><td>{s.date}</td><td className="b">{s.courier}</td><td className="sm">{s.payment_id || s.reference || '—'}</td>
                                <td className="b">{fmt(s.amount)}</td>
                                <td className="sm">{s.parcels.map((p) => `${p.invoice} ${fmt(p.amount)}`).join(' · ') || '—'}</td></tr>
                        ))}
                        {settlements.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No settlements yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>
        </AppLayout>
    );
}
