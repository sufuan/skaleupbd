import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { t } from '@/lib/i18n';

// Money exactly as EcomDrive prints it: Indian-style grouping (3,44,850).
export const fmt = (n) => '৳' + Number(Math.round(Number(n) || 0)).toLocaleString('en-IN');

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const fmtDate = (iso) => {
    if (!iso) return '—';
    const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    if (!y || !m || !d) return iso;
    return `${d} ${MONTHS[m - 1]} ${y}`;
};

export const dhakaToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' });

// Page header in the EcomDrive shape: title left, Guide + primary action right.
export function PageHead({ title, children }) {
    return (
        <div className="flex" style={{ alignItems: 'center', marginBottom: 16 }}>
            <h1 className="page-h" style={{ margin: 0 }}>{title}</h1>
            <span className="spacer" />
            <div className="flex" style={{ gap: 8 }}>{children}</div>
        </div>
    );
}

export function GuideBtn() {
    return <button type="button" className="btn">{t('Guide')}</button>;
}

/**
 * Product picker — copied from New Purchase's "Click To Add Products"
 * card: two search boxes (Code/sku + Name) over a scrollable product
 * list; each row shows image/No Image, name, SKU, Purchase Price, Stock.
 * Clicking a row hands the product to the parent.
 */
export function ProductPicker({ products, onPick, pickedIds = [] }) {
    const [skuQ, setSkuQ] = useState('');
    const [nameQ, setNameQ] = useState('');
    const rows = useMemo(() => products.filter((p) =>
        (!skuQ || String(p.sku || '').toLowerCase().includes(skuQ.toLowerCase())) &&
        (!nameQ || p.name.toLowerCase().includes(nameQ.toLowerCase()))), [products, skuQ, nameQ]);
    return (
        <div className="card">
            <div className="b" style={{ fontSize: 15, marginBottom: 12 }}>{t('Click To Add Products')}</div>
            <div className="grid2" style={{ gap: 10, marginBottom: 6 }}>
                <div>
                    <label className="sm mut" style={{ display: 'block', marginBottom: 4 }}>{t('Code/sku')}</label>
                    <input className="inp" style={{ width: '100%' }} placeholder={t('Type to Search..')} value={skuQ} onChange={(e) => setSkuQ(e.target.value)} />
                </div>
                <div>
                    <label className="sm mut" style={{ display: 'block', marginBottom: 4 }}>{t('Name')}</label>
                    <input className="inp" style={{ width: '100%' }} placeholder={t('Type to Search..')} value={nameQ} onChange={(e) => setNameQ(e.target.value)} />
                </div>
            </div>
            <div style={{ maxHeight: 380, overflowY: 'auto' }}>
                {rows.map((p) => (
                    <button key={p.id} type="button" className="pick-row" disabled={pickedIds.includes(p.id)} style={pickedIds.includes(p.id) ? { opacity: 0.45 } : undefined} onClick={() => onPick(p)}>
                        <span className="pick-img">{p.image ? <img src={p.image} alt="" /> : t('No Image')}</span>
                        <span style={{ flex: 1, minWidth: 0 }}>
                            <span className="b" style={{ display: 'block', fontSize: 13.5 }}>{p.name}</span>
                            <span className="sm mut" style={{ display: 'block' }}>
                                {t('SKU:')} {p.sku} · {t('Purchase Price:')} {fmt(p.cost)} · {t('Stock:')} {p.stock}
                            </span>
                        </span>
                    </button>
                ))}
                {rows.length === 0 && <div className="sm mut" style={{ padding: '14px 6px' }}>{t('No products found')}</div>}
            </div>
        </div>
    );
}

/** Selected-items table (qty + unit cost + line total) shared by New Purchase and Quick Stock. */
export function ItemsTable({ lines, setLines }) {
    const total = lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.unit_cost) || 0), 0);
    const set = (id, field, v) => setLines(lines.map((l) => (l.product_id === id ? { ...l, [field]: v } : l)));
    const remove = (id) => setLines(lines.filter((l) => l.product_id !== id));
    if (lines.length === 0) return null;
    return (
        <div className="card" style={{ marginTop: 16 }}>
            <div className="tbl-wrap">
                <table className="tbl">
                    <thead><tr><th>{t('Product')}</th><th>{t('Qty')}</th><th>{t('Unit cost')}</th><th>{t('Line total')}</th><th></th></tr></thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.product_id}>
                                <td><span className="b">{l.name}</span><span className="sm mut" style={{ display: 'block' }}>{t('SKU:')} {l.sku}</span></td>
                                <td><input className="inp" type="number" min="1" style={{ width: 90 }} value={l.qty} onChange={(e) => set(l.product_id, 'qty', e.target.value)} /></td>
                                <td><input className="inp" type="number" min="0" step="any" style={{ width: 120 }} value={l.unit_cost} onChange={(e) => set(l.product_id, 'unit_cost', e.target.value)} /></td>
                                <td className="b">{fmt((Number(l.qty) || 0) * (Number(l.unit_cost) || 0))}</td>
                                <td><button type="button" className="mini-link" onClick={() => remove(l.product_id)}>{t('Remove')}</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex" style={{ marginTop: 12 }}>
                <span className="spacer" />
                <span className="b" style={{ fontSize: 15 }}>{t('Total')} {fmt(total)}</span>
            </div>
        </div>
    );
}

/**
 * Pay form (modal) — same shape wherever a Pay button appears:
 * amount (default = due), date, account from Finance, note. Posts to
 * the given URL; the server posts exactly one Finance entry.
 */
export function PayModal({ title, due, accounts, today, action, onClose }) {
    const form = useForm({ amount: due || '', account_id: accounts[0]?.id || '', date: today, note: '' });
    return (
        <div className="overlay" onClick={onClose}>
            <div className="modal" onClick={(e) => e.stopPropagation()}>
                <div className="b" style={{ fontSize: 16, marginBottom: 4 }}>{title}</div>
                <div className="sm mut" style={{ marginBottom: 14 }}>{t('Due')} {fmt(due)}</div>
                <form onSubmit={(e) => { e.preventDefault(); form.post(action, { onSuccess: onClose }); }}>
                    <div className="fld">
                        <label>{t('Amount')}</label>
                        <input className="inp" type="number" min="0.01" step="any" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                    </div>
                    <div className="fld">
                        <label>{t('Account')}</label>
                        <select className="inp" value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)} required>
                            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                        {accounts.length === 0 && <div className="sm" style={{ color: 'var(--bad)', marginTop: 4 }}>{t('Add a Finance account first')}</div>}
                    </div>
                    <div className="fld">
                        <label>{t('Date')}</label>
                        <input className="inp" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} required />
                    </div>
                    <div className="fld">
                        <label>{t('Note')}</label>
                        <input className="inp" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </div>
                    <div className="flex" style={{ gap: 8, marginTop: 6 }}>
                        <span className="spacer" />
                        <button type="button" className="btn" onClick={onClose}>{t('Cancel')}</button>
                        <button type="submit" className="btn pri" disabled={form.processing || accounts.length === 0}>{t('Pay')}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export { Link };
