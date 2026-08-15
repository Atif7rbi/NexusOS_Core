"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import {
  fetchCurrentUser,
  getStoredToken,
  login as loginRequest,
  logout as logoutRequest,
  removeStoredToken,
  storeToken,
} from "@/services/auth";
import { normalizeEffectiveModules } from "@/lib/entitlements";
import type {
  AuthUser,
  LoginPayload,
} from "@/types/auth";
import type { CommercialModuleKey } from "@/types/entitlement";

type AuthContextValue = {
  user: AuthUser | null;
  token: string | null;
  effectiveModules: CommercialModuleKey[];
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (payload: LoginPayload) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

type AuthProviderProps = {
  children: ReactNode;
};

export function AuthProvider({
  children,
}: AuthProviderProps) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [effectiveModules, setEffectiveModules] = useState<
    CommercialModuleKey[]
  >([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const restoreAuthentication = async (): Promise<void> => {
      const storedToken = getStoredToken();

      if (!storedToken) {
        setIsLoading(false);
        return;
      }

      try {
        const session =
          await fetchCurrentUser(storedToken);

        setToken(storedToken);
        setUser(session.user);
        setEffectiveModules(session.effectiveModules);
      } catch {
        removeStoredToken();
        setToken(null);
        setUser(null);
        setEffectiveModules([]);
      } finally {
        setIsLoading(false);
      }
    };

    void restoreAuthentication();
  }, []);

  const login = useCallback(
    async (payload: LoginPayload): Promise<void> => {
      const response = await loginRequest(payload);

      storeToken(response.data.token);
      setToken(response.data.token);
      setUser(response.data.user);
      setEffectiveModules(
        normalizeEffectiveModules(
          response.data.effective_modules
        )
      );
    },
    []
  );

  const logout = useCallback(async (): Promise<void> => {
    const currentToken = token ?? getStoredToken();

    try {
      if (currentToken) {
        await logoutRequest(currentToken);
      }
    } finally {
      removeStoredToken();
      setToken(null);
      setUser(null);
      setEffectiveModules([]);
    }
  }, [token]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      token,
      effectiveModules,
      isLoading,
      isAuthenticated: Boolean(user && token),
      login,
      logout,
    }),
    [user, token, effectiveModules, isLoading, login, logout]
  );

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error(
      "useAuth must be used within AuthProvider."
    );
  }

  return context;
}
