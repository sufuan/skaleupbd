import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill, SuccessRing, fmt } from './ui';
import SendCourierModal from '@/Components/SendCourierModal';

export default function Show({ order, customer, couriers, courierMethods = [], sources, products, processingStatuses, cancelReasons, pendingCount }) {
    const [approveOpen, setApproveOpen] = useState(false);
    const [sendOpen, setSendOpen] = useState(false);
    const [changeMethodId, setChangeMethodId] = useState('');
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [addPid, setAddPid] = useState('');
    const [addQty, setAddQty] = useState(1);
    const [addingMethod, setAddingMethod] = useState(false);
    const [newMethod, setNewMethod] = useState('');
    let formOpts = {};
    try { formOpts = JSON.parse(localStorage.getItem('skaleup_order_form_options') || '{}'); } catch (e) { formOpts = {}; }
    const courierOrder = formOpts.courier_order || ['Overall', 'Pathao', 'Steadfast', 'RedX', 'CarryBee'];
    const perCourierSorted = Object.entries(customer.per_courier || {}).sort((a, b) => {
        const ia = courierOrder.findIndex((c) => c.toLowerCase() === a[0].toLowerCase());
        const ib = courierOrder.findIndex((c) => c.toLowerCase() === b[0].toLowerCase());
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    });
    const canAddItem = ['Pending', 'Processing'].includes(order.status);
    const addItem = () => { if (addPid) router.post(`/orders/${order.id}/items`, { product_id: addPid, qty: addQty }, { preserveScroll: true, onSuccess: () => { setAddPid(''); setAddQty(1); } }); };
    const saveMethod = () => { if (newMethod.trim()) router.post('/settings/courier-methods', { name: newMethod.trim() }, { preserveScroll: true, preserveState: true, onSuccess: () => { form.setData('delivery_method', newMethod.trim()); setAddingMethod(false); setNewMethod(''); } }); };
    const risky = order.fraud.verdict === 'risky';
    const canApprove = ['Pending', 'Incomplete'].includes(order.status);

    const form = useForm({
        source: order.source || '',
        processing_status: order.processing_status || 'Processing',
        cancellation_reason: cancelReasons[0],
        delivery_method: order.delivery_method || '',
        delivery_note: order.delivery_note || '',
        shipping_note: order.shipping_note || '',
        tags: order.tags || [],
        note: '',
        advance_payment: '',
    });
    const [tagInput, setTagInput] = useState('');

    const save = (e) => { e.preventDefault(); form.patch(`/orders/${order.id}`, { preserveScroll: true, onSuccess: () => form.reset('note', 'advance_payment') }); };
    const approve = (next) => router.post(`/orders/${order.id}/approve`, { next }, { preserveScroll: false });
    const sms = (type) => router.post(`/orders/${order.id}/sms`, { type }, { preserveScroll: true });
    const block = (type) => router.post(`/orders/${order.id}/block`, { type }, { preserveScroll: true });

    return (
        <AppLayout>
            <Head title={`${t('Web Order')} — ${order.invoice}`} />
            <div className="flex" style={{ flexWrap: 'wrap' }}>
                <div>
                    <h1 className="page-h">{t('Web Order')} — {order.invoice}</h1>
                    <div className="page-sub">{order.created} · {order.created_time} · {t('Source')}: {order.source} · <StatusPill status={order.status} /></div>
                </div>
                <span className="spacer" />
                <div className="flex">
                    <Link href="/orders" className="btn">{t('Back to List')}</Link>
                    <Link href={`/orders/${order.id}/invoice`} className="btn">{t('Download Invoice')}</Link>
                    {canApprove && <button className="btn pri" onClick={() => setApproveOpen(true)}>{t('Approve Order')} ({fmt(order.total)})</button>}
                </div>
            </div>

            {risky && (
                <div className="alert-red">
                    <b>{t('RED ALERT — Risky customer')}</b>
                    <div className="sm" style={{ marginTop: 3 }}>
                        {order.fraud.note}. {t('Take advance payment before approving.')}
                        {customer.per_courier && <> · Steadfast {customer.per_courier.Steadfast ?? '—'}% · Pathao {customer.per_courier.Pathao ?? '—'}%</>}
                    </div>
                </div>
            )}

            <div className="ord-grid">
                <div>
                    <div className="card mb12">
                        <h3>{t('Customer')}</h3>
                        <div className="kv"><span>{t('Name')}</span><b>{order.name}</b></div>
                        <div className="kv"><span>{t('Phone')}</span><b>{order.phone}</b></div>
                        {order.alt_phone && <div className="kv"><span>{t('Alternative Number')}</span><b>{order.alt_phone}</b></div>}
                        {order.email && <div className="kv"><span>{t('Email')}</span><b>{order.email}</b></div>}
                        <div className="kv"><span>{t('Address')}</span><b>{order.address}{order.district ? `, ${order.district}` : ''}</b></div>
                        <div className="kv"><span>{t('Customer History')}</span><b>{customer.orders} {t('orders')} · {t('Success rate')} {customer.rate === null ? '—' : `${customer.rate}%`}</b></div>
                        <div className="kv"><span>{customer.is_returning ? t('Returning customer') : t('New customer')}</span><b>{customer.delivered} {t('delivered')} · {customer.returned} {t('returned')}</b></div>
                    </div>

                    <div className="card mb12">
                        <h3>{t('Success Rate')}</h3>
                        <SuccessRing fraud={order.fraud} size={56} />
                        <div className="mt16">
                            {perCourierSorted.map(([c, v]) => (
                                <div className="kv" key={c}><span>{c}</span><b>{v === null ? t('Not checked') : `${v}%`}</b></div>
                            ))}
                        </div>
                        {customer.network?.length > 0 && (
                            <div className="mt16">
                                <div className="sm b" style={{ marginBottom: 6 }}>{t('Courier network check')}</div>
                                {customer.network.map((n, i) => (
                                    <div className="kv" key={i}><span>{n.courier}</span><b>{n.rate !== null && n.rate !== undefined ? `${Math.round(n.rate)}%` : (n.label || t('Not checked'))}</b></div>
                                ))}
                                <div className="sm mut">{t("The network figure is the headline — it comes from the couriers' own records for this phone.")}</div>
                            </div>
                        )}
                    </div>

                    <div className="card mb12">
                        <h3>{t('Order Summary')}</h3>
                        <div className="kv"><span>{t('Status')}</span><StatusPill status={order.status} /></div>
                        <div className="kv"><span>{t('Payment Method')}</span><b>{order.payment_method}</b></div>
                        <div className="kv"><span>{t('Source')}</span><b>{order.source}</b></div>
                        {order.ip && <div className="kv"><span>IP</span><b>{order.ip}</b></div>}
                        {order.approved_at && <div className="kv"><span>{t('Approved')}</span><b>{order.approved_at}</b></div>}
                        {order.processed_by && <div className="kv"><span>{t('Processed By')}</span><b>{order.processed_by}</b></div>}
                        <div className="flex mt16">
                            <button className="btn" onClick={() => block('phone')}>{t('Block Phone')}</button>
                            <button className="btn" onClick={() => block('ip')}>{t('Block IP')}</button>
                            <button className="btn" style={{ color: 'var(--bad)', borderColor: '#f0caca' }} onClick={() => setDeleteOpen(true)}>{t('Delete Order')}</button>
                        </div>
                    </div>

                    <div className="card mb12">
                        <h3>{t('Delivery')}</h3>
                        {order.status === 'Processing' && (
                            <>
                                {order.booking_status === 'failed' && <p className="sm" style={{ color: 'var(--bad)' }}>{t('The last booking failed — see the reason in Ready to Ship, or try again.')}</p>}
                                <div className="flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                                    <button className="btn pri" onClick={() => setSendOpen(true)}>{t('Send to Courier')}</button>
                                    {order.booking_status === 'failed' && <button className="btn" onClick={() => router.post(`/delivery/${order.id}/retry`)}>{t('Retry')}</button>}
                                </div>
                                {order.courier_method_id && (
                                    <div className="frow mt16">
                                        <select className="inp" value={changeMethodId} onChange={(e) => setChangeMethodId(e.target.value)}>
                                            <option value="">{t('Change courier to…')}</option>
                                            {courierMethods.filter((m) => m.id !== order.courier_method_id).map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                                        </select>
                                        <button className="btn" disabled={!changeMethodId} onClick={() => router.post(`/delivery/${order.id}/change-courier`, { method_id: Number(changeMethodId) })}>{t('Change')}</button>
                                    </div>
                                )}
                                <p className="sm mut" style={{ marginTop: 8 }}>{t('Changing the courier clears the pickup store and any tracking number.')}</p>
                            </>
                        )}
                        {(order.tracking_id || order.consignment_id) && (
                            <>
                                <div className="kv"><span>{t('Courier')}</span><b>{order.courier_label || order.delivery_method}</b></div>
                                <div className="kv"><span>{t('CN / Tracking')}</span><b>{order.consignment_id || order.tracking_id}</b></div>
                                {order.courier_status && <div className="kv"><span>{t('Courier Status')}</span><b>{order.courier_status}</b></div>}
                                {order.pickup_store_name && <div className="kv"><span>{t('Pickup Store')}</span><b>{order.pickup_store_name}</b></div>}
                                {order.shipped_at && <div className="kv"><span>{t('Shipped')}</span><b>{order.shipped_at}</b></div>}
                                <div className="flex mt16" style={{ gap: 8, flexWrap: 'wrap' }}>
                                    <Link href={`/orders/${order.id}/packing-slip`} className="btn pri">{t('Print Packing Slip')}</Link>
                                    <button className="btn" onClick={() => router.post(`/delivery/${order.id}/force-check`)}>{t('Check Status')}</button>
                                </div>
                            </>
                        )}
                        {order.status === 'Pending' && <p className="sm mut">{t('Approve the order first — then it waits in Ready to Ship for the courier.')}</p>}
                        {['Shipped', 'Delivered', 'Returned'].includes(order.status) && !order.tracking_id && !order.consignment_id && (
                            <p className="sm mut">{t('This order was shipped before courier booking was tracked here.')}</p>
                        )}
                    </div>

                    {order.attachments?.length > 0 && (
                        <div className="card mb12">
                            <h3>{t('Attachments')}</h3>
                            {order.attachments.map((a, i) => <div key={i} className="kv"><span>{a.name}</span><a className="mini-link" href={a.url} target="_blank" rel="noreferrer">{t('Open')}</a></div>)}
                        </div>
                    )}
                </div>

                <div>
                    <div className="card mb12">
                        <h3>{t('Items')}</h3>
                        <table className="tbl">
                            <thead><tr><th>{t('Product')}</th><th>{t('Qty')}</th><th>{t('Price')}</th><th>{t('Total')}</th></tr></thead>
                            <tbody>
                                {order.items.map((i, ix) => (
                                    <tr key={ix}><td><div className="b">{i.name}</div>{i.sku && <div className="sm mut">{i.sku}</div>}</td><td>{i.qty}</td><td>{fmt(i.price)}</td><td>{fmt(i.price * i.qty)}</td></tr>
                                ))}
                            </tbody>
                        </table>
                        {canAddItem && (
                            <div className="flex mt16" style={{ flexWrap: 'wrap' }}>
                                <select className="inp" style={{ flex: 2 }} value={addPid} onChange={(e) => setAddPid(e.target.value)}>
                                    <option value="">{t('Add Product')}…</option>
                                    {(products || []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku}) · {fmt(p.price)} · {t('Stock')}: {p.stock}</option>)}
                                </select>
                                <input className="inp" style={{ width: 70 }} type="number" min="1" value={addQty} onChange={(e) => setAddQty(Math.max(1, Number(e.target.value) || 1))} />
                                <button type="button" className="btn" disabled={!addPid} onClick={addItem}>{t('Add Product')}</button>
                            </div>
                        )}
                        <div className="totals">
                            <div className="kv"><span>{t('Subtotal')}</span><b>{fmt(order.subtotal)}</b></div>
                            <div className="kv"><span>{t('Delivery Charge')}</span><b>{fmt(order.delivery_charge)}</b></div>
                            <div className="kv"><span>{t('Discount')}</span><b>− {fmt(order.discount)}</b></div>
                            <div className="kv big"><span>{t('Total')}</span><b>{fmt(order.total)}</b></div>
                            <div className="kv"><span>{t('Advance')}</span><b>{fmt(order.advance)}</b></div>
                            <div className="kv"><span>{t('Due Amount')}</span><b>{fmt(order.due)}</b></div>
                        </div>
                    </div>

                    <form onSubmit={save} className="card mb12">
                        <h3>{t('Process Order')}</h3>
                        <div className="frow">
                            <label className="fl">{t('Change Status')}</label>
                            <select className="inp" value={form.data.processing_status} onChange={(e) => form.setData('processing_status', e.target.value)}>
                                {processingStatuses.map((s) => <option key={s} value={s}>{t(s)}</option>)}
                            </select>
                        </div>
                        {form.data.processing_status === 'Cancel' && (
                            <div className="frow">
                                <label className="fl">{t('Cancellation Reason')}</label>
                                <select className="inp" value={form.data.cancellation_reason} onChange={(e) => form.setData('cancellation_reason', e.target.value)}>
                                    {cancelReasons.map((r) => <option key={r} value={r}>{t(r)}</option>)}
                                </select>
                            </div>
                        )}
                        <div className="frow">
                            <label className="fl">{t('Delivery Method')}</label>
                            <div className="flex">
                                <select className="inp" style={{ flex: 1 }} value={form.data.delivery_method} onChange={(e) => form.setData('delivery_method', e.target.value)}>
                                    <option value="">—</option>
                                    {couriers.map((c) => <option key={c} value={c}>{c}</option>)}
                                </select>
                                <button type="button" className="btn" onClick={() => setAddingMethod(!addingMethod)}>{t('Add Delivery Method')}</button>
                            </div>
                            {addingMethod && (
                                <div className="flex mt8">
                                    <input className="inp" style={{ flex: 1 }} value={newMethod} onChange={(e) => setNewMethod(e.target.value)} placeholder={t('New delivery method name')} />
                                    <button type="button" className="btn pri" disabled={!newMethod.trim()} onClick={saveMethod}>{t('Save')}</button>
                                </div>
                            )}
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Source')}</label>
                            <select className="inp" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                {(sources || []).map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
                            </select>
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Delivery Note')}</label>
                            <textarea className="inp" rows="2" value={form.data.delivery_note} onChange={(e) => form.setData('delivery_note', e.target.value)} />
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Shipping Note')}</label>
                            <textarea className="inp" rows="2" value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} />
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Order Tags')}</label>
                            <div className="flex" style={{ flexWrap: 'wrap' }}>
                                {(form.data.tags || []).map((tag) => (
                                    <span key={tag} className="pill">{tag} <button type="button" onClick={() => form.setData('tags', form.data.tags.filter((x) => x !== tag))}>×</button></span>
                                ))}
                                <input className="inp" style={{ width: 150 }} placeholder={t('Add tag + Enter')} value={tagInput}
                                    onChange={(e) => setTagInput(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (tagInput.trim()) { form.setData('tags', [...(form.data.tags || []), tagInput.trim()]); setTagInput(''); } } }} />
                            </div>
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Record Advance Payment (৳)')}</label>
                            <input className="inp" type="number" min="0" value={form.data.advance_payment} onChange={(e) => form.setData('advance_payment', e.target.value)} placeholder="0" />
                        </div>
                        <div className="frow">
                            <label className="fl">{t('Add Note')}</label>
                            <textarea className="inp" rows="2" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder={t('Write a note for your team…')} />
                        </div>
                        <div className="flex" style={{ flexWrap: 'wrap' }}>
                            <button type="submit" className="btn pri" disabled={form.processing}>{t('Save Changes')}</button>
                            <button type="button" className="btn" onClick={() => sms('reminder')}>{t('Send Reminder SMS')}</button>
                            <button type="button" className="btn" onClick={() => sms('advance')}>{t('Send Advance SMS')}</button>
                        </div>
                    </form>

                    <div className="card">
                        <h3>{t('Processing History & Activity Log')}</h3>
                        <div className="timeline">
                            {[...order.history.map((h) => ({ ...h, kind: 'history' })), ...order.activities.map((a) => ({ text: a.text, time: a.time, date: a.date, kind: a.kind }))]
                                .map((e, i) => (
                                    <div className="tl-row" key={i}>
                                        <span className={`tl-dot ${e.kind === 'sms' ? 'sms' : e.kind === 'note' ? 'note' : ''}`} />
                                        <div style={{ flex: 1 }}>{e.text}</div>
                                        <span className="sm mut">{e.date} · {e.time}</span>
                                    </div>
                                ))}
                            {order.history.length === 0 && order.activities.length === 0 && <div className="mut sm">{t('No activity yet')}</div>}
                        </div>
                    </div>
                </div>
            </div>

            {sendOpen && (
                <SendCourierModal order={{ id: order.id, invoice: order.invoice, customer_name: order.customer_name, cod: order.due }}
                    methods={courierMethods} onClose={() => setSendOpen(false)} />
            )}
            {approveOpen && (
                <div className="overlay" onClick={() => setApproveOpen(false)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <h3>{t('Approve this order?')}</h3>
                        <p className="sm" style={{ marginTop: 8 }}>
                            {order.invoice} {t('will move to Ready to Ship (Delivery). Stock for')} {order.items.reduce((a, i) => a + i.qty, 0)} {t('item(s) will be reserved, and packing can begin.')}
                        </p>
                        {risky && <p className="sm" style={{ color: 'var(--bad)', fontWeight: 700, marginTop: 8 }}>{t('Red alert: the courier network flags this customer as risky. Consider taking advance payment first.')}</p>}
                        <div className="kv mt16"><span>{t('Customer')}</span><b>{order.name}</b></div>
                        <div className="kv"><span>{t('Total (COD)')}</span><b>{fmt(order.total)}</b></div>
                        <div className="flex mt16" style={{ justifyContent: 'flex-end' }}>
                            <button className="btn" onClick={() => setApproveOpen(false)}>{t('Cancel')}</button>
                            <button className="btn" onClick={() => { setApproveOpen(false); approve(true); }}>{t('Confirm Approve & Next')}</button>
                            <button className="btn pri" onClick={() => { setApproveOpen(false); approve(false); }}>{t('Confirm Approve')}</button>
                        </div>
                    </div>
                </div>
            )}

            {deleteOpen && (
                <div className="overlay" onClick={() => setDeleteOpen(false)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <h3>{t('Delete this order?')}</h3>
                        <p className="sm" style={{ marginTop: 8 }}>{order.invoice} {t('will be removed from the order list. The deletion is recorded in Settings → Deletion Logs.')}</p>
                        <div className="flex mt16" style={{ justifyContent: 'flex-end' }}>
                            <button className="btn" onClick={() => setDeleteOpen(false)}>{t('Cancel')}</button>
                            <button className="btn" style={{ background: 'var(--bad)', color: '#fff', borderColor: 'var(--bad)' }}
                                onClick={() => router.delete(`/orders/${order.id}`, { data: { reason: 'Deleted from order detail' } })}>{t('Delete Order')}</button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
