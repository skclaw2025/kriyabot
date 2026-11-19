<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSource extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'name', 'url', 'color', 'last_synced_at', 'last_error', 'events_count'];
    protected $casts = ['url' => 'encrypted', 'last_synced_at' => 'datetime'];
    protected $hidden = ['url'];

    public function user() { return $this->belongsTo(User::class); }
    public function events() { return $this->hasMany(ExternalEvent::class); }
}
