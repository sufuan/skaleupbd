import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import ProductFields, { BTN_OUTLINE, BTN_PRIMARY, INPUT } from './ProductFields';

// Products page — structure copied from the prototype's inv-products panel:
// header actions, 4 stat chips, 8 tabs, toolbar + data table + pagination.
const TABS = ['Products', 'Inactive Products', 'Combo Products', 'Wraps', 'Shared Inventory', 'Stock Links', 'Variations', 'Categories & Brands'];
const money = (n) => '৳' + Number(n || 0).toLocaleString('en-IN');

const TAB_BTN = (active) => `inline-flex items-center justify-center text-sm font-medium ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 group relative h-full whitespace-nowrap rounded-md border px-3 py-1.5 transition-colors duration-200 ${active ? 'border-primary/30 bg-primary/10 text-primary' : 'border-transparent bg-background text-muted-foreground hover:text-foreground/80'}`;
const TH = 'h-12 px-4 text-left align-middle font-medium text-muted-foreground';
const TD = 'p-4 align-middle';

function Pill({ on }) {
    return <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ${on ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'}`}>{on ? 'ON' : 'OFF'}</span>;
}

function Switch({ checked, onClick, label }) {
    return (
        <button type="button" role="switch" aria-checked={checked} aria-label={label} onClick={onClick}
            className={`inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${checked ? 'bg-primary' : 'bg-input'}`}>
            <span className={`pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform ${checked ? 'translate-x-5' : 'translate-x-0'}`} />
        </button>
    );
}

export default function Products({ products, summary, inactive, combos, wraps, sharedGroups, linkGroups, variants, categories, brands, filters }) {
    const [tab, setTab] = useState('Products');
    const [search, setSearch] = useState(filters.search || '');
    const [menuFor, setMenuFor] = useState(null);
    const [editFor, setEditFor] = useState(null);
    const [adjustFor, setAdjustFor] = useState(null);
    const allRows = products.data;

    const doSearch = (e) => { e.preventDefault(); router.get('/inventory/products', { search }, { preserveState: true }); };
    const toggle = (p, field) => router.post(`/inventory/products/${p.id}/toggle`, { field }, { preserveScroll: true });
    const adjust = (p, delta) => router.post(`/inventory/products/${p.id}/adjust`, { delta, reason: 'Quick Adjust' }, { preserveScroll: true });
    const nameOf = (id) => (allRows.find((r) => r.id === id)?.name) || (combos.find((r) => r.id === id)?.name) || (wraps.find((r) => r.id === id)?.name) || ('#' + id);

    const table = (rows, emptyText) => (
        <div className="rounded-md border overflow-x-auto">
            <table className="w-full caption-bottom text-sm">
                <thead>
                    <tr className="border-b transition-colors hover:bg-muted/50">
                        <th className={TH}></th><th className={TH}>{t('Code')}</th><th className={TH}>{t('Product')}</th>
                        <th className={TH}>{t('Manage Stock')}</th><th className={TH}>{t('Cost Price')}</th><th className={TH}>{t('Stock Qty')}</th>
                        <th className={TH}>{t('Selling Price')}</th><th className={TH}>{t('Active')}</th><th className={TH}>{t('Actions')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((p) => (
                        <tr key={p.id} className="border-b transition-colors hover:bg-muted/50">
                            <td className={TD}><span className="block h-4 w-4 rounded-sm border border-primary" /></td>
                            <td className={TD}><div className="font-medium">{p.sku}</div></td>
                            <td className={TD}>
                                <div className="flex items-start gap-3">
                                    {p.image
                                        ? <img src={p.image.startsWith('http') ? p.image : '/storage/' + p.image} alt="" className="h-10 w-10 rounded-md border object-cover" />
                                        : <div className="flex items-center justify-center border border-gray-200 bg-gray-50 h-10 w-10 rounded-md text-gray-400 text-xs">IMG</div>}
                                    <div className="min-w-0 space-y-1">
                                        <div className="block max-w-[260px] truncate font-medium">{p.name}</div>
                                        {p.featured && <span className="text-[11px] text-amber-600">★ {t('Featured')}</span>}
                                    </div>
                                </div>
                            </td>
                            <td className={TD}><div className="flex justify-center"><button type="button" onClick={() => toggle(p, 'manage_stock')}><Pill on={p.manage_stock} /></button></div></td>
                            <td className={TD}><div className="font-medium">{Number(p.cost || 0).toLocaleString('en-IN')}</div></td>
                            <td className={TD}>
                                <div className="flex items-center gap-2">
                                    <div className="flex items-center rounded-full bg-slate-100 p-0.5 shadow-sm">
                                        <button type="button" aria-label={t('Decrease Stock')} className="inline-flex items-center justify-center h-6 w-6 rounded-full hover:bg-white text-sm" onClick={() => adjust(p, -1)}>−</button>
                                        <span className="min-w-[2rem] text-center font-medium">{p.stock_qty}</span>
                                        <button type="button" aria-label={t('Increase Stock')} className="inline-flex items-center justify-center h-6 w-6 rounded-full hover:bg-white text-sm" onClick={() => adjust(p, 1)}>+</button>
                                    </div>
                                    <button type="button" className="text-xs text-primary font-medium" onClick={() => setAdjustFor(p)}>{t('Adjust')}</button>
                                </div>
                                {p.reserved_qty > 0 && <div className="text-[11px] text-muted-foreground">{t('Reserved')}: {p.reserved_qty}</div>}
                            </td>
                            <td className={TD}><div className="font-medium">{Number(p.price || 0).toLocaleString('en-IN')}</div></td>
                            <td className={TD}><Switch checked={p.is_active} label={t('Active')} onClick={() => toggle(p, 'is_active')} /></td>
                            <td className={TD}>
                                <div className="relative">
                                    <button type="button" className={BTN_OUTLINE + ' h-8 px-2'} onClick={() => setMenuFor(menuFor === p.id ? null : p.id)}>•••</button>
                                    {menuFor === p.id && (
                                        <div className="absolute right-0 z-30 mt-1 w-44 rounded-md border bg-white p-1 shadow-md text-sm">
                                            <button type="button" className="block w-full rounded px-2 py-1.5 text-left hover:bg-accent" onClick={() => { setEditFor(p); setMenuFor(null); }}>{t('Edit')}</button>
                                            <button type="button" className="block w-full rounded px-2 py-1.5 text-left hover:bg-accent" onClick={() => { router.post(`/inventory/products/${p.id}/copy`, {}, { preserveScroll: true }); setMenuFor(null); }}>{t('Copy')}</button>
                                            <button type="button" className="block w-full rounded px-2 py-1.5 text-left hover:bg-accent" onClick={() => { setTab('Variations'); setMenuFor(null); }}>{t('Variant')}</button>
                                            <button type="button" className="block w-full rounded px-2 py-1.5 text-left text-red-600 hover:bg-red-50" onClick={() => { if (confirm(t('Delete this product?'))) router.delete(`/inventory/products/${p.id}`, { preserveScroll: true }); setMenuFor(null); }}>{t('Delete')}</button>
                                        </div>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <tr><td colSpan="9" className="p-8 text-center text-muted-foreground">{emptyText}</td></tr>
                    )}
                </tbody>
            </table>
        </div>
    );

    return (
        <AppLayout title={t('Products')}>
            <div className="invx">
                <div className="container mx-auto px-2 sm:px-6 py-4 sm:py-6 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">{t('Products')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Manage your product catalog, combos, and inventory')}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className={BTN_OUTLINE + ' h-9 px-3'}>{t('Guide')}</span>
                            <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => router.reload({ preserveScroll: true })}>{t('Sync Products')}</button>
                            <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => {
                                const csv = ['Code,Product,Cost Price,Stock Qty,Selling Price', ...allRows.map((p) => [p.sku, '"' + p.name.replace(/"/g, '""') + '"', p.cost, p.stock_qty, p.price].join(','))].join('\n');
                                const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'products.csv'; a.click(); URL.revokeObjectURL(a.href);
                            }}>{t('Import / Export')}</button>
                            <Link href="/inventory/products/create" className={BTN_PRIMARY + ' h-9 px-3'}>{t('Add Product')}</Link>
                        </div>
                    </div>

                    <div className="flex gap-1 overflow-x-auto pb-1" role="tablist">
                        {TABS.map((name) => (
                            <button key={name} role="tab" aria-selected={tab === name} className={TAB_BTN(tab === name)} onClick={() => setTab(name)}>
                                <span className="transition-colors">{t(name)}</span>
                            </button>
                        ))}
                    </div>

                    {tab === 'Products' && (
                        <div className="space-y-2 sm:space-y-4">
                            <div className="bg-card grid w-full grid-cols-2 gap-2 rounded-xl border p-2 text-sm sm:gap-3 sm:p-3 xl:grid-cols-4">
                                <div className="bg-muted/40 w-full rounded-lg px-3 py-2 sm:px-4 sm:py-3"><span className="text-muted-foreground text-xs">{t('Products')}</span><div className="font-semibold">{summary.products}</div></div>
                                <div className="bg-muted/40 w-full rounded-lg px-3 py-2 sm:px-4 sm:py-3"><span className="text-muted-foreground text-xs">{t('Stock')}</span><div className="font-semibold">{summary.total_stock}</div></div>
                                <div className="bg-muted/40 w-full rounded-lg px-3 py-2 sm:px-4 sm:py-3"><span className="text-muted-foreground text-xs">{t('Sell Value')}</span><div className="font-semibold text-emerald-600">{money(summary.sell_value)}</div></div>
                                <div className="bg-muted/40 w-full rounded-lg px-3 py-2 sm:px-4 sm:py-3"><span className="text-muted-foreground text-xs">{t('Cost Value')}</span><div className="font-semibold text-amber-600">{money(summary.cost_value)}</div></div>
                            </div>
                            <div className="border-border/70 flex flex-col gap-3 border-b pb-3">
                                <form className="flex flex-wrap items-start gap-2" onSubmit={doSearch}>
                                    <div className="relative w-full sm:w-64">
                                        <input aria-label={t('Search products')} className={INPUT + ' h-9 rounded-md pr-7 pl-8 text-sm'} placeholder={t('Search products by SKU or name...')} value={search} onChange={(e) => setSearch(e.target.value)} />
                                    </div>
                                    <button type="submit" className={BTN_OUTLINE + ' h-9 px-3'}>{t('Filter')}</button>
                                    <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => { setSearch(''); router.get('/inventory/products', {}, { preserveState: true }); }}>{t('Clear')}</button>
                                </form>
                            </div>
                            {table(allRows, t('No products found'))}
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">{products.from || 0} – {products.to || 0} / {products.total}</span>
                                <div className="flex gap-1">
                                    {products.links.map((l, i) => (
                                        <button key={i} type="button" disabled={!l.url} onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}
                                            className={`h-8 min-w-8 rounded-md border px-2 ${l.active ? 'bg-primary text-primary-foreground' : 'bg-background'} disabled:opacity-40`}
                                            dangerouslySetInnerHTML={{ __html: l.label }} />
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'Inactive Products' && table(inactive, t('No inactive products'))}

                    {tab === 'Combo Products' && (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {combos.map((c) => (
                                <div key={c.id} className="rounded-xl border bg-card p-4 space-y-2">
                                    <div className="font-semibold">{c.name}</div>
                                    <div className="text-xs text-muted-foreground">{c.sku} · {money(c.price)}</div>
                                    <div className="text-sm space-y-1">
                                        {c.components.map((comp, i) => <div key={i} className="flex justify-between"><span>{nameOf(comp.product_id)}</span><span>× {comp.qty}</span></div>)}
                                        {c.components.length === 0 && <div className="text-muted-foreground">{t('No components yet')}</div>}
                                    </div>
                                </div>
                            ))}
                            {combos.length === 0 && <div className="text-muted-foreground text-sm">{t('No combo products yet. Set a product type to Combo when creating or editing.')}</div>}
                        </div>
                    )}

                    {tab === 'Wraps' && table(wraps, t('No wraps yet'))}

                    {tab === 'Shared Inventory' && <Groups kind="shared" groups={sharedGroups} allRows={[...allRows, ...combos, ...wraps]} />}
                    {tab === 'Stock Links' && <Groups kind="links" groups={linkGroups} allRows={[...allRows, ...combos, ...wraps]} />}

                    {tab === 'Variations' && <Variations allRows={allRows} variants={variants} />}

                    {tab === 'Categories & Brands' && <Taxonomy categories={categories} brands={brands} />}
                </div>
            </div>

            {editFor && <EditModal product={editFor} categories={categories} brands={brands} close={() => setEditFor(null)} />}
            {adjustFor && <AdjustModal product={adjustFor} close={() => setAdjustFor(null)} />}
        </AppLayout>
    );
}

function Groups({ kind, groups, allRows }) {
    const [name, setName] = useState('');
    const [picked, setPicked] = useState([]);
    const nameOf = (id) => allRows.find((r) => r.id === id)?.name || ('#' + id);
    const create = () => { if (name.trim() && picked.length >= 2) router.post(`/inventory/groups/${kind}`, { name: name.trim(), product_ids: picked }, { preserveScroll: true }); };
    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                {groups.map((g) => (
                    <div key={g.id} className="rounded-xl border bg-card p-4 space-y-2">
                        <div className="flex items-center justify-between">
                            <div className="font-semibold">{g.name}</div>
                            <button type="button" className="text-xs text-red-600" onClick={() => { if (confirm(t('Delete this group?'))) router.delete(`/inventory/groups/${kind}/${g.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                        </div>
                        <div className="flex flex-wrap gap-1">
                            {g.product_ids.map((id) => <span key={id} className="rounded-full border px-2 py-0.5 text-xs">{nameOf(id)}</span>)}
                        </div>
                    </div>
                ))}
                {groups.length === 0 && <div className="text-sm text-muted-foreground">{t('No groups yet')}</div>}
            </div>
            <div className="rounded-xl border bg-card p-4 space-y-3">
                <div className="font-semibold text-sm">{t('Create Group')}</div>
                <input className={INPUT} placeholder={t('Group name')} value={name} onChange={(e) => setName(e.target.value)} />
                <div className="flex flex-wrap gap-1">
                    {allRows.map((p) => (
                        <button key={p.id} type="button" onClick={() => setPicked(picked.includes(p.id) ? picked.filter((x) => x !== p.id) : [...picked, p.id])}
                            className={`rounded-full border px-2 py-0.5 text-xs ${picked.includes(p.id) ? 'bg-primary text-primary-foreground' : ''}`}>{p.name}</button>
                    ))}
                </div>
                <button type="button" className={BTN_PRIMARY + ' h-9'} onClick={create}>{t('Create Group')}</button>
            </div>
        </div>
    );
}

function Variations({ allRows, variants }) {
    const [formFor, setFormFor] = useState(null);
    const [vname, setVname] = useState('');
    const [vprice, setVprice] = useState('');
    const [vstock, setVstock] = useState(0);
    return (
        <div className="space-y-3">
            {allRows.map((p) => (
                <div key={p.id} className="rounded-xl border bg-card p-4 space-y-2">
                    <div className="flex items-center justify-between">
                        <div className="font-semibold text-sm">{p.name} <span className="text-muted-foreground font-normal">({p.sku})</span></div>
                        <button type="button" className="text-xs text-primary font-medium" onClick={() => setFormFor(formFor === p.id ? null : p.id)}>+ {t('Add Variant')}</button>
                    </div>
                    {(variants[p.id] || []).map((v) => (
                        <div key={v.id} className="flex items-center justify-between text-sm border-t pt-2">
                            <span>{v.name}</span>
                            <span className="text-muted-foreground">{v.sku || ''} · {v.price ? money(v.price) : ''} · {t('Stock')}: {v.stock_qty}</span>
                            <button type="button" className="text-xs text-red-600" onClick={() => router.delete(`/inventory/variants/${v.id}`, { preserveScroll: true })}>{t('Delete')}</button>
                        </div>
                    ))}
                    {(variants[p.id] || []).length === 0 && <div className="text-xs text-muted-foreground">{t('No variants')}</div>}
                    {formFor === p.id && (
                        <div className="flex flex-wrap gap-2 border-t pt-3">
                            <input className={INPUT + ' flex-1 min-w-[160px]'} placeholder={t('e.g. Size: L / Color: Red')} value={vname} onChange={(e) => setVname(e.target.value)} />
                            <input className={INPUT + ' w-28'} type="number" placeholder={t('Price')} value={vprice} onChange={(e) => setVprice(e.target.value)} />
                            <input className={INPUT + ' w-24'} type="number" placeholder={t('Stock')} value={vstock} onChange={(e) => setVstock(e.target.value)} />
                            <button type="button" className={BTN_PRIMARY + ' h-10'} onClick={() => { if (vname.trim()) { router.post(`/inventory/products/${p.id}/variants`, { name: vname.trim(), price: vprice || null, stock_qty: vstock }, { preserveScroll: true }); setVname(''); setVprice(''); setVstock(0); } }}>{t('Add')}</button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

function Taxonomy({ categories, brands }) {
    const [cat, setCat] = useState('');
    const [brand, setBrand] = useState('');
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-xl border bg-card p-4 space-y-3">
                <div className="font-semibold">{t('Categories')} ({categories.length})</div>
                {categories.map((c) => (
                    <div key={c.id} className="flex items-center justify-between text-sm border-t pt-2">
                        <span>{c.name}</span>
                        <button type="button" className="text-xs text-red-600" onClick={() => { if (confirm(t('Delete this category?'))) router.delete(`/inventory/categories/${c.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                    </div>
                ))}
                <div className="flex gap-2">
                    <input className={INPUT} placeholder={t('New category')} value={cat} onChange={(e) => setCat(e.target.value)} />
                    <button type="button" className={BTN_PRIMARY + ' h-10'} onClick={() => { if (cat.trim()) { router.post('/inventory/categories', { name: cat.trim() }, { preserveScroll: true }); setCat(''); } }}>{t('Add')}</button>
                </div>
            </div>
            <div className="rounded-xl border bg-card p-4 space-y-3">
                <div className="font-semibold">{t('Brands')} ({brands.length})</div>
                {brands.map((b) => (
                    <div key={b.id} className="flex items-center justify-between text-sm border-t pt-2">
                        <span>{b.name}</span>
                        <button type="button" className="text-xs text-red-600" onClick={() => { if (confirm(t('Delete this brand?'))) router.delete(`/inventory/brands/${b.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                    </div>
                ))}
                <div className="flex gap-2">
                    <input className={INPUT} placeholder={t('New brand')} value={brand} onChange={(e) => setBrand(e.target.value)} />
                    <button type="button" className={BTN_PRIMARY + ' h-10'} onClick={() => { if (brand.trim()) { router.post('/inventory/brands', { name: brand.trim() }, { preserveScroll: true }); setBrand(''); } }}>{t('Add')}</button>
                </div>
            </div>
        </div>
    );
}

function EditModal({ product, categories, brands, close }) {
    // NOTE: submit via POST + _method spoofing — PHP cannot parse a
    // multipart/form-data body on a real PATCH request (the name field
    // would arrive empty), so forceFormData must go through POST.
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PATCH',
        name: product.name, sku: product.sku, price: product.price, cost: product.cost || '',
        original_price: product.original_price || '', stock_qty: product.stock_qty, alert_qty: product.alert_qty,
        manage_stock: product.manage_stock, featured: product.featured, is_active: product.is_active,
        type: product.type, category_id: product.category_id || '', brand_id: product.brand_id || '',
        weight: product.weight || '', weight_unit: product.weight_unit || 'kg',
        short_description: product.short_description || '', long_description: product.long_description || '',
        shipping_note: product.shipping_note || '', admin_note: product.admin_note || '',
        warranty_policy: product.warranty_policy || '', warranty_coverage: product.warranty_coverage || '',
        gallery: product.gallery || [], image: null,
    });
    const submit = (e) => { e.preventDefault(); post(`/inventory/products/${product.id}`, { forceFormData: true, onSuccess: close }); };
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 980, width: '94vw', maxHeight: '92vh', overflowY: 'auto', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{t('Edit Product')} — {product.name}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <form onSubmit={submit} className="p-5">
                    {Object.keys(errors).length > 0 && (
                        <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {Object.values(errors)[0]}
                        </div>
                    )}
                    <ProductFields data={data} setData={setData} categories={categories} brands={brands} forEdit />
                    <div className="flex items-center justify-end gap-2 mt-5">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="submit" className={BTN_PRIMARY} disabled={processing}>{t('Save Changes')}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function AdjustModal({ product, close }) {
    const [delta, setDelta] = useState('');
    const [reason, setReason] = useState('Manual Adjustment');
    const [note, setNote] = useState('');
    const submit = () => {
        const d = parseInt(delta, 10);
        if (!d) return;
        router.post(`/inventory/products/${product.id}/adjust`, { delta: d, reason, note }, { preserveScroll: true, onSuccess: close });
    };
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 440, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{t('Adjust Stock')} — {product.name}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <div className="p-5 space-y-3">
                    <div className="text-sm text-muted-foreground">{t('Current stock')}: <b className="text-foreground">{product.stock_qty}</b> · {t('Use + for stock in, − for stock out. Every adjustment is written to the Stock Ledger.')}</div>
                    <input className={INPUT} type="number" placeholder="+10 / -5" value={delta} onChange={(e) => setDelta(e.target.value)} />
                    <input className={INPUT} placeholder={t('Reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
                    <input className={INPUT} placeholder={t('Note')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <div className="flex items-center justify-end gap-2">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="button" className={BTN_PRIMARY} onClick={submit}>{t('Save Adjustment')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}
