"use client";

import { AuthProvider } from "@/lib/auth/context";
import { I18nProvider } from "@/lib/i18n/context";
import { ThemeProvider } from "@/lib/theme";

export function Providers({ children }: { children: React.ReactNode }) {
  return (
    // Outermost: the theme is a property of the document, not of a session or
    // a company, so it applies on the login screen too.
    <ThemeProvider>
      <I18nProvider>
        <AuthProvider>{children}</AuthProvider>
      </I18nProvider>
    </ThemeProvider>
  );
}
