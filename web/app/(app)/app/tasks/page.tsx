"use client";

import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { TaskDrawer } from "@/components/tasks/TaskDrawer";
import { TaskGroups } from "@/components/tasks/TaskList";
import { Member, ProjectRef, Task, isClosed, readJson } from "@/components/tasks/types";
import { dayKey, fromLocalInput, isOverdue } from "@/lib/datetime";

const VIEWS = [
  { key: "open", label: "Open" },
  { key: "today", label: "Today" },
  { key: "overdue", label: "Overdue" },
  { key: "upcoming", label: "Upcoming" },
  { key: "done", label: "Completed" },
  { key: "all", label: "All" },
] as const;

export default function TasksPage() {
  return <Suspense fallback={<div className="kb-page"><div className="kb-skeleton" style={{ height: 320 }} /></div>}><TasksView /></Suspense>;
}

function TasksView() {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const view = params.get("view") ?? "open";
  const projectFilter = params.get("project") ?? "all";
  const openTaskId = params.get("task");
  const creating = params.get("new") === "1";

  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<ProjectRef[]>([]);
  const [members, setMembers] = useState<Member[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");

  const setParams = useCallback((changes: Record<string, string | null>) => {
    const next = new URLSearchParams(params.toString());
    for (const [key, value] of Object.entries(changes)) { if (value === null) next.delete(key); else next.set(key, value); }
    const query = next.toString();
    router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
  }, [params, pathname, router]);

  const load = useCallback(async () => {
    try {
      const [t, p, m] = await Promise.all([
        fetch("/api/backend/tasks?per_page=200", { cache: "no-store" }),
        fetch("/api/backend/projects", { cache: "no-store" }),
        fetch("/api/backend/workspace/members", { cache: "no-store" }),
      ]);
      const [taskData, projectData, memberData] = await Promise.all([readJson<{ data?: Task[] }>(t), readJson<{ data?: ProjectRef[] }>(p), m.json().catch(() => [])]);
      if (!t.ok) { setError(taskData.message ?? "Your tasks couldn’t be loaded."); return; }
      setTasks(taskData.data ?? []); setProjects(projectData.data ?? []); setMembers(Array.isArray(memberData) ? memberData : []); setError("");
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and refresh."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  async function toggleDone(task: Task) {
    const status = task.status === "done" ? "open" : "done";
    setTasks(current => current.map(item => item.id === task.id ? { ...item, status, completed_at: status === "done" ? new Date().toISOString() : null } : item));
    try {
      const response = await fetch(`/api/backend/tasks/${task.id}/status`, { method: "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ status }) });
      if (!response.ok) { const data = await readJson(response); setError(data.message ?? "The task couldn’t be updated."); await load(); }
    } catch { setError("Kriyabot couldn’t be reached. Try again."); await load(); }
  }

  const today = dayKey(new Date());
  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    return tasks.filter(task => {
      if (projectFilter === "personal" ? !!task.project : projectFilter !== "all" && task.project?.id !== Number(projectFilter)) return false;
      if (q && !`${task.title} ${task.description ?? ""} ${task.project?.name ?? ""}`.toLowerCase().includes(q)) return false;
      switch (view) {
        case "open": return !isClosed(task);
        case "today": return !isClosed(task) && !!task.due_at && dayKey(task.due_at) === today && !isOverdue(task.due_at, task.status);
        case "overdue": return isOverdue(task.due_at, task.status);
        case "upcoming": return !isClosed(task) && !!task.due_at && dayKey(task.due_at) > today;
        case "done": return task.status === "done";
        default: return true;
      }
    });
  }, [tasks, view, projectFilter, search, today]);

  const counts = useMemo(() => ({
    open: tasks.filter(t => !isClosed(t)).length,
    today: tasks.filter(t => !isClosed(t) && t.due_at && dayKey(t.due_at) === today && !isOverdue(t.due_at, t.status)).length,
    overdue: tasks.filter(t => isOverdue(t.due_at, t.status)).length,
    upcoming: tasks.filter(t => !isClosed(t) && t.due_at && dayKey(t.due_at) > today).length,
    done: tasks.filter(t => t.status === "done").length,
    all: tasks.length,
  }), [tasks, today]);

  const openTask = openTaskId ? tasks.find(task => String(task.id) === openTaskId) ?? null : null;
  const drawerOpen = creating || !!openTask;
  const closeDrawer = useCallback(() => setParams({ task: null, new: null, due: null }), [setParams]);

  return <div className="kb-page">
    <header className="kb-page-header">
      <div><h1>My tasks</h1><p>Everything on your plate, sorted by when it’s due.</p></div>
      <button className="kb-button kb-button-primary" onClick={() => setParams({ new: "1", task: null })}>＋ New task</button>
    </header>

    <div className="kb-toolbar">
      <nav className="kb-tabs" aria-label="Task views">{VIEWS.map(item => <button key={item.key} className={view === item.key ? "is-active" : ""} onClick={() => setParams({ view: item.key === "open" ? null : item.key })}>
        {item.label}<span className={item.key === "overdue" && counts.overdue ? "is-danger" : ""}>{counts[item.key]}</span>
      </button>)}</nav>
      <div className="kb-toolbar-filters">
        <input className="kb-input" type="search" placeholder="Filter tasks…" value={search} onChange={event => setSearch(event.target.value)} aria-label="Filter tasks" />
        <select className="kb-input" value={projectFilter} onChange={event => setParams({ project: event.target.value === "all" ? null : event.target.value })} aria-label="Filter by project">
          <option value="all">All projects</option><option value="personal">Personal only</option>
          {projects.map(project => <option key={project.id} value={project.id}>{project.name}</option>)}
        </select>
      </div>
    </div>

    {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}
    {openTaskId && !loading && !openTask && <p className="kb-alert">That task couldn’t be found. It may have been moved to Trash.</p>}

    {loading ? <div className="kb-card"><div className="kb-skeleton-rows">{[0, 1, 2, 3, 4].map(i => <span key={i} />)}</div></div>
      : tasks.length === 0 ? <div className="kb-empty kb-card"><b>No tasks yet</b><p>Create your first task, or ask the assistant: “Remind me to call the bank tomorrow at 11.”</p><button className="kb-button kb-button-primary" onClick={() => setParams({ new: "1" })}>＋ New task</button></div>
      : <TaskGroups tasks={visible} onOpen={task => setParams({ task: String(task.id), new: null })} onToggle={task => void toggleDone(task)} emptyText={view === "overdue" ? "Nothing overdue. Nice work." : view === "today" ? "Nothing due today." : "No tasks match this view."} />}

    {drawerOpen && <TaskDrawer task={creating ? null : openTask} projects={projects} members={members}
      defaultDueAt={params.get("due") && /^\d{4}-\d{2}-\d{2}$/.test(params.get("due")!) ? fromLocalInput(`${params.get("due")}T18:00`) : null}
      defaultProjectId={projectFilter !== "all" && projectFilter !== "personal" ? Number(projectFilter) : null}
      onClose={closeDrawer}
      onChanged={updated => { if (updated?.id) setTasks(current => current.some(t => t.id === updated.id) ? current.map(t => t.id === updated.id ? { ...t, ...updated } : t) : [updated, ...current]); void load(); }} />}
  </div>;
}
