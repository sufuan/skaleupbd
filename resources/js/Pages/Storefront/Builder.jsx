import { useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const TYPES = [
    ['hero', 'Hero banner'],
    ['product_single', 'Single product'],
    ['product_grid', 'Product grid'],
    ['price_offer', 'Price / offer banner'],
    ['order_form', 'Order form'],
    ['testimonials', 'Testimonials'],
    ['faq', 'FAQ'],
    ['countdown', 'Countdown timer'],
    ['legal', 'Policy (Terms / Privacy / Return)'],
];
const typeLabel = (ty) => (TYPES.find(([k]) => k === ty) || [ty, ty])[1];
const rid = () => Math.random().toString(36).slice(2, 10);

const blankData = (ty) => ({
    hero: { title: '', subtitle: '', image: null, cta_text: 'Shop now', cta_product_id: null },
    product_single: { product_id: null },
    product_grid: { source: 'all', category_id: null, count: 8, title: 'Our products' },
    price_offer: { title: '', text: '', product_id: null },
    order_form: { product_id: null, title: 'Order now' },
    testimonials: { title: 'What customers say', items: [{ name: '', text: '' }] },
    faq: { title: 'Questions', items: [{ q: '', a: '' }] },
    countdown: { title: 'Offer ends in', ends_at: '' },
    legal: { kind: 'terms', title: 'Terms & Conditions', text: '' },
}[ty] || {});

function ProductSelect({ products, value, onChange, allowNone }) {
    return (
        <select className="fld" value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            {allowNone && <option value="">{t('— None —')}</option>}
            {!allowNone && <option value="">{t('— Choose a product —')}</option>}
            {products.map((p) => <option key={p.id} value={p.id}>{p.name} (৳{Number(p.price).toLocaleString('en-IN')})</option>)}
        </select>
    );
}

function BlockEditor({ block, setData, products, categories }) {
    const d = block.data || {};
    const F = ({ label, children }) => (
        <div style={{ marginBottom: 10 }}>
            <label className="lbl">{t(label)}</label>
            {children}
        </div>
    );
    switch (block.type) {
        case 'hero': return (<>
            <F label="Title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Subtitle"><textarea className="fld" rows={2} value={d.subtitle || ''} onChange={(e) => setData('subtitle', e.target.value)} /></F>
            <F label="Image">
                <HeroImage value={d.image} onChange={(path) => setData('image', path)} />
            </F>
            <F label="Button text"><input className="fld" value={d.cta_text || ''} onChange={(e) => setData('cta_text', e.target.value)} /></F>
            <F label="Button product (optional)"><ProductSelect products={products} value={d.cta_product_id} onChange={(v) => setData('cta_product_id', v)} allowNone /></F>
        </>);
        case 'product_single': return (
            <F label="Product"><ProductSelect products={products} value={d.product_id} onChange={(v) => setData('product_id', v)} /></F>
        );
        case 'product_grid': return (<>
            <F label="Section title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Show">
                <select className="fld" value={d.source || 'all'} onChange={(e) => setData('source', e.target.value)}>
                    <option value="all">{t('All products')}</option>
                    <option value="category">{t('One category')}</option>
                </select>
            </F>
            {d.source === 'category' && (
                <F label="Category">
                    <select className="fld" value={d.category_id ?? ''} onChange={(e) => setData('category_id', e.target.value ? Number(e.target.value) : null)}>
                        <option value="">{t('— Choose a category —')}</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </F>
            )}
            <F label="How many products"><input className="fld" type="number" min={1} max={24} value={d.count ?? 8} onChange={(e) => setData('count', Number(e.target.value))} /></F>
        </>);
        case 'price_offer': return (<>
            <F label="Title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Text"><textarea className="fld" rows={3} value={d.text || ''} onChange={(e) => setData('text', e.target.value)} placeholder={t('Simple HTML is allowed: <b>, <p>, <a href="https://…">')} /></F>
            <F label="Product (optional)"><ProductSelect products={products} value={d.product_id} onChange={(v) => setData('product_id', v)} allowNone /></F>
        </>);
        case 'order_form': return (<>
            <F label="Title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Product"><ProductSelect products={products} value={d.product_id} onChange={(v) => setData('product_id', v)} /></F>
        </>);
        case 'testimonials': return (<>
            <F label="Section title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            {(d.items || []).map((it, i) => (
                <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 6 }} className="sf-itemrow">
                    <input className="fld" placeholder={t('Customer name')} value={it.name} onChange={(e) => { const items = [...d.items]; items[i] = { ...it, name: e.target.value }; setData('items', items); }} />
                    <input className="fld" placeholder={t('What they said')} value={it.text} onChange={(e) => { const items = [...d.items]; items[i] = { ...it, text: e.target.value }; setData('items', items); }} />
                    <button type="button" className="btn" onClick={() => setData('items', d.items.filter((_, j) => j !== i))}>✕</button>
                </div>
            ))}
            <button type="button" className="btn" onClick={() => setData('items', [...(d.items || []), { name: '', text: '' }])}>{t('Add testimonial')}</button>
        </>);
        case 'faq': return (<>
            <F label="Section title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            {(d.items || []).map((it, i) => (
                <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 6 }} className="sf-itemrow">
                    <input className="fld" placeholder={t('Question')} value={it.q} onChange={(e) => { const items = [...d.items]; items[i] = { ...it, q: e.target.value }; setData('items', items); }} />
                    <input className="fld" placeholder={t('Answer')} value={it.a} onChange={(e) => { const items = [...d.items]; items[i] = { ...it, a: e.target.value }; setData('items', items); }} />
                    <button type="button" className="btn" onClick={() => setData('items', d.items.filter((_, j) => j !== i))}>✕</button>
                </div>
            ))}
            <button type="button" className="btn" onClick={() => setData('items', [...(d.items || []), { q: '', a: '' }])}>{t('Add question')}</button>
        </>);
        case 'countdown': return (<>
            <F label="Title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Ends at"><input className="fld" type="datetime-local" value={(d.ends_at || '').slice(0, 16)} onChange={(e) => setData('ends_at', e.target.value)} /></F>
        </>);
        case 'legal': return (<>
            <F label="Policy">
                <select className="fld" value={d.kind || 'terms'} onChange={(e) => setData('kind', e.target.value)}>
                    <option value="terms">{t('Terms & Conditions')}</option>
                    <option value="privacy">{t('Privacy Policy')}</option>
                    <option value="return">{t('Return Policy')}</option>
                </select>
            </F>
            <F label="Title"><input className="fld" value={d.title || ''} onChange={(e) => setData('title', e.target.value)} /></F>
            <F label="Text"><textarea className="fld" rows={5} value={d.text || ''} onChange={(e) => setData('text', e.target.value)} placeholder={t('Simple HTML is allowed: <b>, <p>, <a href="https://…">')} /></F>
        </>);
        default: return null;
    }
}

