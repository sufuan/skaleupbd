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
                    <h1 style={{ fontSize: 24, fontWeight: 700 }}>{t('Ready to Ship')}</h1>
                    <div className="mut sm" style={{ marginTop: 3 }}>{t('Approved orders being packed - print the invoice, pack the parcel, then send it to a courier.')}</div>
                </div>
                <button className="btn pri" disabled={picked.length === 0} onClick={() => setBulkOpen(true)}>{t('Send Selected')} ({picked.length})</button>
            </div>

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
                                            <Link href={`/orders/${o.id}`} className="b" style={{ color: 'var(--acc-ink)' }}>{o.invoice}</Link>
                                            <div className="sm mut">{o.date}</div>
                                        </td>
                                        <td><div className="b">{o.customer_name}</div><div className="sm mut">{o.customer_phone}</div></td>
                                        <td>{t(o.zone)}<div className="sm mut">{o.district || '-'}</div></td>
                                        <td><span className="b">{o.items_count}</span><div className="sm mut">{o.items_label}</div></td>
                                        <td className="b">{fmt(o.cod)}</td>
                                        <td>{o.booking_status === 'failed'
                                            ? <><span className="pill" style={{ background: '#FCE7F3', color: '#BE123C' }}>{t('Booking Failed')}</span>{o.last_error && <div className="sm" style={{ color: 'var(--bad)', marginTop: 4 }}>{o.last_error}</div>}</>
                                            : <StatusPill status={o.status} />}</td>
                                        <td>{o.waiting}</td>
                                        <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                                            <a href={`/orders/${o.id}/invoice`} className="btn" title={t('Print Invoice')} style={{ marginRight: 6, padding: '6px 9px' }}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ verticalAlign: -2 }}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5" /><path d="M12 15V3" /></svg></a>
                                            {o.booking_status === 'failed'
                                                ? <button className="btn" style={{ background: '#DC2626', color: '#fff', borderColor: '#DC2626' }} onClick={() => router.post(`/delivery/${o.id}/retry`)}>{t('Retry')}</button>
                                                : <button className="btn" onClick={() => setSendOrder(o)}>{t('Send to Courier')}</button>}
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
                                {methods.map((m) => <option key={m.id} value={m.id}>{m.label} - {m.booking_mode === 'manual' ? t('Manual') : t('Automatic')}</option>)}
                            </select></div>
                        {bulkMethodObj?.booking_mode === 'manual' && (
                            <p className="sm" style={{ color: 'var(--bad)', marginTop: 10 }}>{t('A manual courier needs a CN number for every parcel - send those one by one from the row.')}</p>
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
