"use client";

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { dueLabel } from "@/components/tasks/TaskList";
import { Task, isClosed, readJson } from "@/components/tasks/types";
import { currentHour, dayKey, formatLongToday, formatTime, fromLocalInput, isOverdue } from "@/lib/datetime";

type Project = { id: number; name: string; status: string; tasks_count?: number; due_date?: string | null };
type AgendaItem = { id: string; title: string; kind: string; start_at?: string; detail?: string; status?: string; overdue?: boolean };
type Me = { name?: string };

export default function HomePage() {
  const router = useRouter();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [agenda, setAgenda] = useState<AgendaItem[]>([]);
  const [name, setName] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [quickTitle, setQuickTitle] = useState("");
  const [quickWhen, setQuickWhen] = useState<"today" | "tomorrow" | "none">("today");
  const [quickBusy, setQuickBusy] = useState(false);
  const [quickNotice, setQuickNotice] = useState<{ id: number; title: string } | null>(null);
  const [greeting, setGreeting] = useState({ hello: "Hello", date: "" });

  const load = useCallback(async () => {
    try {
      const [t, p, c, me] = await Promise.all([
        fetch("/api/backend/tasks?per_page=200", { cache: "no-store" }),
        fetch("/api/backend/projects", { cache: "no-store" }),
        fetch("/api/backend/assistant/context", { cache: "no-store" }),
        fetch("/api/backend/me", { cache: "no-store" }),
      ]);
      const [taskData, projectData, contextData, meData] = await Promise.all([readJson<{ data?: Task[] }>(t), readJson<{ data?: Project[] }>(p), readJson<{ today_schedule?: AgendaItem[] }>(c), readJson<Me>(me)]);
      if (!t.ok) { setError(taskData.message ?? "Your workspace couldn’t be loaded."); return; }
      setTasks(taskData.data ?? []); setProjects(projectData.data ?? []); setAgenda(contextData.today_schedule ?? []); setName(meData.name ?? ""); setError("");
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and refresh."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => {
    void load();
    const hour = currentHour();
    setGreeting({ hello: hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening", date: formatLongToday() });
  }, [load]);

  const today = dayKey(new Date());
  const open = useMemo(() => tasks.filter(task => !isClosed(task)), [tasks]);
  const overdue = useMemo(() => open.filter(task => isOverdue(task.due_at, task.status)).sort((a, b) => Date.parse(a.due_at!) - Date.parse(b.due_at!)), [open]);
  const dueToday = useMemo(() => open.filter(task => task.due_at && dayKey(task.due_at) === today && !isOverdue(task.due_at, task.status)), [open, today]);
  const upcoming = useMemo(() => open.filter(task => task.due_at && dayKey(task.due_at) > today).sort((a, b) => Date.parse(a.due_at!) - Date.parse(b.due_at!)).slice(0, 6), [open, today]);
  const doneThisWeek = useMemo(() => tasks.filter(task => task.status === "done" && task.completed_at && Date.now() - Date.parse(task.completed_at) < 7 * 86_400_000).length, [tasks]);
  const todayTimeline = useMemo(() => agenda.filter(item => !item.overdue).sort((a, b) => Date.parse(a.start_at ?? "") - Date.parse(b.start_at ?? "")), [agenda]);
  const projectProgress = useMemo(() => projects.map(project => {
    const own = tasks.filter(task => task.project?.id === project.id);
    const done = own.filter(task => task.status === "done").length;
    return { ...project, total: own.length || project.tasks_count || 0, done, open: own.filter(task => !isClosed(task)).length };
  }), [projects, tasks]);

  async function quickAdd(event: FormEvent) {
    event.preventDefault();
    const title = quickTitle.trim();
    if (!title) return;
    setQuickBusy(true); setError("");
    const due = quickWhen === "none" ? null : fromLocalInput(`${quickWhen === "today" ? today : dayKey(new Date(Date.now() + 86_400_000))}T18:00`);
    try {
      const response = await fetch("/api/backend/tasks", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ title, due_at: due, priority: "normal" }) });
      const data = await readJson<Task>(response);
      if (!response.ok) { setError(data.message ?? "The task couldn’t be added."); return; }
      setQuickTitle(""); setQuickNotice({ id: (data as Task).id, title }); void load();
    } catch { setError("Kriyabot couldn’t be reached. Try again."); }
    finally { setQuickBusy(false); }
  }

  const firstName = name.split(/\s+/)[0] ?? "";
  const niceName = firstName ? firstName.charAt(0).toUpperCase() + firstName.slice(1).toLowerCase() : "";
  const agendaHref = (item: AgendaItem) => item.kind === "task" ? `/app/tasks?task=${item.id.replace("task-", "")}` : `/app/calendar?date=${item.start_at ? dayKey(item.start_at) : today}`;

  return <div className="kb-page">
    <header className="kb-page-header">
      <div><p className="kb-overline">{greeting.date}</p><h1>{greeting.hello}{niceName ? `, ${niceName}` : ""}</h1><p>{loading ? "Getting your day ready…" : summarise(overdue.length, dueToday.length, agenda.filter(a => a.kind === "meeting").length)}</p></div>
      <div className="kb-header-actions"><Link className="kb-button kb-button-ghost" href="/app/projects?new=1">New project</Link><Link className="kb-button kb-button-primary" href="/app/tasks?new=1">＋ New task</Link></div>
    </header>

    <form className="kb-quick-add kb-card" onSubmit={quickAdd}>
      <span className="kb-quick-plus" aria-hidden="true">＋</span>
      <input value={quickTitle} onChange={event => setQuickTitle(event.target.value)} placeholder="Add a task… e.g. Send fee receipt to accounts" aria-label="Quick add a task" maxLength={240} />
      <div className="kb-segmented" role="group" aria-label="When is it due">
        {(["today", "tomorrow", "none"] as const).map(option => <button type="button" key={option} className={quickWhen === option ? "is-active" : ""} onClick={() => setQuickWhen(option)}>{option === "none" ? "No date" : option === "today" ? "Today" : "Tomorrow"}</button>)}
      </div>
      <button className="kb-button kb-button-primary" disabled={quickBusy || !quickTitle.trim()}>{quickBusy ? "Adding…" : "Add"}</button>
    </form>
    {quickNotice && <p className="kb-alert kb-alert-success" role="status">Added “{quickNotice.title}”{quickWhen !== "none" ? ` · due ${quickWhen} 6:00 pm` : ""}. <Link href={`/app/tasks?task=${quickNotice.id}`}>Open task →</Link></p>}
    {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}

    <section className="kb-stats">
      <StatCard href="/app/tasks?view=overdue" label="Overdue" value={overdue.length} tone={overdue.length ? "danger" : undefined} loading={loading} />
      <StatCard href="/app/tasks?view=today" label="Due today" value={dueToday.length} loading={loading} />
      <StatCard href="/app/tasks" label="Open tasks" value={open.length} loading={loading} />
      <StatCard href="/app/tasks?view=done" label="Done this week" value={doneThisWeek} tone="success" loading={loading} />
    </section>

    <div className="kb-home-grid">
      <div className="kb-stack">
        <section className="kb-card">
          <header className="kb-card-header"><h2>Today</h2><Link href={`/app/calendar?date=${today}`}>Open calendar →</Link></header>
          {loading ? <div className="kb-skeleton-rows">{[0, 1, 2].map(i => <span key={i} />)}</div>
            : todayTimeline.length ? <ol className="kb-timeline">{todayTimeline.map(item => <li key={item.id}>
              <time>{item.start_at ? formatTime(item.start_at) : "All day"}</time>
              <Link href={agendaHref(item)} className={`kb-timeline-item kind-${item.kind}`}><b>{item.title}</b><small>{item.kind === "meeting" ? "Meeting" : "Task"}{item.detail ? ` · ${item.detail}` : ""}</small></Link>
            </li>)}</ol>
            : <div className="kb-empty-inline">Nothing scheduled for today. <Link href="/app/tasks?new=1">Plan something →</Link></div>}
        </section>

        <section className="kb-card">
          <header className="kb-card-header"><h2>Coming up</h2><Link href="/app/tasks?view=upcoming">All upcoming →</Link></header>
          {loading ? <div className="kb-skeleton-rows">{[0, 1, 2].map(i => <span key={i} />)}</div>
            : upcoming.length ? <ul className="kb-mini-list">{upcoming.map(task => <li key={task.id}><Link href={`/app/tasks?task=${task.id}`}><b>{task.title}</b><small>{task.project?.name ?? "Personal"}</small><span>{dueLabel(task)}</span></Link></li>)}</ul>
            : <div className="kb-empty-inline">No upcoming deadlines.</div>}
        </section>
      </div>

      <div className="kb-stack">
        {overdue.length > 0 && <section className="kb-card kb-card-danger">
          <header className="kb-card-header"><h2>Needs attention</h2><Link href="/app/tasks?view=overdue">View all →</Link></header>
          <ul className="kb-mini-list">{overdue.slice(0, 5).map(task => <li key={task.id}><Link href={`/app/tasks?task=${task.id}`}><b>{task.title}</b><small>{task.project?.name ?? "Personal"}</small><span className="is-overdue">{dueLabel(task)}</span></Link></li>)}</ul>
        </section>}

        <section className="kb-card">
          <header className="kb-card-header"><h2>Projects</h2><Link href="/app/projects">All projects →</Link></header>
          {loading ? <div className="kb-skeleton-rows">{[0, 1].map(i => <span key={i} />)}</div>
            : projectProgress.length ? <ul className="kb-project-progress">{projectProgress.slice(0, 5).map(project => {
              const pct = project.total ? Math.round(project.done / project.total * 100) : 0;
              return <li key={project.id}><Link href={`/app/projects/${project.id}`}>
                <span className="kb-project-dot">{project.name.charAt(0).toUpperCase()}</span>
                <span className="kb-project-copy"><b>{project.name}</b><small>{project.open} open · {project.done}/{project.total} done</small><span className="kb-progress"><span style={{ width: `${pct}%` }} /></span></span>
                <span className="kb-progress-pct">{pct}%</span>
              </Link></li>;
            })}</ul>
            : <div className="kb-empty-inline">No projects yet. <Link href="/app/projects?new=1">Create one →</Link></div>}
        </section>

        <section className="kb-card kb-assistant-card">
          <h2>Ask your assistant</h2>
          <p>Type or speak in English or Hindi — “Meeting with Rahul tomorrow 4 pm at office, remind me 30 min before.”</p>
          <button className="kb-button kb-button-ghost" onClick={() => router.push("/app/assistant")}>Open assistant →</button>
        </section>
      </div>
    </div>
  </div>;
}

function StatCard({ href, label, value, tone, loading }: { href: string; label: string; value: number; tone?: "danger" | "success"; loading: boolean }) {
  return <Link href={href} className={`kb-stat ${tone ? `tone-${tone}` : ""}`}><span>{label}</span><b>{loading ? "–" : value}</b></Link>;
}

function summarise(overdue: number, today: number, meetings: number) {
  const parts: string[] = [];
  if (meetings) parts.push(`${meetings} meeting${meetings === 1 ? "" : "s"}`);
  if (today) parts.push(`${today} task${today === 1 ? "" : "s"} due today`);
  if (overdue) parts.push(`${overdue} overdue`);
  return parts.length ? `You have ${parts.join(", ")}.` : "Your day is clear. Add what matters next.";
}
