"use client";

import { ChangeEvent, FormEvent, useEffect, useState } from "react";
import { readJson } from "@/components/tasks/types";
import { formatDate, dayKey } from "@/lib/datetime";
import { formatINR, prepareBillFile } from "@/lib/image";
import { Entry, FinanceType, Head, PAYMENT_METHODS } from "./types";

type Props = {
  entry: Entry | null;            // null = new entry
  defaultType?: FinanceType;
  heads: Head[];
  notice?: string;
  onClose: () => void;
  onSaved: (entry?: Entry) => void;
  onHeadCreated: (head: Head) => void;
};

/** Create / review / edit one income or expense entry, including its saved bill copy. */
export function EntryDrawer({ entry, defaultType = "expense", heads, notice, onClose, onSaved, onHeadCreated }: Props) {
  const creating = !entry;
  const [type, setType] = useState<FinanceType>(entry?.type ?? defaultType);
  const [headId, setHeadId] = useState<string>(entry?.head_id ? String(entry.head_id) : entry?.head?.id ? String(entry.head.id) : "");
  const [newHead, setNewHead] = useState("");
  const [addingHead, setAddingHead] = useState(false);
  const [receipt, setReceipt] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [current, setCurrent] = useState<Entry | null>(entry);

  useEffect(() => { setCurrent(entry); setType(entry?.type ?? defaultType); setHeadId(entry?.head_id ? String(entry.head_id) : ""); setError(""); setReceipt(null); }, [entry, defaultType]);
  useEffect(() => { const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); }; document.addEventListener("keydown", onKey); return () => document.removeEventListener("keydown", onKey); }, [onClose]);

  const headsOfType = heads.filter(head => head.type === type);

  async function createHead(name: string, assignTo?: number) {
    const clean = name.trim();
    if (!clean) return null;
    const response = await fetch("/api/backend/finance/heads", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ name: clean, type, ...(assignTo ? { assign_entry_id: assignTo } : {}) }) });
    const data = await readJson<Head>(response);
    if (!response.ok) { setError(data.message ?? "That head couldn’t be created."); return null; }
    onHeadCreated(data as Head);
    setHeadId(String((data as Head).id)); setNewHead(""); setAddingHead(false);
    return data as Head;
  }

  async function acceptSuggestion() {
    if (!current?.ai_suggested_head) return;
    setBusy(true);
    const head = await createHead(current.ai_suggested_head, current.id);
    if (head) { setCurrent({ ...current, head_id: head.id, head, ai_suggested_head: null }); onSaved(); }
    setBusy(false);
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const amount = Number(String(form.get("amount") ?? "").replace(/[,₹\s]/g, ""));
    if (!amount || amount <= 0) { setError("Enter an amount greater than zero."); return; }
    setBusy(true); setError("");
    try {
      let chosenHead = headId;
      if (addingHead && newHead.trim()) { const created = await createHead(newHead); if (!created) { setBusy(false); return; } chosenHead = String(created.id); }
      const body = {
        type, amount, entry_date: form.get("entry_date"), head_id: chosenHead ? Number(chosenHead) : null,
        party: String(form.get("party") ?? "").trim() || null, bill_no: String(form.get("bill_no") ?? "").trim() || null,
        payment_method: form.get("payment_method") || null, tax_amount: form.get("tax_amount") ? Number(form.get("tax_amount")) : null,
        description: String(form.get("description") ?? "").trim() || null,
        ...(form.get("allow_duplicate") ? { allow_duplicate: true } : {}),
      };
      const response = await fetch(creating ? "/api/backend/finance/entries" : `/api/backend/finance/entries/${current!.id}`, { method: creating ? "POST" : "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body) });
      const data = await readJson<Entry>(response);
      if (response.status === 409) { setError(`${data.message} Tick “Save anyway” to record it again.`); setBusy(false); return; }
      if (!response.ok) { setError(data.message ?? "This entry couldn’t be saved."); setBusy(false); return; }
      let saved = data as Entry;
      if (receipt) {
        const upload = new FormData(); upload.append("receipt", await prepareBillFile(receipt));
        const r = await fetch(`/api/backend/finance/entries/${saved.id}/receipt`, { method: "POST", body: upload });
        const rd = await readJson<Entry>(r);
        if (!r.ok) { setError(`Entry saved, but the bill copy couldn’t be uploaded: ${rd.message ?? "try again"}`); onSaved(saved); setBusy(false); return; }
        saved = rd as Entry;
      }
      onSaved(saved); onClose();
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and try again."); }
    finally { setBusy(false); }
  }

  async function remove() {
    if (!current || !window.confirm(`Delete this ${current.type} of ${formatINR(current.amount)}?`)) return;
    setBusy(true);
    const response = await fetch(`/api/backend/finance/entries/${current.id}`, { method: "DELETE" }).catch(() => null);
    setBusy(false);
    if (!response?.ok) { setError("This entry couldn’t be deleted."); return; }
    onSaved(); onClose();
  }

  const today = dayKey(new Date());
  return <div className="kb-drawer-layer" role="presentation" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}>
    <aside className="kb-drawer" role="dialog" aria-modal="true" aria-label={creating ? "New entry" : "Edit entry"}>
      <header className="kb-drawer-header">
        <div>
          <p className="kb-overline">{creating ? "New entry" : current?.source === "scan" ? "Scanned bill" : current?.source === "assistant" ? "Added by assistant" : "Entry"}</p>
          {!creating && <p className="kb-drawer-due">{formatDate(current!.entry_date)} · {formatINR(current!.amount)}</p>}
        </div>
        <button type="button" className="kb-icon-button" aria-label="Close" onClick={onClose}>✕</button>
      </header>

      <div className="kb-status-switch kb-type-switch" role="group" aria-label="Type">
        <button type="button" className={type === "expense" ? "is-active is-expense" : ""} onClick={() => { setType("expense"); setHeadId(""); }}>Expense</button>
        <button type="button" className={type === "income" ? "is-active is-income" : ""} onClick={() => { setType("income"); setHeadId(""); }}>Income</button>
      </div>

      <form className="kb-drawer-body" onSubmit={save} key={current?.id ?? "new"}>
        {notice && <p className="kb-alert kb-alert-success" role="status">{notice}</p>}
        {current?.needs_review && <p className="kb-alert kb-alert-warn">Please check this entry{current.ai_confidence != null ? ` — the AI was ${current.ai_confidence}% sure` : ""}. Fix anything that looks wrong and save.</p>}
        {current?.ai_suggested_head && !current.head_id && <div className="kb-suggestion">
          <span>AI suggests a new {current.type} head: <b>{current.ai_suggested_head}</b></span>
          <button type="button" className="kb-button kb-button-ghost kb-button-sm" disabled={busy} onClick={() => void acceptSuggestion()}>Create &amp; assign</button>
        </div>}

        <div className="kb-field-grid">
          <label className="kb-field"><span>Amount (₹)</span><input name="amount" inputMode="decimal" required defaultValue={current ? Number(current.amount) || "" : ""} placeholder="0.00" autoFocus={creating} className="kb-amount-input" /></label>
          <label className="kb-field"><span>Date</span><input name="entry_date" type="date" required max={today} defaultValue={current?.entry_date?.slice(0, 10) ?? today} /></label>
        </div>

        <label className="kb-field"><span>Head</span>
          {addingHead
            ? <div className="kb-inline-row"><input value={newHead} onChange={e => setNewHead(e.target.value)} placeholder={type === "expense" ? "e.g. Groceries, Fuel, Office rent" : "e.g. Salary, Consulting, Fees"} maxLength={80} autoFocus /><button type="button" className="kb-button kb-button-ghost" onClick={() => { setAddingHead(false); setNewHead(""); }}>Cancel</button></div>
            : <div className="kb-inline-row"><select value={headId} onChange={e => setHeadId(e.target.value)}><option value="">Unassigned</option>{headsOfType.map(h => <option key={h.id} value={h.id}>{h.name}</option>)}</select><button type="button" className="kb-button kb-button-ghost" onClick={() => setAddingHead(true)}>＋ New head</button></div>}
        </label>

        <div className="kb-field-grid">
          <label className="kb-field"><span>{type === "expense" ? "Paid to" : "Received from"}</span><input name="party" defaultValue={current?.party ?? ""} placeholder={type === "expense" ? "Shop, vendor or person" : "Client, employer or person"} maxLength={180} /></label>
          <label className="kb-field"><span>Bill / receipt no.</span><input name="bill_no" defaultValue={current?.bill_no ?? ""} maxLength={80} placeholder="Optional" /></label>
          <label className="kb-field"><span>Payment method</span><select name="payment_method" defaultValue={current?.payment_method ?? ""}><option value="">Not specified</option>{PAYMENT_METHODS.map(p => <option key={p.value} value={p.value}>{p.label}</option>)}</select></label>
          <label className="kb-field"><span>GST / tax (₹)</span><input name="tax_amount" inputMode="decimal" defaultValue={current?.tax_amount ? Number(current.tax_amount) : ""} placeholder="Optional" /></label>
        </div>
        <label className="kb-field"><span>Notes</span><textarea name="description" rows={2} defaultValue={current?.description ?? ""} placeholder="What was it for?" maxLength={2000} /></label>

        <section className="kb-attachments">
          <header><h4>Bill copy</h4><label className="kb-button kb-button-ghost kb-button-sm">{current?.has_receipt || receipt ? "Replace" : "＋ Attach"}<input type="file" hidden accept="image/*,application/pdf" onChange={(e: ChangeEvent<HTMLInputElement>) => setReceipt(e.target.files?.[0] ?? null)} /></label></header>
          {receipt ? <p className="kb-hint">Will upload <b>{receipt.name}</b> when you save.</p>
            : current?.has_receipt ? <a className="kb-receipt-link" href={`/api/backend/finance/entries/${current.id}/receipt`} target="_blank" rel="noreferrer">{current.receipt_mime === "application/pdf" ? "📄" : "🧾"} View saved bill{current.receipt_original_name ? ` · ${current.receipt_original_name}` : ""}</a>
            : <p className="kb-hint">No bill attached. Photos and PDFs are stored privately with this entry.</p>}
        </section>

        {error && <p className="kb-alert kb-alert-error" role="alert">{error}{error.includes("Save anyway") && <label className="kb-toggle" style={{ marginLeft: 8 }}><input type="checkbox" name="allow_duplicate" value="1" /> Save anyway</label>}</p>}

        <footer className="kb-drawer-footer">
          {!creating ? <button type="button" className="kb-button kb-button-danger-ghost" onClick={() => void remove()} disabled={busy}>Delete</button> : <span />}
          <div><button type="button" className="kb-button kb-button-ghost" onClick={onClose}>Cancel</button><button className="kb-button kb-button-primary" disabled={busy}>{busy ? "Saving…" : creating ? `Add ${type}` : current?.needs_review ? "Confirm & save" : "Save changes"}</button></div>
        </footer>
      </form>
    </aside>
  </div>;
}
