import { Form, Head, Link, usePage } from "@inertiajs/react";
import { ArrowRight, Check, LockIcon, Mail, User } from "lucide-react";
import { BrandMark } from "@/components/ui/BrandMark";
import { GradientButton } from "@/components/ui/GradientButton";
import { SocialButtons } from "@/components/ui/SocialButtons";
import { TextInput } from "@/components/ui/TextInput";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { themeConfig } from "@/config/theme";
import RegistrationController from "@/actions/App/Http/Controllers/Application/Auth/RegistrationController";

export default function Registration() {
    const { flash } = usePage().props;

    return (
        <>
            <Head title="Register" />
            <main className="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(27rem,0.94fr)]">
                <section className="relative hidden overflow-hidden bg-linear-to-br from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between xl:px-24">
                    <div className="relative z-10">
                        <BrandMark light />
                    </div>
                    <div className="absolute inset-0 z-10 flex items-center justify-center px-12 text-center xl:px-24">
                        <div className="mx-auto max-w-xl">
                            <h1 className="max-w-xl text-6xl leading-[0.98] font-extrabold tracking-[-0.04em]">
                                {themeConfig.register.title}
                            </h1>
                            <p className="mt-6 text-center text-base leading-7 text-white/75">
                                {themeConfig.register.description}
                            </p>
                        </div>
                    </div>
                    <div
                        className="pointer-events-none absolute -right-[19rem] -bottom-[28rem] h-[48rem] w-[48rem] rounded-full bg-white/[0.055]"
                        aria-hidden="true"
                    />
                    <div
                        className="pointer-events-none absolute -right-[12rem] -bottom-[21rem] h-[36rem] w-[36rem] rounded-full bg-white/[0.035] ring-1 ring-white/[0.12]"
                        aria-hidden="true"
                    />
                    <div
                        className="pointer-events-none absolute -right-[6rem] -bottom-[14rem] h-[24rem] w-[24rem] rounded-full bg-white/[0.025] ring-1 ring-white/[0.08]"
                        aria-hidden="true"
                    />
                </section>
                <section className="flex min-h-screen flex-col bg-[var(--color-panel)] px-6 py-6 sm:px-10 lg:px-16 xl:px-24">
                    <div className="flex justify-end">
                        <ThemeToggle />
                    </div>
                    <div className="m-auto w-full max-w-md py-12">
                        <div className="mb-8 lg:hidden">
                            <BrandMark center />
                        </div>
                        <div className="mb-8 text-center">
                            <h2 className="text-4xl font-extrabold tracking-tight text-[var(--color-ink)]">
                                {themeConfig.register.title}
                            </h2>
                            <p className="mt-3 text-sm leading-6 text-[var(--color-muted)]">
                                {themeConfig.register.description}.
                            </p>
                        </div>

                        <div>
                            <SocialButtons />
                        </div>
                        <div
                            className="mt-6 mb-4 flex items-center gap-4"
                            aria-hidden="true"
                        >
                            <span className="h-px flex-1 bg-[var(--color-line)]" />
                            <span className="text-xs font-semibold text-[var(--color-muted)]">
                                or using email account
                            </span>
                            <span className="h-px flex-1 bg-[var(--color-line)]" />
                        </div>

                        {flash.error && (
                            <div className="mb-6 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm font-semibold text-rose-400">
                                {flash.error}
                            </div>
                        )}

                        <Form
                            action={RegistrationController.store().url}
                            method={RegistrationController.store().method}
                            className="grid gap-5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextInput
                                        name="fullname"
                                        type="text"
                                        label="Name"
                                        icon={User}
                                        placeholder="John Doe"
                                        autoComplete="name"
                                        required
                                        error={errors.fullname}
                                    />
                                    <TextInput
                                        name="email"
                                        type="email"
                                        label="Email"
                                        icon={Mail}
                                        placeholder="you@example.com"
                                        autoComplete="email"
                                        required
                                        error={errors.email}
                                    />
                                    <TextInput
                                        name="password"
                                        type="password"
                                        label="Password"
                                        icon={LockIcon}
                                        placeholder="Enter your password"
                                        autoComplete="new-password"
                                        required
                                        error={errors.password}
                                    />
                                    <TextInput
                                        name="password_confirmation"
                                        type="password"
                                        label="Confirm Password"
                                        icon={LockIcon}
                                        placeholder="Re-enter your password"
                                        autoComplete="new-password"
                                        required
                                        error={errors.password}
                                    />

                                    <GradientButton
                                        type="submit"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            "Creating account..."
                                        ) : (
                                            <span className="inline-flex items-center justify-center gap-2">
                                                Create account{" "}
                                                <ArrowRight
                                                    size={17}
                                                    strokeWidth={2.5}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        )}
                                    </GradientButton>
                                </>
                            )}
                        </Form>

                        <p className="mt-8 inline-flex w-full items-center justify-center gap-1 text-sm font-semibold text-[var(--color-muted)]">
                            Already have an account?
                            <Link
                                href="/login"
                                className="text-[var(--color-primary)] hover:underline"
                            >
                                Login
                            </Link>
                        </p>
                    </div>
                </section>
            </main>
        </>
    );
}
