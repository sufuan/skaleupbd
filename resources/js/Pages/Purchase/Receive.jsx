import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { PageHead, fmtDate } from './shared';
import Spinner from '@/Components/Spinner';

// Receive form - no source HTML in the EcomDrive saves (only the queue
// exists); built in the same table language: per line Ordered /
// Already received / Now receiving / Damaged-missing, with Left shown.
// Damaged qty never enters stock.
export default function Receive({ purchase }) {
    const initial = {};
    purchase.items.forEach((i) => { initial[i.id] = { received: '', damaged: '' }; });
    const form = useForm({ lines: initial });

    const set = (id, field, v) => form.setData('lines', { ...form.data.lines, [id]: { ...form.data.lines[id], [field]: v } });

    return (
        <AppLayout>
            <Head title={t('Receive Purchase')} />
            <div className="pur">
                <PageHead title={t('Receive Purchase')}>
                    <Link href="/purchases?tab=receive" className="btn">{t('Purchase List')}</Link>
                </PageHead>
                <div className="card">
                    <div className="flex" style={{ marginBottom: 12, flexWrap: 'wrap' }}>
                        <span className="b">{purchase.supplier || t('Quick Stock Increase')}</span>
                        <span className="sm mut">{fmtDate(purchase.date)}</span>
                    </div>
                    <form onSubmit={(e) => { e.preventDefault(); form.post(`/purchases/${purchase.id}/receive`); }}>
                        <div className="tbl-wrap">
                            <table className="tbl">
                                <thead><tr>
                                    <th>{t('Items')}</th><th>{t('Ordered')}</th><th>{t('Already received')}</th>
                                    <th>{t('Now receiving')}</th><th>{t('Damaged / missing')}</th><th>{t('Left')}</th>
                                </tr></thead>
                                <tbody>
                                    {purchase.items.map((i) => (
                                        <tr key={i.id}>
                                            <td className="b">{i.product}</td>
                                            <td>{i.ordered}</td>
                                            <td>{i.received}{i.damaged > 0 ? ` (+${i.damaged} ${t('damaged')})` : ''}</td>
                                            <td><input className="inp" type="number" min="0" max={i.left} style={{ width: 100 }} value={form.data.lines[i.id]?.received ?? ''} onChange={(e) => set(i.id, 'received', e.target.value)} placeholder="0" /></td>
                                            <td><input className="inp" type="number" min="0" max={i.left} style={{ width: 100 }} value={form.data.lines[i.id]?.damaged ?? ''} onChange={(e) => set(i.id, 'damaged', e.target.value)} placeholder="0" /></td>
                                            <td className="b">{i.left}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="note-box sm" style={{ margin: '14px 0' }}>{t('Damaged / missing goods never enter stock. Anything left stays in To receive.')}</div>
                        <div className="flex">
                            <span className="spacer" />
                            <button type="submit" className="btn pri" disabled={form.processing}>{form.processing && <Spinner color="#fff" />}{form.processing ? t('Saving...') : t('Save Receive')}</button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
