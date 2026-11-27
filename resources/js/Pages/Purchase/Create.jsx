import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { GuideBtn, ItemsTable, PageHead, ProductPicker } from './shared';
import Spinner from '@/Components/Spinner';

// New Purchase - exact copy of EcomDrive's New Purchase: the
// "Click To Add Products" picker card beside the purchase form
// (Supplier* · Purchase date · Note · Tags · Attachments). The
// selected-items table + totals state has no source HTML (the save
// captured an empty picker), so it is built in the same language.
export default function Create({ products, suppliers, today }) {
    const [lines, setLines] = useState([]);
    const [files, setFiles] = useState([]);
    const fileRef = useRef(null);
    const form = useForm({ supplier_id: '', date: today, note: '', tags: '' });

    const pick = (p) => setLines([...lines, { product_id: p.id, name: p.name, sku: p.sku, qty: 1, unit_cost: p.cost }]);

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, items: lines.map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0, unit_cost: Number(l.unit_cost) || 0 })), attachments: files }));
        form.post('/purchases', { forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title={t('New Purchase')} />
            <div className="pur">
                <PageHead title={t('New Purchase')}>
                    <GuideBtn />
                    <Link href="/purchases" className="btn">{t('Purchase List')}</Link>
                </PageHead>

                <form onSubmit={submit}>
                    <div className="grid2" style={{ alignItems: 'start' }}>
                        <ProductPicker products={products} onPick={pick} pickedIds={lines.map((l) => l.product_id)} />
                        <div className="card">
                            <div className="fld">
                                <label>{t('Supplier')} *</label>
                                <select className="inp" value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)} required>
                                    <option value="">{t('Choose supplier')}</option>
                                    {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                {form.errors.supplier_id && <div className="sm" style={{ color: 'var(--bad)' }}>{form.errors.supplier_id}</div>}
                            </div>
                            <div className="fld">
                                <label>{t('Purchase date')}</label>
                                <input className="inp" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} required />
                            </div>
                            <div className="fld">
                                <label>{t('Note')}</label>
                                <textarea className="inp" rows={3} placeholder={t('Invoice number, delivery details...')} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            </div>
                            <div className="fld">
                                <label>{t('Tags')}</label>
                                <input className="inp" placeholder={t('Choose tags')} value={form.data.tags} onChange={(e) => form.setData('tags', e.target.value)} />
                            </div>
                            <div className="fld">
                                <label>{t('Attachments (Optional)')}</label>
                                <div className="upload-zone" onClick={() => fileRef.current?.click()}>
                                    <span className="b" style={{ color: 'var(--acc-ink)' }}>{t('Click to upload')}</span> {t('or drag and drop')}
                                    <div className="sm" style={{ marginTop: 4 }}>{t('Images or PDF, up to 5MB')} · {files.length}/10</div>
                                </div>
                                <input ref={fileRef} type="file" multiple accept="image/*,.pdf" style={{ display: 'none' }}
                                    onChange={(e) => setFiles(Array.from(e.target.files || []).slice(0, 10))} />
                                {files.length > 0 && (
                                    <div style={{ marginTop: 8 }}>
                                        {files.map((f, i) => <div key={i} className="sm">{f.name}</div>)}
                                    </div>
                                )}
                            </div>
                            <button type="submit" className="btn pri" disabled={form.processing || lines.length === 0} style={{ width: '100%', justifyContent: 'center' }}>
                                {form.processing && <Spinner color="#fff" />}{form.processing ? t('Saving...') : t('Save Purchase')}
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
