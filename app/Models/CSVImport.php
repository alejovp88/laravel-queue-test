<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CSVImport extends Model
{
    use HasFactory;

    protected $table = "wp_csv_imports";

    protected $fillable = [
        'user_id',
        'company_id',
        'name',
        'type',
        'status',
        'field_map',
        'csv_file',
        'results',
        'total_records',
        'fail_records',
        'success_records',
        'opt_full_name',
        'opt_status',
        'timestamp',
        'displayed_notification'
    ];

    public $timestamps = false;
}
