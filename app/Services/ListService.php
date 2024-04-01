<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\WPPost;
use App\Models\WPUser;
use App\Models\WPUserMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListService
{
    protected $table = "wp_ks_lists";
    protected $contactListTable = "wp_ks_contact_list";
    protected $contactOptLog = "wp_ks_contacts_opt";
    protected $STATUS_ACTIVE = 'active';
    protected $STATUS_INACTIVE = 'inactive';

    public function __construct()
    {

    }

    public function insertContactOptLog($info)
    {
        if (!is_array($info)) {
            return [
                'message' => 'Value is not vidal',
                'result' => 400
            ];
        }

        $query = DB::TABLE($this->contactOptLog)
            ->where('contact_id', '=', $info['contact_id'])
            ->where('company_id', '=', $info['company_id'])
            ->select('id');

        $result = $query->first();
        $logId = ($result) ? $result->id : null;

        $now = new Carbon();
        $info['timestamp'] = $now->format('Y-m-d H:i:s');
        $info['last_modified'] = $now->format('Y-m-d H:i:s');

        $insertData = [
            "contact_id" => $info['contact_id'],
            "user_id"    => $info['user_id'],
            "full_name"  => $info['full_name'],
            "company_id" => $info['company_id'],
        ];

        if($logId) { // Update
            $insertData['updated_at'] = $info['last_modified'];
            $updated = DB::table($this->contactOptLog)
                ->where('id','=', $logId)
                ->update($insertData);

            if ($updated) {
                return [
                    'message' => 'Opt log updated',
                    'created' => $updated,
                    'result' => 200
                ];
            }

        } else { // Insert
            $insertData['created_at'] = $info['timestamp'];
            $created = DB::table($this->contactOptLog)->insert($insertData);

            if ($created) {
                return [
                    'message' => 'Opt log inserted',
                    'created' => $created,
                    'result' => 200
                ];
            }
        }

        return [
            'message' => 'There was an issue',
            'created' => 0,
            'result' => 400
        ];
    }

    public function insertMultipleContactList($contactsListData) {

        $date = date('Y-m-d H:i:s');
        $insertData = [];
        $duplicatedData = [];
        $insertResult = null;
        $values = [];
        $response = [
            'inserted' => [],
            'duplicated' => 0
        ];;

        foreach ($contactsListData as $contactData) {
            $listId = $contactData['list_id'];
            $contactId = $contactData['contact_id'];

            if(!$this->alreadyExistsContactInList($listId, $contactId)) {
                $values = [
                    "list_id" => $listId,
                    "contact_id" => $contactId,
                    "status" => 'active',
                    'created_at' => $date,
                    'updated_at' => $date
                ];
                $insertData[] = [
                    'list_id' => $listId,
                    'contact_id' => $contactId
                ];

            } elseif( $this->alreadyExistsContactInList($listId, $contactId, $this->STATUS_INACTIVE) ) {
                // If the user is inactive, it should not be added, but updated to "active"
                $this->updateContactListStatus($contactId, $listId, $date, $this->STATUS_ACTIVE);
                $insertData[] = [
                    'list_id' => $listId,
                    'contact_id' => $contactId
                ];

            } else {
                // Then is an active user in the list
                $duplicatedData[] = $contactId;
            }
        }

        // Prepare query to insert all contacts at once
        if(!empty($insertData) && !empty($values)) {
            $insertResult = DB::TABLE($this->contactListTable)->insert($values);
        }

        // Return all data from contacts that were inserted
        if ( !empty($insertData) ) {
            foreach ($insertData as $data) {
                $response['inserted'][] = $this->getContactListByIds($data['list_id'], $data['contact_id']);
            }
        }

        // Return the number of users that were tried to insert but already existed
        if(!empty($duplicatedData)) {
            $response['duplicated'] = count($duplicatedData);
        }

        return (!empty($response['inserted']) || !empty($response['duplicated']))
            ? $response
            : false;
    }

    public function alreadyExistsContactInList($listId, $contactId, $status = '')
    {
        $query = DB::TABLE("wp_ks_contact_list")
            ->where('list_id', '=', $listId)
            ->where('contact_id', '=', $contactId);

        if(!empty($status)) {
            $query->whereRaw("status = '$status'");
        }

        return ($query->get()->count() >= 1);
    }

    public function updateContactListStatus($contactId, $listId, $updatedAt = null, $status = null) {


        // Update the status of the record in the contact_list table
        $updatedAt = date('Y-m-d H:i:s');
        $updateData = [
            'updated_at' => $updatedAt,
            'status' => $status
        ];

        // Perform the update query
        $updated = DB::table($this->contactListTable)
            ->where('contact_id', '=', $contactId)
            ->where('list_id', '=', $listId)
            ->update($updateData);

        // If the update is successful, return true. Otherwise, return false.
        return $updated !== false;
    }

    private function getContactListByIds($listId, $contactId)
    {
        $KsContact = new Contact();
        $contactsTable = $KsContact->getTableName();

        $query = DB::table("{$this->contactListTable} as cl")
            ->join("$contactsTable as ", 'cl.contact_id', '=', 'c.id')
            ->where('cl.list_id', '=', $listId)
            ->where('contact_id', '=', $contactId)
            ->whereRaw("c.`status` = 'publish'")
            ->select([
                'cl.list_id',
                'cl.contact_id',
                'cl.created_at',
                'c.email_address',
                DB::RAW("CONCAT(c.first_name, ' ', c.last_name) AS full_name")
            ]);

        $result = $query->first()->toArray();

        return ($result !== null)
            ? $result
            : false;
    }
}
