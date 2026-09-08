"use client";

import { useI18n } from "@/lib/i18n/context";
import { THEME_PREFERENCES, useTheme, type ThemePreference } from "@/lib/theme";
import { cn } from "@/lib/cn";

/**
 * Light, dark, or follow the machine.
 *
 * A three-way segmented control rather than a sun/moon that flips: with two
 * states there is nowhere to put "follow the system", and dropping it would
 * make the app stop tracking a laptop that dims itself at sunset — which is the
 * behaviour it had before this control existed and the one most people want.
 *
 * It sits in the top bar next to the language picker because it is the same
 * kind of setting: a property of how this person reads the app, not of the
 * company, so it does not belong on the Settings screen behind a permission.
 */
export function ThemeToggle() {
  const { t } = useI18n();
  const { preference, setPreference } = useTheme();

  return (
    <div
      role="radiogroup"
      aria-label={t("theme.label")}
      className="control-surface flex h-10 items-center gap-0.5 rounded-full p-1"
    >
      {THEME_PREFERENCES.map((option) => (
        <button
          key={option}
          type="button"
          role="radio"
          aria-checked={preference === option}
          title={t(`theme.${option}`)}
          onClick={() => setPreference(option)}
          className={cn(
            "grid h-8 w-8 place-items-center rounded-full transition-colors",
            preference === option
              ? "bg-ink text-brand-yellow"
              : "text-zinc-500 hover:bg-zinc-900/5 dark:hover:bg-white/10",
          )}
        >
          <ThemeIcon option={option} />
          <span className="sr-only">{t(`theme.${option}`)}</span>
        </button>
      ))}
    </div>
  );
}

function ThemeIcon({ option }: { option: ThemePreference }) {
  const shared = {
    viewBox: "0 0 24 24",
    className: "h-4 w-4",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 2,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true,
  };

  if (option === "light") {
    return (
      <svg {...shared}>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
      </svg>
    );
  }

  if (option === "dark") {
    return (
      <svg {...shared}>
        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
      </svg>
    );
  }

  // System: a display, which is the thing being deferred to.
  return (
    <svg {...shared}>
      <rect x="2" y="4" width="20" height="13" rx="2" />
      <path d="M8 21h8M12 17v4" />
    </svg>
  );
}
