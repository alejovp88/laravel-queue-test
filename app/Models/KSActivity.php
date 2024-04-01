<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KSActivity extends Model
{
    use HasFactory;

    protected $table = "wp_ks_crm_activities";

    protected $fillable = [
        'post_id',
        'field',
        'label',
        'value',
        'type',
        'created_at',
        'created_by',
        'source',
        'company_id',
        'action'
    ];

    public $timestamps = false;
}
