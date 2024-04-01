<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KSActivityLog extends Model
{
    use HasFactory;

    protected $table = "wp_ks_activities_logs";

    protected $fillable = [
        'created_by',
        'source',
        'object',
        'object_id',
        'action',
        'params',
        'created_at',
        'company_id'
    ];

    public $timestamps = false;
}
