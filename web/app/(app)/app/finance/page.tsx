"use client";

import Link from "next/link";
import { ChangeEvent, FormEvent, Suspense, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { EntryDrawer } from "@/components/finance/EntryDrawer";
import { Entry, FinanceType, Head, Summary, paymentLabel } from "@/components/finance/types";
import { readJson } from "@/components/tasks/types";
import { dayKey, formatDate, relativeDay } from "@/lib/datetime";
import { formatINR, prepareBillFile } from "@/lib/image";

export default function FinancePage() {
  return <Suspense fallback={<div className="kb-page"><div className="kb-skeleton" style={{ height: 320 }} /></div>}><FinanceView /></Suspense>;
}

const monthLabel = (ym: string) => new Intl.DateTimeFormat("en-IN", { month: "long", year: "numeric" }).format(new Date(`${ym}-15T12:00:00`));
const shiftMonth = (ym: string, delta: number) => { const [y, m] = ym.split("-").map(Number); const d = new Date(Date.UTC(y, m - 1 + delta, 15)); return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`; };
const monthRange = (ym: string) => { const [y, m] = ym.split("-").map(Number); const last = new Date(Date.UTC(y, m, 0)).getUTCDate(); return { from: `${ym}-01`, to: `${ym}-${String(last).padStart(2, "0")}` }; };

function FinanceView() {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const month = /^\d{4}-\d{2}$/.test(params.get("month") ?? "") ? params.get("month")! : dayKey(new Date()).slice(0, 7);
  const typeFilter = (params.get("type") ?? "all") as "all" | FinanceType;
  const headFilter = params.get("head") ?? "all";
  const reviewOnly = params.get("review") === "1";
  const openEntryId = params.get("entry");
  const addType = params.get("add") as FinanceType | null;

  const [entries, setEntries] = useState<Entry[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [heads, setHeads] = useState<Head[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [scanState, setScanState] = useState<{ busy: boolean; label: string; results: Array<{ ok: boolean; text: string; entryId?: number }> }>({ busy: false, label: "", results: [] });
  const [scanNotice, setScanNotice] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);

  const setParams = useCallback((changes: Record<string, string | null>) => {
    const next = new URLSearchParams(params.toString());
    for (const [k, v] of Object.entries(changes)) { if (v === null) next.delete(k); else next.set(k, v); }
    const q = next.toString();
    router.replace(q ? `${pathname}?${q}` : pathname, { scroll: false });
  }, [params, pathname, router]);

  const load = useCallback(async () => {
    const { from, to } = monthRange(month);
    try {
      const [e, h] = await Promise.all([fetch(`/api/backend/finance/entries?from=${from}&to=${to}`, { cache: "no-store" }), fetch("/api/backend/finance/heads", { cache: "no-store" })]);
      const [ed, hd] = await Promise.all([readJson<{ data?: Entry[]; summary?: Summary }>(e), h.json().catch(() => [])]);
      if (!e.ok) { setError(ed.message ?? "Your finance book couldn’t be loaded."); return; }
      setEntries(ed.data ?? []); setSummary(ed.summary ?? null); setHeads(Array.isArray(hd) ? hd : []); setError("");
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and refresh."); }
    finally { setLoading(false); }
  }, [month]);
  useEffect(() => { setLoading(true); void load(); }, [load]);

  async function scanFiles(event: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);
    event.target.value = "";
    if (!files.length) return;
    const results: Array<{ ok: boolean; text: string; entryId?: number }> = [];
    let lastEntry: Entry | null = null;
    for (const [index, original] of files.entries()) {
      setScanState({ busy: true, label: files.length > 1 ? `Reading bill ${index + 1} of ${files.length}…` : "Reading your bill…", results });
      try {
        const body = new FormData(); body.append("file", await prepareBillFile(original));
        const response = await fetch("/api/backend/finance/scan", { method: "POST", body });
        const data = await readJson<Entry & { duplicate?: Entry }>(response);
        if (response.status === 409 && data.duplicate) results.push({ ok: false, text: data.message ?? "Already recorded.", entryId: data.duplicate.id });
        else if (!response.ok) results.push({ ok: false, text: `${original.name}: ${data.message ?? "couldn’t be read."}` });
        else {
          const entry = data as Entry; lastEntry = entry;
          results.push({ ok: true, entryId: entry.id, text: `${entry.type === "income" ? "Income" : "Expense"} ${formatINR(entry.amount)}${entry.party ? ` · ${entry.party}` : ""}${entry.head ? ` → ${entry.head.name}` : entry.ai_suggested_head ? ` → suggested “${entry.ai_suggested_head}”` : ""}${entry.needs_review ? " (please check)" : ""}` });
        }
      } catch { results.push({ ok: false, text: `${original.name}: upload failed. Check your connection.` }); }
    }
    setScanState({ busy: false, label: "", results });
    await load();
    // A single scan opens straight away for a quick check.
    if (files.length === 1 && lastEntry) { setScanNotice(`Saved from your bill: ${results[0].text}`); setParams({ entry: String(lastEntry.id), add: null }); }
  }

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    return entries.filter(e => (typeFilter === "all" || e.type === typeFilter)
      && (headFilter === "all" || (headFilter === "none" ? !e.head_id : String(e.head_id) === headFilter))
      && (!reviewOnly || e.needs_review)
      && (!q || `${e.party ?? ""} ${e.description ?? ""} ${e.bill_no ?? ""} ${e.head?.name ?? ""}`.toLowerCase().includes(q)));
  }, [entries, typeFilter, headFilter, reviewOnly, search]);

  const byDay = useMemo(() => {
    const groups = new Map<string, Entry[]>();
    for (const e of visible) { const k = e.entry_date.slice(0, 10); groups.set(k, [...(groups.get(k) ?? []), e]); }
    return [...groups.entries()].sort((a, b) => b[0].localeCompare(a[0]));
  }, [visible]);

  const openEntry = openEntryId ? entries.find(e => String(e.id) === openEntryId) ?? null : null;
  const drawerOpen = !!addType || !!openEntry;
  const expenseHeads = summary?.by_head.filter(h => h.type === "expense") ?? [];
  const incomeHeads = summary?.by_head.filter(h => h.type === "income") ?? [];
  const maxExpense = Math.max(1, ...expenseHeads.map(h => h.total));
  const isCurrentMonth = month === dayKey(new Date()).slice(0, 7);
  const { from, to } = monthRange(month);

  return <div className="kb-page">
    <header className="kb-page-header">
      <div><h1>Finance</h1><p>Household and office income &amp; expenses. Scan a bill and the AI records it for you.</p></div>
      <div className="kb-header-actions">
        <a className="kb-button kb-button-ghost" href={`/api/backend/finance/export?from=${from}&to=${to}`}>Export CSV</a>
        <button className="kb-button kb-button-ghost" onClick={() => setParams({ add: "income", entry: null })}>＋ Income</button>
        <button className="kb-button kb-button-ghost" onClick={() => setParams({ add: "expense", entry: null })}>＋ Expense</button>
        <button className="kb-button kb-button-primary" disabled={scanState.busy} onClick={() => fileRef.current?.click()}>📷 Scan bill</button>
        <input ref={fileRef} type="file" hidden multiple accept="image/*,application/pdf" onChange={event => void scanFiles(event)} />
      </div>
    </header>

    {scanState.busy && <div className="kb-scan-progress kb-card" role="status"><span className="kb-spinner" /> {scanState.label} <small>The AI is reading the amount, date, bill number and vendor.</small></div>}
    {!scanState.busy && scanState.results.length > 1 && <div className="kb-card kb-scan-results"><header><b>Scan results</b><button className="kb-icon-button" onClick={() => setScanState({ busy: false, label: "", results: [] })} aria-label="Dismiss">✕</button></header>
      <ul>{scanState.results.map((r, i) => <li key={i} className={r.ok ? "is-ok" : "is-bad"}>{r.ok ? "✓" : "!"} {r.text}{r.entryId && <button className="kb-link-button" onClick={() => setParams({ entry: String(r.entryId) })}>Open</button>}</li>)}</ul></div>}
    {!scanState.busy && scanState.results.length === 1 && !scanState.results[0].ok && <p className="kb-alert kb-alert-error" role="alert">{scanState.results[0].text}{scanState.results[0].entryId && <> <button className="kb-link-button" onClick={() => setParams({ entry: String(scanState.results[0].entryId) })}>View existing entry</button></>}</p>}
    {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}

    <div className="kb-toolbar">
      <div className="kb-month-nav">
        <button className="kb-icon-button" aria-label="Previous month" onClick={() => setParams({ month: shiftMonth(month, -1) })}>‹</button>
        <b>{monthLabel(month)}</b>
        <button className="kb-icon-button" aria-label="Next month" onClick={() => setParams({ month: shiftMonth(month, 1) })} disabled={isCurrentMonth}>›</button>
        {!isCurrentMonth && <button className="kb-button kb-button-ghost kb-button-sm" onClick={() => setParams({ month: null })}>This month</button>}
      </div>
      <div className="kb-toolbar-filters">
        <input className="kb-input" type="search" placeholder="Search vendor, bill no…" value={search} onChange={e => setSearch(e.target.value)} aria-label="Search entries" />
        <select className="kb-input" value={headFilter} onChange={e => setParams({ head: e.target.value === "all" ? null : e.target.value })} aria-label="Filter by head">
          <option value="all">All heads</option><option value="none">Unassigned</option>
          {heads.map(h => <option key={h.id} value={h.id}>{h.name} ({h.type === "income" ? "income" : "expense"})</option>)}
        </select>
      </div>
    </div>

    <section className="kb-stats">
      <button className={`kb-stat ${typeFilter === "income" ? "is-selected" : ""}`} onClick={() => setParams({ type: typeFilter === "income" ? null : "income" })}><span>Income</span><b className="tone-income">{summary ? formatINR(summary.income) : "–"}</b></button>
      <button className={`kb-stat ${typeFilter === "expense" ? "is-selected" : ""}`} onClick={() => setParams({ type: typeFilter === "expense" ? null : "expense" })}><span>Expenses</span><b className="tone-expense">{summary ? formatINR(summary.expense) : "–"}</b></button>
      <div className="kb-stat"><span>Balance</span><b className={summary && summary.balance < 0 ? "tone-expense" : ""}>{summary ? formatINR(summary.balance) : "–"}</b></div>
      <div className="kb-stat"><span>Today</span><b className="kb-stat-text">{summary ? <><span className="tone-income">+{formatINR(summary.today.income)}</span> / <span className="tone-expense">−{formatINR(summary.today.expense)}</span></> : "–"}</b></div>
    </section>

    {!!summary?.needs_review && <button className="kb-alert kb-alert-warn kb-alert-button" onClick={() => setParams({ review: reviewOnly ? null : "1" })}>
      {reviewOnly ? "Showing entries that need a check · Show all" : `${summary.needs_review} scanned ${summary.needs_review === 1 ? "bill needs" : "bills need"} a quick check (amount, date or head). Review now →`}
    </button>}

    <div className="kb-finance-grid">
      <section className="kb-card kb-ledger">
        {loading ? <div className="kb-skeleton-rows">{[0, 1, 2, 3].map(i => <span key={i} />)}</div>
          : !entries.length ? <div className="kb-empty"><b>No entries in {monthLabel(month)}</b><p>Tap <b>Scan bill</b> to photograph a receipt — the amount, date, bill number and vendor are filled in automatically. Or add income and expenses by hand.</p><button className="kb-button kb-button-primary" onClick={() => fileRef.current?.click()}>📷 Scan your first bill</button></div>
          : !visible.length ? <div className="kb-empty"><b>Nothing matches these filters</b><button className="kb-button kb-button-ghost" onClick={() => { setSearch(""); setParams({ type: null, head: null, review: null }); }}>Clear filters</button></div>
          : byDay.map(([day, items]) => {
            const dayIn = items.filter(e => e.type === "income").reduce((s, e) => s + Number(e.amount), 0);
            const dayOut = items.filter(e => e.type === "expense").reduce((s, e) => s + Number(e.amount), 0);
            return <div key={day} className="kb-ledger-day">
              <header><b>{relativeDay(`${day}T12:00:00+05:30`) ?? formatDate(day, "", { year: false })}</b><span>{dayIn > 0 && <span className="tone-income">+{formatINR(dayIn)}</span>}{dayOut > 0 && <span className="tone-expense">−{formatINR(dayOut)}</span>}</span></header>
              {items.map(e => <button key={e.id} className={`kb-ledger-row ${e.needs_review ? "needs-review" : ""}`} onClick={() => setParams({ entry: String(e.id), add: null })}>
                <span className={`kb-ledger-icon type-${e.type}`}>{e.type === "income" ? "↓" : "↑"}</span>
                <span className="kb-ledger-main">
                  <b>{e.party || e.description || (e.type === "income" ? "Income" : "Expense")}</b>
                  <small>
                    {e.head ? <span className="kb-chip kb-chip-project">{e.head.name}</span> : e.ai_suggested_head ? <span className="kb-chip kb-chip-suggest">Suggested: {e.ai_suggested_head}</span> : <span className="kb-chip kb-chip-muted">Unassigned</span>}
                    {e.bill_no && <span>#{e.bill_no}</span>}
                    {e.payment_method && <span>{paymentLabel(e.payment_method)}</span>}
                    {e.source === "scan" && <span title="Added from a scanned bill">📷 Scanned</span>}
                    {e.has_receipt && e.source !== "scan" && <span title="Bill copy saved">🧾</span>}
                    {e.needs_review && <span className="kb-review-flag">Check</span>}
                  </small>
                </span>
                <span className={`kb-ledger-amount type-${e.type}`}>{e.type === "income" ? "+" : "−"}{formatINR(e.amount)}</span>
              </button>)}
            </div>;
          })}
      </section>

      <aside className="kb-stack">
        <section className="kb-card">
          <header className="kb-card-header"><h2>Where the money went</h2></header>
          {expenseHeads.length ? <ul className="kb-bars">{expenseHeads.slice(0, 8).map(h => <li key={`e-${h.head_id ?? "none"}`}><button onClick={() => setParams({ head: h.head_id ? String(h.head_id) : "none", type: null })}>
            <span className="kb-bar-label"><b>{h.name}</b><span>{formatINR(h.total, { decimals: false })}</span></span>
            <span className="kb-progress kb-progress-expense"><span style={{ width: `${Math.round(h.total / maxExpense * 100)}%` }} /></span>
          </button></li>)}</ul> : <p className="kb-empty-inline">No expenses this month yet.</p>}
          {incomeHeads.length > 0 && <><h3 className="kb-subhead">Income by head</h3><ul className="kb-mini-totals">{incomeHeads.map(h => <li key={`i-${h.head_id ?? "none"}`}><button onClick={() => setParams({ head: h.head_id ? String(h.head_id) : "none", type: null })}><span>{h.name}</span><b className="tone-income">{formatINR(h.total, { decimals: false })}</b></button></li>)}</ul></>}
        </section>
        <HeadsManager heads={heads} onChange={() => void load()} />
        <section className="kb-card kb-assistant-card">
          <h2>Tell the assistant</h2>
          <p>“Paid ₹1,200 electricity bill by UPI” or “Received ₹50,000 fees from Rahul” — it records the entry and picks your head.</p>
          <Link className="kb-button kb-button-ghost" href="/app/assistant">Open assistant →</Link>
        </section>
      </aside>
    </div>

    {drawerOpen && <EntryDrawer entry={addType ? null : openEntry} defaultType={addType ?? "expense"} heads={heads} notice={openEntry && scanNotice ? scanNotice : undefined}
      onClose={() => { setScanNotice(""); setParams({ entry: null, add: null }); }}
      onSaved={() => void load()}
      onHeadCreated={head => setHeads(current => [...current, head])} />}
  </div>;
}

/** Heads are created and named by the person; the AI only assigns or suggests. */
function HeadsManager({ heads, onChange }: { heads: Head[]; onChange: () => void }) {
  const [type, setType] = useState<FinanceType>("expense");
  const [name, setName] = useState("");
  const [error, setError] = useState("");
  const [editing, setEditing] = useState<number | null>(null);
  const [editName, setEditName] = useState("");
  const list = heads.filter(h => h.type === type);

  async function add(event: FormEvent) {
    event.preventDefault(); setError("");
    if (!name.trim()) return;
    const r = await fetch("/api/backend/finance/heads", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ name, type }) });
    const d = await readJson(r);
    if (!r.ok) { setError(d.message ?? "Couldn’t add that head."); return; }
    setName(""); onChange();
  }
  async function rename(head: Head) {
    const r = await fetch(`/api/backend/finance/heads/${head.id}`, { method: "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ name: editName }) });
    const d = await readJson(r);
    if (!r.ok) { setError(d.message ?? "Couldn’t rename."); return; }
    setEditing(null); onChange();
  }
  async function remove(head: Head) {
    if (!window.confirm(`Delete the head “${head.name}”? Its ${head.entries_count ?? 0} entries stay, marked as Unassigned.`)) return;
    const r = await fetch(`/api/backend/finance/heads/${head.id}`, { method: "DELETE" }).catch(() => null);
    if (!r?.ok) { setError("Couldn’t delete that head."); return; }
    onChange();
  }

  return <section className="kb-card">
    <header className="kb-card-header"><h2>Your heads</h2><div className="kb-segmented"><button className={type === "expense" ? "is-active" : ""} onClick={() => setType("expense")}>Expense</button><button className={type === "income" ? "is-active" : ""} onClick={() => setType("income")}>Income</button></div></header>
    {list.length ? <ul className="kb-head-list">{list.map(h => <li key={h.id}>
      {editing === h.id
        ? <form className="kb-inline-row" onSubmit={e => { e.preventDefault(); void rename(h); }}><input className="kb-input" value={editName} onChange={e => setEditName(e.target.value)} autoFocus maxLength={80} /><button className="kb-button kb-button-primary kb-button-sm">Save</button></form>
        : <><span>{h.name}<small>{h.entries_count ?? 0}</small></span><span className="kb-head-actions"><button className="kb-link-button" onClick={() => { setEditing(h.id); setEditName(h.name); }}>Rename</button><button className="kb-link-button is-danger" onClick={() => void remove(h)}>Delete</button></span></>}
    </li>)}</ul> : <p className="kb-hint">No {type} heads yet. Add the ones you use — {type === "expense" ? "e.g. Groceries, Fuel, Rent, Salaries, Electricity" : "e.g. Salary, Client payments, Fees, Rent received"}.</p>}
    <form className="kb-inline-row" onSubmit={add} style={{ marginTop: 10 }}>
      <input className="kb-input" value={name} onChange={e => setName(e.target.value)} placeholder={`New ${type} head`} maxLength={80} aria-label={`New ${type} head`} />
      <button className="kb-button kb-button-ghost" disabled={!name.trim()}>Add</button>
    </form>
    {error && <p className="kb-alert kb-alert-error" style={{ marginTop: 8 }}>{error}</p>}
  </section>;
}
