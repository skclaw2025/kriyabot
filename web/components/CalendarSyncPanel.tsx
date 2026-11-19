"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { readJson } from "@/components/tasks/types";
import { formatDateTime } from "@/lib/datetime";

type Source = { id: number; name: string; last_synced_at?: string | null; last_error?: string | null; events_count: number };

/**
 * Connect Kriyabot with phone calendars and Outlook:
 *  1) subscribe to Kriyabot from Google / iPhone / Outlook (private ICS feed);
 *  2) bring Outlook / Google meetings into Kriyabot (their ICS link), refreshed every 30 minutes.
 */
export function CalendarSyncPanel({ onChanged, onClose }: { onChanged: () => void; onClose: () => void }) {
  const [feed, setFeed] = useState("");
  const [sources, setSources] = useState<Source[]>([]);
  const [name, setName] = useState("Outlook");
  const [url, setUrl] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    const r = await fetch("/api/backend/calendar/sync", { cache: "no-store" }).catch(() => null);
    if (!r?.ok) { setError("Calendar sync settings couldn’t be loaded."); return; }
    const d = await r.json();
    setFeed(d.feed_url ?? ""); setSources(d.sources ?? []);
  }, []);
  useEffect(() => { void load(); }, [load]);

  const webcal = feed.replace(/^https?:\/\//, "webcal://");
  async function copy() { try { await navigator.clipboard.writeText(feed); setNotice("Link copied."); } catch { setNotice("Select the link and copy it."); } }

  async function addSource(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(""); setNotice("");
    const r = await fetch("/api/backend/calendar/sources", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ name, url }) }).catch(() => null);
    const d = r ? await readJson<Source>(r) : { message: "Kriyabot couldn’t be reached." };
    setBusy(false);
    if (!r?.ok) { setError(d.message ?? "That calendar couldn’t be added."); return; }
    setUrl(""); setNotice(`Connected — ${(d as Source).events_count} events imported. Clashes with your Kriyabot meetings will now be flagged.`);
    await load(); onChanged();
  }
  async function refresh(source: Source) {
    setBusy(true);
    const r = await fetch(`/api/backend/calendar/sources/${source.id}/refresh`, { method: "POST" }).catch(() => null);
    setBusy(false); await load(); onChanged();
    if (!r?.ok) setError(`“${source.name}” couldn’t be refreshed. Check that the link is still published.`);
  }
  async function remove(source: Source) {
    if (!window.confirm(`Disconnect “${source.name}”? Its events will disappear from Kriyabot.`)) return;
    await fetch(`/api/backend/calendar/sources/${source.id}`, { method: "DELETE" }).catch(() => null);
    await load(); onChanged();
  }
  async function rotate() {
    if (!window.confirm("Create a new private link? Calendars subscribed with the old link will stop updating until you add the new one.")) return;
    const r = await fetch("/api/backend/calendar/sync/rotate", { method: "POST" }).catch(() => null);
    if (r?.ok) { const d = await r.json(); setFeed(d.feed_url); setNotice("New private link created."); }
  }

  return <section className="kb-card kb-sync-panel" aria-label="Calendar sync">
    <header className="kb-card-header" style={{ marginBottom: 0 }}><h2>Sync with your phone, Google and Outlook</h2><button className="kb-icon-button" onClick={onClose} aria-label="Close">✕</button></header>
    {notice && <p className="kb-alert kb-alert-success">{notice}</p>}
    {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}

    <div>
      <h3 className="kb-subhead" style={{ marginTop: 0 }}>1 · Show Kriyabot in your phone / Outlook calendar</h3>
      <p className="kb-hint" style={{ marginBottom: 8 }}>Your meetings and task deadlines appear in the calendar app you already use, with alerts. Keep this link private.</p>
      <div className="kb-sync-feed"><input className="kb-input" readOnly value={feed} onFocus={e => e.currentTarget.select()} aria-label="Private calendar link" /><button className="kb-button kb-button-ghost" onClick={() => void copy()}>Copy</button></div>
      <div className="kb-sync-buttons" style={{ marginTop: 8 }}>
        <a className="kb-button kb-button-ghost kb-button-sm" href={`https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcal)}`} target="_blank" rel="noreferrer">Add to Google Calendar (Android)</a>
        <a className="kb-button kb-button-ghost kb-button-sm" href={webcal}>Add to iPhone / Mac</a>
        <a className="kb-button kb-button-ghost kb-button-sm" href={`https://outlook.live.com/calendar/0/addfromweb?url=${encodeURIComponent(feed)}&name=${encodeURIComponent("Kriyabot")}`} target="_blank" rel="noreferrer">Add to Outlook.com</a>
        <a className="kb-button kb-button-ghost kb-button-sm" href={`https://outlook.office.com/calendar/0/addfromweb?url=${encodeURIComponent(feed)}&name=${encodeURIComponent("Kriyabot")}`} target="_blank" rel="noreferrer">Add to Outlook (work)</a>
        <button className="kb-link-button" onClick={() => void rotate()}>Reset link</button>
      </div>
    </div>

    <div>
      <h3 className="kb-subhead">2 · Bring Outlook / Google meetings into Kriyabot</h3>
      <ol className="kb-steps">
        <li><b>Outlook:</b> Settings → Calendar → Shared calendars → Publish a calendar → choose “Can view all details” → copy the <b>ICS</b> link.</li>
        <li><b>Google:</b> Calendar settings → your calendar → “Secret address in iCal format” → copy.</li>
        <li>Paste it below. Kriyabot refreshes it every 30 minutes and warns you about clashes.</li>
      </ol>
      <form className="kb-inline-row" onSubmit={addSource} style={{ marginTop: 10, flexWrap: "wrap" }}>
        <select className="kb-input" style={{ maxWidth: 140 }} value={name} onChange={e => setName(e.target.value)} aria-label="Calendar name"><option>Outlook</option><option>Google</option><option>iCloud</option><option>Office</option><option>Family</option></select>
        <input className="kb-input" style={{ minWidth: 220 }} value={url} onChange={e => setUrl(e.target.value)} placeholder="https://outlook.office365.com/owa/calendar/…/calendar.ics" required aria-label="Calendar ICS link" />
        <button className="kb-button kb-button-primary" disabled={busy || !url.trim()}>{busy ? "Connecting…" : "Connect"}</button>
      </form>
      {sources.length > 0 && <ul className="kb-sync-sources" style={{ marginTop: 12 }}>{sources.map(s => <li key={s.id}>
        <span><b>{s.name}</b>{s.last_error ? <small className="is-error">⚠ {s.last_error}</small> : <small>{s.events_count} events · updated {s.last_synced_at ? formatDateTime(s.last_synced_at) : "never"}</small>}</span>
        <span className="kb-head-actions"><button className="kb-link-button" disabled={busy} onClick={() => void refresh(s)}>Refresh</button><button className="kb-link-button is-danger" onClick={() => void remove(s)}>Disconnect</button></span>
      </li>)}</ul>}
    </div>
  </section>;
}
