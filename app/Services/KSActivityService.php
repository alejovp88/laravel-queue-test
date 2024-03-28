<?php

namespace App\Services;

use App\Models\KSActivity;
use App\Models\KSActivityLog;

class KSActivityService
{
    public $CREATE = 'create';
    public $UPDATE = 'update';
    public $DELETE = 'delete';

    public function __construct()
    {

    }

    public function insertCrmActivity($info): array
    {
        if (!is_array($info)) {
            throw new \Exception('Value is not valid', 400);
        }

        $activityRecord = new KSActivity();
        $activityRecord->fill([
            'post_id' => $info['post_id'],
            'field' => $info['field'] ?? '',
            'label' => $info['label'],
            'value' => $info['value'] ?? '',
            'type' => $info['type'],
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $info['created_by'],
            'source' => $info['source'] ?? null,
            'company_id' => $info['company_id'] ?? 0,
            'action' => $info['action'] ?? '',
        ]);

        $created = $activityRecord->save();

        if ($created) {
            return [
                'message' => 'Activity inserted',
                'created' => $created,
                'result' => 200
            ];
        }

        throw new \Exception('There was an issue', 400);
    }

    public function insertActivityLog($info): array
    {
        if (!is_array($info)) {
            return [
                'message' => 'Value is not valid',
                'result' => 400
            ];
        }

        $params = [];
        if(isset($info['params'])){
            $params = $info['params'];
        }

        $activity = new KSActivityLog();
        $activity->fill([
            'created_by'  => $info['created_by'],
            'source'      => $info['source']  ?? null,
            'object'      => $info['object'],
            'object_id'   => $info['object_id'],
            'action'      => $info['action'],
            'params'      => serialize($params),
            'created_at'  => date('Y-m-d H:i:s'),
            'company_id'  => $info['company_id']
        ]);

        $created = $activity->save();

        if ($created) {
            return [
                'message' => 'Activity inserted',
                'created' => $created,
                'result' => 200
            ];
        }

        return [
            'message' => 'There was an issue',
            'created' => $created,
            'result' => 400
        ];
    }
}
