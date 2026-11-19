"use client";

import Link from "next/link";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { useParams, usePathname, useRouter, useSearchParams } from "next/navigation";
import { TaskDrawer } from "@/components/tasks/TaskDrawer";
import { TaskGroups } from "@/components/tasks/TaskList";
import { Member, ProjectRef, Task, isClosed, readJson } from "@/components/tasks/types";
import { formatDate, isOverdue } from "@/lib/datetime";

type Project = { id: number; name: string; description?: string | null; status: string; start_date?: string | null; due_date?: string | null; creator?: { name: string } | null };

export default function ProjectPage() {
  return <Suspense fallback={<div className="kb-page"><div className="kb-skeleton" style={{ height: 320 }} /></div>}><ProjectView /></Suspense>;
}

function ProjectView() {
  const { id } = useParams<{ id: string }>();
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const openTaskId = params.get("task");
  const creating = params.get("new") === "1";

  const [project, setProject] = useState<Project | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<ProjectRef[]>([]);
  const [members, setMembers] = useState<Member[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [showDone, setShowDone] = useState(false);

  const setParams = useCallback((changes: Record<string, string | null>) => {
    const next = new URLSearchParams(params.toString());
    for (const [key, value] of Object.entries(changes)) { if (value === null) next.delete(key); else next.set(key, value); }
    const query = next.toString();
    router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
  }, [params, pathname, router]);

  const load = useCallback(async () => {
    try {
      const [p, t, all, m] = await Promise.all([
        fetch(`/api/backend/projects/${id}`, { cache: "no-store" }),
        fetch(`/api/backend/tasks?project_id=${id}&per_page=200`, { cache: "no-store" }),
        fetch("/api/backend/projects", { cache: "no-store" }),
        fetch("/api/backend/workspace/members", { cache: "no-store" }),
      ]);
      const [projectData, taskData, allData, memberData] = await Promise.all([readJson<Project>(p), readJson<{ data?: Task[] }>(t), readJson<{ data?: ProjectRef[] }>(all), m.json().catch(() => [])]);
      if (!p.ok) { setError(projectData.message ?? "This project couldn’t be loaded."); setProject(null); return; }
      setProject(projectData as Project); setTasks(taskData.data ?? []); setProjects(allData.data ?? []); setMembers(Array.isArray(memberData) ? memberData : []); setError("");
    } catch { setError("Kriyabot couldn’t be reached. Check your connection and refresh."); }
    finally { setLoading(false); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  async function toggleDone(task: Task) {
    const status = task.status === "done" ? "open" : "done";
    setTasks(current => current.map(item => item.id === task.id ? { ...item, status } : item));
    const response = await fetch(`/api/backend/tasks/${task.id}/status`, { method: "PATCH", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ status }) }).catch(() => null);
    if (!response?.ok) { setError("The task couldn’t be updated."); void load(); }
  }

  const stats = useMemo(() => {
    const done = tasks.filter(t => t.status === "done").length;
    return { total: tasks.length, done, open: tasks.filter(t => !isClosed(t)).length, overdue: tasks.filter(t => isOverdue(t.due_at, t.status)).length, pct: tasks.length ? Math.round(done / tasks.length * 100) : 0 };
  }, [tasks]);
  const visible = showDone ? tasks : tasks.filter(task => !isClosed(task));
  const openTask = openTaskId ? tasks.find(task => String(task.id) === openTaskId) ?? null : null;

  if (loading && !project) return <div className="kb-page"><div className="kb-skeleton" style={{ height: 160 }} /><div className="kb-skeleton" style={{ height: 280, marginTop: 16 }} /></div>;
  if (!project) return <div className="kb-page"><Link className="kb-back" href="/app/projects">← Projects</Link><div className="kb-empty kb-card"><b>Project unavailable</b><p>{error || "It may have been moved to Trash, or you may not have access."}</p><Link className="kb-button kb-button-ghost" href="/app/projects">Back to projects</Link></div></div>;

  return <div className="kb-page">
    <Link className="kb-back" href="/app/projects">← Projects</Link>
    <header className="kb-page-header">
      <div>
        <div className="kb-title-row"><h1>{project.name}</h1><span className={`kb-status status-${project.status}`}>{project.status.replaceAll("_", " ")}</span></div>
        <p>{project.description || "No description yet."}</p>
      </div>
      <button className="kb-button kb-button-primary" onClick={() => setParams({ new: "1", task: null })}>＋ Add task</button>
    </header>

    <section className="kb-stats">
      <div className="kb-stat"><span>Progress</span><b>{stats.pct}%</b><span className="kb-progress"><span style={{ width: `${stats.pct}%` }} /></span></div>
      <div className="kb-stat"><span>Open tasks</span><b>{stats.open}</b></div>
      <div className={`kb-stat ${stats.overdue ? "tone-danger" : ""}`}><span>Overdue</span><b>{stats.overdue}</b></div>
      <div className="kb-stat"><span>Target date</span><b className="kb-stat-text">{formatDate(project.due_date ? project.due_date.slice(0, 10) : null, "Not set")}</b></div>
    </section>

    {error && <p className="kb-alert kb-alert-error" role="alert">{error}</p>}

    <div className="kb-section-bar"><h2>Tasks</h2><label className="kb-toggle"><input type="checkbox" checked={showDone} onChange={event => setShowDone(event.target.checked)} /> Show completed ({stats.done})</label></div>
    {tasks.length ? <TaskGroups tasks={visible} showProject={false} onOpen={task => setParams({ task: String(task.id), new: null })} onToggle={task => void toggleDone(task)} emptyText="All tasks in this project are done." />
      : <div className="kb-empty kb-card"><b>No tasks in this project yet</b><p>Break the work into small steps and give each a due date.</p><button className="kb-button kb-button-primary" onClick={() => setParams({ new: "1" })}>＋ Add the first task</button></div>}

    {(creating || openTask) && <TaskDrawer task={creating ? null : openTask} projects={projects} members={members} defaultProjectId={project.id}
      onClose={() => setParams({ task: null, new: null })} onChanged={() => void load()} />}
  </div>;
}
