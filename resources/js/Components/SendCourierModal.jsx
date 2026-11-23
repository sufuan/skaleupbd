import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { t } from '@/lib/i18n';

/**
 * The Send to Courier modal (§10.7b) - shared by Ready to Ship and the
 * Order Detail page. Lists only the couriers configured in Settings,
 * pre-selects the Suggested one with its reason, asks for a CN number
 * for manual couriers, and books only when Send is pressed.
 */
export default function SendCourierModal({ order, methods, suggestedId = null, suggestedReason = null, onClose }) {
    const suggested = methods.find((m) => m.id === suggestedId) || methods.find((m) => m.suggested);
    const [methodId, setMethodId] = useState(suggested?.id ?? methods[0]?.id ?? null);
    const [cn, setCn] = useState('');
    const [storeId, setStoreId] = useState(null);
    const [sending, setSending] = useState(false);
    const selected = methods.find((m) => m.id === methodId) || null;
    const reason = selected && suggested && selected.id === suggested.id
        ? (suggestedReason || selected.suggested_reason)
        : null;

    const send = () => {
        setSending(true);
        router.post('/delivery/send', {
            order_id: order.id,
            method_id: methodId,
            cn: selected?.booking_mode === 'manual' ? cn : null,
            pickup_store_id: storeId,
        }, {
            preserveScroll: true,
            onFinish: () => setSending(false),
            onSuccess: () => onClose && onClose(),
        });
    };

    return (
        <div className="overlay" onClick={onClose}>
            <div className="modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()}>
                <h3 style={{ marginTop: 0 }}>{t('Send to Courier')}</h3>
                <div className="card-sub">{order.invoice} · {order.customer_name} · {t('COD')} ৳{Number(order.cod ?? 0).toLocaleString()}</div>

                {methods.length === 0 ? (
                    <div style={{ padding: '12px 0' }}>
                        <p className="b">{t('No courier configured yet')}</p>
                        <p className="sm mut">{t('Add your courier account in Settings first - then it will show here.')}</p>
                        <div className="frow" style={{ gap: 8, marginTop: 12 }}>
                            <Link href="/settings?group=api" className="btn pri">{t('Go to Settings')}</Link>
                            <button type="button" className="btn" onClick={onClose}>{t('Close')}</button>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="cc-grid">
                            {methods.map((m) => (
                                <button type="button" key={m.id}
                                    className={`cc-card ${m.id === methodId ? 'sel' : ''}`}
                                    onClick={() => { setMethodId(m.id); setStoreId(null); }}>
                                    <span className="cc-name">{m.label}
                                        {suggested && m.id === suggested.id && <span className="pill green" style={{ marginLeft: 8 }}>{t('Suggested')}</span>}
                                    </span>
                                    <span className="sm mut">{m.booking_mode === 'manual' ? t('Manual') : t('Automatic')} · {t('Charge')} ৳{Number(m.charge ?? 0).toLocaleString()}</span>
                                    {suggested && m.id === suggested.id && reason && <span className="sm" style={{ color: 'var(--acc-ink)' }}>{t(reason)}</span>}
                                </button>
                            ))}
                        </div>

                        {selected?.booking_mode === 'manual' && (
                            <div className="frow mt16">
                                <label className="fl">{t('CN Number')}</label>
                                <input className="inp" value={cn} onChange={(e) => setCn(e.target.value)}
                                    placeholder={t('Write the CN number from the courier')} />
                            </div>
                        )}

                        {selected && selected.stores && selected.stores.length > 0 && (
                            <div className="frow mt16">
                                <label className="fl">{t('Pickup Store')}</label>
                                <select className="inp" value={storeId ?? (selected.stores.find((s) => s.is_default)?.id ?? '')}
                                    onChange={(e) => setStoreId(e.target.value ? Number(e.target.value) : null)}>
                                    <option value="">{t('Use default store')}</option>
                                    {selected.stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                            </div>
                        )}
                        {selected && selected.type === 'PATHAO' && !selected.has_store_id && (!selected.stores || selected.stores.length === 0) && (
                            <p className="sm mut" style={{ marginTop: 10 }}>{t('No pickup store synced yet - sync stores in Settings → Courier, or the booking will use the store saved on the connection.')}</p>
                        )}

                        <div className="frow" style={{ gap: 8, marginTop: 18, justifyContent: 'flex-end' }}>
                            <button type="button" className="btn" onClick={onClose}>{t('Cancel')}</button>
                            <button type="button" className="btn pri" disabled={sending || !selected || (selected?.booking_mode === 'manual' && !cn.trim())} onClick={send}>
                                {sending ? t('Sending…') : t('Send')}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
