/**
 * One place for every date/time shown or entered in Kriyabot.
 *
 * Kriyabot is built for India, so everything defaults to India Standard Time (Asia/Kolkata)
 * regardless of the laptop/phone clock setting or the Vercel server (which runs in UTC).
 * A user who sets another timezone on their profile gets that one instead.
 */
export const DEFAULT_TIMEZONE = "Asia/Kolkata";

let activeTimezone = DEFAULT_TIMEZONE;

/** Called once by the app shell with the signed-in user's timezone. */
export function setActiveTimezone(timezone?: string | null) {
  activeTimezone = normaliseTimezone(timezone);
}

export function getActiveTimezone() {
  return activeTimezone;
}

export function normaliseTimezone(timezone?: string | null) {
  if (!timezone || timezone === "UTC" || timezone === "Asia/Calcutta") return DEFAULT_TIMEZONE;
  try { new Intl.DateTimeFormat("en-IN", { timeZone: timezone }); return timezone; } catch { return DEFAULT_TIMEZONE; }
}

const toDate = (value: string | Date) => (value instanceof Date ? value : new Date(value));
const valid = (date: Date) => !Number.isNaN(date.getTime());

/** "19 Nov 2026" */
export function formatDate(value?: string | Date | null, fallback = "No date", options: { year?: boolean } = { year: true }) {
  if (!value) return fallback;
  const date = isDateOnly(value) ? new Date(`${value}T12:00:00+05:30`) : toDate(value);
  if (!valid(date)) return fallback;
  return new Intl.DateTimeFormat("en-IN", { day: "numeric", month: "short", ...(options.year === false ? {} : { year: "numeric" }), timeZone: activeTimezone }).format(date);
}

/** "4:30 pm" */
export function formatTime(value?: string | Date | null, fallback = "") {
  if (!value) return fallback;
  const date = toDate(value);
  if (!valid(date)) return fallback;
  return new Intl.DateTimeFormat("en-IN", { hour: "numeric", minute: "2-digit", hour12: true, timeZone: activeTimezone }).format(date);
}

/** "Today, 4:30 pm" / "Tomorrow, 9:00 am" / "21 Nov, 4:30 pm" */
export function formatDateTime(value?: string | Date | null, fallback = "No date") {
  if (!value) return fallback;
  const date = toDate(value);
  if (!valid(date)) return fallback;
  const day = relativeDay(date);
  const time = formatTime(date);
  return `${day ?? formatDate(date, fallback, { year: date.getFullYear() !== new Date().getFullYear() })}, ${time}`;
}

/** "Today" / "Tomorrow" / "Yesterday" or null, judged in the active timezone. */
export function relativeDay(value: string | Date) {
  const key = dayKey(value);
  const today = dayKey(new Date());
  const diff = Math.round((Date.parse(key) - Date.parse(today)) / 86_400_000);
  return diff === 0 ? "Today" : diff === 1 ? "Tomorrow" : diff === -1 ? "Yesterday" : null;
}

/** YYYY-MM-DD of a moment in the active timezone. */
export function dayKey(value: string | Date) {
  const parts = new Intl.DateTimeFormat("en-CA", { year: "numeric", month: "2-digit", day: "2-digit", timeZone: activeTimezone }).formatToParts(toDate(value));
  const get = (type: string) => parts.find(p => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

/** "Thursday, 19 November" */
export function formatLongToday() {
  return new Intl.DateTimeFormat("en-IN", { weekday: "long", day: "numeric", month: "long", timeZone: activeTimezone }).format(new Date());
}

/** Hour of the day (0-23) in the active timezone, for greetings. */
export function currentHour() {
  return Number(new Intl.DateTimeFormat("en-GB", { hour: "2-digit", hour12: false, timeZone: activeTimezone }).format(new Date())) % 24;
}

export function isOverdue(value?: string | null, status?: string) {
  if (!value || status === "done" || status === "cancelled") return false;
  return new Date(value).getTime() < Date.now();
}

/** Value for <input type="datetime-local"> showing the stored moment in the active timezone. */
export function toLocalInput(value?: string | null) {
  if (!value) return "";
  const date = new Date(value);
  if (!valid(date)) return "";
  const parts = new Intl.DateTimeFormat("en-CA", { year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false, timeZone: activeTimezone }).formatToParts(date);
  const get = (type: string) => parts.find(p => p.type === type)?.value ?? "00";
  return `${get("year")}-${get("month")}-${get("day")}T${get("hour") === "24" ? "00" : get("hour")}:${get("minute")}`;
}

/** Turn an <input type="datetime-local"> value into an ISO string with the active timezone's offset. */
export function fromLocalInput(value?: FormDataEntryValue | string | null) {
  if (!value || typeof value !== "string") return null;
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
  if (!match) return null;
  const [, y, mo, d, h, mi] = match.map(Number);
  // Guess the UTC moment, then correct by the zone's offset at that moment.
  const guess = Date.UTC(y, mo - 1, d, h, mi);
  const offset = zoneOffsetMinutes(new Date(guess));
  return new Date(guess - offset * 60_000).toISOString();
}

function zoneOffsetMinutes(date: Date) {
  const parts = new Intl.DateTimeFormat("en-US", { timeZone: activeTimezone, hourCycle: "h23", year: "numeric", month: "numeric", day: "numeric", hour: "numeric", minute: "numeric" }).formatToParts(date);
  const get = (type: string) => Number(parts.find(p => p.type === type)?.value ?? 0);
  const asUtc = Date.UTC(get("year"), get("month") - 1, get("day"), get("hour"), get("minute"));
  return Math.round((asUtc - date.getTime()) / 60_000);
}

function isDateOnly(value: string | Date): value is string {
  return typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value);
}
