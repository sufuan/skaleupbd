import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import SendCourierModal from '@/Components/SendCourierModal';
import { fmt, StatusPill } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

export default function ReadyToShip({ orders, methods, search }) {
    const [q, setQ] = useState(search || '');
    const [picked, setPicked] = useState([]);
    const [sendOrder, setSendOrder] = useState(null);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkMethod, setBulkMethod] = useState('');

    const toggle = (id) => setPicked((p) => p.includes(id) ? p.filter((x) => x !== id) : [...p, id]);
    const allPicked = orders.length > 0 && picked.length === orders.length;

    const doSearch = (e) => { e.preventDefault(); router.get('/delivery/ready', { search: q }, { preserveState: true }); };

    const bulkSend = () => {
        const method = methods.find((m) => m.id === Number(bulkMethod));
        if (method && method.booking_mode === 'manual') return; // manual needs a CN per parcel
        router.post('/delivery/send-bulk', { order_ids: picked, method_id: bulkMethod ? Number(bulkMethod) : null }, {
            onSuccess: () => { setBulkOpen(false); setPicked([]); },
        });
    };
    const bulkMethodObj = methods.find((m) => m.id === Number(bulkMethod));

    return (
        <AppLayout title={t('Ready to Ship')}>
            <div className="page-head">
                <div>
                    <h1>{t('Ready to Ship')}</h1>
                    <div className="mut sm">{t('Approved orders wait here until they are handed to a courier')}</div>
                </div>
                <form onSubmit={doSearch} className="flex" style={{ gap: 8 }}>
                    <input className="inp" style={{ minWidth: 220 }} placeholder={t('Search invoice or phone')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <button className="btn" type="submit">{t('Search')}</button>
                </form>
            </div>

            {picked.length > 0 && (
                <div className="card" style={{ marginBottom: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
                    <span className="b">{picked.length} {t('selected')}</span>
                    <button className="btn pri" onClick={() => setBulkOpen(true)}>{t('Send Selected')} ({picked.length})</button>
                    <button className="btn" onClick={() => setPicked([])}>{t('Clear')}</button>
                </div>
            )}

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                {orders.length === 0 ? (
                    <div style={{ padding: 34, textAlign: 'center' }} className="mut">{t('No orders waiting')}</div>
                ) : (
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr>
                                <th><input type="checkbox" checked={allPicked} onChange={() => setPicked(allPicked ? [] : orders.map((o) => o.id))} /></th>
                                <th>{t('Invoice')}</th><th>{t('Customer')}</th><th>{t('Area')}</th><th>{t('Items')}</th>
                                <th>{t('COD')}</th><th>{t('Status')}</th><th>{t('Waiting')}</th><th style={{ textAlign: 'right' }}>{t('Actions')}</th>
                            </tr></thead>
                            <tbody>
                                {orders.map((o) => (
                                    <tr key={o.id}>
                                        <td><input type="checkbox" checked={picked.includes(o.id)} onChange={() => toggle(o.id)} /></td>
                                        <td>
                                            <Link href={`/orders/${o.id}`} className="b" style={{ color: 'var(--acc)' }}>{o.invoice}</Link>
                                            {o.booking_status === 'failed' && <div className="sm" style={{ color: 'var(--bad)' }}>{o.last_error}</div>}
                                            {o.suggested_label && <div className="sm mut">{t('Suggested')}: {o.suggested_label} — {t(o.suggested_reason || '')}</div>}
                                        </td>
                                        <td><div className="b">{o.customer_name}</div><div className="sm mut">{o.customer_phone}</div></td>
                                        <td>{o.district || '—'}<div className="sm"><span className="pill blue">{t(o.zone)}</span></div></td>
                                        <td><span className="b">{o.items_count}</span><div className="sm mut">{o.items_label}</div></td>
                                        <td className="b">{fmt(o.cod)}</td>
                                        <td><StatusPill status={o.status} /></td>
                                        <td>{o.waiting}</td>
                                        <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                                            <Link href={`/orders/${o.id}/invoice`} className="btn" style={{ marginRight: 6 }}>{t('Print Invoice')}</Link>
                                            {o.booking_status === 'failed'
                                                ? <button className="btn pri" onClick={() => router.post(`/delivery/${o.id}/retry`)}>{t('Retry')}</button>
                                                : <button className="btn pri" onClick={() => setSendOrder(o)}>{t('Send to Courier')}</button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {sendOrder && (
                <SendCourierModal order={sendOrder} methods={methods.map((m) => ({ ...m, charge: sendOrder.zone === 'Inside Dhaka' ? m.charge_inside : m.charge_outside }))}
                    suggestedId={sendOrder.suggested_method_id} suggestedReason={sendOrder.suggested_reason}
                    onClose={() => setSendOrder(null)} />
            )}

            {bulkOpen && (
                <div className="overlay" onClick={() => setBulkOpen(false)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <h3 style={{ marginTop: 0 }}>{t('Send Selected')} ({picked.length})</h3>
                        <p className="sm mut">{t('Leave the courier empty to use each order\'s own Suggested courier, or pick one courier for the whole batch.')}</p>
                        <div className="frow mt16"><label className="fl">{t('Courier')}</label>
                            <select className="inp" value={bulkMethod} onChange={(e) => setBulkMethod(e.target.value)}>
                                <option value="">{t('Each order\'s Suggested courier')}</option>
                                {methods.map((m) => <option key={m.id} value={m.id}>{m.label} — {m.booking_mode === 'manual' ? t('Manual') : t('Automatic')}</option>)}
                            </select></div>
                        {bulkMethodObj?.booking_mode === 'manual' && (
                            <p className="sm" style={{ color: 'var(--bad)', marginTop: 10 }}>{t('A manual courier needs a CN number for every parcel — send those one by one from the row.')}</p>
                        )}
                        <div className="frow" style={{ gap: 8, marginTop: 18, justifyContent: 'flex-end' }}>
                            <button className="btn" onClick={() => setBulkOpen(false)}>{t('Cancel')}</button>
                            <button className="btn pri" disabled={bulkMethodObj?.booking_mode === 'manual'} onClick={bulkSend}>{t('Send')}</button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
