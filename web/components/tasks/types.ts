export type Member = { id: number; name: string };
export type ProjectRef = { id: number; name: string };
export type Attachment = { id: number; original_name: string; mime_type: string; size: number; uploader?: Member; created_at: string; can_delete?: boolean };
export type Task = {
  id: number;
  title: string;
  description?: string | null;
  status: string;
  priority: string;
  start_at?: string | null;
  due_at?: string | null;
  completed_at?: string | null;
  project?: ProjectRef | null;
  project_id?: number | null;
  assignees?: Member[];
  attachments?: Attachment[];
  can_manage?: boolean;
  can_upload?: boolean;
};

export const STATUSES = [
  { value: "open", label: "To do" },
  { value: "in_progress", label: "In progress" },
  { value: "blocked", label: "Blocked" },
  { value: "done", label: "Done" },
  { value: "cancelled", label: "Cancelled" },
];
export const PRIORITIES = [
  { value: "low", label: "Low" },
  { value: "normal", label: "Normal" },
  { value: "high", label: "High" },
  { value: "urgent", label: "Urgent" },
];
export const statusLabel = (value: string) => STATUSES.find(s => s.value === value)?.label ?? value.replaceAll("_", " ");
export const priorityLabel = (value: string) => PRIORITIES.find(p => p.value === value)?.label ?? value;
export const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join("").toUpperCase();
export const fileSize = (bytes: number) => bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
export const isClosed = (task: Task) => task.status === "done" || task.status === "cancelled";

/** Read JSON from any response without throwing on empty or HTML bodies. */
export async function readJson<T = Record<string, unknown>>(response: Response): Promise<T & { message?: string }> {
  return response.json().catch(() => ({} as T & { message?: string }));
}
