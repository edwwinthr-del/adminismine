import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";
import { Providers } from "@/components/providers";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

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
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <body className="min-h-full" suppressHydrationWarning>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
