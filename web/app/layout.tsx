import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import "./globals.css";
import "./kriyabot-ui.css";

const inter = Inter({ subsets: ["latin"], display: "swap", variable: "--font-inter" });

export const metadata: Metadata = {
  title: { default: "Kriyabot", template: "%s · Kriyabot" },
  description: "Your work, projects, schedule, and personal AI assistant in one place.",
  applicationName: "Kriyabot",
  manifest: "/manifest.webmanifest",
  appleWebApp: { capable: true, statusBarStyle: "default", title: "Kriyabot" },
  icons: {
    icon: [{ url: "/kriyabot-icon.png", type: "image/png" }],
    apple: [{ url: "/kriyabot-icon.png", type: "image/png" }],
  },
};
export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
};
export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en-IN" className={inter.variable}><body>{children}</body></html>;
}
