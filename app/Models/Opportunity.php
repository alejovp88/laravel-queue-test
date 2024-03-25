<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    use HasFactory;

    protected $table = "wp_ks_opportunities";

    protected $fillable = [
        'company_id',
        'account_id',
        'owner',
        'contact_id',
        'name',
        'close_date',
        'amount',
        'stage',
        'description',
        'created',
        'last_modified',
        'status',
        'last_modified_by'
    ];

    public $timestamps = false;

}
