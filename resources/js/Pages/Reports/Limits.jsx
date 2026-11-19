import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

export default function Limits() {
    return (
        <AppLayout>
            <Head title="Reports — My Limits" />
            <div style={{ maxWidth: 560, background: '#fff', border: '1px solid #E7EAF0', borderRadius: 16, padding: 26 }}>
                <h3 style={{ fontSize: 19, marginBottom: 8 }}>My Limits — এখানে নেই</h3>
                <p style={{ color: '#64748B', fontSize: 14, lineHeight: 1.6 }}>Plan-এর limit আর balance (SMS, Auto Call) এখন <b>Settings</b>-এ দেখা যায়, আর SMS balance Marketing page-এও আছে — একই জিনিস দুই জায়গায় রাখা হয়নি। এই page-টা তাই বন্ধ রাখা হলো।</p>
                <p style={{ color: '#94A3B8', fontSize: 13, marginTop: 12 }}>সরাসরি link-এ এলে এই লেখাটাই দেখাবে; menu-তে My Limits আর নেই।</p>
            </div>
        </AppLayout>
    );
}
