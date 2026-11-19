<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinanceEntry;
use App\Models\FinanceHead;
use App\Services\AiClient;
use App\Services\AuditLogger;
use App\Services\PrivateUploadStorage;
use App\Support\UserTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Personal / office income & expense book.
 * Everything is private to the signed-in person; heads are theirs to define.
 */
class FinanceController extends Controller
{
    private const PAYMENT_METHODS = ['cash', 'upi', 'card', 'bank', 'cheque', 'other'];
    private const RECEIPT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    // ---------------------------------------------------------------- Heads

    public function heads(Request $request)
    {
        $user = $request->user();
        $heads = FinanceHead::where('user_id', $user->id)->orderBy('type')->orderBy('sort_order')->orderBy('name')->get();
        $totals = FinanceEntry::where('user_id', $user->id)->whereNotNull('head_id')->selectRaw('head_id, COUNT(*) as entries')->groupBy('head_id')->pluck('entries', 'head_id');
        return response()->json($heads->map(fn (FinanceHead $head) => [...$head->toArray(), 'entries_count' => (int) ($totals[$head->id] ?? 0)]));
    }

    public function storeHead(Request $request, AuditLogger $audit)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'color' => ['nullable', 'string', 'max:16', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        ]);
        $data['name'] = trim(preg_replace('/\s+/', ' ', $data['name']));
        if ($this->headExists($user->id, $data['type'], $data['name'])) {
            return response()->json(['message' => 'You already have a '.$data['type'].' head called “'.$data['name'].'”.'], 422);
        }
        $head = FinanceHead::create([...$data, 'workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'sort_order' => (int) FinanceHead::where('user_id', $user->id)->max('sort_order') + 1]);
        $audit->record($request, 'finance.head_created', $head);

        // Optionally attach the new head to an entry the AI flagged with this suggestion.
        if ($request->filled('assign_entry_id')) {
            FinanceEntry::where('user_id', $user->id)->whereKey($request->integer('assign_entry_id'))->where('type', $head->type)->update(['head_id' => $head->id, 'ai_suggested_head' => null]);
        }
        return response()->json([...$head->toArray(), 'entries_count' => 0], 201);
    }

    public function updateHead(Request $request, FinanceHead $head)
    {
        $user = $request->user();
        abort_unless($head->user_id === $user->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'color' => ['nullable', 'string', 'max:16', 'regex:/^#[0-9a-fA-F]{3,8}$/']]);
        if (isset($data['name'])) {
            $data['name'] = trim(preg_replace('/\s+/', ' ', $data['name']));
            if ($this->headExists($user->id, $head->type, $data['name'], $head->id)) return response()->json(['message' => 'Another head already uses that name.'], 422);
        }
        $head->update($data);
        return response()->json($head);
    }

    public function destroyHead(Request $request, FinanceHead $head, AuditLogger $audit)
    {
        abort_unless($head->user_id === $request->user()->id, 404);
        // Entries keep their amounts; they simply become "Unassigned".
        FinanceEntry::where('head_id', $head->id)->update(['head_id' => null]);
        $head->delete();
        $audit->record($request, 'finance.head_deleted', $head);
        return response()->noContent();
    }

    // ---------------------------------------------------------------- Entries

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::in(['income', 'expense'])],
            'head_id' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:120'],
            'review' => ['nullable', 'boolean'],
        ]);
        [$from, $to] = $this->range($filters, $user);
        $query = $this->filtered($user->id, $from, $to, $filters);
        $entries = (clone $query)->with('head:id,name,type,color')->orderByDesc('entry_date')->orderByDesc('id')->limit(1000)->get();
        return response()->json(['data' => $entries, 'from' => $from, 'to' => $to, 'summary' => $this->summary($user->id, $from, $to, $user)]);
    }

    /** Small summary for Home and the morning check-in. */
    public function summaryOnly(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->range($request->only(['from', 'to']), $user);
        return response()->json($this->summary($user->id, $from, $to, $user));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $user = $request->user();
        $data = $this->validateEntry($request, $user->id, true);
        if (($dup = $this->duplicateOf($user->id, $data)) && ! $request->boolean('allow_duplicate')) {
            return response()->json(['message' => 'This looks like a bill you already recorded ('.($dup->party ?: 'same bill').' · #'.$dup->bill_no.' · ₹'.number_format((float) $dup->amount, 2).').', 'duplicate' => $dup->load('head:id,name,type')], 409);
        }
        $entry = FinanceEntry::create([...$data, 'workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'source' => $request->input('source') === 'assistant' ? 'assistant' : 'manual']);
        if ($request->hasFile('receipt')) $this->attachReceipt($entry, $request, app(PrivateUploadStorage::class));
        $audit->record($request, 'finance.entry_created', $entry, ['amount' => $entry->amount, 'type' => $entry->type]);
        return response()->json($entry->fresh()->load('head:id,name,type,color'), 201);
    }

    public function update(Request $request, FinanceEntry $entry, AuditLogger $audit)
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
        $data = $this->validateEntry($request, $request->user()->id, false, $entry);
        $entry->update([...$data, 'needs_review' => false]);
        $audit->record($request, 'finance.entry_updated', $entry);
        return response()->json($entry->fresh()->load('head:id,name,type,color'));
    }

    public function destroy(Request $request, FinanceEntry $entry, AuditLogger $audit)
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
        $entry->delete();
        $audit->record($request, 'finance.entry_deleted', $entry);
        return response()->noContent();
    }

    public function uploadReceipt(Request $request, FinanceEntry $entry, PrivateUploadStorage $storage)
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
        $request->validate(['receipt' => ['required', 'file', 'max:10240']]);
        $this->attachReceipt($entry, $request, $storage);
        return response()->json($entry->fresh()->load('head:id,name,type,color'));
    }

    public function receipt(Request $request, FinanceEntry $entry)
    {
        abort_unless($entry->user_id === $request->user()->id && $entry->receipt_path, 404);
        $stream = Storage::disk($entry->receipt_disk ?: 'private')->readStream($entry->receipt_path);
        abort_unless(is_resource($stream), 404, 'Receipt not found.');
        $name = $entry->receipt_original_name ?: 'receipt';
        $inline = ! $request->boolean('download');
        return response()->stream(function () use ($stream): void { fpassthru($stream); fclose($stream); }, 200, [
            'Content-Type' => $entry->receipt_mime ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, Str::ascii($name) ?: 'receipt'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $filters = $request->only(['from', 'to', 'type', 'head_id', 'q']);
        [$from, $to] = $this->range($filters, $user);
        $rows = $this->filtered($user->id, $from, $to, $filters)->with('head:id,name')->orderBy('entry_date')->orderBy('id')->get();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
        fputcsv($out, ['Date', 'Type', 'Head', 'Amount (INR)', 'Tax', 'Party', 'Bill no.', 'Payment', 'Description', 'Source', 'Receipt']);
        foreach ($rows as $row) {
            fputcsv($out, [$row->entry_date?->format('d-m-Y'), ucfirst($row->type), $row->head?->name ?? 'Unassigned', $row->amount, $row->tax_amount, $row->party, $row->bill_no, $row->payment_method, $row->description, $row->source, $row->receipt_path ? 'Yes' : 'No']);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="kriyabot-finance-'.$from.'-to-'.$to.'.csv"', 'Cache-Control' => 'private, no-store']);
    }

    // ---------------------------------------------------------------- Scan

    /** Photo or PDF of a bill → saved receipt + AI-filled entry (income or expense). */
    public function scan(Request $request, AiClient $ai, PrivateUploadStorage $storage, AuditLogger $audit)
    {
        $user = $request->user();
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        if (! in_array($mime, self::RECEIPT_TYPES, true)) {
            return response()->json(['message' => 'Upload a photo (JPG, PNG, WebP) or a PDF of the bill.'], 422);
        }
        if (! $ai->configured()) return response()->json(['message' => $ai->notConfiguredMessage()], 422);

        $bytes = (string) file_get_contents($file->getRealPath());
        $forAi = $mime === 'application/pdf' ? ['bytes' => $bytes, 'mime' => $mime] : $this->imageForAi($bytes, $mime);
        if (! $forAi) return response()->json(['message' => 'This photo is too large to read. Please retake it or upload a smaller image.'], 422);

        $heads = FinanceHead::where('user_id', $user->id)->get(['id', 'name', 'type']);
        $tz = UserTime::zone($user);
        $result = $ai->extractBill($forAi['bytes'], $forAi['mime'], ['income' => $heads->where('type', 'income')->pluck('name')->all(), 'expense' => $heads->where('type', 'expense')->pluck('name')->all()], now($tz)->toDateString(), $tz);
        if (! $result['ok']) return response()->json(['message' => $result['message']], $result['status'] ?? 502);
        $x = $result['data'];
        if (($x['document_kind'] ?? 'bill') === 'not_a_bill') {
            return response()->json(['message' => 'This doesn’t look like a bill or receipt. Try another photo, or add the entry by hand.'], 422);
        }

        $type = ($x['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $head = $heads->first(fn ($h) => $h->type === $type && mb_strtolower($h->name) === mb_strtolower(trim((string) ($x['head_name'] ?? ''))));
        $amount = is_numeric($x['amount'] ?? null) ? round((float) $x['amount'], 2) : null;
        $date = $this->safeDate($x['entry_date'] ?? null, $tz);
        $confidence = is_numeric($x['confidence'] ?? null) ? (int) round(max(0, min(1, (float) $x['confidence'])) * 100) : null;
        $data = [
            'type' => $type,
            'amount' => $amount ?? 0,
            'tax_amount' => is_numeric($x['tax_amount'] ?? null) ? round((float) $x['tax_amount'], 2) : null,
            'currency' => strtoupper(substr((string) ($x['currency'] ?? 'INR'), 0, 3)) ?: 'INR',
            'entry_date' => $date,
            'party' => $this->clip($x['party'] ?? null, 180),
            'bill_no' => $this->clip($x['bill_no'] ?? null, 80),
            'description' => $this->clip($x['description'] ?? null, 500),
            'payment_method' => in_array($x['payment_method'] ?? null, self::PAYMENT_METHODS, true) ? $x['payment_method'] : null,
            'head_id' => $head?->id,
            'ai_suggested_head' => $head ? null : $this->clip($x['suggested_head'] ?? null, 80),
            'ai_confidence' => $confidence,
            'needs_review' => $amount === null || $amount <= 0 || ($confidence !== null && $confidence < 70) || ! $head,
            'source' => 'scan',
            'extraction' => $x,
        ];

        if (($dup = $this->duplicateOf($user->id, $data)) && ! $request->boolean('allow_duplicate')) {
            return response()->json(['message' => 'You already recorded this bill on '.$dup->entry_date?->format('j M Y').' (#'.$dup->bill_no.', ₹'.number_format((float) $dup->amount, 2).'). Nothing new was added.', 'duplicate' => $dup->load('head:id,name,type')], 409);
        }

        $entry = DB::transaction(function () use ($user, $data, $file, $storage, $mime): FinanceEntry {
            $entry = FinanceEntry::create([...$data, 'workspace_id' => $user->workspace_id, 'user_id' => $user->id]);
            $path = $storage->store($file, $user->workspace_id, 'receipts/'.$user->id);
            $entry->update(['receipt_disk' => $storage->diskName(), 'receipt_path' => $path, 'receipt_original_name' => $this->clip($file->getClientOriginalName(), 255) ?: 'receipt', 'receipt_mime' => $mime, 'receipt_size' => $file->getSize()]);
            return $entry;
        });
        $audit->record($request, 'finance.bill_scanned', $entry, ['amount' => $entry->amount, 'type' => $entry->type]);
        return response()->json($entry->fresh()->load('head:id,name,type,color'), 201);
    }

    // ---------------------------------------------------------------- Helpers

    private function validateEntry(Request $request, int $userId, bool $creating, ?FinanceEntry $entry = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'type' => [$req, Rule::in(['income', 'expense'])],
            'amount' => [$req, 'numeric', 'min:0', 'max:9999999999'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'entry_date' => [$req, 'date'],
            'head_id' => ['nullable', 'integer', Rule::exists('finance_heads', 'id')->where(fn ($q) => $q->where('user_id', $userId)->whereNull('deleted_at'))],
            'party' => ['nullable', 'string', 'max:180'],
            'bill_no' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
        ]);
        $type = $data['type'] ?? $entry?->type;
        if (! empty($data['head_id']) && FinanceHead::whereKey($data['head_id'])->value('type') !== $type) {
            abort(response()->json(['message' => 'That head belongs to '.($type === 'income' ? 'expenses' : 'income').'. Pick a '.$type.' head.'], 422));
        }
        if (array_key_exists('head_id', $data) && $data['head_id']) $data['ai_suggested_head'] = null;
        return $data;
    }

    private function attachReceipt(FinanceEntry $entry, Request $request, PrivateUploadStorage $storage): void
    {
        $file = $request->file('receipt');
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, self::RECEIPT_TYPES, true), response()->json(['message' => 'Receipts must be a photo (JPG, PNG, WebP) or a PDF.'], 422));
        $old = $entry->receipt_path;
        $path = $storage->store($file, $entry->workspace_id, 'receipts/'.$entry->user_id);
        $entry->update(['receipt_disk' => $storage->diskName(), 'receipt_path' => $path, 'receipt_original_name' => $this->clip($file->getClientOriginalName(), 255) ?: 'receipt', 'receipt_mime' => $mime, 'receipt_size' => $file->getSize()]);
        if ($old) $storage->delete($old);
    }

    private function duplicateOf(int $userId, array $data): ?FinanceEntry
    {
        $bill = trim((string) ($data['bill_no'] ?? ''));
        if ($bill === '') return null;
        return FinanceEntry::where('user_id', $userId)->where('type', $data['type'] ?? 'expense')->whereRaw('LOWER(bill_no) = ?', [mb_strtolower($bill)])
            ->when(filled($data['party'] ?? null), fn (Builder $q) => $q->whereRaw('LOWER(COALESCE(party, \'\')) = ?', [mb_strtolower(trim($data['party']))]))
            ->when(isset($data['amount']) && (float) $data['amount'] > 0, fn (Builder $q) => $q->where('amount', round((float) $data['amount'], 2)))
            ->first();
    }

    private function filtered(int $userId, string $from, string $to, array $filters): Builder
    {
        return FinanceEntry::where('user_id', $userId)->whereBetween('entry_date', [$from, $to])
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(($filters['head_id'] ?? '') === 'none', fn (Builder $q) => $q->whereNull('head_id'))
            ->when(is_numeric($filters['head_id'] ?? null), fn (Builder $q) => $q->where('head_id', (int) $filters['head_id']))
            ->when(! empty($filters['review']), fn (Builder $q) => $q->where('needs_review', true))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $q->where(fn (Builder $w) => $w->where('party', 'like', $term)->orWhere('bill_no', 'like', $term)->orWhere('description', 'like', $term));
            });
    }

    private function summary(int $userId, string $from, string $to, $user): array
    {
        $base = FinanceEntry::where('user_id', $userId)->whereBetween('entry_date', [$from, $to]);
        $income = (float) (clone $base)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('type', 'expense')->sum('amount');
        $byHead = (clone $base)->selectRaw('type, head_id, SUM(amount) as total, COUNT(*) as entries')->groupBy('type', 'head_id')->get();
        $names = FinanceHead::withTrashed()->where('user_id', $userId)->pluck('name', 'id');
        $today = now(UserTime::zone($user))->toDateString();
        $todayBase = FinanceEntry::where('user_id', $userId)->whereDate('entry_date', $today);
        return [
            'from' => $from, 'to' => $to,
            'income' => round($income, 2), 'expense' => round($expense, 2), 'balance' => round($income - $expense, 2),
            'entries' => (clone $base)->count(),
            'needs_review' => (clone $base)->where('needs_review', true)->count(),
            'by_head' => $byHead->map(fn ($row) => ['type' => $row->type, 'head_id' => $row->head_id, 'name' => $row->head_id ? ($names[$row->head_id] ?? 'Deleted head') : 'Unassigned', 'total' => round((float) $row->total, 2), 'entries' => (int) $row->entries])->sortByDesc('total')->values(),
            'today' => ['date' => $today, 'income' => round((float) (clone $todayBase)->where('type', 'income')->sum('amount'), 2), 'expense' => round((float) (clone $todayBase)->where('type', 'expense')->sum('amount'), 2), 'entries' => (clone $todayBase)->count()],
        ];
    }

    /** Default: the current month in the person's timezone. */
    private function range(array $filters, $user): array
    {
        $tz = UserTime::zone($user);
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : now($tz)->startOfMonth()->toDateString();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : now($tz)->endOfMonth()->toDateString();
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    private function headExists(int $userId, string $type, string $name, ?int $except = null): bool
    {
        return FinanceHead::where('user_id', $userId)->where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
    }

    private function safeDate(?string $value, string $tz): string
    {
        try {
            $date = $value ? Carbon::parse($value, $tz) : now($tz);
            // Guard against misread years (e.g. 2062) and far-future dates.
            if ($date->greaterThan(now($tz)->addDays(31)) || $date->year < 2000) return now($tz)->toDateString();
            return $date->toDateString();
        } catch (\Throwable) {
            return now($tz)->toDateString();
        }
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Claude accepts images up to ~5 MB; shrink large phone photos with GD when available. */
    private function imageForAi(string $bytes, string $mime): ?array
    {
        $tooBig = strlen($bytes) > 3_700_000;
        $info = @getimagesizefromstring($bytes);
        $tooWide = $info && max($info[0], $info[1]) > 2400;
        if (! $tooBig && ! $tooWide) return ['bytes' => $bytes, 'mime' => $mime];
        if (! function_exists('imagecreatefromstring')) return $tooBig ? null : ['bytes' => $bytes, 'mime' => $mime];
        $image = @imagecreatefromstring($bytes);
        if (! $image) return $tooBig ? null : ['bytes' => $bytes, 'mime' => $mime];
        $w = imagesx($image); $h = imagesy($image); $scale = min(1, 2000 / max($w, $h));
        $resized = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        ob_start(); imagejpeg($resized ?: $image, null, 82); $jpeg = (string) ob_get_clean();
        imagedestroy($image); if ($resized) imagedestroy($resized);
        return strlen($jpeg) > 4_800_000 ? null : ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
    }
}
