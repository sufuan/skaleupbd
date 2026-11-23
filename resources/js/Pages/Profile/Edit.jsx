import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { t } from '@/lib/i18n';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status, shopName, roleLabel }) {
    return (
        <AppLayout>
            <Head title={t('My Profile & Security')} />

            <h1 className="page-h">{t('My Profile & Security')}</h1>
            <p className="page-sub">
                {t('Your name, sign-in email and password.')}
            </p>

            <div style={{ display: 'grid', gap: 16, maxWidth: 760 }}>
                <UpdateProfileInformationForm
                    mustVerifyEmail={mustVerifyEmail}
                    status={status}
                    shopName={shopName}
                    roleLabel={roleLabel}
                />

                <UpdatePasswordForm />

                <DeleteUserForm />
            </div>
        </AppLayout>
    );
}
