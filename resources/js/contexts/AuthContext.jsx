import { createContext, useState, useEffect, useCallback } from "react";
import authService from "../services/auth.service";
import token from "../utils/token";

export const AuthContext = createContext(null);

const unwrap = (response) => response?.data?.data ?? response?.data ?? response;
const normalizeUser = (value) => {
    if (!value || typeof value !== "object") return null;
    return {
        ...value,
        roles: Array.isArray(value.roles) ? value.roles : (value.role ? [value.role] : []),
    };
};

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(false);
    const [initializing, setInitializing] = useState(true);

    const login = async (credentials) => {
        setLoading(true);
        try {
            const response = await authService.login(credentials);
            const payload = unwrap(response);
            const accessToken = payload?.token ?? payload?.access_token;
            const authenticatedUser = normalizeUser(payload?.user);
            if (!accessToken || !authenticatedUser) {
                throw new Error("The server returned an invalid login response. Please try again.");
            }
            token.set(accessToken);
            setUser(authenticatedUser);
            return payload;
        } finally {
            setLoading(false);
        }
    };

    const checkAuth = useCallback(async () => {
        const accessToken = token.get();
        if (!accessToken) {
            setInitializing(false);
            return;
        }
        try {
            const response = await authService.me();
            const payload = unwrap(response);
            const authenticatedUser = normalizeUser(payload?.user ?? payload);
            if (!authenticatedUser) throw new Error("Invalid session response.");
            setUser(authenticatedUser);
        } catch (error) {
            token.remove();
            setUser(null);
        } finally {
            setInitializing(false);
        }
    }, []);

    useEffect(() => { void checkAuth(); }, [checkAuth]);

    const logout = async () => {
        setLoading(true);
        try {
            await authService.logout();
        } finally {
            token.remove();
            setUser(null);
            setLoading(false);
        }
    };

    const value = {
        user,
        loading,
        initializing,
        isAuthenticated: !!user,
        login,
        logout,
        checkAuth,
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
