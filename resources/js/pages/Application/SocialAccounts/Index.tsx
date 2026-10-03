import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Link2 } from 'lucide-react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { PlatformConnectSection } from '@/components/ui/PlatformConnect';
import SocialAccountController from '@/actions/App/Http/Controllers/Application/SocialAccountController';

interface PlatformView {
    value: string;
    label: string;
    configured: boolean;
    connect_url: string | null;
}

interface AccountView {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    token_expires_at: string | null;
    connected_at: string | null;
}

interface ConnectedAccountsProps {
    accounts: AccountView[];
    platforms: PlatformView[];
}

const statusClasses: Record<string, string> = {
    connected: 'bg-emerald-500/10 text-emerald-600',
    expired: 'bg-amber-500/10 text-amber-600',
    revoked: 'bg-neutral-500/10 text-neutral-500',
};

function expiresLabel(tokenExpiresAt: string | null): string | null {
    if (tokenExpiresAt === null) {
        return null;
    }

    const expiresAt = new Date(tokenExpiresAt);

    if (Number.isNaN(expiresAt.getTime())) {
        return null;
    }

    if (expiresAt.getTime() < Date.now()) {
        return 'Token expired';
    }

    return `Token expires ${expiresAt.toLocaleDateString()}`;
}

export default function ConnectedAccounts({
    accounts,
    platforms,
}: ConnectedAccountsProps) {
    const { auth, flash } = usePage().props;
    const workspace = auth.workspace;
    const canManageAccounts =
        workspace?.abilities.includes('manageAccounts') ?? false;
    const [accountToDisconnect, setAccountToDisconnect] =
        useState<AccountView | null>(null);

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        } else if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;

    const connectablePlatforms = platforms.filter(
        (platform) => platform.configured,
    );

    function disconnect(account: AccountView) {
        router.delete(
            SocialAccountController.destroy({
                workspace: currentWorkspace.slug,
                account: account.id,
            }).url,
            { preserveScroll: true },
        );
        setAccountToDisconnect(null);
    }

    return (
        <AuthenticatedLayout>
            <Head title="Connected accounts" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Connected accounts
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Accounts used to publish posts for{' '}
                            <strong className="font-semibold">
                                {currentWorkspace.name}
                            </strong>
                            .
                        </p>
                    </div>
                </header>

                {canManageAccounts && (
                    <PlatformConnectSection
                        platforms={connectablePlatforms}
                        accounts={accounts}
                    />
                )}

                {accounts.length === 0 ? (
                    <section className="mt-6 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                        <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                            <Link2 className="size-5" aria-hidden="true" />
                        </span>
                        <h2 className="mt-4 text-base font-bold text-(--text)">
                            No accounts connected yet
                        </h2>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                            {!canManageAccounts
                                ? 'No accounts are connected to this workspace yet. Ask an owner or admin to connect one.'
                                : connectablePlatforms.length > 0
                                  ? 'Connect a platform below to start publishing posts to the workspace.'
                                  : 'Publishing is not configured for any platform yet. Ask the workspace owner to enable one.'}
                        </p>
                    </section>
                ) : (
                    <ul className="mt-6 grid gap-4">
                        {accounts.map((account) => {
                            const expiry = expiresLabel(
                                account.token_expires_at,
                            );

                            return (
                                <li
                                    key={account.id}
                                    className="flex flex-wrap items-center gap-4 rounded-2xl border border-(--border) bg-(--panel) p-5"
                                >
                                    <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-(--panel-muted) text-(--muted)">
                                        <Link2
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-(--text)">
                                            {account.display_name}
                                        </p>
                                        <p className="mt-0.5 text-xs text-(--muted)">
                                            {account.platform.label}
                                            {expiry !== null
                                                ? ` · ${expiry}`
                                                : ''}
                                        </p>
                                    </div>
                                    <span
                                        className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${
                                            statusClasses[
                                                account.status.value
                                            ] ??
                                            'bg-neutral-500/10 text-neutral-500'
                                        }`}
                                    >
                                        {account.status.label}
                                    </span>
                                    {canManageAccounts &&
                                        account.status.value ===
                                            'connected' && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setAccountToDisconnect(
                                                        account,
                                                    )
                                                }
                                                className="shrink-0 rounded-lg border border-(--border) px-4 py-2 text-sm font-semibold text-(--muted) transition hover:border-red-300 hover:bg-red-500/5 hover:text-red-600 focus:ring-4 focus:ring-red-500/20 focus:outline-none"
                                            >
                                                Disconnect
                                            </button>
                                        )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <ConfirmDialog
                open={accountToDisconnect !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setAccountToDisconnect(null);
                    }
                }}
                title={
                    accountToDisconnect !== null
                        ? `Disconnect ${accountToDisconnect.display_name}?`
                        : 'Disconnect this account?'
                }
                description="This account will no longer be available for publishing. You can reconnect it at any time."
                confirmText="Disconnect"
                cancelText="Cancel"
                variant="danger"
                onConfirm={() => {
                    if (accountToDisconnect !== null) {
                        disconnect(accountToDisconnect);
                    }
                }}
            />
        </AuthenticatedLayout>
    );
}
