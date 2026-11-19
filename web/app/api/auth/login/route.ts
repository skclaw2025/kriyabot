import { NextRequest, NextResponse } from "next/server";

const apiBase = () => process.env.API_SERVER_URL ?? "http://127.0.0.1:8000/api/v1";

export async function POST(request: NextRequest) {
  const body = await request.json().catch(() => ({}));
  let upstream: Response;
  try {
    upstream = await fetch(`${apiBase()}/login`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ ...body, device_name: "Kriyabot browser" }), cache: "no-store" });
  } catch {
    return NextResponse.json({ message: "The Kriyabot server is not reachable right now. Please try again shortly." }, { status: 502 });
  }
  const data = await upstream.json().catch(() => ({ message: upstream.status === 429 ? "Too many sign-in attempts. Please wait a minute and try again." : "Unable to sign in right now. Please try again." }));
  if (!upstream.ok) return NextResponse.json(data, { status: upstream.status });
  const response = NextResponse.json({ is_admin: data.user?.roles?.includes("super_admin") ?? false });
  response.cookies.set("taskflow_session", data.token, { httpOnly: true, secure: process.env.TASKFLOW_COOKIE_SECURE === "true", sameSite: "lax", path: "/", maxAge: 60 * 60 * 24 * 30 });
  return response;
}
