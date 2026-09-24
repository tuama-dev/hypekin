import { Form, Head, Link, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { ArrowLeft, CheckCircle2, Mail } from "lucide-react";
import { BrandMark } from "@/components/ui/BrandMark";
import { GradientButton } from "@/components/ui/GradientButton";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { appTheme } from "@/config/theme";
import EmailVerificationController from "@/actions/App/Http/Controllers/Application/Auth/EmailVerificationController";
import { index as dashboardIndex } from "@/actions/App/Http/Controllers/Application/DashboardController";

export default function VerifyEmail() {
    const { auth, flash, verification } = usePage().props;

    const resendAvailableAt = verification?.resend_available_at
        ? new Date(verification.resend_available_at)
        : null;

    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (resendAvailableAt === null) {
            return;
        }

        let id: ReturnType<typeof setInterval>;

        const tick = () => {
            if (Date.now() >= resendAvailableAt.getTime()) {
                setNow(resendAvailableAt.getTime());
                clearInterval(id);
            } else {
                setNow(Date.now());
            }
        };

        id = setInterval(tick, 1000);
        tick();

        return () => clearInterval(id);
    }, [resendAvailableAt]);

    const remainingSeconds =
        resendAvailableAt === null
            ? 0
            : Math.max(0, Math.ceil((resendAvailableAt.getTime() - now) / 1000));

    const isLocked = remainingSeconds > 0;

    return (
        <>
            <Head title="Verify your email" />
            <main className="flex min-h-screen flex-col bg-[var(--color-panel)] px-6 py-6 sm:px-10">
                <div className="flex items-center justify-between">
                    <BrandMark />
                    <ThemeToggle />
                </div>

                <div className="m-auto w-full max-w-md py-12 text-center">
                    <div className="mx-auto mb-6 grid size-14 place-items-center rounded-2xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                        <Mail className="size-7" aria-hidden="true" />
                    </div>

                    <h1 className="text-3xl font-extrabold tracking-tight text-[var(--color-ink)]">
                        Verify your email
                    </h1>
                    <p className="mt-3 text-sm leading-6 text-[var(--color-muted)]">
                        We sent a verification link to your email address. Click the
                        link in the message to activate your account and access{" "}
                        {appTheme.brandName}.
                    </p>

                    {flash.success && (
                        <div className="mt-6 flex items-center justify-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-semibold text-emerald-400">
                            <CheckCircle2 className="size-4" aria-hidden="true" />
                            {flash.success}
                        </div>
                    )}

                    {flash.error && (
                        <div className="mt-6 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm font-semibold text-rose-400">
                            {flash.error}
                        </div>
                    )}

                    <Form
                        action={EmailVerificationController.resend().url}
                        method={EmailVerificationController.resend().method}
                        className="mt-8"
                    >
                        {({ processing }) => (
                            <GradientButton
                                type="submit"
                                disabled={processing || isLocked}
                            >
                                {isLocked
                                    ? `Resend available in ${remainingSeconds}s`
                                    : "Resend verification email"}
                            </GradientButton>
                        )}
                    </Form>

                    <p className="mt-8 inline-flex w-full items-center justify-center gap-1 text-sm font-semibold text-[var(--color-muted)]">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        {auth?.workspace ? (
                            <Link
                                href={dashboardIndex({ workspace: auth.workspace.slug }).url}
                                className="text-[var(--color-primary)] hover:underline"
                            >
                                Back to dashboard
                            </Link>
                        ) : null}
                    </p>
                </div>
            </main>
        </>
    );
}