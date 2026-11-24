import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { fmt } from './ui';

const LAYOUTS = [
    ['v2', 'V2 Default', 'Two columns with the calculation details pinned in a sticky rail on the right, so the total and the submit button stay on screen.'],
    ['classic', 'V1 Classic', 'The legacy arrangement: full width, ordered products beside the picker, and the money fields in a band across the bottom. Scroll down to submit.'],
];
const TEMPLATES = [['default', 'Default Template'], ['compact', 'Compact Template'], ['wholesale', 'Wholesale Template']];
const DEFAULT_OPTS = { layout: 'v2', template: 'default' };
const PLUS = (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
);

export default function Create({ sources, products, couriers, defaults, toggles, allTags = [], paymentMethods = [] }) {
    const [mode, setMode] = useState('approved');
    const [layoutOpen, setLayoutOpen] = useState(false);
    const [opts, setOpts] = useState(() => {
        try {
            const saved = { ...DEFAULT_OPTS, ...JSON.parse(localStorage.getItem('skaleup_order_form_options') || '{}') };
            if (!LAYOUTS.some(([k]) => k === saved.layout)) saved.layout = 'v2';
            if (!TEMPLATES.some(([k]) => k === saved.template)) saved.template = 'default';
            return saved;
        } catch (e) { return DEFAULT_OPTS; }
    });
    useEffect(() => { localStorage.setItem('skaleup_order_form_options', JSON.stringify(opts)); }, [opts]);
    const tpl = opts.template;
    const hide = (name) => tpl === 'compact' && ['email', 'shipnote', 'attachment', 'cross'].includes(name);

    const [pop, setPop] = useState(null); // tag | ref | note | att | more
    const togglePop = (id) => setPop((p) => (p === id ? null : id));
    const [cross, setCross] = useState(!!toggles.cross_sale);
    const [picked, setPicked] = useState([]); // {product_id, name, sku, qty, price, stock}
    const [customOpen, setCustomOpen] = useState(false);
    const [custom, setCustom] = useState({ name: '', sku: '', price: 0, qty: 1 });
    const [qSku, setQSku] = useState('');
    const [qName, setQName] = useState('');
    const [favs, setFavs] = useState(() => new Set());
    const [favOnly, setFavOnly] = useState(false);
    const skuRef = useRef(null);
    const fileRef = useRef(null);

    const form = useForm({
        customer_name: '', customer_phone: '', customer_email: '',
        customer_address: '', district: '', area: '', source: '',
        delivery_method: couriers[0] || '', shipping_note: '', delivery_note: '',
        preorder_reason: 'Out of Stock', notify_date: '',
        discount: 0, delivery_charge: defaults.delivery_charge, advance: 0, txn_id: '',
        tags: [], payment_method: '', reference_link: '', invoice_prefix: '',
        items: [], attachment: null,
    });

    const itemsTotal = useMemo(() => picked.reduce((a, i) => a + i.price * i.qty, 0), [picked]);
    const grand = itemsTotal - Number(form.data.discount || 0) + Number(form.data.delivery_charge || 0);
    const due = grand - Number(form.data.advance || 0);
    const itemCount = picked.reduce((a, i) => a + i.qty, 0);

    const visible = products.filter((p) => {
        if (favOnly && !favs.has(p.id)) return false;
        if (qSku && !p.sku.toLowerCase().includes(qSku.toLowerCase())) return false;
        if (qName && !p.name.toLowerCase().includes(qName.toLowerCase())) return false;
        return true;
    });

    const addProduct = (p) => {
        setPicked((prev) => {
            const ex = prev.find((i) => i.product_id === p.id && !i.variant_id);
            if (ex) return prev.map((i) => i === ex ? { ...i, qty: i.qty + 1 } : i);
            return [...prev, { product_id: p.id, name: p.name, sku: p.sku, qty: 1, price: p.price, stock: p.stock, base_price: p.price, base_stock: p.stock, variant_id: null, variant_name: null, variants: p.variants || [] }];
        });
    };
    // Variant picker on a picked line: choosing a variant points the
    // line at that variant (its price + its own stock pool); clearing
    // it falls back to the product itself.
    const pickVariant = (ix, vid) => {
        setPicked((prev) => prev.map((i, j) => {
            if (j !== ix) return i;
            const v = (i.variants || []).find((x) => String(x.id) === String(vid));
            return v
                ? { ...i, variant_id: v.id, variant_name: v.name, price: v.price, stock: v.stock }
                : { ...i, variant_id: null, variant_name: null, price: i.base_price, stock: i.base_stock };
        }));
    };
    const addCustom = () => {
        if (!custom.name || Number(custom.price) < 0) return;
        setPicked((prev) => [...prev, { product_id: null, name: custom.name, sku: custom.sku || '', qty: Math.max(1, Number(custom.qty) || 1), price: Number(custom.price) || 0, stock: null }]);
        setCustom({ name: '', sku: '', price: 0, qty: 1 });
        setCustomOpen(false);
    };
    const toggleFav = (e, id) => {
        e.stopPropagation();
        setFavs((prev) => { const s = new Set(prev); if (s.has(id)) { s.delete(id); } else { s.add(id); } return s; });
    };
    const toggleTag = (tag) => {
        const cur = form.data.tags || [];
        form.setData('tags', cur.includes(tag) ? cur.filter((x) => x !== tag) : [...cur, tag]);
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, mode, items: picked, cross_sale: cross }));
        form.post('/orders', { forceFormData: true });
    };

    const submitLabel = mode === 'manual' ? t('Create Order') : mode === 'preorder' ? t('Create Pre-Order') : t('Create Approved Order');
    const err = (k) => form.errors[k] ? <div className="sm" style={{ color: 'var(--bad)', marginTop: 6 }}>{form.errors[k]}</div> : null;

    return (
        <AppLayout>
            <Head title={t('New Order')} />
            <div className="ord-form">
                <input ref={fileRef} type="file" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.svg,.pdf" onChange={(e) => form.setData('attachment', e.target.files[0] || null)} />
                <form onSubmit={submit}>
                    <div className={`ord-grid layout-${opts.layout}`}>
                        <div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16, flexWrap: 'wrap', background: 'var(--card)', border: '1px solid var(--card-line)', borderRadius: 16, padding: '10px 16px', boxShadow: '0 1px 2px rgba(17,24,39,.04),0 1px 3px rgba(17,24,39,.06)' }}>
                                <span className="seg" style={{ fontSize: 13.5 }}>
                                    <button type="button" className={mode === 'manual' ? 'on' : ''} onClick={() => setMode('manual')}>{t('Manual')}</button>
                                    <button type="button" className={mode === 'approved' ? 'on' : ''} onClick={() => setMode('approved')}>{t('Approved')}</button>
                                    <button type="button" className={mode === 'preorder' ? 'on' : ''} onClick={() => setMode('preorder')}>{t('Pre-Order')}</button>
                                </span>
                                <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                                    <button type="button" className="ctl-btn" style={{ borderColor: '#EEF2F7', borderRadius: 8, fontSize: 12 }} onClick={() => setLayoutOpen(true)}>{t('Layouts & Fields')}</button>
                                </span>
                            </div>

                            <div className="card" style={{ marginBottom: 16 }}>
                                <div className="fld-grid3" style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 16 }}>
                                    <label className="fld-l frow">{t('Mobile Number')} *<input className="fld" placeholder="01XXXXXXXXX" value={form.data.customer_phone} onChange={(e) => form.setData('customer_phone', e.target.value)} /></label>
                                    <label className="fld-l frow">{t('Customer Name')} *<input className="fld" placeholder={t('Customer name')} value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} /></label>
                                    {toggles.show_email && !hide('email') && (
                                        <label className="fld-l frow">{t('Email')} <span className="opt-tag">OPTIONAL</span><input className="fld" placeholder="customer@example.com" value={form.data.customer_email} onChange={(e) => form.setData('customer_email', e.target.value)} /></label>
                                    )}
                                    <label className="fld-l frow">{t('Address')} *<textarea className="fld" rows="6" placeholder={t('Enter address')} value={form.data.customer_address} onChange={(e) => form.setData('customer_address', e.target.value)} /></label>
                                    {!hide('shipnote') && (
                                        <label className="fld-l frow">{t('Shipping Note')} <span className="opt-tag">OPTIONAL</span> <span className="muted-sm">{form.data.shipping_note.length}/350</span><textarea className="fld" rows="6" maxLength="350" placeholder={t('Note printed on invoice / shared with courier')} value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} /></label>
                                    )}
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {mode === 'manual' ? (
                                            <div className="fld-l">{t('Delivery Method')}<div className="muted-sm" style={{ marginTop: 8, fontWeight: 400 }}>{t('Courier will be assigned during Online Order approval.')}</div></div>
                                        ) : (
                                            <label className="fld-l frow">{t('Delivery Method')} *<select className="fld" value={form.data.delivery_method} onChange={(e) => form.setData('delivery_method', e.target.value)}>
                                                <option value="">{t('Select delivery method')}</option>
                                                {couriers.map((c) => <option key={c} value={c}>{c}</option>)}
                                            </select></label>
                                        )}
                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                            <button type="button" className="ctl-btn" onClick={() => togglePop('tag')}>{t('Tag')}</button>
                                            {!hide('cross') && (
                                                <span className="fld-l" style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>{t('Cross Sale')} <button type="button" className={`switch ${cross ? 'on' : ''}`} onClick={() => setCross(!cross)} aria-label={t('Cross Sale')}></button></span>
                                            )}
                                        </div>
                                        <div style={{ display: 'flex', gap: 8 }}>
                                            <button type="button" className="ctl-btn" onClick={() => togglePop('ref')}>{t('Reference Link')}</button>
                                            <button type="button" className="ctl-btn" onClick={() => togglePop('note')}>{t('Note')}</button>
                                        </div>
                                        <div style={{ display: 'flex', gap: 8 }}>
                                            {!hide('attachment') && <button type="button" className="ctl-btn" onClick={() => togglePop('att')}>{t('Attachment')}</button>}
                                            <button type="button" className="ctl-btn" onClick={() => togglePop('more')}>{t('More')}</button>
                                        </div>
                                        {(mode === 'preorder') && (
                                            <label className="fld-l frow">{t('Preorder Reason')}<select className="fld" value={form.data.preorder_reason} onChange={(e) => form.setData('preorder_reason', e.target.value)}>
                                                <option value="">{t('Select reason')}</option><option value="Out of Stock">{t('Out of Stock')}</option><option value="Others">{t('Others')}</option>
                                            </select></label>
                                        )}
                                        {(mode === 'preorder') && (
                                            <label className="fld-l frow">{t('Notification Date')}<input className="fld" type="datetime-local" value={form.data.notify_date} onChange={(e) => form.setData('notify_date', e.target.value)} /></label>
                                        )}
                                    </div>
                                </div>
                                {err('customer_phone')}{err('customer_name')}{err('customer_address')}{err('shipping_note')}

                                {pop === 'tag' && (
                                    <div className="pop-panel"><b>{t('Order Tags')}</b><div className="muted-sm" style={{ margin: '6px 0' }}>{t('Select Custom Tags')}</div>
                                        <div className="tag-cloud">
                                            {allTags.length === 0 && <span className="muted-sm">{t('No tags yet - tags used on earlier orders will appear here.')}</span>}
                                            {allTags.map((tag) => <span key={tag} className={(form.data.tags || []).includes(tag) ? 'on' : ''} onClick={() => toggleTag(tag)}>{tag}</span>)}
                                        </div>
                                    </div>
                                )}
                                {pop === 'ref' && (
                                    <div className="pop-panel"><label className="fld-l frow">{t('Reference link')}<input className="fld" placeholder="https://…" value={form.data.reference_link} onChange={(e) => form.setData('reference_link', e.target.value)} /></label></div>
                                )}
                                {pop === 'note' && (
                                    <div className="pop-panel"><label className="fld-l frow">{t('Order note')}<textarea className="fld" rows="2" placeholder={t('Add an order note')} value={form.data.delivery_note} onChange={(e) => form.setData('delivery_note', e.target.value)} /></label></div>
                                )}
                                {pop === 'att' && (
                                    <div className="pop-panel"><b>{t('Upload Attachments')}</b>
                                        <div className="dashed-empty" style={{ marginTop: 8 }} onClick={() => fileRef.current && fileRef.current.click()}>
                                            {form.data.attachment ? form.data.attachment.name : <>{t('Drag and drop your files here, or click to select')}<br /><span className="muted-sm">{t('Supported formats: JPG, PNG, GIF, WEBP, AVIF, SVG, PDF (max 5MB)')}</span></>}
                                        </div>
                                        {err('attachment')}
                                    </div>
                                )}
                                {pop === 'more' && (
                                    <div className="pop-panel"><label className="fld-l frow">{t('Invoice prefix')}<input className="fld" placeholder={t('Optional invoice prefix')} value={form.data.invoice_prefix} onChange={(e) => form.setData('invoice_prefix', e.target.value)} /></label></div>
                                )}

                                <div className="pay-grid">
                                    <label className="fld-l frow">{t('Payment Method')} <span className="opt-tag">OPTIONAL</span><select className="fld" value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                        <option value="">{t('Select account')}</option>
                                        {paymentMethods.map((m) => <option key={m} value={m}>{t(m)}</option>)}
                                    </select></label>
                                    <label className="fld-l frow">{t('Order Source')}{toggles.mandatory_source ? ' *' : ''}<select className="fld" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                        <option value="">{t('Select order source')}</option>
                                        {sources.map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
                                    </select></label>
                                </div>
                                {err('source')}
                                {tpl === 'wholesale' && (
                                    <label className="fld-l frow" style={{ marginTop: 14 }}>{t('Delivery Note')}<textarea className="fld" rows="2" placeholder={t('Note for the delivery team')} value={form.data.delivery_note} onChange={(e) => form.setData('delivery_note', e.target.value)} /></label>
                                )}
                            </div>

                            <div className="card">
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <button type="button" className="pick-btn" onClick={() => skuRef.current && skuRef.current.focus()}>{PLUS} {t('Add Product')}</button>
                                    <button type="button" className="pick-btn" onClick={() => skuRef.current && skuRef.current.focus()}>{PLUS} {t('Scan Barcode')}</button>
                                    <button type="button" className="pick-btn" onClick={() => setCustomOpen(true)}>{PLUS} {t('Add Custom Product')}</button>
                                </div>
                                <div className="pick-grid">
                                    <div style={{ border: '1px solid #EEF2F7', borderRadius: 12, padding: 16 }}>
                                        <h3>{t('Click To Add Products')} <button type="button" className="ctl-btn" style={{ padding: '2px 8px' }} onClick={() => skuRef.current && skuRef.current.focus()}>?</button></h3>
                                        <div className="pick-search">
                                            <label className="fld-l">{t('Code/sku')}<input ref={skuRef} className="fld" placeholder={t('Type to Search..')} value={qSku} onChange={(e) => setQSku(e.target.value)} /></label>
                                            <label className="fld-l">{t('Name')}<input className="fld" placeholder={t('Type to Search..')} value={qName} onChange={(e) => setQName(e.target.value)} /></label>
                                            <button type="button" className="fav-btn" title={t('Favourites only')} onClick={() => setFavOnly(!favOnly)}>★</button>
                                        </div>
                                        <div className="prod-list">
                                            {visible.map((p) => {
                                                const added = picked.some((i) => i.product_id === p.id);
                                                // A product with variants sells from the variants'
                                                // own pools - the card counts those, not the parent row.
                                                const cardStock = (p.variants && p.variants.length) ? p.variants.reduce((a, v) => a + v.stock, 0) : p.stock;
                                                const dis = added || (cardStock === 0 && mode !== 'preorder');
                                                return (
                                                    <button type="button" key={p.id} className="prod-card" disabled={dis} onClick={() => addProduct(p)}>
                                                        {p.image && <img className="pc-photo" src={p.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none'; }} />}
                                                        <span className="pc-name">{p.name}</span>
                                                        <span className="muted-sm">SKU: {p.sku}</span>
                                                        <span className="muted-sm">{t('Price')}: {fmt(p.price)} · {t('Stock')}: {cardStock}{cardStock === 0 ? ` - ${t('Pre-order allowed')}` : ''}</span>
                                                        <span className="pc-fav" onClick={(e) => toggleFav(e, p.id)}>{favs.has(p.id) ? t('Remove from featured') : t('Mark as featured')}</span>
                                                    </button>
                                                );
                                            })}
                                            {visible.length === 0 && <div className="muted-sm" style={{ padding: '10px 2px' }}>{t('No products match that search')}</div>}
                                        </div>
                                    </div>
                                    <div style={{ border: '1px solid #EEF2F7', borderRadius: 12, padding: 16 }}>
                                        <h3>{t('Ordered Products')} <span className="muted-sm">{itemCount} {t('item(s)')}</span></h3>
                                        {picked.length === 0 && <div id="ordEmpty" className="empty-note" style={{ marginTop: 10 }}>{t('Search and add products from the "Click To Add Products" panel.')}</div>}
                                        {picked.map((i, ix) => (
                                            <div className="ord-row" key={ix}>
                                                <span><b>{i.name}</b><br /><span className="muted-sm">SKU {i.sku || '-'} · {fmt(i.price)} · {t('Stock')}:{i.stock === null ? '-' : i.stock}</span>
                                                    {(i.variants && i.variants.length > 0) && (
                                                        <><br /><select className="fld" style={{ marginTop: 6, maxWidth: 260 }} value={i.variant_id || ''} onChange={(e) => pickVariant(ix, e.target.value)}>
                                                            <option value="">{t('No variant')}</option>
                                                            {i.variants.map((v) => <option key={v.id} value={v.id}>{v.name} · {fmt(v.price)} · {t('Stock')}: {v.stock}</option>)}
                                                        </select></>
                                                    )}
                                                </span>
                                                <span className="qty">{t('Qty')} <button type="button" onClick={() => setPicked(picked.map((x, j) => j === ix ? { ...x, qty: Math.max(1, x.qty - 1) } : x))}>−</button><b>{i.qty}</b><button type="button" onClick={() => setPicked(picked.map((x, j) => j === ix ? { ...x, qty: x.qty + 1 } : x))}>+</button></span>
                                                <span className="qty">{t('Price')} <button type="button" onClick={() => setPicked(picked.map((x, j) => j === ix ? { ...x, price: Math.max(0, x.price - 10) } : x))}>−</button><b>{i.price}</b><button type="button" onClick={() => setPicked(picked.map((x, j) => j === ix ? { ...x, price: x.price + 10 } : x))}>+</button></span>
                                                <b>{t('Total')} {fmt(i.price * i.qty)}</b>
                                                <button type="button" className="ctl-btn" onClick={() => setPicked(picked.filter((_, j) => j !== ix))}>×</button>
                                            </div>
                                        ))}
                                        {err('items')}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div style={{ position: 'sticky', top: 61 }}>
                                <div className="card" style={{ marginBottom: 16 }}><h3>{t('Calculation Details')}</h3>
                                    <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Discount (৳)')}<input className="fld" type="number" min="0" value={form.data.discount} onChange={(e) => form.setData('discount', e.target.value)} /></label>
                                    <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Delivery Charge (৳)')}<input className="fld" type="number" min="0" value={form.data.delivery_charge} onChange={(e) => form.setData('delivery_charge', e.target.value)} /></label>
                                    <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Advance (৳)')}<input className="fld" type="number" min="0" value={form.data.advance} onChange={(e) => form.setData('advance', e.target.value)} /></label>
                                    <label className="fld-l frow" style={{ marginTop: 12 }}>{t('Transaction ID')}<input className="fld" placeholder={t('TrxId (bKash/Nagad/Bank)')} disabled={Number(form.data.advance || 0) <= 0} value={form.data.txn_id} onChange={(e) => form.setData('txn_id', e.target.value)} /></label>
                                    {err('txn_id')}
                                </div>
                                <div className="card"><h3>{t('Order Summary')}</h3>
                                    <div className="sum-row"><span>{t('Items Total')}</span><b>{fmt(itemsTotal)}</b></div>
                                    <div className="sum-row"><span>{t('Discount')}</span><b className="grn">{fmt(form.data.discount)}</b></div>
                                    <div className="sum-row"><span>{t('Delivery Charge')}</span><b>{fmt(form.data.delivery_charge)}</b></div>
                                    <div className="sum-row grand"><span>{t('Grand Total')}</span><b>{fmt(grand)}</b></div>
                                    <div className="sum-row"><span>{t('Advance')}</span><b className="grn">{fmt(form.data.advance)}</b></div>
                                    <div className="sum-row total"><span>{t('Due Amount')}</span><b>{fmt(due)}</b></div>
                                    <button type="submit" className="btn-primary" style={{ width: '100%', marginTop: 16, height: 44, fontSize: 14 }} disabled={form.processing || picked.length === 0}>{submitLabel}</button>
                                    <p className="empty-note" style={{ textAlign: 'center', marginTop: 8 }}>{t('Order will be saved as soon as you click.')}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                {customOpen && (
                    <div className="overlay" onClick={() => setCustomOpen(false)}>
                        <div className="modal" style={{ maxWidth: 480 }} onClick={(e) => e.stopPropagation()}>
                            <div style={{ display: 'flex', alignItems: 'center' }}><h3 style={{ fontSize: 17 }}>{t('Add Custom Product')}</h3><button type="button" className="mini-link" style={{ marginLeft: 'auto' }} onClick={() => setCustomOpen(false)}>✕</button></div>
                            <label className="fld-l" style={{ marginTop: 12 }}>{t('Product Name')} *<input className="fld" placeholder={t('e.g. Special Offer Box')} value={custom.name} onChange={(e) => setCustom({ ...custom, name: e.target.value })} /></label>
                            <label className="fld-l" style={{ marginTop: 12 }}>{t('SKU (optional)')}<input className="fld" placeholder={t('Auto-generated if left blank')} value={custom.sku} onChange={(e) => setCustom({ ...custom, sku: e.target.value })} /></label>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
                                <label className="fld-l">{t('Price (৳)')} *<input className="fld" type="number" min="0" value={custom.price} onChange={(e) => setCustom({ ...custom, price: e.target.value })} /></label>
                                <label className="fld-l">{t('Quantity')} *<input className="fld" type="number" min="1" value={custom.qty} onChange={(e) => setCustom({ ...custom, qty: e.target.value })} /></label>
                            </div>
                            <div style={{ display: 'flex', gap: 10, marginTop: 16, justifyContent: 'flex-end' }}>
                                <button type="button" className="ctl-btn" onClick={() => setCustomOpen(false)}>{t('Cancel')}</button>
                                <button type="button" className="btn-primary" onClick={addCustom}>{t('Add Product')}</button>
                            </div>
                        </div>
                    </div>
                )}

                {layoutOpen && (
                    <div className="overlay" onClick={() => setLayoutOpen(false)}>
                        <div className="modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()}>
                            <h3>{t('Order Form Options')}</h3>
                            <div className="sm mut mt8">{t('Layouts apply on wider screens. On a phone every layout stacks into one column.')}</div>
                            <h3 className="mt16" style={{ fontSize: 14 }}>{t('Layouts')}</h3>
                            {LAYOUTS.map(([key, name, desc]) => (
                                <label key={key} className="conn-row" style={{ cursor: 'pointer' }}>
                                    <input type="radio" name="layout" checked={opts.layout === key} onChange={() => setOpts({ ...opts, layout: key })} />
                                    <div style={{ flex: 1 }}><div className="b sm">{name} {opts.layout === key && <span className="pill green">{t('ACTIVE')}</span>}</div><div className="sm mut">{t(desc)}</div></div>
                                </label>
                            ))}
                            <h3 className="mt16" style={{ fontSize: 14 }}>{t('Fields Template')}</h3>
                            {TEMPLATES.map(([key, name]) => (
                                <label key={key} className="conn-row" style={{ cursor: 'pointer' }}>
                                    <input type="radio" name="tpl" checked={tpl === key} onChange={() => setOpts({ ...opts, template: key })} />
                                    <div style={{ flex: 1 }}><span className="b sm">{t(name)}</span> {tpl === key && <span className="pill green">{t('ACTIVE')}</span>}</div>
                                </label>
                            ))}
                            <div className="flex mt16" style={{ justifyContent: 'flex-end' }}>
                                <button type="button" className="btn pri" onClick={() => setLayoutOpen(false)}>{t('Close')}</button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
