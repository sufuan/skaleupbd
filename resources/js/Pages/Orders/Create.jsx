import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { fmt } from './ui';

export default function Create({ sources, products, couriers, defaults, toggles }) {
    const [mode, setMode] = useState('approved');
    const [picked, setPicked] = useState([]); // {product_id, name, sku, qty, price}
    const [custom, setCustom] = useState({ name: '', price: '' });
    const [query, setQuery] = useState('');

    const form = useForm({
        customer_name: '', customer_phone: '', customer_email: '', alt_phone: '',
        customer_address: '', district: '', area: '', source: '',
        delivery_method: couriers[0] || '', shipping_note: '', delivery_note: '',
        preorder_reason: 'Out of Stock', notify_date: '',
        discount: 0, delivery_charge: defaults.delivery_charge, advance: 0, txn_id: '',
        tags: [], items: [], attachment: null,
    });

    const itemsTotal = useMemo(() => picked.reduce((a, i) => a + i.price * i.qty, 0), [picked]);
    const grand = itemsTotal - Number(form.data.discount || 0) + Number(form.data.delivery_charge || 0);
    const due = grand - Number(form.data.advance || 0);

    const addProduct = (p) => {
        setPicked((prev) => {
            const ex = prev.find((i) => i.product_id === p.id);
            if (ex) return prev.map((i) => i.product_id === p.id ? { ...i, qty: i.qty + 1 } : i);
            return [...prev, { product_id: p.id, name: p.name, sku: p.sku, qty: 1, price: p.price }];
        });
    };
    const addCustom = () => {
        if (!custom.name || !custom.price) return;
        setPicked((prev) => [...prev, { product_id: null, name: custom.name, sku: '', qty: 1, price: Number(custom.price) }]);
        setCustom({ name: '', price: '' });
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, mode, items: picked })).post('/orders', { forceFormData: true });
    };

    const visible = products.filter((p) => !query || p.name.toLowerCase().includes(query.toLowerCase()) || p.sku.toLowerCase().includes(query.toLowerCase()));

    return (
        <AppLayout>
            <Head title={t('New Order')} />
            <h1 className="page-h">{t('New Order')}</h1>
            <div className="page-sub">{t('Create an order by hand — it lands in the same Order List as every other order.')}</div>

            <div className="ptabs">
                {[['manual', 'Manual'], ['approved', 'Approved'], ['preorder', 'Pre-Order']].map(([m, label]) => (
                    <span key={m} className={`ptab ${mode === m ? 'act' : ''}`} onClick={() => setMode(m)}>{t(label)}</span>
                ))}
            </div>

            <form onSubmit={submit}>
                <div className="ord-grid">
                    <div>
                        <div className="card mb12">
                            <h3>{t('Customer & Shipping')}</h3>
                            <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
                                <div className="frow"><label className="fl">{t('Mobile Number')} *</label>
                                    <input className="inp" placeholder="01XXXXXXXXX" value={form.data.customer_phone} onChange={(e) => form.setData('customer_phone', e.target.value)} />
                                    {form.errors.customer_phone && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.customer_phone}</div>}</div>
                                <div className="frow"><label className="fl">{t('Customer Name')} *</label>
                                    <input className="inp" value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} /></div>
                                {toggles.show_email && <div className="frow"><label className="fl">{t('Email')} ({t('Optional')})</label>
                                    <input className="inp" value={form.data.customer_email} onChange={(e) => form.setData('customer_email', e.target.value)} /></div>}
                                {toggles.show_alt_number && <div className="frow"><label className="fl">{t('Alternative Number')}</label>
                                    <input className="inp" value={form.data.alt_phone} onChange={(e) => form.setData('alt_phone', e.target.value)} /></div>}
                            </div>
                            <div className="frow"><label className="fl">{t('Address')} *</label>
                                <textarea className="inp" rows="2" value={form.data.customer_address} onChange={(e) => form.setData('customer_address', e.target.value)} /></div>
                            <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
                                <div className="frow"><label className="fl">{t('District')}</label>
                                    <input className="inp" value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} /></div>
                                <div className="frow"><label className="fl">{t('Area')}</label>
                                    <input className="inp" value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} /></div>
                            </div>
                            <div className="frow"><label className="fl">{t('Shipping Note')} ({t('Optional')})</label>
                                <textarea className="inp" rows="2" maxLength="350" value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} />
                                <div className="sm mut" style={{ textAlign: 'right' }}>{form.data.shipping_note.length}/350</div>
                                {form.errors.shipping_note && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.shipping_note}</div>}</div>
                            {mode === 'manual' ? (
                                <div className="note-box sm">{t('Courier will be assigned during Online Order approval.')}</div>
                            ) : (
                                <div className="frow"><label className="fl">{t('Delivery Method')} *</label>
                                    <select className="inp" value={form.data.delivery_method} onChange={(e) => form.setData('delivery_method', e.target.value)}>
                                        {couriers.map((c) => <option key={c} value={c}>{c}</option>)}
                                    </select></div>
                            )}
                            {mode === 'preorder' && (
                                <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
                                    <div className="frow"><label className="fl">{t('Preorder Reason')}</label>
                                        <select className="inp" value={form.data.preorder_reason} onChange={(e) => form.setData('preorder_reason', e.target.value)}>
                                            <option>Out of Stock</option><option>Others</option>
                                        </select></div>
                                    <div className="frow"><label className="fl">{t('Notification Date')}</label>
                                        <input className="inp" type="date" value={form.data.notify_date} onChange={(e) => form.setData('notify_date', e.target.value)} /></div>
                                </div>
                            )}
                            <div className="frow"><label className="fl">{t('Order Source')}{toggles.mandatory_source ? ' *' : ''}</label>
                                <select className="inp" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                    <option value="">—</option>
                                    {sources.map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
                                </select>
                                {form.errors.source && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.source}</div>}</div>
                            <div className="frow"><label className="fl">{t('Attachment')} (JPG, PNG, PDF · max 5MB)</label>
                                <input className="inp" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.svg,.pdf" onChange={(e) => form.setData('attachment', e.target.files[0] || null)} /></div>
                            {toggles.cross_sale && <div className="note-box sm">{t('Cross Sale is on — related products can be offered with this order.')}</div>}
                        </div>

                        <div className="card mb12">
                            <h3>{t('Click To Add Products')}</h3>
                            <div className="card-sub">{t('Search by code/SKU or name — stock shows live.')}</div>
                            <input className="inp" placeholder={t('Type to Search..')} value={query} onChange={(e) => setQuery(e.target.value)} />
                            <div className="prod-grid">
                                {visible.map((p) => (
                                    <button type="button" key={p.id} className="prod-cell" onClick={() => addProduct(p)}>
                                        <div className="b sm">{p.name}</div>
                                        <div className="sm mut">{p.sku} · {fmt(p.price)}</div>
                                        <div className="sm" style={{ color: p.stock <= 3 ? 'var(--bad)' : 'var(--ok)' }}>{t('Stock')}: {p.stock}</div>
                                    </button>
                                ))}
                                {visible.length === 0 && <div className="sm mut">{t('No products match that search')}</div>}
                            </div>
                            <div className="flex mt16">
                                <input className="inp" style={{ flex: 2 }} placeholder={t('Custom product name')} value={custom.name} onChange={(e) => setCustom({ ...custom, name: e.target.value })} />
                                <input className="inp" style={{ flex: 1 }} type="number" placeholder={t('Price')} value={custom.price} onChange={(e) => setCustom({ ...custom, price: e.target.value })} />
                                <button type="button" className="btn" onClick={addCustom}>{t('Add Custom Product')}</button>
                            </div>
                        </div>

                        <div className="card">
                            <h3>{t('Ordered Products')} — {picked.reduce((a, i) => a + i.qty, 0)} {t('item(s)')}</h3>
                            {picked.length === 0 && <div className="sm mut">{t('Search and add products from the Click To Add Products panel.')}</div>}
                            {picked.map((i, ix) => (
                                <div className="conn-row" key={ix}>
                                    <div style={{ flex: 1 }}><div className="b sm">{i.name}</div><div className="sm mut">{i.sku} · {fmt(i.price)}</div></div>
                                    <input className="inp" style={{ width: 70 }} type="number" min="1" value={i.qty}
                                        onChange={(e) => setPicked(picked.map((x, j) => j === ix ? { ...x, qty: Math.max(1, Number(e.target.value) || 1) } : x))} />
                                    <b>{fmt(i.price * i.qty)}</b>
                                    <button type="button" className="mini-link" onClick={() => setPicked(picked.filter((_, j) => j !== ix))}>{t('Remove')}</button>
                                </div>
                            ))}
                            {form.errors.items && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.items}</div>}
                        </div>
                    </div>

                    <div>
                        <div className="card mb12" style={{ position: 'sticky', top: 78 }}>
                            <h3>{t('Calculation Details')}</h3>
                            <div className="frow"><label className="fl">{t('Discount (৳)')}</label>
                                <input className="inp" type="number" min="0" value={form.data.discount} onChange={(e) => form.setData('discount', e.target.value)} /></div>
                            <div className="frow"><label className="fl">{t('Delivery Charge (৳)')}</label>
                                <input className="inp" type="number" min="0" value={form.data.delivery_charge} onChange={(e) => form.setData('delivery_charge', e.target.value)} /></div>
                            <div className="frow"><label className="fl">{t('Advance (৳)')}</label>
                                <input className="inp" type="number" min="0" value={form.data.advance} onChange={(e) => form.setData('advance', e.target.value)} /></div>
                            <div className="frow"><label className="fl">{t('Transaction ID')}</label>
                                <input className="inp" disabled={Number(form.data.advance || 0) <= 0} value={form.data.txn_id} onChange={(e) => form.setData('txn_id', e.target.value)} placeholder={Number(form.data.advance || 0) <= 0 ? t('Enter an advance first') : 'bKash TrxID'} />
                                {form.errors.txn_id && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.txn_id}</div>}</div>
                            <h3 className="mt16">{t('Order Summary')}</h3>
                            <div className="kv"><span>{t('Items Total')}</span><b>{fmt(itemsTotal)}</b></div>
                            <div className="kv"><span>{t('Discount')}</span><b>− {fmt(form.data.discount)}</b></div>
                            <div className="kv"><span>{t('Delivery Charge')}</span><b>{fmt(form.data.delivery_charge)}</b></div>
                            <div className="kv big"><span>{t('Grand Total')}</span><b>{fmt(grand)}</b></div>
                            <div className="kv"><span>{t('Advance')}</span><b>{fmt(form.data.advance)}</b></div>
                            <div className="kv"><span>{t('Due Amount')}</span><b>{fmt(due)}</b></div>
                            <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', marginTop: 14 }} disabled={form.processing || picked.length === 0}>
                                {mode === 'approved' ? t('Create Approved Order') : mode === 'preorder' ? t('Create Preorder') : t('Create Order')}
                            </button>
                            <div className="sm mut" style={{ textAlign: 'center', marginTop: 8 }}>{t('Order will be saved as soon as you click.')}</div>
                        </div>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}
