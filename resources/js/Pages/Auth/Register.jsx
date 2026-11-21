import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register({ pack, onboardingFee }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        pack_id: pack?.id || '',
    });
    const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;
    const num = (n) => Number(n || 0).toLocaleString('en-IN');
    const fee = pack ? Number(onboardingFee || 0) : 0;

    const submit = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout>
            <Head title={t('Create an account')} />
            <h1 className="auth-title">{t('Create an account')}</h1>
            <p className="auth-sub">{t('Start running your shop from one dashboard')}</p>
            {pack && (
                <div style={{ border: '1px solid #E7DEF0', background: '#F6F1FA', borderRadius: 10, padding: '12px 14px', marginBottom: 16 }}>
                    <div style={{ fontSize: 12, letterSpacing: '.05em', color: '#7A6A8A', fontWeight: 700 }}>{t('YOUR CHOSEN PACK')}</div>
                    <div style={{ fontSize: 16, fontWeight: 700, marginTop: 3 }}>{pack.name} — {tk(pack.price_bdt)}</div>
                    <div style={{ fontSize: 13, color: '#5D5266', marginTop: 2 }}>
                        {num(pack.credits)} {t('points')} · {pack.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}
                    </div>
                    <div style={{ fontSize: 13.5, marginTop: 8 }}>
                        {t('Total to pay after signup:')} <b>{tk(Number(pack.price_bdt) + fee)}</b>
                        {fee > 0 && <span style={{ color: '#5D5266' }}> ({t('pack')} {tk(pack.price_bdt)} + {t('one-time onboarding fee')} {tk(fee)})</span>}
                    </div>
                    <div style={{ fontSize: 13, marginTop: 6 }}>{t('Pay from your Points & Billing page once your shop is created.')} <Link href="/pricing" className="auth-link">{t('Change pack')}</Link></div>
                </div>
            )}
            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="name">{t('Name')}</label>
                    <input id="name" type="text" name="name" value={data.name} className="inp" autoComplete="name" autoFocus onChange={(e) => setData('name', e.target.value)} />
                    {errors.name && <div className="auth-err">{errors.name}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="email">{t('Email')}</label>
                    <input id="email" type="email" name="email" value={data.email} className="inp" autoComplete="username" onChange={(e) => setData('email', e.target.value)} />
                    {errors.email && <div className="auth-err">{errors.email}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="password">{t('Password')}</label>
                    <input id="password" type="password" name="password" value={data.password} className="inp" autoComplete="new-password" onChange={(e) => setData('password', e.target.value)} />
                    {errors.password && <div className="auth-err">{errors.password}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="password_confirmation">{t('Confirm password')}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" value={data.password_confirmation} className="inp" autoComplete="new-password" onChange={(e) => setData('password_confirmation', e.target.value)} />
                    {errors.password_confirmation && <div className="auth-err">{errors.password_confirmation}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px', marginTop: 6 }} disabled={processing}>
                    {t('Create an account')}
                </button>
            </form>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                {t('Already have an account?')} <Link href={route('login')} className="auth-link">{t('Log in')}</Link>
            </p>
        </GuestLayout>
    );
}
