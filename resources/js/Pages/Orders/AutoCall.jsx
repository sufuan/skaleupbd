import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { fmt } from './ui';

const OUTCOMES = [['verified', 'Verified'], ['no_answer', 'No Answer'], ['phone_off', 'Phone Off'], ['wrong_number', 'Wrong Number'], ['failed', 'Failed']];

export default function AutoCall({ stats, waitingOrders, logs }) {
    const form = useForm({ order_id: '', phone: '', outcome: 'verified', minutes: 1 });
    const submit = (e) => { e.preventDefault(); form.post('/orders/autocall/log', { preserveScroll: true, onSuccess: () => form.reset('phone', 'order_id') }); };
    const cards = [
        ['Calls Today', stats.calls_today], ['Successful Verifications', stats.verified], ['Failed Calls', stats.failed],
        ['Minutes Used', stats.minutes], ['Waiting Orders', stats.waiting],
    ];
    return (
        <AppLayout>
            <Head title={t('Auto Call Center')} />
            <h1 className="page-h">{t('Auto Call Center')}</h1>
            <div className="page-sub">{t('Call waiting customers yourself and log the outcome - calls are billed ৳1.00 per minute from the service balance.')}</div>

            <div className="kpis" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(180px,1fr))' }}>
                {cards.map(([label, v]) => (
                    <div className="card kpi" key={label}><div className="lb"><span>{t(label)}</span></div><div className="vl">{v}</div></div>
                ))}
                <div className="card kpi"><div className="lb"><span>{t('Service Balance')}</span></div><div className="vl">{fmt(stats.balance)}</div></div>
            </div>

            <div className="ord-grid">
                <div className="card">
                    <h3>{t('Waiting Orders')}</h3>
                    <div className="card-sub">{t('Pending orders waiting for a confirmation call.')}</div>
                    {waitingOrders.map((o) => (
                        <div className="conn-row" key={o.id}>
                            <div style={{ flex: 1 }}><div className="b sm">{o.invoice} - {o.name}</div><div className="sm mut">{o.phone} · {fmt(o.total)}</div></div>
                            <a className="btn" href={`tel:${o.phone}`}>{t('Call')}</a>
                            <button className="btn" onClick={() => form.setData({ ...form.data, order_id: o.id, phone: o.phone })}>{t('Log')}</button>
                        </div>
                    ))}
                    {waitingOrders.length === 0 && <div className="sm mut">{t('No orders are waiting')}</div>}
                </div>
                <div>
                    <form onSubmit={submit} className="card mb12">
                        <h3>{t('Log a Call Outcome')}</h3>
                        <div className="frow"><label className="fl">{t('Phone')}</label>
                            <input className="inp" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" /></div>
                        <div className="frow"><label className="fl">{t('Outcome')}</label>
                            <select className="inp" value={form.data.outcome} onChange={(e) => form.setData('outcome', e.target.value)}>
                                {OUTCOMES.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
                            </select></div>
                        <div className="frow"><label className="fl">{t('Minutes')}</label>
                            <input className="inp" type="number" min="0" max="60" value={form.data.minutes} onChange={(e) => form.setData('minutes', e.target.value)} /></div>
                        <button type="submit" className="btn pri" disabled={form.processing}>{t('Save Outcome')}</button>
                    </form>
                    <div className="card">
                        <h3>{t('Recent Calls')}</h3>
                        {logs.map((l, i) => (
                            <div className="kv" key={i}><span>{l.phone} · {l.staff}</span><b>{t(OUTCOMES.find(([v]) => v === l.outcome)?.[1] || l.outcome)} · {l.minutes} min · {l.time}</b></div>
                        ))}
                        {logs.length === 0 && <div className="sm mut">{t('No calls logged yet')}</div>}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
