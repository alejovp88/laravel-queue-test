<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountMeta extends Model
{
    use HasFactory;

    protected $table = 'wp_ks_accounts_meta';

    protected $fillable = [
        'account_id',
        'name',
        'value',
        'last_modified',
        'last_modified_by'
    ];

    public $timestamps = false;
}
