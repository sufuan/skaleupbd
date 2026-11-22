import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

// Work Inbox Settings - the module's OWN settings page (prototype panel
// wi-settings): all 37 rules with switch, time, priority and assignee,
// plus the notification behaviour switches.
export default function Settings({ groups, toggles, staff, priorities, assignees }) {
    const [rules, setRules] = useState(() => {
        const out = {};
        groups.forEach((g) => g.rules.forEach((r) => {
            out[r.key] = { enabled: r.enabled, value: r.value, unit: r.unit, priority: r.priority, assign: r.assign, assign_user: r.assign_user || '' };
        }));
        return out;
    });
    const [tg, setTg] = useState(toggles);

    const save = (nextRules, nextTg) => router.post('/work-inbox/settings', { rules: nextRules, toggles: nextTg }, { preserveScroll: true });
    const setRule = (key, patch, commit = true) => {
        const next = { ...rules, [key]: { ...rules[key], ...patch } };
        setRules(next);
        if (commit) save(next, tg);
    };
    const setToggle = (name, value) => {
        const next = { ...tg, [name]: value };
        setTg(next);
        save(rules, next);
    };

    return (
        <AppLayout>
            <Head title={t('Work Inbox Settings')} />
            <div className="wi">
                <div className="wi-head">
                    <div>
                        <h3 style={{ fontSize: 20 }}>{t('Work Inbox Settings')}</h3>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>সিস্টেম প্রতি ~5 মিনিটে এই নিয়মগুলো যাচাই করে। যেটা বন্ধ, সেটার task তৈরি হবে না। সময়, গুরুত্ব আর দায়িত্ব - সব এখান থেকে বদলানো যায়। মোট 37টা নিয়ম।</p>
                    </div>
                </div>

                {groups.map((g) => (
                    <div key={g.key}>
                        <div className="rule-group">{g.label} ({g.count}টা নিয়ম)</div>
                        {g.rules.map((r) => {
                            const c = rules[r.key];
                            return (
                                <div className="rule-row" key={r.key}>
                                    <button className={`switch${c.enabled ? ' on' : ''}`} onClick={() => setRule(r.key, { enabled: !c.enabled })}></button>
                                    <div className="rule-main">
                                        <div className="rule-name">{r.num} · {r.name}</div>
                                        <div className="rule-desc">{r.desc}</div>
                                    </div>
                                    {r.immediate ? (
                                        <span className="rule-fld">সাথে সাথে</span>
                                    ) : (
                                        <label className="rule-fld">সময়
                                            <input type="number" min="0" value={c.value}
                                                onChange={(e) => setRule(r.key, { value: e.target.value }, false)}
                                                onBlur={() => save(rules, tg)} />
                                            <select value={c.unit} onChange={(e) => setRule(r.key, { unit: e.target.value })}>
                                                <option value="minutes">মিনিট</option>
                                                <option value="hours">ঘণ্টা</option>
                                                <option value="days">দিন</option>
                                            </select>
                                        </label>
                                    )}
                                    <label className="rule-fld">গুরুত্ব
                                        <select value={c.priority} onChange={(e) => setRule(r.key, { priority: e.target.value })}>
                                            {Object.entries(priorities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                        </select>
                                    </label>
                                    <label className="rule-fld">কাকে যাবে
                                        <select value={c.assign} onChange={(e) => setRule(r.key, { assign: e.target.value })}>
                                            {Object.entries(assignees).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                        </select>
                                    </label>
                                    {c.assign === 'staff' && (
                                        <label className="rule-fld">Staff
                                            <select value={c.assign_user} onChange={(e) => setRule(r.key, { assign_user: e.target.value ? Number(e.target.value) : '' })}>
                                                <option value="">বেছে নাও</option>
                                                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                            </select>
                                        </label>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ))}

                <div className="rule-group">Notification</div>
                <div className="rule-row">
                    <button className={`switch${tg.notify_new ? ' on' : ''}`} onClick={() => setToggle('notify_new', !tg.notify_new)}></button>
                    <div className="rule-main">
                        <div className="rule-name">নতুন task এলে bell notification</div>
                        <div className="rule-desc">দায়িত্বের লোকের কাছে সাথে সাথে যাবে; sidebar-এ Work Inbox-এর পাশে সংখ্যাও দেখাবে</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.notify_overdue ? ' on' : ''}`} onClick={() => setToggle('notify_overdue', !tg.notify_overdue)}></button>
                    <div className="rule-main">
                        <div className="rule-name">Overdue হলে আবার মনে করিয়ে দেওয়া</div>
                        <div className="rule-desc">সময় পার হলে দায়িত্বের লোককে আরেকবার; খুব জরুরি হলে supervisor-ও পাবে</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.auto_close ? ' on' : ''}`} onClick={() => setToggle('auto_close', !tg.auto_close)}></button>
                    <div className="rule-main">
                        <div className="rule-name">সমস্যা মিটে গেলে task নিজে থেকে বন্ধ</div>
                        <div className="rule-desc">যেমন order Delivered হয়ে গেলে "Shipped আটকে আছে" task নিজে বন্ধ হবে - ইতিহাসে কারণ লেখা থাকবে</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.my_work_enabled ? ' on' : ''}`} onClick={() => setToggle('my_work_enabled', !tg.my_work_enabled)}></button>
                    <div className="rule-main">
                        <div className="rule-name">My Work page চালু</div>
                        <div className="rule-desc">চালু করলে প্রতিটা staff নিজের কাজ আলাদা My Work page-এ দেখতে পাবে; বন্ধ থাকলে সব কাজ Work Control থেকেই হবে</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
