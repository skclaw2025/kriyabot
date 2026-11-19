"use client";
import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

export default function LoginPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("verified") === "1") setNotice("Your email is verified. You can sign in once your account is approved.");
    if (params.get("reset") === "1") setNotice("Your password was changed. Sign in with the new password.");
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setError("");
    try {
      const body = Object.fromEntries(new FormData(event.currentTarget));
      const result = await fetch("/api/auth/login", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body) });
      const data = await result.json().catch(() => ({}));
      if (!result.ok) {
        setError(result.status === 429 ? "Too many sign-in attempts. Please wait a minute and try again." : data.message ?? "Unable to sign in right now. Please try again.");
        return;
      }
      router.push(data.is_admin ? "/admin" : "/app");
      router.refresh();
    } catch {
      setError("Kriyabot could not be reached. Check your internet connection and try again.");
    } finally {
      setBusy(false);
    }
  }

  return <main className="auth-page"><section className="auth-card"><img className="auth-brand-logo" src="/kriyabot-logo.png" alt="Kriyabot" /><h1>Welcome back</h1><p className="muted">Sign in to continue to your workspace.</p>{notice && <p className="success" role="status">{notice}</p>}<form onSubmit={submit}><label className="field">Email<input name="email" type="email" required autoComplete="email" /></label><label className="field">Password<input name="password" type="password" required autoComplete="current-password" /></label><button className="primary" disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>{error && <p className="error" role="alert">{error}</p>}</form><p className="muted"><Link href="/forgot-password">Forgot your password?</Link></p><p className="muted">New here? <Link href="/register">Create an account</Link></p></section></main>;
}
