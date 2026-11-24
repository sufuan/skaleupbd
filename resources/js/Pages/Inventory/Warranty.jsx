import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { BTN_OUTLINE, BTN_PRIMARY, INPUT, TEXTAREA } from './ProductFields';

// Warranty - plan structure: tabs Claims / History & Reports / Settings.
// The Settings tab form is copied from the prototype's inv-warranty panel
// (Default Warranty Period / Warranty Type / Warranty Terms) plus the
// configurable claim vocabularies.
const FLOW = ['received', 'in_repair', 'sent_to_supplier', 'back_from_supplier', 'ready_for_pickup', 'closed'];
const STATUS_LABEL = { received: 'Received', in_repair: 'In Repair', sent_to_supplier: 'Sent to Supplier', back_from_supplier: 'Back from Supplier', ready_for_pickup: 'Ready for Pickup', closed: 'Closed', rejected: 'Rejected', cancelled: 'Cancelled' };
const PERIODS = ['No Warranty', '7 Days', '30 Days', '6 Months', '1 Year'];
const CLAIM_TYPES = ['Replacement', 'Repair', 'Refund'];

export default function Warranty({ claims, counts, products, vocab, filters }) {
    const [tab, setTab] = useState('Claims');
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [newOpen, setNewOpen] = useState(false);
    const [actFor, setActFor] = useState(null);

    const apply = (extra = {}) => router.get('/inventory/warranty', { search, status, ...extra }, { preserveState: true });
    const history = claims.data.filter((c) => ['closed', 'rejected', 'cancelled'].includes(c.status));

    const ClaimTable = ({ list, showActions }) => (
        <div className="rounded-md border overflow-x-auto">
            <table className="w-full caption-bottom text-sm whitespace-nowrap">
                <thead><tr className="border-b">
                    {[t('Claim No.'), t('Customer'), t('Product'), t('Serial'), t('Invoice'), t('Issue'), t('Status'), t('Received'), t('Due'), showActions ? t('Actions') : t('Resolution')].map((h) => (
                        <th key={h} className="h-12 px-4 text-left font-medium text-muted-foreground">{h}</th>
                    ))}
                </tr></thead>
                <tbody>
                    {list.map((c) => (
                        <tr key={c.id} className="border-b">
                            <td className="p-4 font-medium">{c.claim_no}</td>
                            <td className="p-4">{c.customer_name}<div className="text-xs text-muted-foreground">{c.customer_phone}</div></td>
                            <td className="p-4">{c.product_name || '-'}</td>
                            <td className="p-4">{c.serial_no || '-'}</td>
                            <td className="p-4">{c.invoice_no || '-'}</td>
                            <td className="p-4 max-w-[220px] truncate">{c.issue}</td>
                            <td className="p-4">{t(STATUS_LABEL[c.status] || c.status)}{c.overdue && <span className="ml-2 rounded-full bg-red-50 border border-red-200 text-red-700 px-2 py-0.5 text-[11px] font-semibold">{t('Overdue')}</span>}</td>
                            <td className="p-4">{c.received_date || '-'}</td>
                            <td className="p-4">{c.due_date || '-'}</td>
                            <td className="p-4">
                                {showActions
                                    ? <button type="button" className={BTN_OUTLINE + ' h-8 px-2 text-xs'} onClick={() => setActFor(c)}>{t('Update')}</button>
                                    : (c.resolution || c.reject_reason || c.cancel_reason || '-')}
                            </td>
                        </tr>
                    ))}
                    {list.length === 0 && <tr><td colSpan="10" className="p-8 text-center text-muted-foreground">{t('No claims found')}</td></tr>}
                </tbody>
            </table>
        </div>
    );

    return (
        <AppLayout title={t('Warranty')}>
            <div className="invx">
                <div className="container mx-auto px-2 sm:px-6 py-4 sm:py-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{t('Warranty')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Claims start from the delivered date. Default turnaround is')} {vocab.turnaround_days} {t('days.')}</p>
                        </div>
                        <button type="button" className={BTN_PRIMARY + ' h-9 px-3'} onClick={() => setNewOpen(true)}>{t('New Claim')}</button>
                    </div>

                    <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Total Claims')}</div><div className="text-2xl font-bold">{counts.total}</div></div>
                        <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Open')}</div><div className="text-2xl font-bold">{counts.open}</div></div>
                        <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Overdue')}</div><div className="text-2xl font-bold text-red-600">{counts.overdue}</div></div>
                        <div className="rounded-xl border bg-card p-4"><div className="text-xs text-muted-foreground">{t('Closed')}</div><div className="text-2xl font-bold">{counts.closed}</div></div>
                    </div>

                    <div className="flex gap-1 overflow-x-auto pb-1" role="tablist">
                        {['Claims', 'History & Reports', 'Settings'].map((name) => (
                            <button key={name} role="tab" aria-selected={tab === name} onClick={() => setTab(name)}
                                className={`inline-flex items-center justify-center text-sm font-medium group relative h-full whitespace-nowrap rounded-md border px-3 py-1.5 transition-colors duration-200 ${tab === name ? 'border-primary/30 bg-primary/10 text-primary' : 'border-transparent bg-background text-muted-foreground hover:text-foreground/80'}`}>
                                <span className="transition-colors">{t(name)}</span>
                            </button>
                        ))}
                    </div>

                    {tab === 'Claims' && (
                        <div className="space-y-4">
                            <div className="flex flex-wrap gap-2">
                                <input className={INPUT + ' h-9 sm:w-72'} placeholder={t('Search by claim no, customer, mobile, serial or invoice')} value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply()} />
                                <select className={INPUT + ' h-9 sm:w-48'} value={status} onChange={(e) => { setStatus(e.target.value); apply({ status: e.target.value }); }}>
                                    <option value="">{t('Everything')}</option>
                                    <option value="overdue">{t('Overdue')}</option>
                                    {FLOW.concat(['rejected', 'cancelled']).map((s) => <option key={s} value={s}>{t(STATUS_LABEL[s])}</option>)}
                                </select>
                                <button type="button" className={BTN_OUTLINE + ' h-9 px-3'} onClick={() => apply()}>{t('Search')}</button>
                            </div>
                            <ClaimTable list={claims.data} showActions />
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">{claims.from || 0} – {claims.to || 0} / {claims.total}</span>
                                <div className="flex gap-1">
                                    <button type="button" className={BTN_OUTLINE + ' h-8 px-3'} disabled={!claims.prev_page_url} onClick={() => claims.prev_page_url && router.get(claims.prev_page_url, {}, { preserveState: true })}>{t('Previous')}</button>
                                    <button type="button" className={BTN_OUTLINE + ' h-8 px-3'} disabled={!claims.next_page_url} onClick={() => claims.next_page_url && router.get(claims.next_page_url, {}, { preserveState: true })}>{t('Next')}</button>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'History & Reports' && (
                        <div className="space-y-4">
                            <div className="text-sm text-muted-foreground">{t('Closed, rejected and cancelled claims')} - {history.length}</div>
                            <ClaimTable list={history} showActions={false} />
                        </div>
                    )}

                    {tab === 'Settings' && <WarrantySettings vocab={vocab} />}
                </div>
            </div>

            {newOpen && <NewClaim products={products} vocab={vocab} close={() => setNewOpen(false)} />}
            {actFor && <ClaimAction claim={actFor} vocab={vocab} close={() => setActFor(null)} />}
        </AppLayout>
    );
}

