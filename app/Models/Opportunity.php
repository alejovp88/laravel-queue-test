<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    use HasFactory;

    protected $table = "wp_ks_opportunities";
    protected $metaTable = "wp_ks_opportunities_meta";
    protected $stages = "wp_ks_crm_opportunities_stages";
    protected $objectType = 'opportunity';

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

    public function getStages($id = null, $companyId = null) {

        $query = $this::query();
        if($id) {
            $query->where('id', '=', $id);
        } elseif ($companyId) {
            $query->where('company_id', '=', $companyId);
        }
        $query->select('*')
            ->orderBy('position', 'ASC');

        return $query->get();
    }

    public function getTableName() {
        return $this->table;
    }

    public function getObjectInfo() {
        return [
            'table' => $this->table,
            'meta_table' => $this->metaTable,
            'stages_table' => $this->stages,
            'object_type' => $this->objectType
        ];
    }
}
