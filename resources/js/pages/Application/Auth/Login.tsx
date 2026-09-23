import { Form, Head, Link, usePage } from "@inertiajs/react";
import { ArrowRight, Check, LockIcon, Mail, ShieldCheck } from "lucide-react";
import AuthController from "@/actions/App/Http/Controllers/Application/Auth/AuthController";
import { BrandMark } from "@/components/ui/brand-mark";
import { GradientButton } from "@/components/ui/gradient-button";
import { SocialButtons } from "@/components/ui/social-buttons";
import { TextInput } from "@/components/ui/text-input";
import { ThemeToggle } from "@/components/ui/theme-toggle";
import { themeConfig } from "@/config/theme";
import CustomToaster from "@/components/ui/CustomToaster";
import { toast } from "sonner";

export default function Login() {
    const { flash } = usePage().props;

    if (flash.error) {
        toast.error(flash.error);
    }

    return (
        <>
            <Head title="Log in" />
            <main className="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(27rem,0.94fr)]">
                <section className="relative hidden overflow-hidden bg-linear-to-br from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between xl:px-24">
                    <div className="relative z-10">
                        <BrandMark light />
                    </div>
                    <div className="absolute inset-0 z-10 flex items-center justify-center px-12 text-center xl:px-24">
                        <div className="mx-auto max-w-xl">
                            <p className="mb-5 text-xs font-bold tracking-[0.24em] text-white/65 uppercase">
                                {themeConfig.brand.eyebrow}
                            </p>
                            <h1 className="max-w-lg text-6xl leading-[0.98] font-extrabold tracking-[-0.04em] xl:text-7xl">
                                {themeConfig.login.title}
                            </h1>
                            <p className="mt-6 text-center text-base leading-7 text-white/75">
                                {themeConfig.login.description}
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
                        <div className="mb-10 lg:hidden">
                            <BrandMark center />
                        </div>
                        <div className="mb-9 text-center">
                            <h2 className="text-4xl font-extrabold tracking-tight text-[var(--color-ink)]">
                                Log in
                            </h2>
                            <p className="mt-3 text-sm leading-6 text-[var(--color-muted)]">
                                {themeConfig.login.description}.
                            </p>
                        </div>

                        <div className="mb-8">
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

                        <Form
                            action={AuthController.auth().url}
                            method={AuthController.auth().method}
                            className="grid gap-5"
                        >
                            {({ errors, processing }) => (
                                <>
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
                                        autoComplete="current-password"
                                        required
                                        error={errors.password}
                                    />
                                    <div className="flex items-center justify-between gap-4 text-xs font-semibold text-[var(--color-muted)]">
                                        <label className="inline-flex items-center gap-2.5">
                                            <span className="relative flex h-4 w-4 items-center justify-center">
                                                <input
                                                    name="remember"
                                                    type="checkbox"
                                                    value="1"
                                                    className="peer absolute inset-0 h-4 w-4 cursor-pointer appearance-none rounded-[5px] border bg-[var(--color-panel)] checked:border-[var(--color-accent-start)] checked:bg-[var(--color-accent-start)]"
                                                />
                                                <Check
                                                    size={12}
                                                    strokeWidth={3}
                                                    className="pointer-events-none hidden text-white peer-checked:block"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            Keep me logged in
                                        </label>
                                        <a
                                            className="transition hover:text-[var(--color-accent-start)]"
                                            href="#forgot-password"
                                        >
                                            Forgot password?
                                        </a>
                                    </div>
                                    <GradientButton
                                        type="submit"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            "Logging in..."
                                        ) : (
                                            <span className="inline-flex items-center justify-center gap-2">
                                                Log in{" "}
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
                            Don't have an account?
                            <Link
                                href="/register"
                                className="text-[var(--color-primary)] hover:underline"
                            >
                                Register
                            </Link>
                        </p>

                        <p className="mt-20 inline-flex w-full items-center justify-center gap-2 text-xs font-semibold text-[var(--color-muted)]">
                            <ShieldCheck
                                size={15}
                                className="text-emerald-500"
                                strokeWidth={2.25}
                                aria-hidden="true"
                            />
                            {themeConfig.login.secureLabel}
                        </p>
                    </div>
                </section>
                <CustomToaster />
            </main>
        </>
    );
}
