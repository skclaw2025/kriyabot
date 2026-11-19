import { NextRequest, NextResponse } from "next/server";

type Context = { params: Promise<{ segments: string[] }> };
const apiBase = () => process.env.API_SERVER_URL ?? "http://127.0.0.1:8000/api/v1";
const isId = (value?: string) => !!value && /^\d+$/.test(value);

function isAllowed(segments: string[], method: string): boolean {
  const [resource, id, child, childId, action] = segments;
  if (resource === "trash") {
    if (segments.length === 1) return method === "GET";
    if (["projects", "tasks"].includes(id ?? "") && isId(child)) {
      if (segments.length === 3) return method === "DELETE";
      if (segments.length === 4 && childId === "restore") return method === "POST";
    }
    return false;
  }
  if (resource === "workspace") return segments.join("/") === "workspace/members" && method === "GET";
  if (resource === "calendar") {
    if (segments.length === 1) return method === "GET";
    if (id === "sync") return segments.length === 2 && method === "GET" || segments.length === 3 && child === "rotate" && method === "POST";
    if (id === "sources") return segments.length === 2 && method === "POST" || segments.length === 3 && isId(child) && method === "DELETE" || segments.length === 4 && isId(child) && childId === "refresh" && method === "POST";
    return false;
  }
  if (resource === "assistant") return segments.length === 2 && ["context", "briefing"].includes(id ?? "") && method === "GET" || segments.length === 2 && ["parse", "ask"].includes(id ?? "") && method === "POST";
  if (resource === "meetings") return segments.length === 1 && ["GET", "POST"].includes(method) || segments.length === 2 && id === "conflicts" && method === "POST" || segments.length === 2 && isId(id) && method === "DELETE";
  if (resource === "finance") {
    if (id === "heads") return segments.length === 2 && ["GET", "POST"].includes(method) || segments.length === 3 && isId(child) && ["PATCH", "DELETE"].includes(method);
    if (id === "entries") return segments.length === 2 && ["GET", "POST"].includes(method) || segments.length === 3 && isId(child) && ["PATCH", "DELETE"].includes(method) || segments.length === 4 && isId(child) && childId === "receipt" && ["GET", "POST"].includes(method);
    if (id === "summary" || id === "export") return segments.length === 2 && method === "GET";
    if (id === "scan") return segments.length === 2 && method === "POST";
    return false;
  }
  if (resource === "saved-places") return segments.length === 1 && method === "POST" || segments.length === 2 && isId(id) && method === "DELETE";
  if (resource === "notifications") return segments.length === 1 && method === "GET" || segments.length === 3 && isId(id) && child === "read" && method === "POST" || segments.join("/") === "notifications/push/key" && method === "GET" || segments.join("/") === "notifications/push/subscriptions" && method === "POST" || segments.join("/") === "notifications/push/unsubscribe" && method === "POST";
  if (resource === "people") return segments.length === 1 && method === "GET";
  if (resource === "me") return segments.length === 1 && ["GET", "PATCH"].includes(method);
  if (resource === "conversations") {
    if (segments.length === 1) return method === "GET";
    if (segments.length === 2 && id === "direct") return method === "POST";
    if (segments.length === 3 && isId(id) && child === "messages") return ["GET", "POST"].includes(method);
  }
  if (resource === "files") {
    if (segments.length === 1) return ["GET", "POST"].includes(method);
    if (isId(id) && segments.length === 2) return method === "DELETE";
    if (isId(id) && child === "preview" && segments.length === 3) return method === "GET";
  }
  if (resource !== "projects" && resource !== "tasks") return false;
  if (!id && segments.length === 1) return ["GET", "POST"].includes(method);
  if (!isId(id)) return false;
  if (segments.length === 2) return ["GET", "PATCH", "DELETE"].includes(method);
  if (resource === "tasks" && child === "status" && segments.length === 3) return method === "PATCH";
  if (resource === "tasks" && child === "attachments") {
    if (segments.length === 3) return ["GET", "POST"].includes(method);
    if (segments.length === 4 && isId(childId)) return method === "DELETE";
    if (segments.length === 5 && isId(childId) && action === "preview") return method === "GET";
  }
  return false;
}

async function forward(request: NextRequest, context: Context) {
  const { segments } = await context.params;
  const method = request.method;
  if (!isAllowed(segments, method)) return NextResponse.json({ message: "Not found." }, { status: 404 });

  const token = request.cookies.get("taskflow_session")?.value;
  if (!token) return NextResponse.json({ message: "Unauthenticated." }, { status: 401 });
  const incomingType = request.headers.get("Content-Type");
  const isMultipart = incomingType?.toLowerCase().startsWith("multipart/form-data") ?? false;
  const body = method === "GET" || method === "DELETE" ? undefined : isMultipart ? await request.arrayBuffer() : await request.text();
  let upstream: Response;
  try {
    upstream = await fetch(`${apiBase()}/${segments.map(encodeURIComponent).join("/")}${request.nextUrl.search}`, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json, application/pdf, image/jpeg, image/png, image/webp, */*",
        ...(body === undefined ? {} : { "Content-Type": incomingType ?? "application/json" }),
      },
      body,
      cache: "no-store",
    });
  } catch {
    return NextResponse.json({ message: "The Kriyabot server is not reachable right now. Please try again shortly." }, { status: 502 });
  }

  // Pages call response.json(); never hand them an HTML error page.
  const upstreamType = upstream.headers.get("Content-Type") ?? "";
  if (!upstream.ok && !upstreamType.includes("application/json")) {
    const friendly: Record<number, string> = {
      401: "Your session has ended. Please sign in again.",
      403: "You do not have access to this item.",
      404: "This item could not be found.",
      413: "That file is too large to upload.",
      419: "Your session has ended. Please sign in again.",
      429: "Too many requests. Please wait a few seconds and try again.",
    };
    return NextResponse.json({ message: friendly[upstream.status] ?? "Something went wrong on the server. Please try again." }, { status: upstream.status });
  }

  const headers = new Headers({ "Content-Type": upstream.headers.get("Content-Type") ?? "application/json", "Cache-Control": "private, no-store" });
  for (const name of ["Content-Disposition", "Content-Length", "X-Content-Type-Options", "Content-Security-Policy"]) {
    const value = upstream.headers.get(name);
    if (value) headers.set(name, value);
  }
  if (upstream.status === 204) return new NextResponse(null, { status: 204, headers });
  return new NextResponse(await upstream.arrayBuffer(), { status: upstream.status, headers });
}

export const GET = forward;
export const POST = forward;
export const PATCH = forward;
export const DELETE = forward;

// Bill scanning and AI replies can take longer than Vercel's default function timeout.
export const maxDuration = 60;
