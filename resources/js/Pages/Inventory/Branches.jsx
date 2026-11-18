import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { BTN_OUTLINE, BTN_PRIMARY, INPUT } from './ProductFields';

// Branches — copied from the prototype's inv-branches panel:
// stats, Branch Stock master toggle, branch cards, and the Stock Transfer tab.
const TYPE_LABEL = { store: 'Retail Store', warehouse: 'Warehouse', central: 'Central' };

export default function Branches({ branches, stats, branchStockEnabled, cartonsEnabled, transfers, products }) {
    const [tab, setTab] = useState('Branches');
    const [addOpen, setAddOpen] = useState(false);
    const [editFor, setEditFor] = useState(null);
    const branchName = (id) => branches.find((b) => b.id === id)?.name || ('#' + id);
    const productName = (id) => products.find((p) => p.id === id)?.name || ('#' + id);

    const Stat = ({ label, value }) => (
        <div className="rounded-xl border bg-card p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-2xl font-bold">{value}</div>
        </div>
    );

    return (
        <AppLayout title={t('Branches')}>
            <div className="invx">
                <div className="container mx-auto px-2 sm:px-6 py-4 sm:py-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{t('Branches')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Stores and warehouses that hold your stock')}</p>
                        </div>
                        <button type="button" className={BTN_PRIMARY + ' h-9 px-3'} onClick={() => setAddOpen(true)}>{t('Add Branch')}</button>
                    </div>

                    <div className="flex gap-1 overflow-x-auto pb-1" role="tablist">
                        {['Branches', 'Stock Transfer'].map((name) => (
                            <button key={name} role="tab" aria-selected={tab === name} onClick={() => setTab(name)}
                                className={`inline-flex items-center justify-center text-sm font-medium group relative h-full whitespace-nowrap rounded-md border px-3 py-1.5 transition-colors duration-200 ${tab === name ? 'border-primary/30 bg-primary/10 text-primary' : 'border-transparent bg-background text-muted-foreground hover:text-foreground/80'}`}>
                                <span className="transition-colors">{t(name)}</span>
                            </button>
                        ))}
                    </div>

                    {tab === 'Branches' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                                <Stat label={t('Branches')} value={stats.branches} />
                                <Stat label={t('Stores')} value={stats.stores} />
                                <Stat label={t('Warehouses')} value={stats.warehouses} />
                                <Stat label={t('Central')} value={stats.central} />
                            </div>

                            <div className="rounded-xl border bg-card p-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="font-medium text-sm">{t('Set up Branch Stock')}</div>
                                    <div className="text-xs text-muted-foreground">{t('When branch stock is on, every branch keeps its own stock count and transfers move stock between branches.')}</div>
                                </div>
                                <button type="button" role="switch" aria-checked={branchStockEnabled} aria-label={t('Branch Stock')}
                                    onClick={() => router.post('/inventory/branches/toggle-stock', {}, { preserveScroll: true })}
                                    className={`inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors ${branchStockEnabled ? 'bg-primary' : 'bg-input'}`}>
                                    <span className={`pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform ${branchStockEnabled ? 'translate-x-5' : 'translate-x-0'}`} />
                                </button>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {branches.map((b) => (
                                    <div key={b.id} className="rounded-xl border bg-card p-4 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <div className="font-semibold">{b.name}</div>
                                            <span className="rounded-full border px-2 py-0.5 text-[11px] font-semibold">{t(TYPE_LABEL[b.type] || b.type)}</span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">{b.address || '—'} · {b.phone || '—'}</div>
                                        <div className="text-sm">{t('Products')}: <b>{b.products_count}</b> · {t('Total Qty')}: <b>{b.total_qty}</b></div>
                                        {!b.is_active && <div className="text-xs text-red-600 font-medium">{t('Inactive')}</div>}
                                        <div className="flex flex-wrap gap-2 pt-1">
                                            <button type="button" className={BTN_OUTLINE + ' h-8 px-2 text-xs'} onClick={() => setEditFor(b)}>{t('Edit')}</button>
                                            <button type="button" className={BTN_OUTLINE + ' h-8 px-2 text-xs'} onClick={() => router.patch(`/inventory/branches/${b.id}`, { name: b.name, type: b.type, address: b.address, phone: b.phone, is_active: !b.is_active }, { preserveScroll: true })}>{b.is_active ? t('Deactivate') : t('Activate')}</button>
                                            {b.type !== 'central' && <button type="button" className={BTN_OUTLINE + ' h-8 px-2 text-xs'} onClick={() => router.patch(`/inventory/branches/${b.id}`, { name: b.name, type: 'central', address: b.address, phone: b.phone, is_active: b.is_active }, { preserveScroll: true })}>{t('Set as Central')}</button>}
                                            <button type="button" className="h-8 px-2 text-xs text-red-600" onClick={() => { if (confirm(t('Delete this branch?'))) router.delete(`/inventory/branches/${b.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                                        </div>
                                    </div>
                                ))}
                                {branches.length === 0 && <div className="text-sm text-muted-foreground">{t('No branches yet')}</div>}
                            </div>
                        </div>
                    )}

                    {tab === 'Stock Transfer' && <TransferForm branches={branches} products={products} transfers={transfers} branchName={branchName} productName={productName} cartonsEnabled={cartonsEnabled} />}
                </div>
            </div>

            {addOpen && <BranchForm close={() => setAddOpen(false)} />}
            {editFor && <BranchForm branch={editFor} close={() => setEditFor(null)} />}
        </AppLayout>
    );
}

function BranchForm({ branch, close }) {
    const [form, setForm] = useState({ name: branch?.name || '', type: branch?.type || 'store', address: branch?.address || '', phone: branch?.phone || '', is_active: branch?.is_active ?? true });
    const set = (k, v) => setForm({ ...form, [k]: v });
    const save = () => {
        if (!form.name.trim()) return;
        if (branch) router.patch(`/inventory/branches/${branch.id}`, form, { preserveScroll: true, onSuccess: close });
        else router.post('/inventory/branches', form, { preserveScroll: true, onSuccess: close });
    };
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 460, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{branch ? t('Edit Branch') : t('Add Branch')}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <div className="p-5 space-y-3">
                    <input className={INPUT} placeholder={t('Branch name')} value={form.name} onChange={(e) => set('name', e.target.value)} />
                    <select className={INPUT} value={form.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="store">{t('Retail Store')}</option>
                        <option value="warehouse">{t('Warehouse')}</option>
                        <option value="central">{t('Central')}</option>
                    </select>
                    <input className={INPUT} placeholder={t('Address')} value={form.address} onChange={(e) => set('address', e.target.value)} />
                    <input className={INPUT} placeholder={t('Phone')} value={form.phone} onChange={(e) => set('phone', e.target.value)} />
                    <div className="flex items-center justify-end gap-2">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Save')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function TransferForm({ branches, products, transfers, branchName, productName, cartonsEnabled }) {
    const [form, setForm] = useState({ from_branch_id: '', to_branch_id: '', product_id: '', qty: '', cartons: '', note: '' });
    const set = (k, v) => setForm({ ...form, [k]: v });
    const create = () => {
        if (!form.from_branch_id || !form.to_branch_id || !form.product_id || !form.qty) return;
        router.post('/inventory/transfers', form, { preserveScroll: true, onSuccess: () => setForm({ from_branch_id: '', to_branch_id: '', product_id: '', qty: '', cartons: '', note: '' }) });
    };
    return (
        <div className="space-y-6">
            <div className="rounded-xl border bg-card p-4 space-y-3">
                <div className="font-semibold">{t('Stock Transfer')}</div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1">
                        <label className="text-sm font-medium">{t('From Warehouse')}</label>
                        <select className={INPUT} value={form.from_branch_id} onChange={(e) => set('from_branch_id', e.target.value)}>
                            <option value="">{t('Select')}</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">{t('To Warehouse')}</label>
                        <select className={INPUT} value={form.to_branch_id} onChange={(e) => set('to_branch_id', e.target.value)}>
                            <option value="">{t('Select')}</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">{t('Product')}</label>
                        <select className={INPUT} value={form.product_id} onChange={(e) => set('product_id', e.target.value)}>
                            <option value="">{t('Select')}</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.name} — {p.sku}</option>)}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">{t('Quantity')}</label>
                        <input className={INPUT} type="number" min="1" placeholder="0" value={form.qty} onChange={(e) => set('qty', e.target.value)} />
                    </div>
                    {cartonsEnabled && (
                        <div className="space-y-1">
                            <label className="text-sm font-medium">{t('Cartons')}</label>
                            <input className={INPUT} type="number" min="0" placeholder="0" value={form.cartons} onChange={(e) => set('cartons', e.target.value)} />
                        </div>
                    )}
                </div>
                <input className={INPUT} placeholder={t('Note')} value={form.note} onChange={(e) => set('note', e.target.value)} />
                <button type="button" className={BTN_PRIMARY} onClick={create}>{t('Create Transfer')}</button>
            </div>

            <div>
                <h2 className="mb-3 text-lg font-medium">{t('Recent Transfers')}</h2>
                <div className="rounded-md border overflow-x-auto">
                    <table className="w-full caption-bottom text-sm">
                        <thead><tr className="border-b">
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Date')}</th>
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('From')}</th>
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('To')}</th>
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Items')}</th>
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Status')}</th>
                            <th className="h-12 px-4 text-left font-medium text-muted-foreground">{t('Actions')}</th>
                        </tr></thead>
                        <tbody>
                            {transfers.map((tr) => (
                                <tr key={tr.id} className="border-b">
                                    <td className="p-4">{tr.created_at}</td>
                                    <td className="p-4">{branchName(tr.from_branch_id)}</td>
                                    <td className="p-4">{branchName(tr.to_branch_id)}</td>
                                    <td className="p-4">{productName(tr.product_id)} × {tr.qty}{tr.cartons ? ` · ${tr.cartons} ${t('cartons')}` : ''}</td>
                                    <td className="p-4">{t(tr.status === 'sent' ? 'Sent' : tr.status === 'received' ? 'Received' : tr.status === 'cancelled' ? 'Cancelled' : 'Pending')}</td>
                                    <td className="p-4">
                                        {tr.status === 'sent' && (
                                            <span className="flex gap-2">
                                                <button type="button" className="text-xs text-primary font-medium" onClick={() => router.patch(`/inventory/transfers/${tr.id}`, { status: 'received' }, { preserveScroll: true })}>{t('Mark Received')}</button>
                                                <button type="button" className="text-xs text-red-600 font-medium" onClick={() => router.patch(`/inventory/transfers/${tr.id}`, { status: 'cancelled' }, { preserveScroll: true })}>{t('Cancel')}</button>
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {transfers.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-muted-foreground">{t('No transfers yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
