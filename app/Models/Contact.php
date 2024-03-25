<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $table = "wp_ks_contacts";

    protected $fillable = [
        'company_id',
        'account_id',
        'user_id',
        'description',
        'contact_photo',
        'email_address',
        'phone',
        'lead_stage',
        'account',
        'last_modified',
        'last_modified_by',
        'owner',
        'first_name',
        'last_name',
        'buying_role',
        'job_title',
        'imported',
        'status',
        'autotask_id',
        'created_at',
        'opt_status'
    ];

    public $timestamps = false;
}
