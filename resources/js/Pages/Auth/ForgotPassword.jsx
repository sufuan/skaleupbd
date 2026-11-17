import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title={t('Forgot your password?')} />
            <h1 className="auth-title">{t('Forgot your password?')}</h1>
            <p className="auth-sub">{t('No problem. Give us your email and we will send you a link to set a new password.')}</p>
            {status && <div className="auth-status">{status}</div>}
            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="email">{t('Email')}</label>
                    <input id="email" type="email" name="email" value={data.email} className="inp" autoComplete="username" autoFocus onChange={(e) => setData('email', e.target.value)} />
                    {errors.email && <div className="auth-err">{errors.email}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Email password reset link')}
                </button>
            </form>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                <Link href={route('login')} className="auth-link">{t('Back to log in')}</Link>
            </p>
        </GuestLayout>
    );
}
