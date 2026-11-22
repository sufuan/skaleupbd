import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [['ip', 'IP Addresses'], ['phone', 'Mobile Numbers'], ['device', 'Devices']];

export default function BlockList({ blocks }) {
    const [tab, setTab] = useState('ip');
    const [open, setOpen] = useState(false);
    const form = useForm({ type: 'ip', value: '', reason: '', expires_days: '' });
    const rows = blocks.filter((b) => b.type === tab);
    const submit = (e) => { e.preventDefault(); form.post('/orders/block-list', { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } }); };

    return (
        <AppLayout>
            <Head title={t('Order Block List')} />
            <div className="flex" style={{ flexWrap: 'wrap' }}>
                <div>
                    <h1 className="page-h">{t('Order Block List')}</h1>
                    <div className="page-sub">{t('Blocked numbers, IPs and devices are flagged at order intake by the fraud shield.')}</div>
                </div>
                <span className="spacer" />
                <div className="flex">
                    <button className="btn" onClick={() => router.post('/orders/block-list/cleanup', {}, { preserveScroll: true })}>{t('Cleanup Expired')}</button>
                    <button className="btn pri" onClick={() => { form.setData('type', tab); setOpen(true); }}>{t('Block New')} {tab === 'ip' ? 'IP' : tab === 'phone' ? t('Mobile') : t('Device')}</button>
                </div>
            </div>

            <div className="ptabs">
                {TABS.map(([k, label]) => <span key={k} className={`ptab ${tab === k ? 'act' : ''}`} onClick={() => setTab(k)}>{t(label)}</span>)}
            </div>

            <div className="flex mb12" style={{ flexWrap: 'wrap' }}>
                <span className="pill">{t('Blocked IPs')} {blocks.filter((b) => b.type === 'ip').length}/2500</span>
                <span className="pill">{t('Blocked Mobile Numbers')} {blocks.filter((b) => b.type === 'phone').length}/2500</span>
                <span className="pill">{t('Blocked Devices')} {blocks.filter((b) => b.type === 'device').length}/2500</span>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{tab === 'ip' ? t('IP Address') : tab === 'phone' ? t('Mobile Number') : t('Device Key')}</th><th>{t('Blocked At')}</th><th>{t('Reason')}</th><th>{t('Added By')}</th><th>{t('Expires')}</th><th>{t('Status')}</th><th>{t('Actions')}</th></tr></thead>
                        <tbody>
                            {rows.map((b) => (
                                <tr key={b.id}>
                                    <td className="b">{b.value}</td><td>{b.blocked_at}</td><td>{b.reason || '-'}</td><td>{b.added_by || '-'}</td>
                                    <td>{b.expires || t('Never')}</td>
                                    <td><span className={`pill ${b.expired ? '' : 'red'}`}>{b.expired ? t('Expired') : t('Active')}</span></td>
                                    <td><button className="mini-link" onClick={() => router.delete(`/orders/block-list/${b.id}`, { preserveScroll: true })}>{t('Unblock')}</button></td>
                                </tr>
                            ))}
                            {rows.length === 0 && <tr><td colSpan="7" style={{ textAlign: 'center', padding: 26 }} className="mut">{t('Nothing blocked here yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            {open && (
                <div className="overlay" onClick={() => setOpen(false)}>
                    <form className="modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
                        <h3>{t('Block New')} {form.data.type === 'ip' ? 'IP' : form.data.type === 'phone' ? t('Mobile') : t('Device')}</h3>
                        <div className="frow mt16"><label className="fl">{t('Type')}</label>
                            <select className="inp" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                {TABS.map(([k, l]) => <option key={k} value={k}>{t(l)}</option>)}
                            </select></div>
                        <div className="frow"><label className="fl">{t('Value')}</label>
                            <input className="inp" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} /></div>
                        <div className="frow"><label className="fl">{t('Reason')}</label>
                            <input className="inp" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></div>
                        <div className="frow"><label className="fl">{t('Expires after (days, empty = never)')}</label>
                            <input className="inp" type="number" min="1" value={form.data.expires_days} onChange={(e) => form.setData('expires_days', e.target.value)} /></div>
                        <div className="flex" style={{ justifyContent: 'flex-end' }}>
                            <button type="button" className="btn" onClick={() => setOpen(false)}>{t('Cancel')}</button>
                            <button type="submit" className="btn pri" disabled={form.processing}>{t('Block')}</button>
                        </div>
                    </form>
                </div>
            )}
        </AppLayout>
    );
}
