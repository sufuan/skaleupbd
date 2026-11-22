import { Head, Link } from '@inertiajs/react';
import { fmt } from './ui';
import { t } from '@/lib/i18n';

/** Print pattern of the invoice page (§5.3): courier, CN with a barcode
 *  strip, COD amount, customer block and the parcel's items. */
export default function PackingSlip({ order, shop }) {
    const code = String(order.consignment_id || order.tracking_id || order.invoice);
    const bars = code.split('').map((ch, i) => 6 + ((ch.charCodeAt(0) * (i + 3)) % 26));

    return (
        <div className="slip-page">
            <Head title={`${t('Packing Slip')} - ${order.invoice}`} />
            <div className="no-print flex" style={{ marginBottom: 16 }}>
                <Link href="/delivery/ready" className="btn">← {t('Back')}</Link>
                <span className="spacer" />
                <button className="btn pri" onClick={() => window.print()}>{t('Print Packing Slip')}</button>
            </div>
            <div className="slip-sheet">
                <div className="inv-head">
                    <div>
                        <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--acc)' }}>{shop.name}</div>
                        <div className="sm mut">{shop.address}</div>
                        <div className="sm mut">{shop.mobile}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 17, fontWeight: 800, letterSpacing: '.12em' }}>{t('PACKING SLIP')}</div>
                        <div className="sm">{order.invoice}</div>
                    </div>
                </div>

                <div className="sm mut">{t('Courier')}</div>
                <div className="b" style={{ fontSize: 18 }}>{order.courier || '-'}</div>
                <div className="slip-bars" aria-hidden="true">
                    {bars.map((h, i) => <i key={i} style={{ height: h + 14 }} />)}
                </div>
                <div className="slip-cn">{code}</div>

                <div className="grid2" style={{ marginTop: 18 }}>
                    <div>
                        <div className="sm mut">{t('Deliver To')}</div>
                        <div className="b" style={{ fontSize: 16 }}>{order.customer_name}</div>
                        <div>{order.customer_phone}</div>
                        <div className="sm">{order.address}{order.area ? `, ${order.area}` : ''}{order.district ? `, ${order.district}` : ''}</div>
                        {order.delivery_note && <div className="sm mut" style={{ marginTop: 6 }}>{order.delivery_note}</div>}
                    </div>
                    <div style={{ textAlign: 'right' }}>
                        <div className="sm mut">{t('Cash on Delivery')}</div>
                        <div className="slip-cod">{fmt(order.cod)}</div>
                    </div>
                </div>

                <table className="tbl" style={{ marginTop: 18 }}>
                    <thead><tr><th>{t('Item')}</th><th style={{ textAlign: 'right' }}>{t('Qty')}</th></tr></thead>
                    <tbody>
                        {order.items.map((i, idx) => <tr key={idx}><td>{i.name}</td><td style={{ textAlign: 'right' }}>{i.qty}</td></tr>)}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
