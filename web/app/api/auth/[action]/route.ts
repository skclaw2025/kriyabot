import { NextRequest, NextResponse } from "next/server";

export async function POST(request: NextRequest, context: { params: Promise<{ action: string }> }) {
  const { action } = await context.params;
  if (!["register", "forgot-password", "reset-password"].includes(action)) return NextResponse.json({ message: "Not found." }, { status: 404 });
  const body = await request.json().catch(() => ({}));
  try {
    const upstream = await fetch(`${process.env.API_SERVER_URL ?? "http://127.0.0.1:8000/api/v1"}/${action}`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body), cache: "no-store" });
    const data = await upstream.json().catch(() => ({ message: upstream.status === 429 ? "Too many attempts. Please wait a minute and try again." : "The request could not be completed. Please try again." }));
    return NextResponse.json(data, { status: upstream.status });
  } catch {
    return NextResponse.json({ message: "The Kriyabot server is not reachable right now. Please try again shortly." }, { status: 502 });
  }
}
