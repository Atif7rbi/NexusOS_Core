import type { Metadata } from "next";
import {
  IBM_Plex_Sans_Arabic,
  Noto_Sans_Arabic,
  Tajawal,
} from "next/font/google";
import type { ReactNode } from "react";

import "./globals.css";

import { AppShellProvider } from "@/providers/AppShellProvider";
import { AuthProvider } from "@/providers/AuthProvider";
import { LanguageProvider } from "@/providers/LanguageProvider";
import { SystemSettingsProvider } from "@/providers/SystemSettingsProvider";
import { ThemeProvider } from "@/providers/ThemeProvider";
import { TypographyProvider } from "@/providers/TypographyProvider";

export const metadata: Metadata = {
  title: "NexusOS",
  description: "نظام تشغيل وإدارة الأعمال",
};

const ibmPlexSansArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic", "latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-ibm-plex-sans-arabic",
  display: "swap",
});

const notoSansArabic = Noto_Sans_Arabic({
  subsets: ["arabic", "latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-noto-sans-arabic",
  display: "swap",
});

const tajawal = Tajawal({
  subsets: ["arabic", "latin"],
  weight: ["400", "500", "700"],
  variable: "--font-tajawal",
  display: "swap",
});

export default function RootLayout({
  children,
}: {
  children: ReactNode;
}) {
  return (
    <html
      lang="ar-SA"
      dir="rtl"
      suppressHydrationWarning
      className={`${ibmPlexSansArabic.variable} ${notoSansArabic.variable} ${tajawal.variable}`}
    >
      <body>
        <SystemSettingsProvider>
          <LanguageProvider>
            <AuthProvider>
              <ThemeProvider>
                <TypographyProvider>
                  <AppShellProvider>
                    {children}
                  </AppShellProvider>
                </TypographyProvider>
              </ThemeProvider>
            </AuthProvider>
          </LanguageProvider>
        </SystemSettingsProvider>
      </body>
    </html>
  );
}
