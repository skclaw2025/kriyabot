"use client";
import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { firstError, postAuth } from "@/lib/auth-fetch";

export default function RegisterPage() {
  const router = useRouter(); const [error, setError] = useState(""); const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError("");
    const body: Record<string, FormDataEntryValue | string> = Object.fromEntries(new FormData(event.currentTarget));
    if (body.password !== body.password_confirmation) { setError("The two passwords do not match."); setBusy(false); return; }
    // Save the person's real timezone so reminders and "tomorrow at 4pm" resolve correctly.
    body.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "Asia/Kolkata";
    body.locale = navigator.language?.toLowerCase().startsWith("hi") ? "hi" : "en";
    const result = await postAuth("/api/auth/register", body);
    setBusy(false);
    if (!result.ok) { setError(firstError(result.data, "Please check your information.")); return; }
    router.push("/pending");
  }
  return <main className="auth-page"><section className="auth-card"><img className="auth-brand-logo" src="/kriyabot-logo.png" alt="Kriyabot" /><h1>Create your account</h1><p className="muted">We’ll create your private workspace. Access begins after email verification and approval.</p><form onSubmit={submit}><label className="field">Full name<input name="name" required autoComplete="name" /></label><label className="field">Email<input name="email" type="email" required autoComplete="email" /></label><label className="field">Password<input name="password" type="password" required minLength={12} autoComplete="new-password" /><small className="muted">At least 12 characters.</small></label><label className="field">Confirm password<input name="password_confirmation" type="password" required minLength={12} autoComplete="new-password" /></label><button className="primary" disabled={busy}>{busy ? "Creating account…" : "Register"}</button>{error && <p className="error" role="alert">{error}</p>}</form><p className="muted">Already registered? <Link href="/login">Sign in</Link></p></section></main>;
}
