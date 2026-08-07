"use client";

import { useEffect, useRef, useState } from "react";
import { useI18n } from "@/lib/i18n/context";
import { amountTone, expenseAmount, formatSignedMoney } from "@/lib/format";

export interface CashflowPoint {
  month: string;
  income: number;
  expenses: number;
  net: number;
}

/**
 * Money in vs money out over the last six months.
 *
 * Two series, so the job is identity: categorical slots 1 (blue) and 2 (orange),
 * validated for lightness band, chroma, CVD separation and contrast against the
 * card surfaces this actually renders on. Both measures are EUR on one axis —
 * never two scales.
 */
export function CashflowChart({ points }: { points: CashflowPoint[] }) {
  const { t, locale } = useI18n();
  const containerRef = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(640);
  const [hovered, setHovered] = useState<number | null>(null);
  const [showTable, setShowTable] = useState(false);

  // Measured rather than scaled by viewBox, so the mark specs below (column
  // cap, gaps) are real pixels instead of whatever the aspect ratio makes them.
  useEffect(() => {
    const element = containerRef.current;
    if (!element) return;

    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const height = 200;
  const padding = { top: 16, right: 8, bottom: 28, left: 56 };
  const plotWidth = Math.max(width - padding.left - padding.right, 80);
  const plotHeight = height - padding.top - padding.bottom;

  const max = niceMax(Math.max(1, ...points.flatMap((p) => [p.income, p.expenses])));
  const ticks = [0, max / 2, max];

  const band = points.length > 0 ? plotWidth / points.length : plotWidth;
  const GAP = 2; // surface gap between the two columns of a pair
  const columnWidth = Math.min(24, Math.max(6, (band * 0.62 - GAP) / 2));
  const pairWidth = columnWidth * 2 + GAP;

  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight;
  const bandCentre = (index: number) => padding.left + band * index + band / 2;

  const monthLabel = (month: string) =>
    new Intl.DateTimeFormat(locale, { month: "short" }).format(new Date(`${month}T00:00:00`));

  const active = hovered === null ? null : points[hovered];

  return (
    <div className="cashflow-chart">
      <style>{`
        /*
         * Re-validated for the warm redesign with the skill's validator against
         * the surface this chart actually renders on. The card is translucent,
         * so that surface is the composite, not #ffffff:
         *   light  white 62% over the sand wash  -> #f8f7f5
         *   dark   #2e2c27 60% over #14130f      -> #24221d
         *
         *   node scripts/validate_palette.js "#2a78d6,#e05f2a" --mode light  --surface "#f8f7f5"
         *   node scripts/validate_palette.js "#3987e5,#d95926" --mode dark   --surface "#24221d"
         * Both: all six checks PASS.
         *
         * The expenses orange moved #eb6834 -> #e05f2a because the lighter
         * surface pushed the old value to 2.99:1, just under the 3:1 floor.
         *
         * The series stay blue/orange rather than taking the brand yellow: that
         * yellow is a *surface* colour — as a thin mark on a near-white card it
         * cannot reach 3:1 against anything. It earns its keep on the solid
         * cards, not in here.
         */
        .cashflow-chart {
          --series-income: #2a78d6;
          --series-expenses: #e05f2a;
          --chart-grid: #e3e0d6;
          --chart-axis: #c6c2b4;
          --chart-muted: #8a8579;
          --chart-surface: #f8f7f5;
        }
        @media (prefers-color-scheme: dark) {
          .cashflow-chart {
            --series-income: #3987e5;
            --series-expenses: #d95926;
            --chart-grid: #33302a;
            --chart-axis: #403c35;
            --chart-muted: #8a8579;
            --chart-surface: #24221d;
          }
        }
      `}</style>

      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        {/* Two series, so a legend is always present — identity never rests on colour alone. */}
        <ul className="flex flex-wrap gap-4">
          {(
            [
              ["income", "var(--series-income)"],
              ["expenses", "var(--series-expenses)"],
            ] as const
          ).map(([key, colour]) => (
            <li key={key} className="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
              <span
                aria-hidden
                className="h-2.5 w-2.5 rounded-full"
                style={{ backgroundColor: colour }}
              />
              {t(`dashboard.${key}`)}
            </li>
          ))}
        </ul>
        <button
          onClick={() => setShowTable((prev) => !prev)}
          className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
        >
          {showTable ? t("dashboard.showChart") : t("dashboard.showTable")}
        </button>
      </div>

      {showTable ? (
        <CashflowTable points={points} monthLabel={monthLabel} />
      ) : (
        <div ref={containerRef} className="relative w-full">
          <svg width={width} height={height} role="img" aria-label={t("dashboard.cashflowTitle")}>
            {ticks.map((tick) => (
              <g key={tick}>
                {/* Hairline, solid, one step off the surface. */}
                <line
                  x1={padding.left}
                  x2={padding.left + plotWidth}
                  y1={y(tick)}
                  y2={y(tick)}
                  stroke={tick === 0 ? "var(--chart-axis)" : "var(--chart-grid)"}
                  strokeWidth={1}
                />
                <text
                  x={padding.left - 8}
                  y={y(tick) + 4}
                  textAnchor="end"
                  className="tabular-nums"
                  fontSize={10}
                  fill="var(--chart-muted)"
                >
                  {compact(tick)}
                </text>
              </g>
            ))}

            {points.map((point, index) => {
              const centre = bandCentre(index);
              const left = centre - pairWidth / 2;
              const isLast = index === points.length - 1;

              return (
                <g key={point.month}>
                  {/* Hit target spans the whole band, so hovering never asks
                      the reader to land on a thin column. */}
                  <rect
                    x={padding.left + band * index}
                    y={padding.top}
                    width={band}
                    height={plotHeight}
                    fill="transparent"
                    onMouseEnter={() => setHovered(index)}
                    onMouseLeave={() => setHovered(null)}
                  />
                  <Column
                    x={left}
                    value={point.income}
                    y={y(point.income)}
                    baseline={y(0)}
                    width={columnWidth}
                    fill="var(--series-income)"
                    dimmed={hovered !== null && hovered !== index}
                  />
                  <Column
                    x={left + columnWidth + GAP}
                    value={point.expenses}
                    y={y(point.expenses)}
                    baseline={y(0)}
                    width={columnWidth}
                    fill="var(--series-expenses)"
                    dimmed={hovered !== null && hovered !== index}
                  />
                  <text
                    x={centre}
                    y={height - 10}
                    textAnchor="middle"
                    fontSize={10}
                    fill="var(--chart-muted)"
                    fontWeight={isLast ? 600 : 400}
                  >
                    {monthLabel(point.month)}
                  </text>
                </g>
              );
            })}
          </svg>

          {active && (
            <div
              className="pointer-events-none absolute z-10 rounded-md border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
              style={{
                left: Math.min(Math.max(bandCentre(hovered ?? 0) - 70, 0), Math.max(width - 140, 0)),
                top: 0,
                width: 140,
              }}
            >
              <p className="font-medium text-zinc-900 dark:text-zinc-50">{monthLabel(active.month)}</p>
              {/*
                The columns are drawn as magnitudes against one axis, which is
                what a paired-column chart is; the numbers beside them carry the
                sign, so the reader can see that Net is income minus expenses
                rather than having to assume it. The figures are left in the
                tooltip's own text colour — a green/red here would fight the
                series colours the swatches and columns already use.
              */}
              <p className="mt-1 flex justify-between gap-2 text-zinc-600 dark:text-zinc-300">
                <span>{t("dashboard.income")}</span>
                <span className="tabular-nums">{formatSignedMoney(active.income)}</span>
              </p>
              <p className="flex justify-between gap-2 text-zinc-600 dark:text-zinc-300">
                <span>{t("dashboard.expenses")}</span>
                <span className="tabular-nums">{formatSignedMoney(expenseAmount(active.expenses))}</span>
              </p>
              <p className="mt-1 flex justify-between gap-2 border-t border-zinc-200 pt-1 font-medium text-zinc-900 dark:border-zinc-700 dark:text-zinc-50">
                <span>{t("dashboard.net")}</span>
                <span className={`tabular-nums ${amountTone(active.net)}`}>{formatSignedMoney(active.net)}</span>
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/** A column: 4px rounded at the data end, square where it meets the baseline. */
function Column({
  x,
  y,
  value,
  baseline,
  width,
  fill,
  dimmed,
}: {
  x: number;
  y: number;
  value: number;
  baseline: number;
  width: number;
  fill: string;
  dimmed: boolean;
}) {
  const barHeight = Math.max(baseline - y, value > 0 ? 2 : 0);
  if (barHeight <= 0) return null;

  const radius = Math.min(4, width / 2, barHeight);
  const top = baseline - barHeight;

  return (
    <path
      d={`M ${x} ${baseline}
          L ${x} ${top + radius}
          Q ${x} ${top} ${x + radius} ${top}
          L ${x + width - radius} ${top}
          Q ${x + width} ${top} ${x + width} ${top + radius}
          L ${x + width} ${baseline} Z`}
      fill={fill}
      opacity={dimmed ? 0.45 : 1}
    />
  );
}

/** The table-view twin: every value readable without hovering anything. */
function CashflowTable({
  points,
  monthLabel,
}: {
  points: CashflowPoint[];
  monthLabel: (month: string) => string;
}) {
  const { t } = useI18n();

  return (
    <table className="w-full text-sm">
      <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
        <tr>
          <th className="py-2">{t("dashboard.month")}</th>
          <th className="py-2 text-right">{t("dashboard.income")}</th>
          <th className="py-2 text-right">{t("dashboard.expenses")}</th>
          <th className="py-2 text-right">{t("dashboard.net")}</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
        {points.map((point) => (
          <tr key={point.month} className="text-zinc-800 dark:text-zinc-200">
            <td className="py-2">{monthLabel(point.month)}</td>
            {/* A numeric readout, so every figure is signed and toned as it is
                everywhere else in the app. */}
            <td className={`py-2 text-right tabular-nums ${amountTone(point.income)}`}>
              {formatSignedMoney(point.income)}
            </td>
            <td className={`py-2 text-right tabular-nums ${amountTone(expenseAmount(point.expenses))}`}>
              {formatSignedMoney(expenseAmount(point.expenses))}
            </td>
            <td className={`py-2 text-right tabular-nums ${amountTone(point.net)}`}>
              {formatSignedMoney(point.net)}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** Round the axis top up to a clean number so the ticks read 0 / 5,000 / 10,000. */
function niceMax(value: number): number {
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const normalized = value / magnitude;
  const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;

  return step * magnitude;
}

function compact(value: number): string {
  if (Math.abs(value) >= 1000) return `${Math.round(value / 100) / 10}k`;
  return String(Math.round(value));
}
