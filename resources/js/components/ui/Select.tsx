import {
    useEffect,
    useId,
    useRef,
    useState,
    type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";

export type SelectOption = {
    label: string;
    value: string;
};

type SelectProps = {
    options: SelectOption[];
    value: string;
    onValueChange: (value: string) => void;
    "aria-label"?: string;
    className?: string;
};

export function Select({
    options,
    value,
    onValueChange,
    "aria-label": ariaLabel,
    className,
}: SelectProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [highlightIndex, setHighlightIndex] = useState(0);
    const wrapperRef = useRef<HTMLDivElement>(null);
    const optionRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const listboxId = useId();

    useEffect(() => {
        function handlePointerDown(event: PointerEvent) {
            if (!wrapperRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === "Escape") {
                setIsOpen(false);
            }
        }

        document.addEventListener("pointerdown", handlePointerDown);
        document.addEventListener("keydown", handleKeyDown);
        return () => {
            document.removeEventListener("pointerdown", handlePointerDown);
            document.removeEventListener("keydown", handleKeyDown);
        };
    }, []);

    useEffect(() => {
        if (isOpen) {
            const index = options.findIndex((option) => option.value === value);
            setHighlightIndex(Math.max(index, 0));
        }
    }, [isOpen, options, value]);

    useEffect(() => {
        if (isOpen) {
            optionRefs.current[highlightIndex]?.scrollIntoView({
                block: "nearest",
            });
        }
    }, [highlightIndex, isOpen]);

    const selectedOption =
        options.find((option) => option.value === value) ?? null;

    function toggleOpen() {
        setIsOpen((current) => !current);
    }

    function handleKeyDown(event: ReactKeyboardEvent) {
        switch (event.key) {
            case "ArrowDown":
                event.preventDefault();
                if (!isOpen) {
                    setIsOpen(true);
                } else {
                    setHighlightIndex((current) =>
                        current === options.length - 1 ? 0 : current + 1,
                    );
                }
                break;
            case "ArrowUp":
                event.preventDefault();
                if (!isOpen) {
                    setIsOpen(true);
                } else {
                    setHighlightIndex((current) =>
                        current === 0 ? options.length - 1 : current - 1,
                    );
                }
                break;
            case "Home":
                event.preventDefault();
                setIsOpen(true);
                setHighlightIndex(0);
                break;
            case "End":
                event.preventDefault();
                setIsOpen(true);
                setHighlightIndex(options.length - 1);
                break;
            case "Enter":
                event.preventDefault();
                if (isOpen) {
                    selectOption(options[highlightIndex]);
                } else {
                    setIsOpen(true);
                }
                break;
            case "Tab":
                setIsOpen(false);
                break;
        }
    }

    function selectOption(option: SelectOption) {
        onValueChange(option.value);
        setIsOpen(false);
    }

    const listboxIdForPanel = `${listboxId}-listbox`;
    const activeOptionId = isOpen
        ? `${listboxId}-option-${highlightIndex}`
        : undefined;

    return (
        <div ref={wrapperRef} className="relative">
            <button
                type="button"
                onClick={toggleOpen}
                onKeyDown={handleKeyDown}
                aria-label={ariaLabel}
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                aria-controls={isOpen ? listboxIdForPanel : undefined}
                className={cn(
                    "flex h-9 cursor-pointer items-center justify-between gap-3 rounded-lg border border-(--border) bg-(--panel-muted) py-2 pr-3 pl-4 text-sm font-semibold text-(--text) transition hover:bg-(--panel) focus:border-[var(--color-accent-start)] focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15 focus:outline-none",
                    className,
                )}
            >
                <span className="truncate text-left">
                    {selectedOption?.label ?? value}
                </span>
                <ChevronDown
                    className={cn(
                        "size-4 shrink-0 text-(--muted) transition-transform duration-200",
                        isOpen && "rotate-180",
                    )}
                    aria-hidden="true"
                />
            </button>

            {isOpen && (
                <div
                    id={listboxIdForPanel}
                    role="listbox"
                    aria-label={ariaLabel}
                    aria-activedescendant={activeOptionId}
                    className="absolute z-50 mt-2 min-w-full w-max origin-top rounded-lg border border-(--surface-border) bg-(--surface) p-1 shadow-(--surface-shadow)"
                >
                    <ul className="max-h-64 overflow-y-auto">
                        {options.map((option, index) => {
                            const isSelected = option.value === value;
                            const isHighlighted = index === highlightIndex;

                            return (
                                <li key={option.value}>
                                    <button
                                        ref={(element) => {
                                            optionRefs.current[index] = element;
                                        }}
                                        type="button"
                                        role="option"
                                        id={`${listboxId}-option-${index}`}
                                        aria-selected={isSelected}
                                        onMouseEnter={() =>
                                            setHighlightIndex(index)
                                        }
                                        onClick={() => selectOption(option)}
                                        className={cn(
                                            "flex w-full cursor-pointer items-center rounded-md px-3 py-2 text-sm transition",
                                            isSelected
                                                ? "bg-[var(--color-accent-start)] font-semibold text-[var(--color-accent-ink)] hover:brightness-110"
                                                : "text-(--text) hover:bg-(--panel-muted)",
                                            isHighlighted &&
                                                !isSelected &&
                                                "bg-(--panel-muted)",
                                        )}
                                    >
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
