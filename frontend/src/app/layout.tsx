import type { Metadata } from "next";
import { Geist_Mono, Urbanist } from "next/font/google";
import "./globals.css";
import { Providers } from "@/components/providers";

/*
 * Urbanist, per the reference design. It is a geometric sans with a very light
 * 300 weight, which is what carries the oversized page titles — those are set
 * in `font-light`, not bold, and the size does the work.
 *
 * The Latin Extended subset is not optional here: the app is Serbian, Turkish
 * and English, so č/ć/š/ž/đ and ğ/ı/İ/ş/ö/ü all have to render in the same
 * face rather than falling back mid-word.
 */
const urbanist = Urbanist({
  variable: "--font-urbanist",
  subsets: ["latin", "latin-ext"],
  weight: ["300", "400", "500", "600", "700"],
  display: "swap",
});

/* Kept for tabular figures in tables and money columns. */
const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "AdminisMine",
  description: "AdminisMine Finance Operations",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  /*
   * Both the document element and the body suppress hydration warnings, and
   * both have to say so: the flag applies only to the element it is set on, it
   * does not cascade to children.
   *
   * The reason is browser extensions. Password managers, ad blockers and
   * antivirus tools stamp their own attributes (`bis_register`,
   * `bis_skin_checked`, `data-lt-installed`, …) onto <html> and <body> before
   * React hydrates, so the markup React finds is not the markup the server
   * sent, and it reports a mismatch the app cannot fix — the attributes are not
   * ours and differ per machine. Suppressing is scoped to these two elements,
   * so a real mismatch anywhere inside the app is still reported.
   */
  return (
    <html
      lang="en"
      className={`${urbanist.variable} ${geistMono.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <body className="min-h-full" suppressHydrationWarning>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
