import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Modal from '@/Components/Modal';

export const fmt = (n) => '৳' + Number(Math.round(Number(n) || 0)).toLocaleString('en-IN');
export const fmtSigned = (n, dir) => (dir === 'in' ? '+ ' : '− ') + fmt(Math.abs(Number(n) || 0));
export const fmtMoney = (n) => (Number(n) < 0 ? '− ' : '') + fmt(Math.abs(Number(n) || 0));

const KINDS = [
    { key: 'expense', label: 'খরচ' },
    { key: 'income', label: 'আয়' },
    { key: 'transfer', label: 'Transfer' },
    { key: 'deposit', label: 'জমা' },
    { key: 'withdrawal', label: 'তোলা' },
    { key: 'owner_in', label: 'মালিকের জমা' },
    { key: 'owner_out', label: 'মালিকের তোলা' },
    { key: 'courier_payout', label: 'Courier payout' },
];

export default function EntrySheet({ open, kind, setKind, accounts = [], categories = [], onClose }) {
    const form = useForm({
        kind: kind || 'expense', amount: '', account_id: accounts[0]?.id || '',
        to_account_id: accounts[1]?.id || accounts[0]?.id || '', category_id: '', note: '',
        txn_date: new Date().toISOString().slice(0, 10),
    });

    useEffect(() => { if (open) { form.setData('kind', kind || 'expense'); } }, [open, kind]); // eslint-disable-line
    useEffect(() => {
        if (!form.data.account_id && accounts[0]) form.setData('account_id', accounts[0].id);
    }, [accounts]); // eslint-disable-line

    const k = form.data.kind;
    const cats = categories.filter((c) => (k === 'income' ? c.kind === 'income' : c.kind === 'expense'));
    const needsCategory = k === 'income' || k === 'expense';

    const submit = (e) => {
        e.preventDefault();
        form.post('/finance/transactions', { onSuccess: () => { form.reset('amount', 'note'); onClose && onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} style={{ padding: 20 }}>
                <h3 style={{ fontSize: 17, marginBottom: 12 }}>নতুন লেনদেন</h3>
                <div className="cat-chips" style={{ marginBottom: 14 }}>
                    {KINDS.map((x) => (
                        <button type="button" key={x.key} className={k === x.key ? 'on' : ''}
                            onClick={() => { form.setData('kind', x.key); setKind && setKind(x.key); }}>{x.label}</button>
                    ))}
                </div>
                <label className="lbl">পরিমাণ (৳)</label>
                <input className="inp" type="number" min="0" step="any" autoFocus placeholder="0"
                    value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required
                    style={{ fontSize: 20, fontWeight: 700 }} />
                {needsCategory && (
                    <>
                        <label className="lbl" style={{ marginTop: 12 }}>Category</label>
                        <div className="cat-chips">
                            {cats.map((c) => (
                                <button type="button" key={c.id} className={String(form.data.category_id) === String(c.id) ? 'on' : ''}
                                    onClick={() => form.setData('category_id', c.id)}>{c.name}</button>
                            ))}
                        </div>
                    </>
                )}
                <div style={{ display: 'grid', gridTemplateColumns: k === 'transfer' ? '1fr 1fr' : '1fr 1fr', gap: 10, marginTop: 12 }}>
                    <div>
                        <label className="lbl">{k === 'transfer' ? 'কোন account থেকে' : 'Account'}</label>
                        <select className="inp" value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>
                            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                    </div>
                    {k === 'transfer' && (
                        <div>
                            <label className="lbl">কোন account-এ</label>
                            <select className="inp" value={form.data.to_account_id} onChange={(e) => form.setData('to_account_id', e.target.value)}>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                        </div>
                    )}
                    <div>
                        <label className="lbl">তারিখ</label>
                        <input className="inp" type="date" value={form.data.txn_date} onChange={(e) => form.setData('txn_date', e.target.value)} />
                    </div>
                </div>
                <label className="lbl" style={{ marginTop: 12 }}>Note</label>
                <input className="inp" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="কিসের জন্য (না লিখলেও চলবে)" />
                {form.errors.amount && <div style={{ color: '#DC2626', fontSize: 13, marginTop: 6 }}>{form.errors.amount}</div>}
                <div style={{ display: 'flex', gap: 8, marginTop: 16, justifyContent: 'flex-end' }}>
                    <button type="button" className="btn-s" onClick={onClose}>বাদ দাও</button>
                    <button type="submit" className="btn-p" disabled={form.processing}>সেভ করো</button>
                </div>
            </form>
        </Modal>
    );
}

export function FinActions({ accounts, categories }) {
    const [open, setOpen] = useState(false);
    const [kind, setKind] = useState('expense');
    return (
        <>
            <div className="fin-actions">
                <button className="btn-p" onClick={() => { setKind('expense'); setOpen(true); }}>+ খরচ</button>
                <button className="btn-p" onClick={() => { setKind('income'); setOpen(true); }}>+ আয়</button>
                <button className="btn-s" onClick={() => { setKind('transfer'); setOpen(true); }}>⇄ Transfer</button>
            </div>
            <EntrySheet open={open} kind={kind} setKind={setKind} accounts={accounts} categories={categories} onClose={() => setOpen(false)} />
        </>
    );
}
