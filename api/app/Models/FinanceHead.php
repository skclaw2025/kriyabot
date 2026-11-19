<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceHead extends Model
{
    use SoftDeletes;

    protected $fillable = ['workspace_id', 'user_id', 'name', 'type', 'color', 'sort_order'];
    protected $hidden = ['deleted_at'];

    public function entries() { return $this->hasMany(FinanceEntry::class, 'head_id'); }
}
