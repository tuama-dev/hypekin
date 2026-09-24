import { themeConfig } from "@/config/theme";

type BrandMarkProps = {
    light?: boolean;
    center?: boolean;
};

export function BrandMark({ light = false, center = false }: BrandMarkProps) {
    const { brand } = themeConfig;

    return (
        <div
            className={`flex items-center gap-3 ${light ? "text-white" : "text-[var(--color-ink)]"} ${center ? "justify-center" : ""}`}
            aria-label={brand.name}
        >
            {brand.image ? (
                <img
                    className="h-8 w-8 object-contain"
                    src={brand.image}
                    alt=""
                />
            ) : (
                <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-white text-sm font-extrabold text-[var(--color-accent-start)] shadow-sm">
                    {brand.mark}
                </span>
            )}
            <h3 className="text-xl font-semibold tracking-tight">
                {brand.name}
            </h3>
        </div>
    );
}
