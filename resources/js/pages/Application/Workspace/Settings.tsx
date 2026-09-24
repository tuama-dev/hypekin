import { Form, Head, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
    CalendarDays,
    CheckCircle2,
    Hash,
    ShieldCheck,
    Tag,
    Users,
} from "lucide-react";
import AuthenticatedLayout from "@/components/Layout/AuthenticatedLayout";
import { TextInput } from "@/components/ui/TextInput";
import WorkspaceSettingsController from "@/actions/App/Http/Controllers/Application/WorkspaceSettingsController";

interface WorkspaceSettingsPageProps {
    memberCount: number;
    createdAt: string | null;
}

function DetailRow({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: ReactNode;
}) {
    return (
        <div className="flex items-center gap-3">
            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-(--panel-muted) text-(--muted)">
                <Icon className="size-4" aria-hidden="true" />
            </span>
            <div className="min-w-0">
                <dt className="text-xs font-semibold tracking-wide text-(--muted) uppercase">
                    {label}
                </dt>
                <dd className="truncate text-sm font-semibold text-(--text)">
                    {value}
                </dd>
            </div>
        </div>
    );
}

export default function WorkspaceSettings({
    memberCount,
    createdAt,
}: WorkspaceSettingsPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;

    if (workspace === null) {
        return null;
    }

    const capitalize = (value: string) =>
        value.charAt(0).toUpperCase() + value.slice(1);

    return (
        <AuthenticatedLayout>
            <Head title="Workspace settings" />
            <div className="w-full max-w-4xl px-4 py-8 sm:px-6">
                <header>
                    <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                        Workspace settings
                    </h1>
                    <p className="mt-1 text-sm text-(--muted)">
                        Manage how{" "}
                        <strong className="font-semibold">
                            {workspace.name}
                        </strong>{" "}
                        works.
                    </p>
                </header>

                <div className="mt-6 grid gap-6">
                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-base font-bold text-(--text)">
                            General
                        </h2>
                        <p className="mt-1 text-sm text-(--muted)">
                            The public name that identifies this workspace.
                        </p>

                        <Form
                            action={
                                WorkspaceSettingsController.update({
                                    workspace: workspace.slug,
                                }).url
                            }
                            method={
                                WorkspaceSettingsController.update({
                                    workspace: workspace.slug,
                                }).method
                            }
                            className="mt-5 grid gap-5"
                            setDefaultsOnSuccess
                        >
                            {({ errors, processing, wasSuccessful }) => (
                                <>
                                    <TextInput
                                        id="name"
                                        name="name"
                                        label="Workspace name"
                                        required
                                        defaultValue={workspace.name}
                                        error={errors.name}
                                        style={{ paddingLeft: "1rem" }}
                                    />
                                    <div className="flex min-h-10 items-center gap-4">
                                        {wasSuccessful && (
                                            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-500">
                                                <CheckCircle2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Saved
                                            </span>
                                        )}
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="ml-auto rounded-lg bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-2.5 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:outline-none focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {processing
                                                ? "Saving..."
                                                : "Save changes"}
                                        </button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>

                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-base font-bold text-(--text)">
                            Workspace details
                        </h2>
                        <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                            <DetailRow
                                icon={Tag}
                                label="Name"
                                value={workspace.name}
                            />
                            <DetailRow
                                icon={Hash}
                                label="Slug"
                                value={workspace.slug}
                            />
                            <DetailRow
                                icon={ShieldCheck}
                                label="Your role"
                                value={capitalize(workspace.role)}
                            />
                            <DetailRow
                                icon={Users}
                                label="Members"
                                value={memberCount}
                            />
                            {createdAt && (
                                <DetailRow
                                    icon={CalendarDays}
                                    label="Created"
                                    value={createdAt}
                                />
                            )}
                        </dl>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
