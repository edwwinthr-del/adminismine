"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { NAV } from "@/lib/nav";
import { useModuleEnabled, useStructureLevel } from "@/lib/modules";
import { useSidenav } from "@/lib/sidenav";
import { cn } from "@/lib/cn";
import { NavIcon } from "./nav-icons";

/**
 * The navigation, rebuilt in the Vision UI idiom.
 *
 * Every dimension here is the template's own, read out of
 * `examples/Sidenav/styles/sidenavCollapse.js` and `SidenavRoot.js` rather than
 * approximated: 250px expanded and 96px collapsed, items inset 16px with a
 * 15px radius and 10.8/12.8px padding, a 32px icon tile at a 12px radius, and
 * the label at 14px going from regular to medium when active.
 *
 * What is *not* the template's is the colour. Vision UI marks the active item
 * with its electric blue on navy; this marks it with the brand yellow the app
 * already uses for its primary action, on the app's own surfaces. That was the
 * explicit instruction and it is the reason this reads as AdminisMine rather
 * than as the demo.
 *
 * **`lib/nav.ts` is untouched.** The same three questions decide what appears —
 * may this user reach it, does this company have the module, does it use that
 * level of the work structure — so permissions and module toggles keep working
 * exactly as they did.
 */

/** MUI's `easing.sharp`, which is what the template animates the drawer on. */
const SHARP = "cubic-bezier(0.4, 0, 0.6, 1)";

