/** POST JSON to a Kriyabot auth route and always resolve with readable data, even on network/HTML errors. */
export async function postAuth(path: string, body: unknown): Promise<{ ok: boolean; status: number; data: { message?: string; errors?: Record<string, string[]>; [key: string]: unknown } }> {
  try {
    const response = await fetch(path, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok && response.status === 429) data.message = "Too many attempts. Please wait a minute and try again.";
    return { ok: response.ok, status: response.status, data };
  } catch {
    return { ok: false, status: 0, data: { message: "Kriyabot could not be reached. Check your internet connection and try again." } };
  }
}

/** First Laravel validation message, falling back to the general message. */
export function firstError(data: { message?: string; errors?: Record<string, string[]> }, fallback: string): string {
  const first = data.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first ?? data.message ?? fallback;
}
