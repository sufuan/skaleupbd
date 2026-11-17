import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { StatusPill, fmt } from './ui';

export default function Invoice({ order, business, template }) {
    return (
        <div className="invoice-page">
            <Head title={`${t('Invoice')} — ${order.invoice}`} />
            <div className="no-print flex" style={{ marginBottom: 16 }}>
                <Link href={`/orders/${order.id}`} className="btn">← {t('Back to Order')}</Link>
                <span className="spacer" />
                <button className="btn pri" onClick={() => window.print()}>{t('Print / Download')}</button>
            </div>
            <div className={`invoice-sheet ${template === 'a5' ? 'a5' : ''}`}>
                <div className="inv-head">
                    <div>
                        <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--acc)' }}>{business.name}</div>
                        <div className="sm mut">{business.address}</div>
                        <div className="sm mut">{business.mobile}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 20, fontWeight: 800, letterSpacing: '.12em' }}>{t('INVOICE')}</div>
                        <div className="sm">{order.invoice}</div>
                        <div className="sm mut">{order.created} · {order.created_time}</div>
                    </div>
                </div>
                <div className="inv-sec">
                    <div className="b sm">{t('Bill To')}</div>
                    <div>{order.name}</div>
                    <div className="sm">{order.phone}</div>
                    <div className="sm mut">{order.address}</div>
                </div>
                <table className="tbl">
                    <thead><tr><th>{t('Product')}</th><th>{t('Qty')}</th><th>{t('Price')}</th><th>{t('Total')}</th></tr></thead>
                    <tbody>
                        {order.items.map((i, ix) => (
                            <tr key={ix}><td>{i.name}{i.sku ? ` (${i.sku})` : ''}</td><td>{i.qty}</td><td>{fmt(i.price)}</td><td>{fmt(i.price * i.qty)}</td></tr>
                        ))}
                    </tbody>
                </table>
                <div className="totals" style={{ maxWidth: 300, marginLeft: 'auto' }}>
                    <div className="kv"><span>{t('Subtotal')}</span><b>{fmt(order.subtotal)}</b></div>
                    <div className="kv"><span>{t('Delivery Charge')}</span><b>{fmt(order.delivery_charge)}</b></div>
                    <div className="kv"><span>{t('Discount')}</span><b>− {fmt(order.discount)}</b></div>
                    <div className="kv big"><span>{t('Grand Total')}</span><b>{fmt(order.total)}</b></div>
                    <div className="kv"><span>{t('Advance')}</span><b>{fmt(order.advance)}</b></div>
                    <div className="kv"><span>{t('Due Amount')}</span><b>{fmt(order.due)}</b></div>
                </div>
                <div className="flex mt16">
                    <span className="sm mut">{t('Status at download time')}:</span>
                    <StatusPill status={order.status} />
                    <span className="spacer" />
                    <span className="sm mut">{t('Payment Method')}: {order.payment_method}</span>
                </div>
            </div>
        </div>
    );
}
