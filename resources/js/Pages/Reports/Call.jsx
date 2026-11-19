import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';

export default function Call({ preset, kpis, best_slot }) {
    return (
        <AppLayout>
            <Head title="Reports — Call Center Report" />
            <div className="rep">
                <RepHead title="Call Center Report" sub="Call-এ কতটা কাজ হচ্ছে — ধরা, কথা, আর তা থেকে order।" preset={preset} base="/reports/call" exportKey="call" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>এই সময়ে মোট call</span></div>
                    <div className="x-kpi green"><b>{kpis.answered_pct}%</b><span>Call ধরা হয়েছে</span></div>
                    <div className="x-kpi"><b>{fmtNum(kpis.orders)}টা</b><span>Call থেকে order</span></div>
                    <div className="x-kpi"><b>{kpis.avg}</b><span>গড় কথার সময় (মিনিট)</span></div>
                </div>
                {best_slot ? (
                    <div className="x-note">সবচেয়ে বেশি order এসেছে {best_slot}-এর call থেকে — ওই সময়ে লোক বাড়ানো যায়।</div>
                ) : (
                    <div className="x-note">এই সময়ে call থেকে কোনো order আসেনি।</div>
                )}
            </div>
        </AppLayout>
    );
}
