<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un ingreso de un usuario al panel (fecha, IP y navegador). */
class LoginHistory extends Model
{
    public $timestamps = false;

    protected $table = 'login_histories';

    protected $fillable = ['user_id', 'ip_address', 'user_agent', 'is_new_ip', 'logged_in_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_new_ip' => 'boolean', 'logged_in_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
