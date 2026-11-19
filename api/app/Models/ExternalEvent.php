<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalEvent extends Model
{
    protected $fillable = ['calendar_source_id', 'user_id', 'uid_hash', 'title', 'location', 'starts_at', 'ends_at', 'all_day'];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean'];

    public function source() { return $this->belongsTo(CalendarSource::class, 'calendar_source_id'); }
}
