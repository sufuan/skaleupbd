import { useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { BTN_OUTLINE, BTN_PRIMARY, INPUT } from './ProductFields';

// Inventory Dashboard — copied from the prototype's inv-dashboard panel:
// tabs Overview / Warehouse Lookup / Smart Restock / Low Stock.
const TABS = ['Overview', 'Warehouse Lookup', 'Smart Restock', 'Low Stock'];
const TAB_BTN = (active) => `inline-flex items-center justify-center text-sm font-medium ring-offset-background focus-visible:outline-none group relative h-full whitespace-nowrap rounded-md border px-3 py-1.5 transition-colors duration-200 ${active ? 'border-primary/30 bg-primary/10 text-primary' : 'border-transparent bg-background text-muted-foreground hover:text-foreground/80'}`;

function HealthCard({ label, value, tone }) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className={`text-2xl font-bold ${tone || ''}`}>{value}</div>
        </div>
    );
}

export default function Dashboard({ health, movement, attention, lowStock, outStock, allProducts, restock, branches, branchStock, categories }) {
    const [tab, setTab] = useState('Overview');
    const [lookup, setLookup] = useState('');
    const [lookupResult, setLookupResult] = useState(null);
    const [lowFilter, setLowFilter] = useState('all');
    const [lowSearch, setLowSearch] = useState('');
    const [lowCat, setLowCat] = useState('');
    const [editFor, setEditFor] = useState(null);
    const fileRef = useRef(null);

    const reorder = restock.filter((r) => r.suggested > 0);
    const fast = restock.filter((r) => r.speed === 'Fast Moving');
    const slow = restock.filter((r) => r.speed !== 'Fast Moving');

    const doLookup = () => {
        const q = lookup.trim().toLowerCase();
        if (!q) return setLookupResult(null);
        const p = allProducts.find((x) => x.sku.toLowerCase() === q || x.name.toLowerCase().includes(q));
        if (!p) return setLookupResult({ missing: true });
        const perBranch = branches.map((b) => ({ name: b.name, qty: (branchStock[b.id] || []).find((s) => s.product_id === p.id)?.qty ?? 0 }));
        setLookupResult({ product: p, perBranch });
    };

    const lowRows = useMemo(() => {
        let rows = lowFilter === 'out' ? outStock : lowFilter === 'critical' ? lowStock.filter((p) => p.available <= Math.max(2, Math.floor(p.alert_qty / 2))) : [...lowStock, ...outStock];
        if (lowSearch) rows = rows.filter((p) => p.name.toLowerCase().includes(lowSearch.toLowerCase()) || p.sku.toLowerCase().includes(lowSearch.toLowerCase()));
        if (lowCat) rows = rows.filter((p) => String(p.category_id) === String(lowCat));
        return rows;
    }, [lowFilter, lowSearch, lowCat, lowStock, outStock]);

    const exportCsv = () => {
        const csv = ['name,sku,price,cost,stock_qty', ...allProducts.map((p) => ['"' + p.name.replace(/"/g, '""') + '"', p.sku, p.price, p.cost, p.stock_qty].join(','))].join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'products.csv'; a.click(); URL.revokeObjectURL(a.href);
    };
    const templateCsv = () => {
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['name,sku,price,cost,stock_qty\n'], { type: 'text/csv' })); a.download = 'product-import-template.csv'; a.click(); URL.revokeObjectURL(a.href);
    };

    return (
        <AppLayout title={t('Inventory Dashboard')}>
            <div className="invx">
                <div className="container mx-auto py-6 space-y-8 px-2 sm:px-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{t('Inventory Dashboard')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Monitor stock health and movements across your inventory')}</p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button type="button" className={BTN_OUTLINE} onClick={() => setTab('Reconcile')}>{t('Stock Reconciliation')}</button>
                        </div>
                    </div>

                    <div className="flex gap-1 overflow-x-auto pb-1" role="tablist">
                        {TABS.map((name) => (
                            <button key={name} role="tab" aria-selected={tab === name} className={TAB_BTN(tab === name)} onClick={() => setTab(name)}>
                                <span className="transition-colors">{t(name)}</span>
                            </button>
                        ))}
                    </div>

                    {tab === 'Overview' && (
                        <div className="space-y-8">
                            <div>
                                <h2 className="mb-4 text-lg font-medium">{t('Stock Health Overview')}</h2>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                                    <HealthCard label={t('Total Products')} value={health.total} />
                                    <HealthCard label={t('Healthy Stock')} value={health.healthy} tone="text-emerald-600" />
                                    <HealthCard label={t('Low Stock')} value={health.low} tone="text-amber-600" />
                                    <HealthCard label={t('Out of Stock')} value={health.out} tone="text-red-600" />
                                    <HealthCard label={t('Negative Stock')} value={health.negative} tone="text-red-600" />
                                </div>
                            </div>

                            <div>
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="mb-0 text-lg font-medium">{t('Stock Movement Summary')}</h2>
                                    <span className="text-xs text-muted-foreground">{t('Last 7 days')}</span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <HealthCard label={t('Units shipped out')} value={movement.sold} />
                                    <HealthCard label={t('Units Purchased')} value={movement.purchased} />
                                    <HealthCard label={t('Returns')} value={movement.returns} />
                                    <HealthCard label={t('Net Change')} value={movement.net} />
                                </div>
                            </div>

                            <div>
                                <h2 className="mb-4 text-lg font-medium">{t('Attention Required')}</h2>
                                <div className="rounded-xl border bg-card p-4 space-y-2">
                                    <div className="text-sm font-medium">{t('Products Needing Attention')}</div>
                                    {attention.length === 0 && <div className="text-sm text-muted-foreground">{t('Everything looks healthy')}</div>}
                                    {attention.map((a, i) => (
                                        <div key={i} className="flex items-center justify-between border-t pt-2 text-sm">
                                            <span>{a.text}</span>
                                            {a.product_id && <button type="button" className="text-primary text-xs font-medium" onClick={() => setTab('Low Stock')}>{t('View Details →')}</button>}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <h2 style={{ fontSize: 18, fontWeight: 700, margin: '26px 0 14px' }}>{t('Quick Actions')}</h2>
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" className={BTN_OUTLINE} onClick={templateCsv}>{t('Template')}</button>
                                    <button type="button" className={BTN_OUTLINE} onClick={() => fileRef.current?.click()}>{t('Import')}</button>
                                    <input ref={fileRef} type="file" accept=".csv" className="hidden" onChange={(e) => { const f = e.target.files[0]; if (f) router.post('/inventory/products/import', { file: f }, { forceFormData: true }); e.target.value = ''; }} />
                                    <button type="button" className={BTN_OUTLINE} onClick={exportCsv}>{t('Export')}</button>
                                    <button type="button" className={BTN_OUTLINE} onClick={() => router.reload({ preserveScroll: true })}>{t('Recalculate Analytics')}</button>
                                    <Link href="/inventory/products" className={BTN_OUTLINE}>{t('View All →')}</Link>
                                </div>
                            </div>

                            <div>
                                <h2 className="mb-4 text-lg font-medium">{t('Products Needing Reorder')}</h2>
                                <div className="rounded-md border overflow-x-auto">
                                    <table className="w-full caption-bottom text-sm">
                                        <thead><tr className="border-b">
                                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Product')}</th>
                                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Stock')}</th>
                                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Reorder Point')}</th>
                                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Status')}</th>
                                        </tr></thead>
                                        <tbody>
                                            {reorder.map((r) => (
                                                <tr key={r.id} className="border-b">
                                                    <td className="p-4">{r.name} <span className="text-muted-foreground">({r.sku})</span></td>
                                                    <td className="p-4">{r.available}</td>
                                                    <td className="p-4">{r.alert_qty}</td>
                                                    <td className="p-4">{r.available <= 0 ? <span className="text-red-600 font-medium">{t('Out of Stock')}</span> : <span className="text-amber-600 font-medium">{t('Low Stock')}</span>}</td>
                                                </tr>
                                            ))}
                                            {reorder.length === 0 && <tr><td colSpan="4" className="p-6 text-center text-muted-foreground">{t('Nothing needs reorder right now')}</td></tr>}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'Warehouse Lookup' && (
                        <div className="space-y-4">
                            <div className="flex gap-2">
                                <input className={INPUT} placeholder={t('Scan or type Product Code, or search product name')} value={lookup} onChange={(e) => setLookup(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && doLookup()} />
                                <button type="button" className={BTN_PRIMARY} onClick={doLookup}>{t('Search')}</button>
                            </div>
                            {lookupResult?.missing && <div className="text-sm text-red-600">{t('No product found')}</div>}
                            {lookupResult?.product && (
                                <div className="rounded-xl border bg-card p-4 space-y-2">
                                    <div className="font-semibold">{lookupResult.product.name} <span className="text-muted-foreground font-normal">({lookupResult.product.sku})</span></div>
                                    <div className="text-sm">{t('Total stock')}: <b>{lookupResult.product.stock_qty}</b> · {t('Reserved')}: {lookupResult.product.reserved_qty} · {t('Available')}: {lookupResult.product.available}</div>
                                    {lookupResult.perBranch.map((b) => (
                                        <div key={b.name} className="flex justify-between border-t pt-2 text-sm"><span>{b.name}</span><b>{b.qty}</b></div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'Smart Restock' && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div className="rounded-xl border bg-card p-4 space-y-2">
                                <div className="font-semibold">{t('Fast Moving')}</div>
                                {fast.map((r) => <div key={r.id} className="flex justify-between border-t pt-2 text-sm"><span>{r.name}</span><span>{t('Shipped (30d)')}: {r.sold_30d} · {t('Stock')}: {r.available}</span></div>)}
                                {fast.length === 0 && <div className="text-sm text-muted-foreground">{t('No fast moving products yet')}</div>}
                            </div>
                            <div className="rounded-xl border bg-card p-4 space-y-2">
                                <div className="font-semibold">{t('Slow Moving')}</div>
                                {slow.map((r) => <div key={r.id} className="flex justify-between border-t pt-2 text-sm"><span>{r.name}</span><span>{t('Shipped (30d)')}: {r.sold_30d} · {t('Stock')}: {r.available}</span></div>)}
                                {slow.length === 0 && <div className="text-sm text-muted-foreground">{t('No slow moving products')}</div>}
                            </div>
                        </div>
                    )}

                    {tab === 'Low Stock' && (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <h2 className="text-xl font-bold tracking-tight">{t('Low Stock Alerts')}</h2>
                                <button type="button" className={BTN_OUTLINE} onClick={() => router.reload({ preserveScroll: true })}>{t('Recalculate')}</button>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button type="button" className={TAB_BTN(lowFilter === 'all')} onClick={() => setLowFilter('all')}>{t('All Alerts')} {lowStock.length + outStock.length} {t('items')}</button>
                                <button type="button" className={TAB_BTN(lowFilter === 'out')} onClick={() => setLowFilter('out')}>{t('Out of Stock')} {outStock.length} {t('items')}</button>
                                <button type="button" className={TAB_BTN(lowFilter === 'critical')} onClick={() => setLowFilter('critical')}>{t('Critical Stock')} {lowStock.filter((p) => p.available <= Math.max(2, Math.floor(p.alert_qty / 2))).length} {t('items')}</button>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <input className={INPUT + ' sm:w-64'} placeholder={t('Search by product name or SKU...')} value={lowSearch} onChange={(e) => setLowSearch(e.target.value)} />
                                <select className={INPUT + ' sm:w-48'} value={lowCat} onChange={(e) => setLowCat(e.target.value)}>
                                    <option value="">{t('All categories')}</option>
                                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {lowRows.map((p) => (
                                    <div key={p.id} className="rounded-xl border bg-card p-4 space-y-2">
                                        <div className="font-semibold text-sm">{p.name}</div>
                                        <div className="text-xs text-muted-foreground">{p.sku}</div>
                                        <div className="flex items-center justify-between text-sm">
                                            <span>{t('Stock')}: <b>{p.available}</b></span>
                                            <span className="text-muted-foreground">{t('Min Limit')}: {p.alert_qty}</span>
                                        </div>
                                        <div className="flex gap-2">
                                            <button type="button" className={BTN_OUTLINE + ' h-8 px-2 text-xs'} onClick={() => setEditFor(p)}>{t('Edit Product')}</button>
                                            <Link href="/inventory/branches" className={BTN_OUTLINE + ' h-8 px-2 text-xs'}>{t('Stock Transfer')}</Link>
                                        </div>
                                    </div>
                                ))}
                                {lowRows.length === 0 && <div className="text-sm text-muted-foreground">{t('No low stock products')}</div>}
                            </div>
                        </div>
                    )}

                    {tab === 'Reconcile' && <Reconcile allProducts={allProducts} back={() => setTab('Overview')} />}
                </div>
            </div>

            {editFor && <QuickEdit product={editFor} categories={categories} close={() => setEditFor(null)} />}
        </AppLayout>
    );
}

function Reconcile({ allProducts, back }) {
    const [counts, setCounts] = useState({});
    const save = () => router.post('/inventory/reconcile', { counts }, { preserveScroll: true, onSuccess: back });
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-medium">{t('Stock Reconciliation')}</h2>
                <button type="button" className={BTN_OUTLINE} onClick={back}>{t('Back')}</button>
            </div>
            <p className="text-sm text-muted-foreground">{t('Type the physical count for each product. Only the differences are booked as adjustments in the Stock Ledger.')}</p>
            <div className="rounded-md border overflow-x-auto">
                <table className="w-full caption-bottom text-sm">
                    <thead><tr className="border-b">
                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Product')}</th>
                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('System Stock')}</th>
                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Counted')}</th>
                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Difference')}</th>
                    </tr></thead>
                    <tbody>
                        {allProducts.map((p) => {
                            const counted = counts[p.id] ?? '';
                            const diff = counted === '' ? '' : Number(counted) - p.stock_qty;
                            return (
                                <tr key={p.id} className="border-b">
                                    <td className="p-4">{p.name} <span className="text-muted-foreground">({p.sku})</span></td>
                                    <td className="p-4">{p.stock_qty}</td>
                                    <td className="p-4"><input className={INPUT + ' w-28'} type="number" min="0" value={counted} onChange={(e) => setCounts({ ...counts, [p.id]: e.target.value })} /></td>
                                    <td className="p-4">{diff === '' ? '—' : <span className={diff === 0 ? 'text-emerald-600' : 'text-red-600 font-medium'}>{diff > 0 ? '+' + diff : diff}</span>}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <div className="flex justify-end">
                <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Save Reconciliation')}</button>
            </div>
        </div>
    );
}

function QuickEdit({ product, categories, close }) {
    const [form, setForm] = useState({
        name: product.name, sku: product.sku, category_id: product.category_id || '',
        cost: product.cost || '', price: product.price, stock_qty: product.stock_qty, alert_qty: product.alert_qty,
    });
    const set = (k, v) => setForm({ ...form, [k]: v });
    const save = () => router.patch(`/inventory/products/${product.id}`, { ...form, manage_stock: product.manage_stock, featured: product.featured, is_active: product.is_active, type: product.type }, { preserveScroll: true, onSuccess: close });
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 520, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{t('Edit Product')}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>✕</button>
                </div>
                <div className="p-5 space-y-3">
                    <div className="space-y-1"><label className="text-sm font-medium">{t('Product Name')}</label><input className={INPUT} value={form.name} onChange={(e) => set('name', e.target.value)} /></div>
                    <div className="space-y-1"><label className="text-sm font-medium">{t('SKU')}</label><input className={INPUT} value={form.sku} onChange={(e) => set('sku', e.target.value)} /></div>
                    <div className="space-y-1"><label className="text-sm font-medium">{t('Category')}</label>
                        <select className={INPUT} value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                            <option value="">—</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1"><label className="text-sm font-medium">{t('Cost Price (৳)')}</label><input className={INPUT} type="number" value={form.cost} onChange={(e) => set('cost', e.target.value)} /></div>
                        <div className="space-y-1"><label className="text-sm font-medium">{t('Sell Price (৳)')}</label><input className={INPUT} type="number" value={form.price} onChange={(e) => set('price', e.target.value)} /></div>
                        <div className="space-y-1"><label className="text-sm font-medium">{t('Stock')}</label><input className={INPUT} type="number" value={form.stock_qty} disabled /></div>
                        <div className="space-y-1"><label className="text-sm font-medium">{t('Stock Alert Qty')}</label><input className={INPUT} type="number" value={form.alert_qty} onChange={(e) => set('alert_qty', e.target.value)} /></div>
                    </div>
                    <div className="flex items-center justify-end gap-2 pt-2">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Save Changes')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}
