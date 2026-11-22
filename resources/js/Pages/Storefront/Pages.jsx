import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

const STATUS = { live: ['Live', 'green'], draft: ['Draft', 'amber'], paused: ['Paused', ''] };
const TEMPLATES = [
    ['single', 'Single product'],
    ['store', 'Full store'],
    ['offer', 'Offer page'],
];

export default function Pages({ pages }) {
    const [showForm, setShowForm] = useState(false);
    const [name, setName] = useState('');
    const [template, setTemplate] = useState('store');
    const [copiedId, setCopiedId] = useState(null);

    const copy = async (page) => {
        try { await navigator.clipboard.writeText(page.url); setCopiedId(page.id); setTimeout(() => setCopiedId(null), 1800); } catch { /* ignore */ }
    };

    const askDelete = async (page) => {
        if (await confirmDialog(t('Delete this page? The page and its link will stop working. This cannot be undone.'))) {
            router.delete(`/storefront/pages/${page.id}`, { preserveScroll: true });
        }
    };
    const askPause = async (page) => {
        if (await confirmDialog(t('Pause this page? It will show a short "paused" note to visitors until you resume it.'))) {
            router.post(`/storefront/pages/${page.id}/pause`, {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title={t('My Pages')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
                <h3 style={{ fontSize: 19, margin: 0 }}>{t('My Pages')}</h3>
                <span style={{ flex: 1 }} />
                <button className="btn pri" onClick={() => setShowForm(!showForm)}>+ {t('New page')}</button>
            </div>
            <p style={{ color: 'var(--muted)', fontSize: 14, margin: '0 0 14px' }}>{t('Every page gets its own link automatically. Products on every page come from your Inventory by themselves.')}</p>

            {showForm && (
                <div className="card sf-form" style={{ marginBottom: 14 }}>
                    <div style={{ fontWeight: 700, fontSize: 15.5, marginBottom: 8 }}>{t('New page')}</div>
                    <label className="lbl">{t('Page name')}</label>
                    <input className="fld" value={name} onChange={(e) => setName(e.target.value)} maxLength={60} placeholder={t('e.g. Eid Offer')} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Design')}</label>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 6 }}>
                        {TEMPLATES.map(([key, label]) => (
                            <button key={key} type="button" className={`btn ${template === key ? 'pri' : ''}`} onClick={() => setTemplate(key)}>{t(label)}</button>
                        ))}
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                        <button className="btn pri" disabled={name.trim().length < 2} onClick={() => router.post('/storefront/pages', { name, template })}>{t('Create page')}</button>
                        <button className="btn" onClick={() => setShowForm(false)}>{t('Cancel')}</button>
                    </div>
                </div>
            )}

            <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
                <table className="tbl" style={{ width: '100%', minWidth: 720 }}>
                    <thead>
                        <tr>
                            <th>{t('Page')}</th><th>{t('Link')}</th><th>{t('Status')}</th>
                            <th>{t('Views')}</th><th>{t('Orders')}</th><th>{t('Updated')}</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {pages.map((p) => {
                            const [sLabel, sTone] = STATUS[p.status] || [p.status, ''];
                            return (
                                <tr key={p.id}>
                                    <td>
                                        <div style={{ fontWeight: 600 }}>{p.name}</div>
                                        {p.isRoot && <span className="pill" style={{ marginTop: 2 }}>{t('Main page')}</span>}
                                        {p.hasUnpublishedChanges && <span className="pill amber" style={{ marginTop: 2, marginLeft: 4 }}>{t('Unpublished changes')}</span>}
                                    </td>
                                    <td>
                                        <span style={{ fontSize: 13, wordBreak: 'break-all' }}>{p.url.replace(/^https?:\/\//, '')}</span>
                                        <button className="btn" style={{ marginLeft: 6, height: 28, padding: '0 9px', fontSize: 12.5 }} onClick={() => copy(p)}>{copiedId === p.id ? t('Copied!') : t('Copy')}</button>
                                    </td>
                                    <td><span className={`pill ${sTone}`}>{t(sLabel)}</span></td>
                                    <td>{p.views}</td>
                                    <td>{p.orders}</td>
                                    <td style={{ whiteSpace: 'nowrap', fontSize: 13 }}>{p.updated}</td>
                                    <td style={{ whiteSpace: 'nowrap' }}>
                                        <Link className="btn" href={`/storefront/pages/${p.id}/edit`}>{t('Edit page')}</Link>
                                        <a className="btn" style={{ marginLeft: 6 }} href={`/storefront/pages/${p.id}/preview`} target="_blank" rel="noreferrer">{t('Preview')}</a>
                                        <button className="btn" style={{ marginLeft: 6 }} onClick={() => router.post(`/storefront/pages/${p.id}/duplicate`, {}, { preserveScroll: true })}>{t('Duplicate')}</button>
                                        {!p.isRoot && p.status === 'live' && <button className="btn" style={{ marginLeft: 6 }} onClick={() => askPause(p)}>{t('Pause')}</button>}
                                        {!p.isRoot && p.status === 'paused' && <button className="btn" style={{ marginLeft: 6 }} onClick={() => router.post(`/storefront/pages/${p.id}/resume`, {}, { preserveScroll: true })}>{t('Resume')}</button>}
                                        {!p.isRoot && <button className="btn" style={{ marginLeft: 6, color: '#B91C1C', borderColor: '#F3C2BF' }} onClick={() => askDelete(p)}>{t('Delete')}</button>}
                                    </td>
                                </tr>
                            );
                        })}
                        {pages.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', color: 'var(--muted)', padding: 18 }}>{t('No pages yet.')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style>
        </AppLayout>
    );
}