function HeroImage({ value, onChange }) {
    const ref = useRef(null);
    const [busy, setBusy] = useState(false);
    const upload = async (file) => {
        if (!file) return;
        setBusy(true);
        try {
            const fd = new FormData();
            fd.append('image', file);
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await fetch('/storefront/images', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (json.path) onChange(json.path);
        } finally { setBusy(false); }
    };
    return (
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            {value && <img src={`/storage/${value}`} alt="" style={{ width: 74, height: 52, objectFit: 'cover', borderRadius: 8, border: '1px solid var(--border, #E7DEF0)' }} />}
            <input ref={ref} type="file" accept="image/*" style={{ display: 'none' }} onChange={(e) => upload(e.target.files?.[0])} />
            <button type="button" className="btn" disabled={busy} onClick={() => ref.current?.click()}>{busy ? t('Uploading…') : t('Upload image')}</button>
            {value && <button type="button" className="btn" onClick={() => onChange(null)}>{t('Remove')}</button>}
        </div>
    );
}

export default function Builder({ sf, blocks: initial, products, categories }) {
    const [blocks, setBlocks] = useState(initial || []);
    const [newType, setNewType] = useState('product_grid');
    const form = useForm({});

    const setBlockData = (idx, key, value) => {
        setBlocks((bs) => bs.map((b, i) => (i === idx ? { ...b, data: { ...b.data, [key]: value } } : b)));
    };
    const move = (idx, dir) => {
        setBlocks((bs) => {
            const next = [...bs];
            const j = idx + dir;
            if (j < 0 || j >= next.length) return bs;
            [next[idx], next[j]] = [next[j], next[idx]];
            return next;
        });
    };
    const remove = (idx) => setBlocks((bs) => bs.filter((_, i) => i !== idx));
    const add = () => setBlocks((bs) => [...bs, { id: rid(), type: newType, data: blankData(newType) }]);
    const save = () => {
        form.transform(() => ({ blocks }));
        form.post('/storefront/builder', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('Page Builder')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
                <h3 style={{ fontSize: 19, margin: 0, flex: 1 }}>{t('Page Builder')}</h3>
                {sf.subdomainUrl && <a className="btn" href={sf.subdomainUrl} target="_blank" rel="noreferrer">{t('Preview shop')}</a>}
                <button className="btn pri" onClick={save} disabled={form.processing}>{t('Save page')}</button>
            </div>
            <p style={{ fontSize: 14, color: 'var(--muted)', marginTop: 0, maxWidth: 720 }}>
                {t('Your page is a list of blocks. Add blocks, move them up or down, and edit each one. Prices and stock always come live from your products — they are never typed here.')}
            </p>

            <div style={{ display: 'grid', gap: 12, maxWidth: 820 }}>
                {blocks.map((b, i) => (
                    <div key={b.id || i} className="card" data-block-type={b.type}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                            <b style={{ fontSize: 14.5, flex: 1 }}>{t(typeLabel(b.type))}</b>
                            <button type="button" className="btn" onClick={() => move(i, -1)} disabled={i === 0} aria-label="Move up">↑</button>
                            <button type="button" className="btn" onClick={() => move(i, 1)} disabled={i === blocks.length - 1} aria-label="Move down">↓</button>
                            <button type="button" className="btn" onClick={() => remove(i)}>{t('Delete')}</button>
                        </div>
                        <BlockEditor block={b} products={products} categories={categories} setData={(k, v) => setBlockData(i, k, v)} />
                    </div>
                ))}
                {blocks.length === 0 && <div className="card" style={{ color: 'var(--muted)', fontSize: 14.5 }}>{t('No blocks yet. Add your first block below.')}</div>}
                <div className="card" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    <select className="fld" style={{ flex: '1 1 220px' }} value={newType} onChange={(e) => setNewType(e.target.value)}>
                        {TYPES.map(([k, label]) => <option key={k} value={k}>{t(label)}</option>)}
                    </select>
                    <button type="button" className="btn pri" onClick={add}>{t('Add block')}</button>
                    <button type="button" className="btn pri" onClick={save} disabled={form.processing}>{t('Save page')}</button>
                </div>
            </div>
            <style>{`@media (max-width: 760px){ .sf-itemrow{grid-template-columns:1fr !important} }`}</style>
        </AppLayout>
    );
}
