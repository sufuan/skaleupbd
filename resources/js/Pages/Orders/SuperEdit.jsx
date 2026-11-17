import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill, fmt } from './ui';

export default function SuperEdit({ order, searched, statuses, recent }) {
    const [invoice, setInvoice] = useState(searched || '');
    const form = useForm({ status: order?.status || 'Pending' });
    const save = (e) => { e.preventDefault(); form.post(`/orders/super-edit/${order.id}`, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title={t('Super Edit')} />
            <h1 className="page-h">{t('Super Edit')}</h1>
            <div className="page-sub">{t('Admin only — override an order status outside the normal flow. Every change is written to the activity log with before and after values.')}</div>

            <div className="card mb12">
                <div className="flex">
                    <input className="inp" style={{ maxWidth: 260 }} placeholder={t('Invoice number, e.g. OB286')} value={invoice} onChange={(e) => setInvoice(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && router.get('/orders/super-edit', { invoice })} />
                    <button className="btn pri" onClick={() => router.get('/orders/super-edit', { invoice })}>{t('Find Order')}</button>
                </div>
                {searched && !order && <div className="sm mt16" style={{ color: 'var(--bad)' }}>{t('No order found with that invoice number')}</div>}
            </div>

            {order && (
                <form onSubmit={save} className="card mb12">
                    <h3>{t('ORDER SNAPSHOT')}</h3>
                    <div className="kv"><span>{t('Invoice')}</span><b>{order.invoice}</b></div>
                    <div className="kv"><span>{t('Customer')}</span><b>{order.name} · {order.phone}</b></div>
                    <div className="kv"><span>{t('Items')}</span><b>{order.items_text}</b></div>
                    <div className="kv"><span>{t('Total')}</span><b>{fmt(order.total)}</b></div>
                    <div className="kv"><span>{t('Current Status')}</span><StatusPill status={order.status} /></div>
                    <div className="frow mt16">
                        <label className="fl">{t('New Status')}</label>
                        <select className="inp" style={{ maxWidth: 280 }} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {statuses.map((s) => <option key={s} value={s}>{t(s)}</option>)}
                        </select>
                    </div>
                    <div className="flex" style={{ justifyContent: 'flex-end' }}>
                        <button type="button" className="btn" onClick={() => router.get('/orders/super-edit')}>{t('Cancel')}</button>
                        <button type="submit" className="btn pri" disabled={form.processing}>{t('Save Override Changes')}</button>
                    </div>
                </form>
            )}

            <div className="card">
                <h3>{t('Recent Override Activity')}</h3>
                {recent.map((r, i) => <div className="kv" key={i}><span>{r.text}</span><b className="sm mut">{r.time}</b></div>)}
                {recent.length === 0 && <div className="sm mut">{t('No overrides yet')}</div>}
            </div>
        </AppLayout>
    );
}
