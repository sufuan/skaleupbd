import { useRef } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function CustomerUpload({ history }) {
    const form = useForm({ file: null });
    const inputRef = useRef(null);

    const submit = (e) => { e.preventDefault(); if (form.data.file) form.post('/customers/upload', { forceFormData: true, onSuccess: () => form.reset() }); };

    return (
        <AppLayout>
            <Head title={t('Bulk Upload Customers')} />
            <h3 style={{ fontSize: 19, marginBottom: 18 }}>{t('Bulk Upload Customers')}</h3>
            <form onSubmit={submit}>
                <div className="card" onClick={() => inputRef.current?.click()}
                    style={{ border: '2px dashed var(--border)', textAlign: 'center', padding: '38px 20px', cursor: 'pointer', marginBottom: 14 }}>
                    <div style={{ fontSize: 15, fontWeight: 600 }}>{form.data.file ? form.data.file.name : t('Drag and drop your CSV / Excel file here, or click to select')}</div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 6 }}>{t('Columns: Name, Phone, Address - duplicate phone numbers are skipped automatically')}</div>
                    <input ref={inputRef} type="file" accept=".csv,.txt" style={{ display: 'none' }} onChange={(e) => form.setData('file', e.target.files?.[0] || null)} />
                </div>
                <div style={{ display: 'flex', gap: 8, marginBottom: 18 }}>
                    <button className="btn-primary" disabled={!form.data.file || form.processing}>{t('Upload & Import')}</button>
                    <a className="btn-ghost" href="/customers/sample-csv">{t('Download Sample CSV')}</a>
                </div>
            </form>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <h3 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Upload History')}</h3>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Date')}</th><th>{t('File')}</th><th>{t('Imported')}</th><th>{t('Skipped')}</th><th>{t('Status')}</th></tr></thead>
                        <tbody>
                            {history.map((h) => (
                                <tr key={h.id}><td>{h.date}</td><td>{h.file}</td><td>{h.imported}</td><td>{h.skipped}</td><td><span className="pill green">{h.status}</span></td></tr>
                            ))}
                            {history.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No uploads yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
