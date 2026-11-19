"use client";

import Link from "next/link";
import { dayKey, formatDate, formatDateTime, formatTime, isOverdue, relativeDay } from "@/lib/datetime";
import { Task, initials, isClosed, priorityLabel, statusLabel } from "./types";

export type TaskGroup = { key: string; label: string; tasks: Task[]; tone?: "danger" | "muted" };

/** Group tasks the way people plan: overdue, today, upcoming, someday, finished. */
export function groupTasks(tasks: Task[]): TaskGroup[] {
  const today = dayKey(new Date());
  const groups: Record<string, Task[]> = { overdue: [], today: [], upcoming: [], someday: [], done: [] };
  for (const task of tasks) {
    if (isClosed(task)) groups.done.push(task);
    else if (!task.due_at) groups.someday.push(task);
    else if (isOverdue(task.due_at, task.status)) groups.overdue.push(task);
    else if (dayKey(task.due_at) === today) groups.today.push(task);
    else groups.upcoming.push(task);
  }
  const byDue = (a: Task, b: Task) => new Date(a.due_at ?? 0).getTime() - new Date(b.due_at ?? 0).getTime();
  groups.overdue.sort(byDue); groups.today.sort(byDue); groups.upcoming.sort(byDue);
  const rank: Record<string, number> = { urgent: 0, high: 1, normal: 2, low: 3 };
  groups.someday.sort((a, b) => (rank[a.priority] ?? 9) - (rank[b.priority] ?? 9));
  groups.done.sort((a, b) => new Date(b.completed_at ?? b.due_at ?? 0).getTime() - new Date(a.completed_at ?? a.due_at ?? 0).getTime());
  return [
    { key: "overdue", label: "Overdue", tasks: groups.overdue, tone: "danger" as const },
    { key: "today", label: "Today", tasks: groups.today },
    { key: "upcoming", label: "Upcoming", tasks: groups.upcoming },
    { key: "someday", label: "No due date", tasks: groups.someday },
    { key: "done", label: "Completed", tasks: groups.done, tone: "muted" as const },
  ].filter(group => group.tasks.length);
}

export function dueLabel(task: Task) {
  if (!task.due_at) return "No due date";
  const rel = relativeDay(task.due_at);
  if (rel === "Today") return `Today, ${formatTime(task.due_at)}`;
  if (rel) return `${rel}, ${formatTime(task.due_at)}`;
  return formatDate(task.due_at, "No due date", { year: new Date(task.due_at).getFullYear() !== new Date().getFullYear() });
}

export function TaskRow({ task, onOpen, onToggle, showProject = true }: { task: Task; onOpen: (task: Task) => void; onToggle?: (task: Task) => void; showProject?: boolean }) {
  const overdue = isOverdue(task.due_at, task.status);
  const done = task.status === "done";
  return <div className={`kb-task-row ${done ? "is-done" : ""} ${showProject ? "" : "no-project"}`} role="button" tabIndex={0} onClick={() => onOpen(task)} onKeyDown={event => { if (event.key === "Enter") onOpen(task); }}>
    {onToggle ? <button type="button" className={`kb-check ${done ? "is-checked" : ""}`} aria-label={done ? `Mark “${task.title}” as not done` : `Mark “${task.title}” as done`} onClick={event => { event.stopPropagation(); onToggle(task); }}>
      <svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 8.5 3 3 6-7" /></svg>
    </button> : <span className="kb-check-spacer" />}
    <span className="kb-task-main">
      <b>{task.title}</b>
      <small>
        {task.status !== "open" && !done && <span className={`kb-status status-${task.status}`}>{statusLabel(task.status)}</span>}
        {task.description && <span className="kb-task-desc">{task.description}</span>}
      </small>
    </span>
    {showProject && <span className="kb-project-cell">{task.project
      ? <Link className="kb-chip kb-chip-project" href={`/app/projects/${task.project.id}`} onClick={event => event.stopPropagation()} title={`Open ${task.project.name}`}>{task.project.name}</Link>
      : <span className="kb-chip kb-chip-muted">Personal</span>}</span>}
    <span className="kb-meta-icon" title={task.attachments?.length ? `${task.attachments.length} file(s)` : undefined}>{task.attachments?.length ? `📎 ${task.attachments.length}` : ""}</span>
    <span className={`kb-priority priority-${task.priority}`} title={`${priorityLabel(task.priority)} priority`}>{priorityLabel(task.priority)}</span>
    <span className={`kb-due ${overdue ? "is-overdue" : ""}`} title={task.due_at ? formatDateTime(task.due_at) : undefined}>{dueLabel(task)}</span>
    <span className="kb-avatars">{task.assignees?.slice(0, 3).map(person => <span key={person.id} title={person.name}>{initials(person.name)}</span>)}</span>
  </div>;
}

export function TaskGroups({ tasks, onOpen, onToggle, showProject = true, emptyText = "No tasks here yet." }: { tasks: Task[]; onOpen: (task: Task) => void; onToggle?: (task: Task) => void; showProject?: boolean; emptyText?: string }) {
  const groups = groupTasks(tasks);
  if (!groups.length) return <div className="kb-empty"><b>{emptyText}</b></div>;
  return <div className="kb-task-groups">{groups.map(group => <section key={group.key} className={`kb-task-group ${group.tone ? `tone-${group.tone}` : ""}`}>
    <header><h3>{group.label}</h3><span>{group.tasks.length}</span></header>
    <div className="kb-card kb-task-list">{group.tasks.map(task => <TaskRow key={task.id} task={task} onOpen={onOpen} onToggle={onToggle} showProject={showProject} />)}</div>
  </section>)}</div>;
}