export function Sidebar({ company }: { company: string }) {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const moduleEnabled = useModuleEnabled();
  const levelEnabled = useStructureLevel();
  const pathname = usePathname();

  const { mini, toggle } = useSidenav();

  return (
    <aside
      className="sticky top-0 hidden h-screen shrink-0 py-4 pl-4 md:flex"
      style={{
        // 250 / 96 are the template's two widths. Animated on its own easing so
        // the panel and the labels inside it settle together.
        width: mini ? "7rem" : "16.625rem",
        transition: `width var(--vui-dur-enter) ${SHARP}`,
      }}
    >
      <div
        className="vui-surface vui-edge relative flex w-full flex-col overflow-hidden"
        style={{ borderRadius: "var(--vui-r-xl)" }}
      >
        {/* ---- brand ------------------------------------------------- */}
        <div className={cn("flex items-center gap-3 pt-7 pb-2", mini ? "justify-center px-0" : "px-6")}>
          <Logo />
          <span
            className="truncate text-[15px] font-semibold tracking-tight text-zinc-900 dark:text-zinc-50"
            style={{
              opacity: mini ? 0 : 1,
              maxWidth: mini ? 0 : "100%",
              transition: `opacity var(--vui-dur-standard) var(--vui-ease), max-width var(--vui-dur-standard) var(--vui-ease)`,
            }}
          >
            {company}
          </span>
        </div>

        {/* The template rules a line under the brand before the first group. */}
        <div
          className="mx-4 mt-2 mb-3 h-px shrink-0"
          style={{
            background:
              "linear-gradient(90deg, transparent, rgb(from currentColor r g b / 0.16), transparent)",
            color: "var(--foreground)",
          }}
        />

        {/* ---- destinations ------------------------------------------ */}
        <nav className="scroll-quiet flex-1 overflow-y-auto overflow-x-hidden pb-3">
          {NAV.map((group) => {
            const items = group.items.filter(
              (i) =>
                (i.permission === null || hasPermission(i.permission)) &&
                moduleEnabled(i.module) &&
                levelEnabled(i.level),
            );
            if (items.length === 0) return null;

            return (
              <div key={group.key} className="mt-4 first:mt-0">
                {/*
                  Vision UI sets its group titles bold uppercase at caption size
                  and insets them 24px. Collapsed, the label would be a word
                  fragment in a 96px rail, so it becomes a short rule instead —
                  the grouping survives without the text.
                */}
                {mini ? (
                  <div className="mx-auto mb-2 h-px w-8 bg-zinc-900/10 dark:bg-white/10" />
                ) : (
                  <p className="mb-2 px-6 text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-500 dark:text-zinc-400">
                    {t(group.key)}
                  </p>
                )}

                <ul>
                  {items.map((item) => {
                    const label = t(item.key);
                    const active =
                      item.enabled &&
                      (pathname === item.href || pathname.startsWith(`${item.href}/`));

                    const row = (
                      <>
                        <IconTile active={active} muted={!item.enabled}>
                          <NavIcon name={item.key} className="h-[18px] w-[18px]" />
                        </IconTile>

                        <span
                          className={cn(
                            "truncate text-sm",
                            active
                              ? "font-medium text-zinc-900 dark:text-zinc-50"
                              : item.enabled
                                ? "text-zinc-600 dark:text-zinc-300"
                                : "text-zinc-500 dark:text-zinc-400",
                          )}
                          style={{
                            // The template's collapse: the label loses its width
                            // and its margin together, so the tile slides left
                            // rather than the text simply vanishing.
                            marginLeft: mini ? 0 : "0.8rem",
                            opacity: mini ? 0 : 1,
                            maxWidth: mini ? 0 : "100%",
                            transition: `opacity var(--vui-dur-standard) var(--vui-ease), margin var(--vui-dur-standard) var(--vui-ease), max-width var(--vui-dur-standard) var(--vui-ease)`,
                          }}
                        >
                          {label}
                        </span>

                        {!item.enabled && !mini && (
                          <span className="ml-auto shrink-0 rounded-full bg-zinc-900/5 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                            {t("common.soon")}
                          </span>
                        )}
                      </>
                    );

                    // Exact item metrics from the template: 16px side inset,
                    // 10.8/12.8/10.8/16 padding, 15px radius.
                    const rowStyle = {
                      margin: "0 1rem",
                      padding: mini ? "0.675rem" : "0.675rem 0.8rem 0.675rem 1rem",
                      borderRadius: "var(--vui-r-lg)",
                      transition: `background-color var(--vui-dur-shorter) var(--vui-ease), box-shadow var(--vui-dur-shorter) var(--vui-ease)`,
                    } as const;

                    return (
                      <li key={item.key} className="mb-0.5">
                        {item.enabled ? (
                          <Link
                            href={item.href}
                            aria-current={active ? "page" : undefined}
                            title={mini ? label : undefined}
                            className={cn(
                              "focus-ink flex w-auto cursor-pointer items-center select-none",
                              mini && "justify-center",
                              active
                                ? "bg-white/70 dark:bg-white/[0.07]"
                                : "hover:bg-white/45 dark:hover:bg-white/[0.05]",
                            )}
                            style={{
                              ...rowStyle,
                              boxShadow: active ? "var(--vui-shadow-card)" : "none",
                            }}
                          >
                            {row}
                          </Link>
                        ) : (
                          <span
                            title={mini ? label : undefined}
                            className={cn(
                              "flex w-auto cursor-default items-center select-none opacity-60",
                              mini && "justify-center",
                            )}
                            style={rowStyle}
                          >
                            {row}
                          </span>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </div>
            );
          })}
        </nav>

        {/* ---- collapse ------------------------------------------------ */}
        <button
          type="button"
          onClick={toggle}
          aria-expanded={!mini}
          aria-label={t(mini ? "nav.expand" : "nav.collapse")}
          title={t(mini ? "nav.expand" : "nav.collapse")}
          className={cn(
            "focus-ink mx-4 mb-4 flex shrink-0 items-center gap-3 text-zinc-500 transition-colors",
            "hover:bg-white/45 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/[0.05] dark:hover:text-zinc-100",
            mini ? "justify-center" : "",
          )}
          style={{
            padding: mini ? "0.675rem" : "0.675rem 0.8rem 0.675rem 1rem",
            borderRadius: "var(--vui-r-lg)",
          }}
        >
          <svg
            viewBox="0 0 24 24"
            aria-hidden
            className="h-[18px] w-[18px] shrink-0"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            style={{
              transform: mini ? "rotate(180deg)" : "none",
              transition: `transform var(--vui-dur-standard) var(--vui-ease)`,
            }}
          >
            <path d="M15 18l-6-6 6-6" />
          </svg>
          <span
            className="truncate text-sm"
            style={{
              opacity: mini ? 0 : 1,
              maxWidth: mini ? 0 : "100%",
              transition: `opacity var(--vui-dur-standard) var(--vui-ease), max-width var(--vui-dur-standard) var(--vui-ease)`,
            }}
          >
            {t("nav.collapse")}
          </span>
        </button>
      </div>
    </aside>
  );
}

/**
 * The template's icon tile: a 32px square at a 12px radius carrying its own
 * shadow, filled with the accent when active and a flat tint when not. It is
 * the single most recognisable piece of the Vision UI sidenav, and the reason
 * its navigation reads as a list of objects rather than a list of links.
 */
function IconTile({
  active,
  muted,
  children,
}: {
  active: boolean;
  muted?: boolean;
  children: React.ReactNode;
}) {
  return (
    <span
      className={cn(
        "grid shrink-0 place-items-center",
        active
          ? "bg-brand-yellow text-ink"
          : muted
            ? "bg-zinc-900/[0.04] text-zinc-400 dark:bg-white/[0.06] dark:text-zinc-500"
            : "bg-zinc-900/[0.05] text-zinc-600 dark:bg-white/[0.08] dark:text-zinc-300",
      )}
      style={{
        width: "2rem",
        height: "2rem",
        borderRadius: "var(--vui-r-button)",
        boxShadow: active ? "var(--vui-shadow-button)" : "var(--vui-shadow-inset)",
        transition: `background-color var(--vui-dur-shorter) var(--vui-ease), color var(--vui-dur-shorter) var(--vui-ease), box-shadow var(--vui-dur-shorter) var(--vui-ease)`,
      }}
    >
      {children}
    </span>
  );
}

/** The reference's mark: two facing half-discs, an hourglass in silhouette. */
function Logo() {
  return (
    <span
      className="grid h-9 w-9 shrink-0 place-items-center bg-ink text-brand-yellow"
      style={{ borderRadius: "var(--vui-r-button)", boxShadow: "var(--vui-shadow-button)" }}
    >
      <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden>
        <path d="M4 3h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8Z" />
        <path d="M20 21H4a8 8 0 0 1 8-8 8 8 0 0 1 8 8Z" />
      </svg>
    </span>
  );
}
