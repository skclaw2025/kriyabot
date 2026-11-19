"use client";
import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { firstError, postAuth } from "@/lib/auth-fetch";

export default function ResetPasswordPage() {
  const router = useRouter();
  const [token, setToken] = useState(""); const [email, setEmail] = useState(""); const [error, setError] = useState(""); const [busy, setBusy] = useState(false);
  useEffect(() => { const params = new URLSearchParams(window.location.search); setToken(params.get("token") ?? ""); setEmail(params.get("email") ?? ""); }, []);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError("");
    const form = new FormData(event.currentTarget);
    if (form.get("password") !== form.get("password_confirmation")) { setError("The two passwords do not match."); return; }
    setBusy(true);
    const result = await postAuth("/api/auth/reset-password", { token, email, password: form.get("password"), password_confirmation: form.get("password_confirmation") });
    setBusy(false);
    if (!result.ok) { setError(firstError(result.data, "This recovery link is invalid or expired.")); return; }
    router.push("/login?reset=1");
  }
  const missing = !token || !email;
  return <main className="auth-page"><section className="auth-card"><img className="auth-brand-logo" src="/kriyabot-logo.png" alt="Kriyabot" /><h1>Choose a new password</h1>{missing ? <p className="muted">This page needs the link from your recovery email. <Link href="/forgot-password">Request a new link</Link>.</p> : <form onSubmit={submit}><label className="field">New password<input name="password" type="password" required minLength={12} autoComplete="new-password" /></label><label className="field">Confirm new password<input name="password_confirmation" type="password" required minLength={12} autoComplete="new-password" /></label><button className="primary" disabled={busy}>{busy ? "Updating…" : "Update password"}</button>{error && <p className="error" role="alert">{error}</p>}</form>}</section></main>;
}
