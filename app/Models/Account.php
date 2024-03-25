<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $table = "wp_ks_accounts";

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'description',
        'contact_photo',
        'website',
        'revenue',
        'type',
        'owner',
        'city',
        'state',
        'last_modified',
        'last_modified_by',
        'country',
        'phone',
        'status',
        'autotask_id',
        'created_at',
        'currency',
        'zip_code'
    ];

    public $timestamps = false;
}
