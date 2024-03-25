<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpportunityMeta extends Model
{
    use HasFactory;

    protected $table = "wp_ks_opportunities_meta";

    protected $fillable = [
        'opportunity_id',
        'name',
        'value',
        'last_modified',
        'last_modified_by'
    ];

    public $timestamps = false;
}
