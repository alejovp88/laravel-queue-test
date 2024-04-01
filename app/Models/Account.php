<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $table = "wp_ks_accounts";
    protected $metaTable = "wp_ks_accounts_meta";
    protected $objectType = 'account';

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

    public function getTableName() {
        return $this->table;
    }

    public function getObjectInfo() {
        return [
            'table' => $this->table,
            'meta_table' => $this->metaTable,
            'object_type' => $this->objectType
        ];
    }
}
