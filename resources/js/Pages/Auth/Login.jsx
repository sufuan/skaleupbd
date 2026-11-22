import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Login({ status, canResetPassword, devLogin }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout>
            <Head title={t('Log in')} />
            <h1 className="auth-title">{t('Welcome back')}</h1>
            <p className="auth-sub">{t('Log in to your shop dashboard')}</p>

            {status && <div className="auth-status">{status}</div>}

            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="email">{t('Email')}</label>
                    <input id="email" type="email" name="email" value={data.email} className="inp" autoComplete="username" autoFocus onChange={(e) => setData('email', e.target.value)} placeholder="you@shop.com" />
                    {errors.email && <div className="auth-err">{errors.email}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="password">{t('Password')}</label>
                    <input id="password" type="password" name="password" value={data.password} className="inp" autoComplete="current-password" onChange={(e) => setData('password', e.target.value)} placeholder="••••••••" />
                    {errors.password && <div className="auth-err">{errors.password}</div>}
                </div>
                <div className="flex" style={{ margin: '4px 0 18px' }}>
                    <label className="flex" style={{ gap: 8, cursor: 'pointer', fontSize: 13.5 }}>
                        <input type="checkbox" name="remember" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                        {t('Remember me')}
                    </label>
                    <span className="spacer" />
                    {canResetPassword && <Link href={route('password.request')} className="auth-link">{t('Forgot your password?')}</Link>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Log in')}
                </button>
            </form>
            {devLogin && (
                <button
                    type="button"
                    className="btn sec"
                    style={{ width: '100%', justifyContent: 'center', padding: '11px 16px', marginTop: 10 }}
                    onClick={() => router.post(route('dev.login'))}
                >
                    {t('Dev quick login')}
                </button>
            )}
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                {t('New to SkaleUp BD?')} <Link href={route('register')} className="auth-link">{t('Create an account')}</Link>
            </p>
        </GuestLayout>
    );
}
