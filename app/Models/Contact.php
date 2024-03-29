<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $table = "wp_ks_contacts";
    protected $metaTable = "wp_ks_contacts_meta";
    protected $contactOptTable = "wp_ks_contacts_opt";
    protected $objectType = 'contact';

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

    public function getTableName() {
        return $this->table;
    }

    public function getObjectInfo() {
        return [
            'table' => $this->table,
            'meta_table' => $this->metaTable,
            'contact_opt' => $this->contactOptTable,
            'object_type' => $this->objectType
        ];
    }
}
