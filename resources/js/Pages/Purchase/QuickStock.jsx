import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { GuideBtn, ItemsTable, PageHead, ProductPicker } from './shared';

// Quick Stock — no source HTML in the EcomDrive saves; built in the
// same visual language: the same product picker, qty + unit cost per
// line, a date. Saving stocks the goods in at once, with no due.
export default function QuickStock({ products, today }) {
    const [lines, setLines] = useState([]);
    const form = useForm({ date: today, note: '' });
    const pick = (p) => setLines([...lines, { product_id: p.id, name: p.name, sku: p.sku, qty: 1, unit_cost: p.cost }]);

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, items: lines.map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0, unit_cost: Number(l.unit_cost) || 0 })) }));
        form.post('/purchases/quick');
    };

    return (
        <AppLayout>
            <Head title={t('Quick Stock')} />
            <div className="pur">
                <PageHead title={t('Quick Stock')}>
                    <GuideBtn />
                    <Link href="/purchases" className="btn">{t('Purchase List')}</Link>
                </PageHead>
                <form onSubmit={submit}>
                    <div className="grid2" style={{ alignItems: 'start' }}>
                        <ProductPicker products={products} onPick={pick} pickedIds={lines.map((l) => l.product_id)} />
                        <div className="card">
                            <div className="fld">
                                <label>{t('Date')}</label>
                                <input className="inp" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} required />
                            </div>
                            <div className="fld">
                                <label>{t('Note')}</label>
                                <textarea className="inp" rows={3} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            </div>
                            <div className="note-box sm" style={{ marginBottom: 14 }}>{t('Quick Stock has no supplier bill — the goods go into stock at once and nothing is owed.')}</div>
                            <button type="submit" className="btn pri" disabled={form.processing || lines.length === 0} style={{ width: '100%', justifyContent: 'center' }}>
                                {t('Add Stock')}
                            </button>
                            {lines.length === 0 && <div className="sm mut" style={{ marginTop: 6 }}>{t('Add at least one product')}</div>}
                        </div>
                    </div>
                    <ItemsTable lines={lines} setLines={setLines} />
                </form>
            </div>
        </AppLayout>
    );
}
