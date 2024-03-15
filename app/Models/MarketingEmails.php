<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingEmails extends Model
{
    use HasFactory;

    protected $table = 'wp_ks_marketing_emails';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email_subject',
        'sent_at',
        'company_id',
        'content',
        'template_id',
        'status',
        'entity',
        'entity_id',
        'batch',
        'asset_id',
        'user_id',
        'available_at',
        'contact_id',
    ];
}
