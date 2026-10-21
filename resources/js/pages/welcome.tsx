import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Estimates and invoices for UK businesses" />
            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between p-6">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6 fill-current" />
                        Estymate
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={route('dashboard')}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={route('login')}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={route('register')}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-6 p-6">
                    <h1 className="max-w-2xl text-4xl font-semibold tracking-tight lg:text-5xl">Estimates and invoices for UK businesses</h1>
                    <p className="text-muted-foreground max-w-xl text-lg">
                        Send professional quotes, turn them into invoices and get paid, with UK VAT built in.
                    </p>
                    {!auth.user && (
                        <div>
                            <Button size="lg" asChild>
                                <Link href={route('register')}>Get started</Link>
                            </Button>
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
