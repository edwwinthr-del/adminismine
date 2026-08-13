import { forwardRef, type InputHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

type CheckboxSize = "sm" | "md" | "lg";

interface CheckboxProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, "size"> {
    size?: CheckboxSize;
}

const sizes: Record<
    CheckboxSize,
    {
        box: string;
        icon: string;
    }
> = {
    sm: {
        box: "h-4 w-4 rounded-[5px]",
        icon: "h-3 w-3",
    },
    md: {
        box: "h-5 w-5 rounded-[6px]",
        icon: "h-3.5 w-3.5",
    },
    lg: {
        box: "h-6 w-6 rounded-[7px]",
        icon: "h-4 w-4",
    },
};

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
    function Checkbox(
        { className, size = "md", disabled, ...props },
        ref,
    ) {
        const styles = sizes[size];

        return (
            <span className="relative inline-flex shrink-0">
        <input
            ref={ref}
            type="checkbox"
            disabled={disabled}
            className={cn(
                "peer appearance-none cursor-pointer",
                styles.box,

                // Base
                "border border-zinc-300/80 bg-white/75",

                // Depth
                "shadow-[inset_0_1px_1px_rgb(13_12_11/0.04),0_1px_2px_rgb(13_12_11/0.07)]",

                // Hover
                "hover:border-zinc-400 hover:bg-white",
                "hover:shadow-[inset_0_1px_1px_rgb(13_12_11/0.03),0_2px_5px_rgb(13_12_11/0.09)]",

                // Checked
                "checked:border-brand-yellow checked:bg-brand-yellow",
                "checked:shadow-[0_3px_10px_-4px_rgb(213_205_20/0.9)]",

                // Focus
                "focus-visible:outline-none",
                "focus-visible:ring-2",
                "focus-visible:ring-brand-yellow/40",
                "focus-visible:ring-offset-2",
                "focus-visible:ring-offset-white",

                // Disabled
                "disabled:pointer-events-none",
                "disabled:cursor-not-allowed",
                "disabled:opacity-45",

                // Dark mode
                "dark:border-white/15",
                "dark:bg-white/5",
                "dark:hover:border-white/25",
                "dark:hover:bg-white/10",
                "dark:checked:border-brand-yellow",
                "dark:checked:bg-brand-yellow",
                "dark:focus-visible:ring-offset-zinc-950",

                className,
            )}
            {...props}
        />

        <svg
            viewBox="0 0 16 16"
            fill="none"
            aria-hidden="true"
            className={cn(
                "pointer-events-none absolute inset-0 m-auto",
                styles.icon,
                "scale-50 opacity-0",
                "transition-all duration-150 ease-out",
                "peer-checked:scale-100 peer-checked:opacity-100",
            )}
        >
          <path
              d="M3.25 8.25L6.5 11.25L12.75 4.75"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="text-ink"
          />
        </svg>
      </span>
        );
    },
);

Checkbox.displayName = "Checkbox";