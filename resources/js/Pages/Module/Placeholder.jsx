import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';

export default function ModulePlaceholder({ title }) {
    return (
        <AppLayout>
            <Head title={title} />
            <h1 className="page-h">{title}</h1>
            <div className="page-sub">Module-by-module build plan অনুযায়ী এই module-টা তার ধাপে তৈরি হবে</div>
            <div className="card">
                <h3>এই module এখনো তৈরি হয়নি</h3>
                <p className="mut" style={{ marginTop: 8, maxWidth: 560 }}>
                    আমরা একটা করে module ধরে বানাচ্ছি — প্রতিটা module তার Settings-সহ dynamic করে, test করে, তারপর পরেরটায় যাই।
                    Step 1-এ Dashboard আর Settings-এর কাঠামো তৈরি হয়েছে। এই module-এর পালা এলে এখানে আসল page বসবে।
                </p>
                <div className="flex mt16">
                    <Link href="/dashboard" className="btn pri">Dashboard-এ ফিরে যাও</Link>
                    <Link href="/settings" className="btn">Settings দেখো</Link>
                </div>
            </div>
        </AppLayout>
    );
}
