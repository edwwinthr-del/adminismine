"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";
import { onSessionExpired } from "@/lib/auth/session";
import { clearCache } from "@/lib/data/cache";
import { clearToken, getToken, setToken } from "@/lib/token";
import type { User } from "@/lib/types";

interface AuthValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  setLocaleRemote: (locale: string) => Promise<void>;
  refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const loadMe = useCallback(async () => {
    if (!getToken()) {
      setUser(null);
      setLoading(false);
      return;
    }
    try {
      const res = await apiFetch<{ user: User }>("/me");
      setUser(res.user);
    } catch {
      clearToken();
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadMe();
  }, [loadMe]);

  // A 401 means the server has stopped accepting this session — the account was
  // deactivated, or its password was reset, which revokes its tokens. Dropping
  // the user here is what makes AppLayout send the browser back to the login
  // screen; the token and the read cache are already gone by this point.
  useEffect(() => onSessionExpired(() => setUser(null)), []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await apiFetch<{ token: string; user: User }>("/login", {
      method: "POST",
      json: { email, password },
    });
    setToken(res.token);
    setUser(res.user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiFetch("/logout", { method: "POST" });
    } catch {
      // ignore — clear locally regardless
    }
    clearToken();
    setUser(null);
    // Nothing the previous user could see may be served to the next one.
    clearCache();
  }, []);

  const hasPermission = useCallback(
    (permission: string) =>
      !!user && (user.roles.includes("Super Admin") || user.permissions.includes(permission)),
    [user],
  );

  const setLocaleRemote = useCallback(async (locale: string) => {
    if (!getToken()) return;
    try {
      const res = await apiFetch<{ user: User }>("/me/locale", { method: "PUT", json: { locale } });
      setUser(res.user);
    } catch {
      // non-critical: local preference still applies
    }
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasPermission, setLocaleRemote, refresh: loadMe }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
