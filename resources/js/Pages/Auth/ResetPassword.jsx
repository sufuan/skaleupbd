import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    useEffect(() => () => reset('password', 'password_confirmation'), []);

    const submit = (e) => {
        e.preventDefault();
        post(route('password.store'));
    };

    return (
        <GuestLayout>
            <Head title={t('Reset password')} />
            <h1 className="auth-title">{t('Reset password')}</h1>
            <p className="auth-sub">{t('Set a new password for your account')}</p>
            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="email">{t('Email')}</label>
                    <input id="email" type="email" name="email" value={data.email} className="inp" autoComplete="username" onChange={(e) => setData('email', e.target.value)} />
                    {errors.email && <div className="auth-err">{errors.email}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="password">{t('Password')}</label>
                    <input id="password" type="password" name="password" value={data.password} className="inp" autoComplete="new-password" autoFocus onChange={(e) => setData('password', e.target.value)} />
                    {errors.password && <div className="auth-err">{errors.password}</div>}
                </div>
                <div className="frow">
                    <label className="fl" htmlFor="password_confirmation">{t('Confirm password')}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" value={data.password_confirmation} className="inp" autoComplete="new-password" onChange={(e) => setData('password_confirmation', e.target.value)} />
                    {errors.password_confirmation && <div className="auth-err">{errors.password_confirmation}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Reset password')}
                </button>
            </form>
        </GuestLayout>
    );
}
