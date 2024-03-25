<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMeta extends Model
{
    use HasFactory;

    protected $table = "wp_ks_contacts_meta";

    protected $fillable = [
        'contact_id',
        'name',
        'value',
        'last_modified',
        'last_modified_by'
    ];

    public $timestamps = false;
}
