import { forwardRef, type SelectHTMLAttributes } from "react";
import { cn } from "@/lib/cn";
import { hasExplicitWidth } from "./input";

/*
 * Matches Input: a frosted pill. The native arrow is replaced with an inline
 * chevron so the control looks the same across browsers — `appearance-none`
 * alone would leave no affordance that it opens.
 *
 * The `<option>` list is drawn by the OS and cannot be styled, so it is left to
 * the platform rather than faked; `<AsyncSelect>` is the searchable one.
 */
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select(
  { className, ...props },
  ref,
) {
  return (
    <select
      ref={ref}
      className={cn(
        "control-surface focus-ink h-10 cursor-pointer appearance-none rounded-full bg-[length:1rem] bg-[right:0.9rem_center] bg-no-repeat py-0 pl-4 pr-10 text-sm text-zinc-900 dark:text-zinc-100",
        hasExplicitWidth(className) ? undefined : "w-full",
        "bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 fill=%22none%22 viewBox=%220 0 24 24%22 stroke=%22%236b675d%22 stroke-width=%222%22%3E%3Cpath stroke-linecap=%22round%22 stroke-linejoin=%22round%22 d=%22M19 9l-7 7-7-7%22/%3E%3C/svg%3E')]",
        className,
      )}
      {...props}
    />
  );
});
