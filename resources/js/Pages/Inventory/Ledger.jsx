import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { BTN_OUTLINE, BTN_PRIMARY, INPUT } from './ProductFields';

// Stock Ledger — copied from the prototype's inv-ledger panel:
// tabs Stock Ledger / Stock Integrity, insight links, filters, 17-column table.
const TYPES = ['reserve', 'release_reserve', 'ship_out', 'return_in', 'adjust_in', 'adjust_out', 'transfer_in', 'transfer_out', 'damage'];
const TYPE_LABEL = { reserve: 'Reserve', release_reserve: 'Release Reserve', ship_out: 'Shipped Out', return_in: 'Return In', adjust_in: 'Adjustment In', adjust_out: 'Adjustment Out', transfer_in: 'Transfer In', transfer_out: 'Transfer Out', damage: 'Damage / Lost' };

export default function Ledger({ rows, cards, rail, findings, insights, filters, products }) {
    const [tab, setTab] = useState('Stock Ledger');
    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || '');
    const [direction, setDirection] = useState(filters.direction || '');
    const [adjustOpen, setAdjustOpen] = useState(false);

    const apply = (extra = {}) => router.get('/inventory/ledger', { search, type, direction, ...extra }, { preserveState: true });
    const exportCsv = () => {
        const csv = ['Date,Product,SKU,Type,Direction,Qty,Previous,New,Unit Cost,Value Impact,Reference', ...rows.data.map((r) => [r.created_at, '"' + (r.product?.name || '') + '"', r.product?.sku || '', r.type, r.direction, r.qty, r.prev_stock, r.new_stock, r.unit_cost, r.value_impact, r.reference].join(','))].join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'stock-ledger.csv'; a.click(); URL.revokeObjectURL(a.href);
    };

    const Stat = ({ label, value }) => (
        <div className="bg-muted/40 w-full rounded-lg px-3 py-2 sm:px-4 sm:py-3">
            <span className="text-muted-foreground text-xs">{label}</span>
            <div className="font-semibold">{value}</div>
        </div>
    );

    return (
        <AppLayout title={t('Stock Ledger')}>
            <div className="invx">
                <div className="container mx-auto px-2 sm:px-6 py-4 sm:py-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{t('Stock Ledger')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Every stock movement, written by the system — the ledger is the source of truth')}</p>
                        </div>
                        <div className="flex gap-2">
                            <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={exportCsv}>{t('Export')}</button>
                            <button type="button" className={BTN_PRIMARY + ' h-9 px-3'} onClick={() => setAdjustOpen(true)}>{t('Add Adjustment')}</button>
                        </div>
                    </div>

                    <div className="flex gap-1 overflow-x-auto pb-1" role="tablist">
                        {['Stock Ledger', 'Stock Integrity'].map((name) => (
                            <button key={name} role="tab" aria-selected={tab === name} onClick={() => setTab(name)}
                                className={`inline-flex items-center justify-center text-sm font-medium group relative h-full whitespace-nowrap rounded-md border px-3 py-1.5 transition-colors duration-200 ${tab === name ? 'border-primary/30 bg-primary/10 text-primary' : 'border-transparent bg-background text-muted-foreground hover:text-foreground/80'}`}>
                                <span className="transition-colors">{t(name)}</span>
                            </button>
                        ))}
                    </div>

                    {tab === 'Stock Ledger' && (
                        <div className="space-y-4">
                            <div className="grid w-full grid-cols-2 gap-2 text-sm sm:gap-3 xl:grid-cols-4">
                                <button type="button" className="text-left rounded-lg border p-3 hover:bg-accent/40" onClick={() => router.visit('/inventory/dashboard')}>
                                    <div className="font-medium">{t('Low Stock Products')} {insights.low_stock}</div>
                                    <div className="text-xs text-muted-foreground">{t('At or below alert quantity')}</div>
                                </button>
                                <div className="rounded-lg border p-3">
                                    <div className="font-medium">{t('Stockout Risk')} {insights.stockout_risk}</div>
                                    <div className="text-xs text-muted-foreground">{t('Out of stock or selling fast')}</div>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <div className="font-medium">{t('Fast Moving SKUs')} {insights.fast_moving}</div>
                                    <div className="text-xs text-muted-foreground">{t('10+ units sold in range')}</div>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <div className="font-medium">{t('High Adjustment Alerts')} {insights.high_adjustments}</div>
                                    <div className="text-xs text-muted-foreground">{t('3+ manual adjustments in range')}</div>
                                </div>
                            </div>

                            <div className="bg-card grid w-full grid-cols-2 gap-2 rounded-xl border p-2 text-sm sm:gap-3 sm:p-3 xl:grid-cols-6">
                                <Stat label={t('Opening')} value={cards.opening} />
                                <Stat label={t('Stock In')} value={cards.stock_in} />
                                <Stat label={t('Stock Out')} value={cards.stock_out} />
                                <Stat label={t('Closing')} value={cards.closing} />
                                <Stat label={t('Adjustment')} value={cards.adjustment} />
                                <Stat label={t('Damage-Lost')} value={cards.damage} />
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <input className={INPUT + ' h-9 sm:w-56'} placeholder={t('SKU / Variant')} value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply()} />
                                <select className={INPUT + ' h-9 sm:w-48'} value={type} onChange={(e) => { setType(e.target.value); apply({ type: e.target.value }); }}>
                                    <option value="">{t('All movement types')}</option>
                                    {TYPES.map((x) => <option key={x} value={x}>{t(TYPE_LABEL[x])}</option>)}
                                </select>
                                <select className={INPUT + ' h-9 sm:w-40'} value={direction} onChange={(e) => { setDirection(e.target.value); apply({ direction: e.target.value }); }}>
                                    <option value="">{t('All directions')}</option>
                                    <option value="in">{t('In')}</option>
                                    <option value="out">{t('Out')}</option>
                                </select>
                                <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => apply()}>{t('Filter')}</button>
                            </div>

                            <div className="grid gap-4 xl:grid-cols-[1fr_260px]">
                                <div className="rounded-md border overflow-x-auto">
                                    <table className="w-full caption-bottom text-sm whitespace-nowrap">
                                        <thead><tr className="border-b">
                                            {[t('Date & Time'), t('Image'), t('Product'), t('SKU'), t('Movement Type'), t('Direction'), t('Qty'), t('Previous Stock'), t('New Stock'), t('Unit Cost'), t('Value Impact'), t('Reference'), t('Warehouse'), t('Updated By'), t('Reason'), t('Note'), t('Status')].map((h) => (
                                                <th key={h} className="h-12 px-4 text-left font-medium text-muted-foreground">{h}</th>
                                            ))}
                                        </tr></thead>
                                        <tbody>
                                            {rows.data.map((r) => (
                                                <tr key={r.id} className="border-b">
                                                    <td className="p-4">{r.created_at}</td>
                                                    <td className="p-4">{r.product?.image ? <img src={r.product.image.startsWith('http') ? r.product.image : '/storage/' + r.product.image} alt="" className="h-8 w-8 rounded border object-cover" /> : <span className="text-gray-400 text-xs">IMG</span>}</td>
                                                    <td className="p-4 font-medium">{r.product?.name}</td>
                                                    <td className="p-4">{r.product?.sku}</td>
                                                    <td className="p-4">{t(TYPE_LABEL[r.type] || r.type)}</td>
                                                    <td className="p-4">{r.direction}</td>
                                                    <td className="p-4">{r.qty}</td>
                                                    <td className="p-4">{r.prev_stock ?? '—'}</td>
                                                    <td className="p-4">{r.new_stock ?? '—'}</td>
                                                    <td className="p-4">{r.unit_cost ?? '—'}</td>
                                                    <td className="p-4">{r.value_impact ?? '—'}</td>
                                                    <td className="p-4">{r.reference || '—'}</td>
                                                    <td className="p-4">{r.branch || '—'}</td>
                                                    <td className="p-4">{r.updated_by || '—'}</td>
                                                    <td className="p-4">{r.reason || '—'}</td>
                                                    <td className="p-4">{r.note || '—'}</td>
                                                    <td className="p-4">{r.status}</td>
                                                </tr>
                                            ))}
                                            {rows.data.length === 0 && <tr><td colSpan="17" className="p-8 text-center text-muted-foreground">{t('No movements found')}</td></tr>}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="space-y-3">
                                    <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Total Movements')}</div><div className="text-xl font-bold">{rail.total_movements}</div></div>
                                    <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Manual Adjustments Today')}</div><div className="text-xl font-bold">{rail.manual_today}</div></div>
                                    <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Stock Accuracy')}</div><div className="text-xl font-bold">{rail.accuracy}%</div></div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">{rows.from || 0} – {rows.to || 0} / {rows.total}</span>
                                <div className="flex gap-1">
                                    <button type="button" className={BTN_OUTLINE + ' h-8 px-3'} disabled={!rows.prev_page_url} onClick={() => rows.prev_page_url && router.get(rows.prev_page_url, {}, { preserveState: true })}>{t('Previous')}</button>
                                    <button type="button" className={BTN_OUTLINE + ' h-8 px-3'} disabled={!rows.next_page_url} onClick={() => rows.next_page_url && router.get(rows.next_page_url, {}, { preserveState: true })}>{t('Next')}</button>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'Stock Integrity' && (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <h2 className="text-lg font-medium">{t('Stock Integrity')}</h2>
                                <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => router.reload({ preserveScroll: true })}>{t('Run scan now')}</button>
                            </div>
                            <div className="text-sm text-muted-foreground">{t('Stock Accuracy')}: <b className="text-foreground">{rail.accuracy}%</b></div>
                            <div className="rounded-md border overflow-x-auto">
                                <table className="w-full caption-bottom text-sm">
                                    <thead><tr className="border-b">
                                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Check')}</th>
                                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Severity')}</th>
                                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Finding')}</th>
                                        <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Status')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {findings.map((f, i) => (
                                            <tr key={i} className="border-b">
                                                <td className="p-4 font-medium">{f.kind}</td>
                                                <td className="p-4"><span className="text-amber-600 font-medium">{t('Warning')}</span></td>
                                                <td className="p-4">{f.product} ({f.sku}) — {f.text}</td>
                                                <td className="p-4">{t('Open')}</td>
                                            </tr>
                                        ))}
                                        {findings.length === 0 && <tr><td colSpan="4" className="p-8 text-center text-muted-foreground">{t('No integrity problems found — the ledger and stock agree')}</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {adjustOpen && <AddAdjustment products={products} close={() => setAdjustOpen(false)} />}
        </AppLayout>
    );
}

function AddAdjustment({ products, close }) {
    const [productId, setProductId] = useState('');
    const [delta, setDelta] = useState('');
    const [reason, setReason] = useState('Manual Adjustment');
    const [note, setNote] = useState('');
    const submit = () => {
        const d = parseInt(delta, 10);
        if (!productId || !d) return;
        router.post(`/inventory/products/${productId}/adjust`, { delta: d, reason, note }, { preserveScroll: true, onSuccess: close });
    };
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 460, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{t('Add Adjustment')}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <div className="p-5 space-y-3">
                    <p className="text-sm text-muted-foreground">{t('Every adjustment is written to the ledger with the balance before and after.')}</p>
                    <select className={INPUT} value={productId} onChange={(e) => setProductId(e.target.value)}>
                        <option value="">{t('Select product')}</option>
                        {(products || []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku}) — {t('Stock')}: {p.stock_qty}</option>)}
                    </select>
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
