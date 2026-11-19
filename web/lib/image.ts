/**
 * Shrink a phone photo before upload: max 2000px on the long side, JPEG ~85%.
 * Keeps bills readable for the AI while staying well under hosting upload limits (Vercel ~4.5 MB).
 * PDFs and small images are returned unchanged.
 */
export async function prepareBillFile(file: File, maxSide = 2000): Promise<File> {
  if (!file.type.startsWith("image/") || file.type === "image/gif") return file;
  if (file.size < 900_000) return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" } as ImageBitmapOptions);
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const ctx = canvas.getContext("2d");
    if (!ctx) return file;
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, "image/jpeg", 0.85));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg", lastModified: Date.now() });
  } catch {
    return file;
  }
}

/** "₹1,23,456.50" — Indian digit grouping. */
export function formatINR(value: number | string | null | undefined, options: { sign?: boolean; decimals?: boolean } = {}) {
  const n = Number(value ?? 0);
  const abs = Math.abs(n);
  const text = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", minimumFractionDigits: options.decimals === false ? 0 : (abs % 1 ? 2 : 0), maximumFractionDigits: 2 }).format(abs);
  return `${options.sign ? (n < 0 ? "−" : "+") : n < 0 ? "−" : ""}${text}`;
}
