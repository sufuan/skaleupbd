import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';

const DAYS = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

export default function Settings({ settings, holidays, can_manage }) {
    const form = useForm({
        late_grace: settings.late_grace,
        max_break: settings.max_break,
        weekly_off: settings.weekly_off,
        require_checkin: settings.require_checkin,
        auto_checkout: settings.auto_checkout,
        payroll_enabled: settings.payroll_enabled,
    });
    const hol = useForm({ date: '', name: '' });
    const delHoliday = async (id) => { if (await confirmDialog('এই ছুটির দিন মুছে ফেলতে চান?')) router.delete(`/hrm/holidays/${id}`, { preserveScroll: true }); };

    return (
        <AppLayout title="HR Settings">
            <Head title="HR Settings" />
            <div className="hrm">
                <div className="x-head">
                    <h3>HR Settings</h3>
                    <p>উপস্থিতির নিয়মগুলো এখান থেকে বদলায়। এই page শুধু Admin বদলাতে পারে।</p>
                </div>

                <form onSubmit={(e) => { e.preventDefault(); form.post('/hrm/settings', { preserveScroll: true }); }}>
                    <div className="x-card">
                        <h4>উপস্থিতির নিয়ম</h4>
                        <div className="form-grid">
                            <div className="fld"><label className="lbl">দেরির ছাড় (মিনিট)</label>
                                <input className="inp" type="number" min="0" max="120" value={form.data.late_grace} onChange={(e) => form.setData('late_grace', e.target.value)} disabled={!can_manage} required />
                                <span className="x-note" style={{ marginTop: 2 }}>Shift শুরুর পর এই সময়ের ভিতরে এলে দেরি ধরা হবে না।</span></div>
                            <div className="fld"><label className="lbl">সবচেয়ে বেশি Break (মিনিট)</label>
                                <input className="inp" type="number" min="0" max="480" value={form.data.max_break} onChange={(e) => form.setData('max_break', e.target.value)} disabled={!can_manage} required /></div>
                            <div className="fld"><label className="lbl">সাপ্তাহিক ছুটি</label>
                                <select className="inp" value={form.data.weekly_off} onChange={(e) => form.setData('weekly_off', e.target.value)} disabled={!can_manage}>
                                    {DAYS.map((d) => <option key={d} value={d}>{d}</option>)}
                                </select></div>
                        </div>
                        <div className="switch-row">
                            <div>
                                <b style={{ fontSize: 14 }}>Check-in ছাড়া কাজ বন্ধ</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>চালু থাকলে কর্মী আজ Check-in না করা পর্যন্ত অন্য page খুলবে না। Admin-এর জন্য এই নিয়ম নেই।</div>
                            </div>
                            <input type="checkbox" checked={form.data.require_checkin} onChange={(e) => form.setData('require_checkin', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        <div className="switch-row">
                            <div>
                                <b style={{ fontSize: 14 }}>নিজে থেকে Check-out</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>Shift শেষের ১০ মিনিট পরও Check-out না হলে system নিজে Check-out করে দেবে।</div>
                            </div>
                            <input type="checkbox" checked={form.data.auto_checkout} onChange={(e) => form.setData('auto_checkout', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        {can_manage && <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 12 }}>সেভ করো</button>}
                    </div>

                    <div className="x-card">
                        <h4>Payroll — বেতনের হিসাব</h4>
                        <div className="switch-row" style={{ borderBottom: 'none', padding: 0 }}>
                            <div>
                                <b style={{ fontSize: 14 }}>Payroll চালু করো</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>এখন বন্ধ আছে। চালু করলে sidebar-এ Payroll menu আসবে — সেখানে প্রতি মাসে Attendance দেখে বেতনের হিসাব তৈরি হবে, আর মোট অঙ্কটা Finance-এ খরচ হয়ে যাবে। বন্ধ থাকলে বেতন আগের মতোই Finance-এর খরচ entry দিয়ে চলবে।</div>
                            </div>
                            <input type="checkbox" checked={form.data.payroll_enabled} onChange={(e) => form.setData('payroll_enabled', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        {can_manage && <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 12 }}>সেভ করো</button>}
                    </div>
                </form>

                <div className="x-card">
                    <h4>ছুটির দিন (বছরের)</h4>
                    {holidays.length === 0 && <p style={{ fontSize: 13.5, color: '#94A3B8' }}>এখনো কোনো ছুটির দিন যোগ হয়নি।</p>}
                    {holidays.map((h) => (
                        <div key={h.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #F1F5F9' }}>
                            <span style={{ fontSize: 13.5 }}><b>{h.name}</b> · {h.date}</span>
                            {can_manage && <button type="button" className="btn-mini danger" onClick={() => delHoliday(h.id)}>মুছুন</button>}
                        </div>
                    ))}
                    {can_manage && (
                        <form className="form-grid" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); hol.post('/hrm/holidays', { preserveScroll: true, onSuccess: () => hol.reset() }); }}>
                            <div className="fld"><label className="lbl">তারিখ</label>
                                <input className="inp" type="date" value={hol.data.date} onChange={(e) => hol.setData('date', e.target.value)} required /></div>
                            <div className="fld"><label className="lbl">দিনের নাম</label>
                                <input className="inp" value={hol.data.name} onChange={(e) => hol.setData('name', e.target.value)} placeholder="যেমন: ঈদুল ফিতর" required /></div>
                            <button className="btn-p" type="submit" disabled={hol.processing}>যোগ করো</button>
                        </form>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
