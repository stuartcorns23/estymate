import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import BusinessFields, { emptyBusinessForm, type BusinessForm } from '@/components/business/business-fields';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { type BusinessRole } from '@/types';

interface Invitation {
    business: string;
    role: BusinessRole;
    url: string;
}

export default function CreateBusiness({ invitations }: { invitations: Invitation[] }) {
    const { data, setData, post, processing, errors } = useForm<BusinessForm>(emptyBusinessForm);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('businesses.store'));
    };

    return (
        <AuthLayout title="Set up your business" description="These details appear on your estimates and invoices. You can change them later.">
            <Head title="Set up your business" />

            {invitations.length > 0 && (
                <div className="grid gap-3 rounded-lg border p-4">
                    <p className="text-sm font-medium">You've been invited to join</p>
                    {invitations.map((invitation) => (
                        <div key={invitation.url} className="flex items-center justify-between gap-4">
                            <span className="text-sm">
                                {invitation.business} <span className="text-muted-foreground">as {invitation.role}</span>
                            </span>
                            <Button size="sm" asChild>
                                <a href={invitation.url}>Join</a>
                            </Button>
                        </div>
                    ))}
                    <p className="text-muted-foreground text-xs">Or create your own business below.</p>
                </div>
            )}

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <BusinessFields data={data} setData={setData} errors={errors} disabled={processing} withAddress={false} />

                    <Button type="submit" className="mt-2 w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Create business
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    <Link href={route('logout')} method="post" as="button" className="underline underline-offset-4">
                        Log out
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