function NewClaim({ products, vocab, close }) {
    const [form, setForm] = useState({ product_id: '', customer_name: '', customer_phone: '', serial_no: '', invoice_no: '', issue: '', type: vocab.types?.[0] || 'Brand Warranty', received_date: '' });
    const set = (k, v) => setForm({ ...form, [k]: v });
    const save = () => { if (form.customer_name.trim() && form.issue.trim()) router.post('/inventory/warranty', form, { preserveScroll: true, onSuccess: close }); };
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 520, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{t('New Claim')}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <div className="p-5 space-y-3">
                    <select className={INPUT} value={form.product_id} onChange={(e) => set('product_id', e.target.value)}>
                        <option value="">{t('Select product')}</option>
                        {products.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>)}
                    </select>
                    <input className={INPUT} placeholder={t('Customer name')} value={form.customer_name} onChange={(e) => set('customer_name', e.target.value)} />
                    <input className={INPUT} placeholder={t('Customer mobile')} value={form.customer_phone} onChange={(e) => set('customer_phone', e.target.value)} />
                    <div className="grid grid-cols-2 gap-3">
                        <input className={INPUT} placeholder={t('Serial No')} value={form.serial_no} onChange={(e) => set('serial_no', e.target.value)} />
                        <input className={INPUT} placeholder={t('Invoice No')} value={form.invoice_no} onChange={(e) => set('invoice_no', e.target.value)} />
                    </div>
                    <textarea className={TEXTAREA} placeholder={t('Issue')} value={form.issue} onChange={(e) => set('issue', e.target.value)} />
                    <div className="grid grid-cols-2 gap-3">
                        <select className={INPUT} value={form.type} onChange={(e) => set('type', e.target.value)}>
                            {(vocab.types || []).map((x) => <option key={x} value={x}>{x}</option>)}
                        </select>
                        <input className={INPUT} type="date" value={form.received_date} onChange={(e) => set('received_date', e.target.value)} />
                    </div>
                    <div className="flex items-center justify-end gap-2">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Create Claim')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function ClaimAction({ claim, vocab, close }) {
    const [form, setForm] = useState({ status: claim.status, resolution: claim.resolution || '', reject_reason: claim.reject_reason || '', cancel_reason: claim.cancel_reason || '', note: claim.note || '' });
    const set = (k, v) => setForm({ ...form, [k]: v });
    const save = () => router.patch(`/inventory/warranty/${claim.id}`, form, { preserveScroll: true, onSuccess: close });
    return (
        <div className="overlay" onClick={close}>
            <div className="modal invx" style={{ maxWidth: 480, width: '92vw', padding: 0 }} onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b">
                    <div className="text-lg font-semibold">{claim.claim_no} - {claim.customer_name}</div>
                    <button type="button" className="text-xl leading-none" onClick={close}>×</button>
                </div>
                <div className="p-5 space-y-3">
                    <div className="space-y-1">
                        <label className="text-sm font-medium">{t('Status')}</label>
                        <select className={INPUT} value={form.status} onChange={(e) => set('status', e.target.value)}>
                            {FLOW.concat(['rejected', 'cancelled']).map((s) => <option key={s} value={s}>{t(STATUS_LABEL[s])}</option>)}
                        </select>
                    </div>
                    {form.status === 'closed' && (
                        <div className="space-y-1">
                            <label className="text-sm font-medium">{t('Resolution')}</label>
                            <select className={INPUT} value={form.resolution} onChange={(e) => set('resolution', e.target.value)}>
                                <option value="">-</option>
                                {(vocab.resolutions || []).map((x) => <option key={x} value={x}>{x}</option>)}
                            </select>
                        </div>
                    )}
                    {form.status === 'rejected' && (
                        <div className="space-y-1">
                            <label className="text-sm font-medium">{t('Reject Reason')}</label>
                            <select className={INPUT} value={form.reject_reason} onChange={(e) => set('reject_reason', e.target.value)}>
                                <option value="">-</option>
                                {(vocab.reject_reasons || []).map((x) => <option key={x} value={x}>{x}</option>)}
                            </select>
                        </div>
                    )}
                    {form.status === 'cancelled' && (
                        <div className="space-y-1">
                            <label className="text-sm font-medium">{t('Cancel Reason')}</label>
                            <select className={INPUT} value={form.cancel_reason} onChange={(e) => set('cancel_reason', e.target.value)}>
                                <option value="">-</option>
                                {(vocab.cancel_reasons || []).map((x) => <option key={x} value={x}>{x}</option>)}
                            </select>
                        </div>
                    )}
                    <textarea className={TEXTAREA} placeholder={t('Note')} value={form.note} onChange={(e) => set('note', e.target.value)} />
                    <div className="flex items-center justify-end gap-2">
                        <button type="button" className={BTN_OUTLINE} onClick={close}>{t('Cancel')}</button>
                        <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Save')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function WarrantySettings({ vocab }) {
    const [period, setPeriod] = useState(vocab.default_period || 'No Warranty');
    const [type, setType] = useState(vocab.default_type || 'Repair');
    const [terms, setTerms] = useState(vocab.terms || '');
    const [lists, setLists] = useState({
        types: (vocab.types || []).join('\n'),
        resolutions: (vocab.resolutions || []).join('\n'),
        reject_reasons: (vocab.reject_reasons || []).join('\n'),
        cancel_reasons: (vocab.cancel_reasons || []).join('\n'),
    });
    const [days, setDays] = useState(vocab.turnaround_days || 7);
    const save = () => router.post('/inventory/warranty-settings', {
        default_period: period, default_type: type, terms,
        types: lists.types.split('\n').map((s) => s.trim()).filter(Boolean),
        resolutions: lists.resolutions.split('\n').map((s) => s.trim()).filter(Boolean),
        reject_reasons: lists.reject_reasons.split('\n').map((s) => s.trim()).filter(Boolean),
        cancel_reasons: lists.cancel_reasons.split('\n').map((s) => s.trim()).filter(Boolean),
        turnaround_days: Number(days),
    }, { preserveScroll: true, onSuccess: () => router.reload({ only: ['vocab'], preserveScroll: true }) });
    return (
        <div className="rounded-xl border bg-card p-5 space-y-4 max-w-2xl">
            <div className="font-semibold">{t('Warranty Settings')}</div>
            <div className="space-y-1">
                <label className="text-sm font-medium">{t('Default Warranty Period')}</label>
                <select className={INPUT} value={period} onChange={(e) => setPeriod(e.target.value)}>
                    {PERIODS.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                </select>
            </div>
            <div className="space-y-1">
                <label className="text-sm font-medium">{t('Warranty Type')}</label>
                <select className={INPUT} value={type} onChange={(e) => setType(e.target.value)}>
                    {CLAIM_TYPES.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                </select>
            </div>
            <div className="space-y-1">
                <label className="text-sm font-medium">{t('Turnaround Days')}</label>
                <input className={INPUT} type="number" min="1" max="90" value={days} onChange={(e) => setDays(e.target.value)} />
            </div>
            <div className="space-y-1">
                <label className="text-sm font-medium">{t('Warranty Terms')}</label>
                <textarea className={TEXTAREA} placeholder={t('Terms customers see on the invoice')} value={terms} onChange={(e) => setTerms(e.target.value)} />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {[
                    ['types', 'Claim Types (one per line)'],
                    ['resolutions', 'Resolutions (one per line)'],
                    ['reject_reasons', 'Reject Reasons (one per line)'],
                    ['cancel_reasons', 'Cancel Reasons (one per line)'],
                ].map(([key, label]) => (
                    <div key={key} className="space-y-1">
                        <label className="text-sm font-medium">{t(label)}</label>
                        <textarea className={TEXTAREA} value={lists[key]} onChange={(e) => setLists({ ...lists, [key]: e.target.value })} />
                    </div>
                ))}
            </div>
            <button type="button" className={BTN_PRIMARY} onClick={save}>{t('Save Settings')}</button>
        </div>
    );
}
