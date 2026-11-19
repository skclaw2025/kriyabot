<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'user_id', 'head_id', 'type', 'amount', 'tax_amount', 'currency', 'entry_date', 'party', 'bill_no',
        'description', 'payment_method', 'source', 'needs_review', 'ai_suggested_head', 'ai_confidence', 'extraction',
        'receipt_disk', 'receipt_path', 'receipt_original_name', 'receipt_mime', 'receipt_size',
    ];
    protected $casts = ['amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'entry_date' => 'date:Y-m-d', 'needs_review' => 'boolean', 'extraction' => 'array'];
    protected $hidden = ['receipt_disk', 'receipt_path', 'extraction', 'deleted_at'];
    protected $appends = ['has_receipt'];

    public function head() { return $this->belongsTo(FinanceHead::class, 'head_id')->withTrashed(); }
    public function getHasReceiptAttribute(): bool { return filled($this->receipt_path); }
}
