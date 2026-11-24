import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Verdict, fmt, fmtNum, smsParts } from './parts';
import { t } from '@/lib/i18n';

export default function Sms({ balance, rate, success_rate, audiences, dnd_count, templates, campaigns }) {
    const [audKey, setAudKey] = useState(audiences.find((a) => a.key === 'bought_30d')?.key || audiences[0]?.key);
    const [message, setMessage] = useState('');
    const [coupon, setCoupon] = useState('');
    const aud = audiences.find((a) => a.key === audKey) || { count: 0, sendable: 0 };
    // Z4-L5: the phone preview must show the message the customer
    // actually receives. The server substitutes {coupon}/{discount}
    // from the chosen coupon (MarketingController::sendCampaign via
    // AutomationService::fill) and appends the code when the text
    // never mentions it - the preview runs the same steps, and the
    // character/part counts are taken from the substituted body too,
    // since that is what is stored, sent and charged.
    const preview = useMemo(() => {
        let body = message;
        if (coupon) {
            const discount = { FEST10: '10%', RETURN50: '৳50' }[coupon];
            body = body.split('{coupon}').join(coupon);
            if (discount) body = body.split('{discount}').join(discount);
            if (!body.includes(coupon)) body += ' Code: ' + coupon;
        }
        return body;
    }, [message, coupon]);
    const parts = useMemo(() => smsParts(preview), [preview]);
    const chars = useMemo(() => [...preview].length, [preview]);
    const total = aud.sendable * parts * rate;
    const afterRaw = balance - total;
    // The remainder is a display figure and never reads below zero -
    // the shortfall itself is carried by the warning line under the
    // numbers (and the send stays disabled while it is short).
    const after = Math.max(0, afterRaw);
    // Warn as soon as the numbers say the balance will not cover the send —
    // not only after the seller starts typing (an empty composer used to
    // show "Balance left ৳-9" with no warning at all).
    const warn = afterRaw < 0;

    const pickTemplate = (id) => {
        const tpl = templates.find((x) => String(x.id) === String(id));
        if (tpl) setMessage(tpl.body);
    };
    const send = () => router.post('/marketing/sms/send', { audience_key: audKey, message, coupon: coupon || null }, { preserveScroll: true });

    return (
        <AppLayout>
            <Head title={t('Marketing - SMS Marketing')} />
            <div className="mkt">
                <div style={{ marginBottom: 14 }}>
                    <h3 style={{ fontSize: 20 }}>{t('SMS Marketing')}</h3>
                    <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('Send a campaign in 3 steps - who you will send to, what you will write, how much it will cost. All the accounts are in front of your eyes before sending.')}</p>
                </div>
                <div className="mk-strip">
                    <div className="mk-kpi"><b>{fmt(balance)}</b><span>{t('SMS balance (you have)')}</span></div>
                    <div className="mk-kpi"><b>{fmt(rate)}</b><span>{t('The price of each SMS part starts from')}</span></div>
                    <div className="mk-kpi"><b>{success_rate === null ? '-' : success_rate + '%'}</b><span>{t('of SMS sent last month went successfully')}</span></div>
                </div>

                <div className="step-card">
                    <div className="step-title">{t('Step 1 · Who will you send to?')}</div>
                    <div>
                        {audiences.map((a) => (
                            <button key={a.key} className={`aud-chip${a.key === audKey ? ' sel' : ''}`} onClick={() => setAudKey(a.key)}>{a.label} · {t(':count people', { count: fmtNum(a.count) })}</button>
                        ))}
                    </div>
                    <div style={{ fontSize: 13, color: '#94A3B8', marginTop: 4 }}>{t(':count people who said "do not send again" are removed on their own - it will never go to them.', { count: fmtNum(dnd_count) })}</div>
                </div>

                <div className="step-card">
                    <div className="step-title">{t('Step 2 · What will you write?')}</div>
                    <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap' }}>
                        <div style={{ flex: 1, minWidth: 280 }}>
                            <select defaultValue="" onChange={(e) => pickTemplate(e.target.value)} style={{ width: '100%', height: 40, border: '1px solid var(--fld-line)', borderRadius: 9, padding: '0 10px', fontFamily: 'inherit', marginBottom: 10, background: 'var(--field-bg)', color: 'var(--ink)' }}>
                                <option value="">{t('- Pick a ready template, or write your own below -')}</option>
                                {templates.map((tpl) => <option key={tpl.id} value={tpl.id}>{tpl.name}</option>)}
                            </select>
                            <textarea rows="4" value={message} onChange={(e) => setMessage(e.target.value)} style={{ width: '100%', border: '1px solid var(--fld-line)', borderRadius: 10, padding: 11, fontFamily: 'inherit', fontSize: 14.5, boxSizing: 'border-box', background: 'var(--field-bg)', color: 'var(--ink)' }} placeholder={t('Write here - as you write, you can see on the phone on the right how it will look')}></textarea>
                            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
                                <label style={{ fontSize: 13.5, color: '#64748B' }}>{t('Add a Coupon:')}{' '}
                                    <select value={coupon} onChange={(e) => setCoupon(e.target.value)} style={{ height: 36, border: '1px solid var(--fld-line)', borderRadius: 8, fontFamily: 'inherit', background: 'var(--field-bg)', color: 'var(--ink)' }}>
                                        <option value="">{t('No coupon')}</option>
                                        <option value="FEST10">{t('FEST10 - 10% off')}</option>
                                        <option value="RETURN50">{t('RETURN50 - ৳50 off')}</option>
                                    </select>
                                </label>
                                <span style={{ fontSize: 13, color: '#94A3B8' }}>{t(':chars characters · :parts part', { chars, parts })}</span>
                            </div>
                        </div>
                        <div className="phone">
                            <div className="phone-screen">
                                <div style={{ fontSize: 11.5, color: '#78716C', marginBottom: 8 }}>{t("How it will go to the customer's phone")}</div>
                                <div className="sms-bubble">{preview || t('It will show here as you write…')}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="step-card">
                    <div className="step-title">{t('Step 3 · How much will it cost?')}</div>
                    <div style={{ display: 'flex', gap: 26, flexWrap: 'wrap', alignItems: 'center' }}>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>{t('Recipients')}</div><b style={{ fontSize: 22 }}>{t(':count people', { count: fmtNum(aud.sendable) })}</b></div>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>{t('Per person')}</div><b style={{ fontSize: 22 }}>{parts} part</b></div>
                        <div><div style={{ fontSize: 13, color: 'var(--mut-ink)' }}>{t('Total cost')}</div><b style={{ fontSize: 22, color: 'var(--acc-ink)' }}>{fmt(total)}</b></div>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>{t('Balance left after sending')}</div><b style={{ fontSize: 22, color: warn ? '#DC2626' : undefined }}>{fmt(after)}</b></div>
                        <button className="btn-p" style={{ marginLeft: 'auto' }} disabled={!message || aud.sendable === 0 || warn} onClick={send}>{t('Send Campaign')}</button>
                    </div>
                    {warn && <div style={{ color: '#DC2626', fontSize: 13.5, marginTop: 10 }}>{t('The balance will not cover it - either shrink the audience, or shorten the text, or fill the balance.')}</div>}
                </div>

                <h4 style={{ fontSize: 16.5, margin: '20px 0 10px' }}>{t('Previous Campaigns - results')}</h4>
                <table className="mk-table">
                    <thead><tr><th>{t('Campaign')}</th><th>{t('Who it was sent to')}</th><th>{t('Cost')}</th><th>{t('Orders came')}</th><th>{t('Verdict')}</th></tr></thead>
                    <tbody>
                        {campaigns.map((c) => (
                            <tr key={c.id}>
                                <td style={{ fontWeight: 600 }}>{c.name}<div style={{ fontWeight: 400, color: '#94A3B8', fontSize: 12 }}>{c.date}</div></td>
                                <td>{t(':count people', { count: fmtNum(c.recipients) })} ({c.audience_label})</td>
                                <td>{fmt(c.total_cost)}</td>
                                <td>{fmtNum(c.orders)} · {fmt(c.sales)}</td>
                                <td>{c.recipients > 0
                                    ? <Verdict profit={c.profit} />
                                    : <span className="chip chip-grey">{t('No SMS could be sent')}</span>}</td>
                            </tr>
                        ))}
                        {campaigns.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>{t('No campaign has been sent yet.')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
