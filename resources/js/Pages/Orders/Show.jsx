import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill, fmt } from './ui';
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
    const verdictLabel = order.fraud.verdict === 'safe' ? 'Safe' : risky ? 'Risky - take advance' : 'Not checked';

    // The Change Status dropdown must show the order's CURRENT status.
    // While the order is in the processing flow that is its processing
    // sub-status; once it has moved on (Shipped, Delivered, ...) the
    // main status is what the dropdown shows - never a stale "Processing".
    const inFlow = ['Pending', 'Processing'].includes(order.status);
    const form = useForm({
        source: order.source || '',
        processing_status: inFlow ? (order.processing_status || 'Processing') : '',
        cancellation_reason: cancelReasons[0],
        delivery_method: order.delivery_method || '',
        delivery_note: order.delivery_note || '',
        shipping_note: order.shipping_note || '',
        tags: order.tags || [],
        note: '',
        advance_payment: '',
    });
    const [tagInput, setTagInput] = useState('');
    const [statusSel, setStatusSel] = useState(inFlow ? (order.processing_status || 'Processing') : order.status);
    const statusOptions = inFlow ? processingStatuses : [order.status, ...processingStatuses.filter((s) => s !== order.status)];
    const onStatusSel = (v) => { setStatusSel(v); form.setData('processing_status', processingStatuses.includes(v) ? v : ''); };

    const save = (e) => { e.preventDefault(); form.patch(`/orders/${order.id}`, { preserveScroll: true, onSuccess: () => form.reset('note', 'advance_payment') }); };
    const approve = (next) => router.post(`/orders/${order.id}/approve`, { next }, { preserveScroll: false });
    const sms = (type) => router.post(`/orders/${order.id}/sms`, { type }, { preserveScroll: true });
    const block = (type) => router.post(`/orders/${order.id}/block`, { type }, { preserveScroll: true });
    const historyRows = [...order.history.map((h) => ({ text: h.text, date: h.date, time: h.time })), ...order.activities.map((a) => ({ text: a.text, date: a.date, time: a.time }))];

    return (
        <AppLayout>
            <Head title={`${order.source_label ? `${order.source_label} ${t('Order')}` : t('Order')} - ${order.invoice}`} />
            <div className="ord-detail">
                <div className="page-head">
                    <div>
                        <h1>{order.source_label ? `${order.source_label} ${t('Order')}` : t('Order')} - {order.invoice}</h1>
                        <p>{order.created}, {order.created_time} · {t('Source')}: {order.source_label || order.source}</p>
                    </div>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 10, alignItems: 'center' }}>
                        <StatusPill status={order.status} />
                        {canApprove && <button className="btn-primary" onClick={() => setApproveOpen(true)}>{t('Approve Order')} ({fmt(order.total)})</button>}
                    </span>
                </div>

                <div className="grid-21">
                    <div>
                        <div className="card">
                            <h3 style={{ marginBottom: 10 }}>{t('Customer')}</h3>
                            <div className="sum-row"><span>{t('Name')}</span><b>{order.name}</b></div>
                            <div className="sum-row"><span>{t('Phone')}</span><b>{order.phone}</b></div>
                            {order.alt_phone && <div className="sum-row"><span>{t('Alternative Number')}</span><b>{order.alt_phone}</b></div>}
                            {order.email && <div className="sum-row"><span>{t('Email')}</span><b>{order.email}</b></div>}
                            <div className="sum-row"><span>{t('Address')}</span><b style={{ textAlign: 'right' }}>{order.address}{order.district ? `, ${order.district}` : ''}</b></div>
                            <div className="sum-row"><span>{t('Customer History')}</span><b style={{ textAlign: 'right', whiteSpace: 'normal' }}>{customer.orders} {customer.orders === 1 ? t('order') : t('orders')} · {t('Success rate')}: {customer.rate === null ? '-' : `${customer.rate}%`} · {t(verdictLabel)}</b></div>
                            <div className="sum-row"><span>{customer.is_returning ? t('Returning customer') : t('New customer')}</span><b>{customer.delivered} {t('delivered')} · {customer.returned} {t('returned')}</b></div>
                            <h3 style={{ fontSize: 13.5, margin: '14px 0 2px' }}>{t('Success Rate')}</h3>
                            {perCourierSorted.map(([c, v]) => (
                                <div className="sum-row" key={c}><span>{c}</span><b>{v === null ? t('Not checked') : `${v}%`}</b></div>
                            ))}
                            {customer.network?.length > 0 && (
                                <>
                                    <div className="sm b" style={{ margin: '12px 0 2px' }}>{t('Courier network check')}</div>
                                    {customer.network.map((n, i) => (
                                        <div className="sum-row" key={i}><span>{n.courier}</span><b>{n.rate !== null && n.rate !== undefined ? `${Math.round(n.rate)}%` : (n.label || t('Not checked'))}</b></div>
                                    ))}
                                    <div className="sm mut" style={{ marginTop: 6 }}>{t("The network figure is the headline - it comes from the couriers' own records for this phone.")}</div>
                                </>
                            )}
                        </div>

                        {risky && (
                            <div className="alert-red">
                                <b>{t('RED ALERT - Risky customer')}</b>
                                <div className="sm" style={{ marginTop: 3 }}>
                                    {order.fraud.note}. {t('Take advance payment before approving.')}
                                    {customer.per_courier && <> · Steadfast {customer.per_courier.Steadfast ?? '-'}% · Pathao {customer.per_courier.Pathao ?? '-'}%</>}
                                </div>
                            </div>
                        )}

                        <div className="card">
                            <h3 style={{ marginBottom: 10 }}>{t('Items')}</h3>
                            <table className="plain">
                                <thead><tr><th>{t('Product')}</th><th>{t('Qty')}</th><th>{t('Price')}</th><th>{t('Total')}</th></tr></thead>
                                <tbody>
                                    {order.items.map((i, ix) => (
                                        <tr key={ix}><td>{i.name}{i.sku && <span className="muted-sm"> ({i.sku})</span>}</td><td>{i.qty}</td><td>{fmt(i.price)}</td><td>{fmt(i.price * i.qty)}</td></tr>
                                    ))}
                                </tbody>
                            </table>
                            {canAddItem && (
                                <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
                                    <select className="fld" style={{ flex: 2, marginTop: 0 }} value={addPid} onChange={(e) => setAddPid(e.target.value)}>
                                        <option value="">{t('Add Product')}…</option>
                                        {(products || []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku}) · {fmt(p.price)} · {t('Stock')}: {p.stock}</option>)}
                                    </select>
                                    <input className="fld" style={{ width: 70, marginTop: 0 }} type="number" min="1" value={addQty} onChange={(e) => setAddQty(Math.max(1, Number(e.target.value) || 1))} />
                                    <button type="button" className="ctl-btn" disabled={!addPid} onClick={addItem}>{t('Add Product')}</button>
                                </div>
                            )}
                            <div style={{ marginTop: 10 }}>
                                <div className="sum-row"><span>{t('Payment Method')}</span><b>{order.payment_method}</b></div>
                                <div className="sum-row"><span>{t('Subtotal')}</span><b>{fmt(order.subtotal)}</b></div>
                                <div className="sum-row"><span>{t('Delivery Charge')}</span><b>{fmt(order.delivery_charge)}</b></div>
                                <div className="sum-row"><span>{t('Discount')}</span><b>{fmt(order.discount)}</b></div>
                                <div className="sum-row total"><span>{t('Total')}</span><b>{fmt(order.total)}</b></div>
                                <div className="sum-row"><span>{t('Advance')}</span><b>{fmt(order.advance)}</b></div>
                                <div className="sum-row"><span>{t('Due Amount')}</span><b>{fmt(order.due)}</b></div>
                            </div>
                        </div>

                        {order.attachments?.length > 0 && (
                            <div className="card">
                                <h3 style={{ marginBottom: 10 }}>{t('Attachments')}</h3>
                                {order.attachments.map((a, i) => <div key={i} className="sum-row"><span>{a.name}</span><a href={a.url} target="_blank" rel="noreferrer">{t('Open')}</a></div>)}
                            </div>
                        )}
                    </div>

                    <div>
                        <form onSubmit={save} className="card">
                            <h3 style={{ marginBottom: 12 }}>{t('Process Order')}</h3>
                            <label className="fld-l frow">{t('Change Status')}
                                <select className="fld" value={statusSel} onChange={(e) => onStatusSel(e.target.value)}>
                                    {statusOptions.map((s) => <option key={s} value={s}>{t(s)}</option>)}
                                </select>
                            </label>
                            {form.data.processing_status === 'Cancel' && (
                                <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Cancellation Reason')}
                                    <select className="fld" value={form.data.cancellation_reason} onChange={(e) => form.setData('cancellation_reason', e.target.value)}>
                                        {cancelReasons.map((r) => <option key={r} value={r}>{t(r)}</option>)}
                                    </select>
                                </label>
                            )}
                            <div className="fld-l frow" style={{ marginTop: 12 }}>{t('Delivery Method')}
                                <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <select className="fld" style={{ flex: '1 1 170px' }} value={form.data.delivery_method} onChange={(e) => form.setData('delivery_method', e.target.value)}>
                                        <option value="">-</option>
                                        {couriers.map((c) => <option key={c} value={c}>{c}</option>)}
                                    </select>
                                    <button type="button" className="ctl-btn" style={{ marginTop: 6 }} onClick={() => setAddingMethod(!addingMethod)}>{t('Add Delivery Method')}</button>
                                </span>
                                {addingMethod && (
                                    <span style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                                        <input className="fld" style={{ flex: 1, marginTop: 0 }} value={newMethod} onChange={(e) => setNewMethod(e.target.value)} placeholder={t('New delivery method name')} />
                                        <button type="button" className="ctl-btn" disabled={!newMethod.trim()} onClick={saveMethod}>{t('Save')}</button>
                                    </span>
                                )}
                            </div>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Source')}
                                <select className="fld" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                    {(sources || []).map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
                                </select>
                            </label>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Delivery Note')}
                                <textarea className="fld" rows="2" value={form.data.delivery_note} onChange={(e) => form.setData('delivery_note', e.target.value)} />
                            </label>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Shipping Note')}
                                <textarea className="fld" rows="2" value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} />
                            </label>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Order Tags')}
                                <span style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                                    {(form.data.tags || []).map((tag) => (
                                        <span key={tag} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, background: '#FCE7F3', color: '#BE185D', borderRadius: 999, padding: '3px 9px', fontSize: 12, fontWeight: 600 }}>{tag} <button type="button" style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#BE185D', fontWeight: 700, padding: 0 }} onClick={() => form.setData('tags', form.data.tags.filter((x) => x !== tag))}>×</button></span>
                                    ))}
                                    <input className="fld" style={{ width: 150, marginTop: 0, height: 32 }} placeholder={t('Add tag + Enter')} value={tagInput}
                                        onChange={(e) => setTagInput(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (tagInput.trim()) { form.setData('tags', [...(form.data.tags || []), tagInput.trim()]); setTagInput(''); } } }} />
                                </span>
                            </label>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Record Advance Payment (৳)')}
                                <input className="fld" type="number" min="0" value={form.data.advance_payment} onChange={(e) => form.setData('advance_payment', e.target.value)} placeholder="0" />
                            </label>
                            <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Add Note')}
                                <textarea className="fld" rows="2" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder={t('Write a note for your team…')} />
                            </label>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 16 }}>
                                <button type="submit" className="ctl-btn w100" disabled={form.processing}>{t('Save Changes')}</button>
                                <button type="button" className="ctl-btn w100" onClick={() => sms('reminder')}>{t('Send Reminder SMS')}</button>
                                <button type="button" className="ctl-btn w100" onClick={() => sms('advance')}>{t('Send Advance SMS')}</button>
                                <Link href="/orders" className="ctl-btn w100">{t('Back to List')}</Link>
                            </div>
                            <div style={{ display: 'flex', gap: 14, marginTop: 12 }}>
                                <button type="button" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 12.5, color: '#64748B', padding: 0, fontFamily: 'inherit' }} onClick={() => block('phone')}>{t('Block Phone')}</button>
                                <button type="button" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 12.5, color: '#64748B', padding: 0, fontFamily: 'inherit' }} onClick={() => block('ip')}>{t('Block IP')}</button>
                                <button type="button" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 12.5, color: '#DC2626', padding: 0, fontFamily: 'inherit' }} onClick={() => setDeleteOpen(true)}>{t('Delete Order')}</button>
                            </div>
                        </form>

                        <div className="card">
                            <h3 style={{ marginBottom: 10 }}>{t('Delivery')}</h3>
                            {order.status === 'Processing' && (
                                <>
                                    {order.booking_status === 'failed' && <p className="sm" style={{ color: 'var(--bad)', marginBottom: 10 }}>{t('The last booking failed - see the reason in Ready to Ship, or try again.')}</p>}
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        <button className="btn-primary" style={{ width: '100%' }} onClick={() => setSendOpen(true)}>{t('Send to Courier')}</button>
                                        {order.booking_status === 'failed' && <button className="ctl-btn w100" onClick={() => router.post(`/delivery/${order.id}/retry`)}>{t('Retry')}</button>}
                                    </div>
                                    {order.courier_method_id && (
                                        <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Change courier to…')}
                                            <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                <select className="fld inp" style={{ flex: '1 1 170px' }} value={changeMethodId} onChange={(e) => setChangeMethodId(e.target.value)}>
                                                    <option value="">{t('Change courier to…')}</option>
                                                    {courierMethods.filter((m) => m.id !== order.courier_method_id).map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                                                </select>
                                                <button type="button" className="ctl-btn" style={{ marginTop: 6 }} disabled={!changeMethodId} onClick={() => router.post(`/delivery/${order.id}/change-courier`, { method_id: Number(changeMethodId) })}>{t('Change')}</button>
                                            </span>
                                        </label>
                                    )}
                                    <p className="sm mut" style={{ marginTop: 8 }}>{t('Changing the courier clears the pickup store and any tracking number.')}</p>
                                </>
                            )}
                            {(order.tracking_id || order.consignment_id) && (
                                <>
                                    <div className="sum-row"><span>{t('Courier')}</span><b style={{ textAlign: 'right', whiteSpace: 'normal', wordBreak: 'break-word' }}>{order.courier_label || order.delivery_method}</b></div>
                                    <div className="sum-row"><span>{t('CN / Tracking')}</span><b>{order.consignment_id || order.tracking_id}</b></div>
                                    {order.courier_status && <div className="sum-row"><span>{t('Courier Status')}</span><b>{order.courier_status}</b></div>}
                                    {order.pickup_store_name && <div className="sum-row"><span>{t('Pickup Store')}</span><b>{order.pickup_store_name}</b></div>}
                                    {order.shipped_at && <div className="sum-row"><span>{t('Shipped')}</span><b>{order.shipped_at}</b></div>}
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
                                        <Link href={`/orders/${order.id}/packing-slip`} className="ctl-btn w100">{t('Print Packing Slip')}</Link>
                                        <button className="ctl-btn w100" onClick={() => router.post(`/delivery/${order.id}/force-check`)}>{t('Check Status')}</button>
                                    </div>
                                </>
                            )}
                            {order.status === 'Pending' && <p className="sm mut">{t('Approve the order first - then it waits in Ready to Ship for the courier.')}</p>}
                            {['Shipped', 'Delivered', 'Returned'].includes(order.status) && !order.tracking_id && !order.consignment_id && (
                                <p className="sm mut">{t('This order was shipped before courier booking was tracked here.')}</p>
                            )}
                        </div>

                        <div className="card">
                            <h3 style={{ marginBottom: 10 }}>{t('Processing History')}</h3>
                            {order.processed_by && <div className="sum-row"><span>{t('Processed By')}</span><b>{order.processed_by}</b></div>}
                            {order.approved_at && <div className="sum-row"><span>{t('Approved')}</span><b>{order.approved_at}</b></div>}
                            {historyRows.map((e, i) => (
                                <div className="sum-row" key={i}><span style={{ color: 'var(--ink)' }}>{e.text}</span><b style={{ whiteSpace: 'nowrap' }}>{e.date} · {e.time}</b></div>
                            ))}
                            {historyRows.length === 0 && <div className="mut sm">{t('No activity yet')}</div>}
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
