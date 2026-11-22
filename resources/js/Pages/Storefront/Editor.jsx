import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

const TYPE_LABEL = { hero: 'Top banner', offer: 'Offer strip', products: 'Products', faq: 'Questions & answers', order_form: 'Order form' };
const ADDABLE = ['hero', 'offer', 'products', 'faq', 'order_form'];

export default function Editor({ page, previewUrl, products }) {
    const draft = page.draft || { version: 2, blocks: [] };
    const hasUnpublished = !!page.hasUnpublishedChanges;
    const versions = page.versions || [];
    const [blocks, setBlocks] = useState(() => JSON.parse(JSON.stringify(draft.blocks || [])));
    const [savedTick, setSavedTick] = useState(0);
    const [versionId, setVersionId] = useState('');

    const uploadImageFile = async (blockId, file) => {
        const fd = new FormData();
        fd.append('image', file);
        const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
        try {
            const res = await fetch('/storefront/images', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' } });
            if (res.ok) { const j = await res.json(); setProp(blockId, 'image', j.url); }
        } catch { /* keep the old image */ }
    };

    const setProp = (id, key, value) => setBlocks((bs) => bs.map((b) => b.id === id ? { ...b, props: { ...b.props, [key]: value } } : b));
    const move = (idx, dir) => setBlocks((bs) => {
        const next = [...bs];
        const j = idx + dir;
        if (j < 0 || j >= next.length) return bs;
        [next[idx], next[j]] = [next[j], next[idx]];
        return next;
    });
    const remove = async (id) => { if (await confirmDialog(t('Remove this section from the page?'))) setBlocks((bs) => bs.filter((b) => b.id !== id)); };
    const add = (type) => {
        const defaults = {
            hero: { title: '', subtitle: '', image: '', button_text: t('Order now') },
            offer: { text: '' },
            products: { heading: '', product_ids: [] },
            faq: { heading: '', items: [{ q: '', a: '' }] },
            order_form: { product_id: null, heading: '' },
        };
        setBlocks((bs) => [...bs, { id: `b${Date.now()}`, type, props: defaults[type] }]);
    };

    // The draft endpoint answers JSON ({ok, saved_at}).
    const saveDraft = async () => {
        const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
        try {
            const res = await fetch(`/storefront/pages/${page.id}/draft`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
                body: JSON.stringify({ blocks: { version: 2, blocks } }),
            });
            if (res.ok) setSavedTick(Date.now());
        } catch { /* the tick simply does not show */ }
    };
    const publish = () => router.post(`/storefront/pages/${page.id}/publish`, { blocks: { version: 2, blocks } }, { preserveScroll: true });
    const restore = () => { if (versionId) router.post(`/storefront/pages/${page.id}/restore/${versionId}`, {}, { preserveScroll: true }); };

    const blockForm = (b) => {
        const p = b.props || {};
        if (b.type === 'hero') return (
            <>
                <label className="lbl">{t('Big title')}</label>
                <input className="fld" maxLength={60} value={p.title || ''} onChange={(e) => setProp(b.id, 'title', e.target.value)} />
                <label className="lbl" style={{ marginTop: 10 }}>{t('Small line under it')}</label>
                <input className="fld" maxLength={120} value={p.subtitle || ''} onChange={(e) => setProp(b.id, 'subtitle', e.target.value)} />
                <label className="lbl" style={{ marginTop: 10 }}>{t('Picture (optional)')}</label>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 5 }}>
                    {p.image ? <img src={p.image} alt="" style={{ width: 74, height: 48, objectFit: 'cover', borderRadius: 6, border: '1px solid #E7DEF0' }} /> : null}
                    <input type="file" accept="image/*" onChange={(e) => {
                        const file = e.target.files && e.target.files[0];
                        if (file) uploadImageFile(b.id, file);
                    }} />
                </div>
                <label className="lbl" style={{ marginTop: 10 }}>{t('Button text')}</label>
                <input className="fld" maxLength={30} value={p.button_text || ''} onChange={(e) => setProp(b.id, 'button_text', e.target.value)} />
            </>
        );
        if (b.type === 'offer') return (
            <>
                <label className="lbl">{t('Offer text')}</label>
                <input className="fld" maxLength={80} value={p.text || ''} onChange={(e) => setProp(b.id, 'text', e.target.value)} placeholder={t('e.g. Eid offer — 20% off everything')} />
            </>
        );
        if (b.type === 'products') {
            const ids = p.product_ids || [];
            const toggle = (pid) => setProp(b.id, 'product_ids', ids.includes(pid) ? ids.filter((x) => x !== pid) : [...ids, pid]);
            return (
                <>
                    <label className="lbl">{t('Heading')}</label>
                    <input className="fld" maxLength={60} value={p.heading || ''} onChange={(e) => setProp(b.id, 'heading', e.target.value)} />
                    <p style={{ fontSize: 13.5, color: 'var(--muted)', margin: '10px 0 6px' }}>{t('Products come from your Inventory by themselves, with true prices. Tick products only if you want to show a few chosen ones — leave all unticked to show everything.')}</p>
                    <div style={{ display: 'grid', gap: 5, maxHeight: 190, overflowY: 'auto' }}>
                        {products.map((pr) => (
                            <label key={pr.id} style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 14, cursor: 'pointer' }}>
                                <input type="checkbox" checked={ids.includes(pr.id)} onChange={() => toggle(pr.id)} />
                                <span style={{ flex: 1 }}>{pr.name}</span>
                                <span style={{ color: 'var(--muted)', fontSize: 13 }}>৳{Number(pr.price).toLocaleString('en-IN')} · {t('Stock')} {pr.stock}</span>
                            </label>
                        ))}
                        {products.length === 0 && <span style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t('No products in Inventory yet.')}</span>}
                    </div>
                </>
            );
        }
        if (b.type === 'faq') {
            const items = p.items || [];
            const setItem = (i, key, value) => setProp(b.id, 'items', items.map((it, j) => j === i ? { ...it, [key]: value } : it));
            return (
                <>
                    <label className="lbl">{t('Heading')}</label>
                    <input className="fld" maxLength={60} value={p.heading || ''} onChange={(e) => setProp(b.id, 'heading', e.target.value)} />
                    {items.map((it, i) => (
                        <div key={i} style={{ border: '1px solid #EFE9F6', borderRadius: 8, padding: 10, marginTop: 10 }}>
                            <input className="fld" placeholder={t('Question')} maxLength={120} value={it.q || ''} onChange={(e) => setItem(i, 'q', e.target.value)} />
                            <input className="fld" placeholder={t('Answer')} maxLength={300} value={it.a || ''} onChange={(e) => setItem(i, 'a', e.target.value)} />
                            <button type="button" className="btn" style={{ marginTop: 8, color: '#B91C1C', borderColor: '#F3C2BF' }} onClick={() => setProp(b.id, 'items', items.filter((_, j) => j !== i))}>{t('Remove')}</button>
                        </div>
                    ))}
                    <button type="button" className="btn" style={{ marginTop: 10 }} onClick={() => setProp(b.id, 'items', [...items, { q: '', a: '' }])}>+ {t('Add question')}</button>
                </>
            );
        }
        if (b.type === 'order_form') return (
            <>
                <label className="lbl">{t('Heading')}</label>
                <input className="fld" maxLength={60} value={p.heading || ''} onChange={(e) => setProp(b.id, 'heading', e.target.value)} />
                <label className="lbl" style={{ marginTop: 10 }}>{t('Product for this form')}</label>
                <select className="fld" value={p.product_id || ''} onChange={(e) => setProp(b.id, 'product_id', e.target.value ? Number(e.target.value) : null)}>
                    <option value="">{t('The first product on this page')}</option>
                    {products.map((pr) => <option key={pr.id} value={pr.id}>{pr.name}</option>)}
                </select>
                <p style={{ fontSize: 13.5, color: 'var(--muted)', margin: '8px 0 0' }}>{t('Name, mobile number and address — the customer fills these in. Orders land in your Orders list like any other order.')}</p>
            </>
        );
        return null;
    };

    return (
        <AppLayout>
            <Head title={`${t('Edit page')} — ${page.name}`} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 6 }}>
                <Link className="btn" href="/storefront/pages">← {t('My Pages')}</Link>
                <h3 style={{ fontSize: 19, margin: 0 }}>{page.name}</h3>
                <span className={`pill ${page.status === 'live' ? 'green' : page.status === 'paused' ? '' : 'amber'}`}>{t(page.status === 'live' ? 'Live' : page.status === 'paused' ? 'Paused' : 'Draft')}</span>
                {hasUnpublished && <span className="pill amber">{t('Unpublished changes')}</span>}
                <span style={{ flex: 1 }} />
                <a className="btn" href={previewUrl} target="_blank" rel="noreferrer">{t('Preview')}</a>
                <button className="btn" onClick={saveDraft}>{t('Save draft')}</button>
                <button className="btn pri" onClick={publish}>{t('Publish changes')}</button>
            </div>
            <p style={{ fontSize: 13.5, color: 'var(--muted)', margin: '0 0 14px' }}>
                {t('Your link:')} <b>{page.url}</b> · {t('Changes go live only when you press Publish changes.')}
                {savedTick > 0 && <span style={{ color: '#15803d' }}> · {t('Draft saved.')}</span>}
            </p>

            <div style={{ display: 'grid', gap: 12 }}>
                {blocks.map((b, i) => (
                    <div key={b.id} className="card sf-form">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                            <span style={{ fontWeight: 700, fontSize: 15 }}>{t(TYPE_LABEL[b.type] || b.type)}</span>
                            <span style={{ flex: 1 }} />
                            <button type="button" className="btn" disabled={i === 0} onClick={() => move(i, -1)}>↑</button>
                            <button type="button" className="btn" disabled={i === blocks.length - 1} onClick={() => move(i, 1)}>↓</button>
                            <button type="button" className="btn" style={{ color: '#B91C1C', borderColor: '#F3C2BF' }} onClick={() => remove(b.id)}>{t('Remove')}</button>
                        </div>
                        {blockForm(b)}
                    </div>
                ))}
                {blocks.length === 0 && <div className="card" style={{ textAlign: 'center', color: 'var(--muted)' }}>{t('This page has no sections yet. Add one below.')}</div>}
            </div>

            <div className="card" style={{ marginTop: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                <span style={{ fontWeight: 600, fontSize: 14.5 }}>{t('Add a section:')}</span>
                {ADDABLE.map((type) => <button key={type} type="button" className="btn" onClick={() => add(type)}>+ {t(TYPE_LABEL[type])}</button>)}
            </div>

            {versions.length > 0 && (
                <div className="card" style={{ marginTop: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <span style={{ fontWeight: 600, fontSize: 14.5 }}>{t('Earlier versions:')}</span>
                    <select className="fld" style={{ width: 240, marginTop: 0 }} value={versionId} onChange={(e) => setVersionId(e.target.value)}>
                        <option value="">{t('Choose a version')}</option>
                        {versions.map((v) => <option key={v.id} value={v.id}>{v.at}</option>)}
                    </select>
                    <button type="button" className="btn" disabled={!versionId} onClick={restore}>{t('Restore this version')}</button>
                </div>
            )}

            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style>
        </AppLayout>
    );
}
