"use client";

import Link from "next/link";
import { ChangeEvent, FormEvent, useEffect, useRef, useState } from "react";
import { formatDateTime, fromLocalInput, getActiveTimezone, isOverdue, toLocalInput } from "@/lib/datetime";
import { Attachment, Member, PRIORITIES, ProjectRef, STATUSES, Task, fileSize, initials, readJson } from "./types";

type Props = {
  /** The task to show, or null to create a new one. */
  task: Task | null;
  projects: ProjectRef[];
  members: Member[];
  defaultProjectId?: number | null;
  defaultDueAt?: string | null;
  onClose: () => void;
  /** Called after any change so the list can refresh. */
  onChanged: (task?: Task) => void;
};

/** Side panel to create, view and edit a task — the same everywhere in Kriyabot. */
export function TaskDrawer({ task, projects, members, defaultProjectId = null, defaultDueAt = null, onClose, onChanged }: Props) {
  const creating = !task;
  const [assignees, setAssignees] = useState<number[]>(task?.assignees?.map(person => person.id) ?? []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [attachments, setAttachments] = useState<Attachment[]>(task?.attachments ?? []);
  const titleRef = useRef<HTMLInputElement>(null);
  const zoneLabel = getActiveTimezone() === "Asia/Kolkata" ? "India time (IST)" : getActiveTimezone();

  useEffect(() => { setAssignees(task?.assignees?.map(person => person.id) ?? []); setAttachments(task?.attachments ?? []); setError(""); setNotice(""); if (creating) titleRef.current?.focus(); }, [task, creating]);
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const canEdit = creating || task?.can_manage !== false;

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const projectId = form.get("project_id");
    const body = {
      title: String(form.get("title") ?? "").trim(),
      description: String(form.get("description") ?? "").trim() || null,
      priority: form.get("priority"),
      project_id: projectId ? Number(projectId) : null,
      start_at: fromLocalInput(form.get("start_at")),
      due_at: fromLocalInput(form.get("due_at")),
      assignee_ids: assignees,
      ...(creating ? {} : { status: form.get("status") }),
    };
    if (!body.title) { setError("Give the task a title."); return; }
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await fetch(creating ? "/api/backend/tasks" : `/api/backend/tasks/${task!.id}`, { method: creating ? "POST" : "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body) });
      const data = await readJson<Task>(response);
      if (!response.ok) { setError(data.message ?? "This task couldn’t be saved."); return; }
      setNotice(creating ? "Task created." : "Changes saved.");
      onChanged(data as Task);
      if (creating) onClose();
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and try again."); }
    finally { setBusy(false); }
  }

  async function setStatus(status: string) {
    if (!task) return;
    setBusy(true); setError("");
    try {
      const response = await fetch(`/api/backend/tasks/${task.id}/status`, { method: "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ status }) });
      const data = await readJson(response);
      if (!response.ok) { setError(data.message ?? "Status couldn’t be updated."); return; }
      onChanged({ ...task, status });
    } catch { setError("Kriyabot couldn’t be reached. Try again."); }
    finally { setBusy(false); }
  }

  async function moveToTrash() {
    if (!task || !window.confirm(`Move “${task.title}” to Trash? You can restore it for 10 days.`)) return;
    setBusy(true);
    try {
      const response = await fetch(`/api/backend/tasks/${task.id}`, { method: "DELETE" });
      const data = await readJson(response);
      if (!response.ok) { setError(data.message ?? "This task couldn’t be moved to Trash."); return; }
      onChanged(); onClose();
    } catch { setError("Kriyabot couldn’t be reached. Try again."); }
    finally { setBusy(false); }
  }

  async function upload(event: ChangeEvent<HTMLInputElement>) {
    if (!task) return;
    const input = event.currentTarget; const files = Array.from(input.files ?? []);
    if (!files.length) return;
    setBusy(true); setError("");
    try {
      for (const file of files) {
        const body = new FormData(); body.append("file", file);
        const response = await fetch(`/api/backend/tasks/${task.id}/attachments`, { method: "POST", body });
        const data = await readJson<Attachment>(response);
        if (!response.ok) throw new Error(data.message ?? `Couldn’t upload ${file.name}.`);
        setAttachments(current => [...current, { ...(data as Attachment), can_delete: true }]);
      }
      onChanged();
    } catch (exception) { setError(exception instanceof Error ? exception.message : "A file couldn’t be uploaded."); onChanged(); }
    finally { setBusy(false); input.value = ""; }
  }

  async function removeAttachment(attachment: Attachment) {
    if (!task || !window.confirm(`Remove “${attachment.original_name}” from this task?`)) return;
    try {
      const response = await fetch(`/api/backend/tasks/${task.id}/attachments/${attachment.id}`, { method: "DELETE" });
      const data = await readJson(response);
      if (!response.ok) { setError(data.message ?? "This file couldn’t be removed."); return; }
      setAttachments(current => current.filter(item => item.id !== attachment.id)); onChanged();
    } catch { setError("Kriyabot couldn’t be reached. Try again."); }
  }

  const overdue = task ? isOverdue(task.due_at, task.status) : false;
  const projectId = task?.project?.id ?? task?.project_id ?? defaultProjectId ?? "";

  return <div className="kb-drawer-layer" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) onClose(); }}>
    <aside className="kb-drawer" role="dialog" aria-modal="true" aria-label={creating ? "New task" : `Task: ${task!.title}`}>
      <header className="kb-drawer-header">
        <div>
          <p className="kb-overline">{creating ? "New task" : task!.project ? <Link href={`/app/projects/${task!.project.id}`}>{task!.project.name}</Link> : "Personal task"}</p>
          {!creating && <p className={`kb-drawer-due ${overdue ? "is-overdue" : ""}`}>{task!.due_at ? `${overdue ? "Overdue · " : "Due "}${formatDateTime(task!.due_at)}` : "No due date"}</p>}
        </div>
        <button type="button" className="kb-icon-button" aria-label="Close" onClick={onClose}>✕</button>
      </header>

      {!creating && <div className="kb-status-switch" role="group" aria-label="Status">
        {STATUSES.filter(s => s.value !== "cancelled").map(s => <button key={s.value} type="button" disabled={busy || !canEdit} className={task!.status === s.value ? "is-active" : ""} onClick={() => void setStatus(s.value)}>{s.label}</button>)}
      </div>}

      <form className="kb-drawer-body" onSubmit={save} key={task?.id ?? "new"}>
        <label className="kb-field kb-field-title"><span className="visually-hidden">Title</span><input ref={titleRef} name="title" defaultValue={task?.title ?? ""} placeholder="Task title" maxLength={240} required disabled={!canEdit} /></label>
        <label className="kb-field"><span>Details</span><textarea name="description" rows={4} defaultValue={task?.description ?? ""} placeholder="Add notes, links or context" disabled={!canEdit} /></label>
        <div className="kb-field-grid">
          <label className="kb-field"><span>Project</span><select name="project_id" defaultValue={projectId} disabled={!canEdit}><option value="">Personal (no project)</option>{projects.map(project => <option key={project.id} value={project.id}>{project.name}</option>)}</select></label>
          <label className="kb-field"><span>Priority</span><select name="priority" defaultValue={task?.priority ?? "normal"} disabled={!canEdit}>{PRIORITIES.map(p => <option key={p.value} value={p.value}>{p.label}</option>)}</select></label>
          <label className="kb-field"><span>Due</span><input name="due_at" type="datetime-local" defaultValue={toLocalInput(task?.due_at ?? defaultDueAt)} disabled={!canEdit} /></label>
          <label className="kb-field"><span>Starts</span><input name="start_at" type="datetime-local" defaultValue={toLocalInput(task?.start_at)} disabled={!canEdit} /></label>
          {!creating && <label className="kb-field"><span>Status</span><select name="status" defaultValue={task!.status} disabled={!canEdit}>{STATUSES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}</select></label>}
        </div>
        <p className="kb-hint">Times are in {zoneLabel}.</p>

        {members.length > 0 && <fieldset className="kb-field kb-assignees"><legend>Assignees</legend><div>{members.map(member => <label key={member.id} className={assignees.includes(member.id) ? "is-selected" : ""}>
          <input type="checkbox" checked={assignees.includes(member.id)} disabled={!canEdit} onChange={event => setAssignees(current => event.target.checked ? [...current, member.id] : current.filter(id => id !== member.id))} />
          <span className="kb-avatar-sm">{initials(member.name)}</span>{member.name}
        </label>)}</div></fieldset>}

        {!creating && <section className="kb-attachments">
          <header><h4>Files</h4>{task!.can_upload !== false && <label className="kb-button kb-button-ghost kb-button-sm">＋ Attach<input type="file" multiple hidden disabled={busy} accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" onChange={event => void upload(event)} /></label>}</header>
          {attachments.length ? <ul>{attachments.map(file => <li key={file.id}>
            <a href={`/api/backend/tasks/${task!.id}/attachments/${file.id}/preview`} target="_blank" rel="noreferrer"><span className="kb-file-type">{file.mime_type === "application/pdf" ? "PDF" : file.mime_type.startsWith("image/") ? "IMG" : "DOC"}</span><span><b>{file.original_name}</b><small>{fileSize(file.size)} · {file.uploader?.name ?? "Member"}</small></span></a>
            {file.can_delete && <button type="button" className="kb-icon-button" aria-label={`Remove ${file.original_name}`} onClick={() => void removeAttachment(file)}>✕</button>}
          </li>)}</ul> : <p className="kb-hint">No files yet. Images, PDFs and Office files up to 10 MB.</p>}
        </section>}

        {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}
        {notice && <p className="kb-alert kb-alert-success" role="status">{notice}</p>}

        <footer className="kb-drawer-footer">
          {!creating && canEdit ? <button type="button" className="kb-button kb-button-danger-ghost" onClick={() => void moveToTrash()} disabled={busy}>Move to Trash</button> : <span />}
          <div>
            <button type="button" className="kb-button kb-button-ghost" onClick={onClose}>Cancel</button>
            {canEdit && <button className="kb-button kb-button-primary" disabled={busy}>{busy ? "Saving…" : creating ? "Create task" : "Save changes"}</button>}
          </div>
        </footer>
      </form>
    </aside>
  </div>;
}
