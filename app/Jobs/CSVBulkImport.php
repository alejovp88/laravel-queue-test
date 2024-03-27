<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AccountMeta;
use App\Models\Contact;
use App\Models\ContactMeta;
use App\Models\Opportunity;
use App\Models\OpportunityMeta;
use App\Services\CSVBulkImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CSVBulkImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $importType;
    protected $fileName;
    protected $fieldsMap;
    protected $fieldErrors;
    protected $offSet;
    protected $numberOfRecords;
    protected $companyId;
    protected $userId;
    protected $csvId;
    protected $listId;
    protected $wpDbPrefix;
    protected $defaultFields;
    protected $defaultTables;
    protected $fieldRules;
    protected $tableSchema;
    protected $lineIndex;
    protected $objectTypeOptions;
    protected $optData;
    protected $isValid;
    protected $fieldRulesFormatted;
    protected $notNullColumns;
    protected $optionalForeignKeys;
    protected $nullColumns;
    protected $customFieldsTable;
    protected $customFieldsInCsvData;
    protected $availableCustomFieldValues;
    protected $customFieldsToSave;
    protected $CSVBulkImportService;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, CSVBulkImportService $CSVBulkImportService)
    {
        $this->importType = $params['importType'];
        $this->fileName = $params['fileName'];
        $this->offSet = $params['offSet'];
        $this->numberOfRecords = $params['offSet'] + $params['numberOfRecords'];
        $this->fieldsMap = json_decode($params['fieldsMap']);
        $this->companyId = $params['companyId'];
        $this->userId = $params['userId'];
        $this->csvId = $params['csvId'];
        if($params['opt_status'] != null) {
            $this->optData = [
                'opt_status' => $params['opt_status'],
                'full_name' => $params['opt_full_name']
            ];
        } else {
            $this->optData = [];
        }
        $this->listId = null;
        $this->tableSchema = env('DB_DATABASE');
        $this->wpDbPrefix = env('DB_WP_TABLES_PREFIX', 'wp_');
        $this->CSVBulkImportService = $CSVBulkImportService;
        $this->defaultTables = [
            'Contacts' => [
                'table' => "{$this->wpDbPrefix}ks_contacts",
                'meta_table' => "{$this->wpDbPrefix}ks_contacts_meta",
                'contact_opt' => "{$this->wpDbPrefix}ks_contacts_opt",
                'object_type' => 'contact'
            ],
            'Accounts' => [
                'table' => "{$this->wpDbPrefix}ks_accounts",
                'meta_table' => "{$this->wpDbPrefix}ks_accounts_meta",
                'object_type' => 'account'
            ],
            'Opportunities' => [
                'table' => "{$this->wpDbPrefix}ks_contacts",
                'meta_table' => "{$this->wpDbPrefix}ks_contacts_meta",
                'stages_table' => "{$this->wpDbPrefix}ks_crm_opportunities_stages",
                'object_type' => 'opportunity'
            ]
        ];
        $this->customFieldsTable = "{$this->wpDbPrefix}ks_settings";
        $this->defaultFields = [
            'Contacts' => [
                'company_id',
                'user_id',
                'last_modified',
                'last_modified_by',
                'owner',
                'created_at'
            ],
            'Accounts' => [
                'company_id',
                'user_id',
                'last_modified',
                'last_modified_by',
                'owner',
                'currency',
                'revenue'
            ],
            'Opportunities' => [
                'created',
                'company_id',
                'last_modified',
                'last_modified_by',
                'owner'
            ]
        ];
        $this->objectTypeOptions   = [
            'contact' => [
                'field' => 'opt_status',
                'options' => [
                    'Opted-In',
                    'opted-in',
                    'Opted-Out',
                    'opted-out'
                ]
            ],
            'account' => [
                'field' => 'type',
                'options' => [
                    'Prospect',
                    'Customer',
                    'prospect',
                    'customer'
                ]
            ],
            'opportunity' => [
                'field' => 'stage',
                'options' => []
            ]
        ];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $csvFile = fopen("{$this->fileName}", "r");
        $successImportFile = substr($this->fileName, 0, -4) . "-success.json";
        $failedImportFile = substr($this->fileName, 0, -4) . "-failed.json";
        Log::info("Success File Name: $successImportFile");
        Log::info("Failed File Name: $failedImportFile");
        $successFile = fopen("{$successImportFile}", "a+");
        $failedFile = fopen("{$failedImportFile}", "a+");

        $listIdIndex = null;

        for($this->lineIndex = 1; $line = fgetcsv($csvFile); $this->lineIndex++) {
            if ($this->lineIndex == 1) {
                [$this->customFieldsInCsvData, $this->availableCustomFieldValues] = $this->getMetaValuesInCsv($line);
                if(in_array('list_id', $line)) {
                    $listIdIndex = array_search('list_id', $line);
                }
            } elseif ($this->lineIndex >= $this->offSet && $this->lineIndex < $this->numberOfRecords) {
                $this->isValid = [
                    'column' => true,
                    'foreignRecord' => true,
                    'userField' => true,
                    'typeField' => true,
                    'currencyField' => true,
                    'customFields' => true,
                    'dateField' => true,
                    'emailField' => true
                ];
                $this->listId = ($listIdIndex !== null) ? $line[$listIdIndex] : null;
                $mapFields = array_merge($this->fieldsMap, $this->defaultFields[$this->importType]);
                [$this->fieldRulesFormatted, $this->notNullColumns, $this->optionalForeignKeys, $this->nullColumns] = $this->getFieldsDefinition($mapFields);

                $record = $this->fillCommonInformation($line, $mapFields);
                $results = $this->makeFieldsValidation();

                switch ($this->importType) {
                    case 'Contacts': {
                        [$record, $results] = $this->validateContacts($record, $results);
                        break;
                    }
                    case 'Accounts': {
                        [$record, $results] = $this->validateAccounts($record, $results);
                        break;
                    }
                    case 'Opportunities': {
                        [$record, $results] = $this->validateOpportunities($record, $results);
                        break;
                    }
                }

                $this->customFieldsToSave = [];
                [$record, $results] = $this->validateLine($record, $results);

                // ---- Set error and skip row if it's invalid
                if (
                    !$this->isValid['column'] || !$this->isValid['foreignRecord'] ||
                    !$this->isValid['userField'] || !$this->isValid['typeField'] ||
                    !$this->isValid['customFields'] || !$this->isValid['dateField'] ||
                    !$this->isValid['currencyField'] || !$this->isValid['emailField']
                ) {
                    $results['rows'][] = [
                        'status' => 'error',
                        'id' => $record['id'] ?? '',
                        'custom_fields' => [],
                    ];
                    fwrite($failedFile, json_encode($results) . "\n"); // Add more fields as needed
                    continue;
                };

                /** system search for element values to check if exist in the database */
                $query = DB::table("{$this->defaultTables[$this->importType]['table']}");
                if (key_exists('id', $record) && $record['id']) {
                    $query->where('id', '=', $record['id']);
                }

                if ($this->defaultTables[$this->importType]['object_type'] === 'contact') {
                    $query->whereRaw("email_address = '{$record['email_address']}'");
                } elseif ($this->defaultTables[$this->importType]['object_type'] === 'account') {
                    $query->whereRaw("name = '{$record['name']}'");
                }

                $element = $query->where('company_id', '=', $this->companyId)
                ->select('id')
                ->first();

                fwrite($successFile, json_encode($record) . "\n"); // Add more fields as needed
                fwrite($failedFile, json_encode($results) . "\n"); // Add more fields as needed
                // ---- Update record if ID exist
                /*if ($element) {
                    $record['id'] = $element->id;
                    [$record, $results] = $this->updateRecord($record, $results);
                } else {
                    [$record, $results] = $this->insertRecord($record, $results);
                }*/

            }
        }
    }

    protected function validateContacts($record, $results) {
        if(key_exists('email_address', $record) && !empty($record['email_address'])){
            $validateEmail = filter_var($record['email_address'], FILTER_VALIDATE_EMAIL);
            if($validateEmail) {
                $results['field_error'][] = [
                    'field' => 'email_address',
                    'message' => 'Invalid email_address',
                    'value' => $record['email_address'],
                    'row' => $this->lineIndex
                ];
                $this->isValid['emailField'] = false;
            }
        }

        if(key_exists('opt_status', $record)) {
            $record['opt_status'] = $this->toCamelCase($record['opt_status']);
        }

        return [
            $record,
            $results
        ];
    }

    protected function validateAccounts($record, $results) {
        $allCurrencies = $this->CSVBulkImportService->getAllCurrencies();

        if(key_exists('type', $record)) {
            $record['type'] = $this->toCamelCase($record['type']);
        }

        if (key_exists('currency', $record) && !empty($record['currency'])) {
            $currency = $record['currency'];
            $founded = false;

            foreach ($allCurrencies as $data) {
                if ($data["code"] === $currency || $data["name"] === $currency) {
                    $record['currency'] = $data['code'];
                    $founded = true;
                    break;
                }
            }

            if(!$founded) {
                $results['field_error'][] = [
                    'field' => 'currency',
                    'message' => 'Invalid currency',
                    'value' => $record['currency'],
                    'row' => $this->lineIndex
                ];
                $this->isValid['currencyField'] = false;
            }
        }

        return [
            $record,
            $results
        ];
    }

    protected function validateOpportunities($record, $results) {

        $objectType = $this->defaultTables[$this->importType]['object_type'];

        if(!empty($record['company'])) {
            $account = DB::TABLE("{$this->defaultTables['Accounts']['table']}")
                ->whereRaw("name = '{$record['company']}'")
                ->where('company_id', '=', $this->companyId)
                ->select('id')
                ->first();

            if ($account) {
                $record['account_id'] = $account->id;
            }
        }

        if(!empty($record['contact'])) {
            $contact = DB::TABLE("{$this->defaultTables['Contacts']['table']}")
                ->whereRaw("email_address = '{$record['contact']}'")
                ->where('company_id', '=', $this->companyId)
                ->select('id')
                ->first();
            if ($contact) {
                $record['contact_id'] = $contact->id;
            }
        }

        $customStages = DB::table("{$this->defaultTables[$this->importType]['stages_table']}")
            ->where('company_id', '=', $this->companyId)
            ->select('*')
            ->orderBy('position', 'ASC')
            ->get();

        if ($customStages) {
            $this->objectTypeOptions[$objectType]['options'] = array_map(function ($stage) {
                return $stage['stage_name'];
            }, $customStages->toArray());

            $this->objectTypeOptions[$objectType]['ids'] = array_map(function ($stage) {
                return $stage['id'];
            }, $customStages->toArray());
        }

        //--- Validate close_date field
        if (key_exists('close_date', $record)) {
            $date = date('Y-m-d', strtotime($record['close_date']));
            $validate_date =  explode('-', $date)[0];
            if ($validate_date == 1970 || !$validate_date) {
                $results['field_error'][] = [
                    'field' => 'close_date',
                    'message' => 'Invalid close_date format',
                    'value' => $record['close_date'],
                    'row' => $this->lineIndex
                ];
                $this->isValid['dateField'] = false;
            } else {
                $record['close_date'] = date('Y-m-d', strtotime($record['close_date']));
            }
        }

        //--- Validate account_id field from opportunity
        if (!key_exists('account_id', $record) || is_null($record['account_id'])) {
            $results['field_error'][] =[
                'field' => 'account_id',
                'message' => 'Invalid account_id',
                'value' => $record['account_id'] ?? '',
                'row' => $this->lineIndex
            ];
            $this->isValid['foreignRecord'] = false;
        }

        //--- Validate contact_id field from opportunity
        if (!key_exists('contact_id', $record) || is_null($record['contact_id'])) {
            $results['field_error'][] =[
                'field' => 'contact_id',
                'message' => 'Invalid contact_id',
                'value' => $record['contact_id'] ?? '',
                'row' => $this->lineIndex
            ];
            $this->isValid['foreignRecord'] = false;
        }

        if ($this->isValid['foreignRecord']) {
            //--- Validate contact_id belongs to account_id from opportunity
            if (key_exists('contact_id', $record) && key_exists('account_id', $record)) {

                $contactId = $record['contact_id'];
                $accountId = $record['account_id'];

                $contactAccountId = DB::table("{$this->defaultTables['Contacts']['table']}")
                    ->where('id', '=', $contactId)
                    ->where('account_id', '=', $accountId)
                    ->where('company_id', '=', $this->companyId)
                    ->select('id')
                    ->first();

                if ($contactAccountId != $contactId || is_null($contactId)) {
                    $results['field_error'][] = [
                        'field' => 'contact_id',
                        'message' => 'Invalid contact_id is not related to a company',
                        'value' => $record['contact_id'],
                        'row' => $this->lineIndex
                    ];
                    $this->isValid['foreignRecord'] = false;
                }
            }
        }

        if (key_exists('stage', $record)) {
            $found_key = array_search($record['stage'], $this->objectTypeOptions[$objectType]['options']);
            $record['stage'] = $this->objectTypeOptions[$objectType]['ids'][$found_key];
        }

        return [
            $record,
            $results
        ];
    }

    protected function fillCommonInformation($line, $mapFields) {
        $newRow= [];

        $defaultFields = $this->defaultFields[$this->importType];
        foreach ($line as $key => $value) {
            if ($mapFields[$key] != 'Skip this field') {
                $newRow[$mapFields[$key]] = trim(str_replace('"', '', $value));
            }
        }

        if (in_array('owner', $defaultFields)) {
            $newRow['owner'] = $this->userId ?? 0;
        }
        if (in_array('company_id', $defaultFields)) {
            $newRow['company_id'] = $this->companyId;
        }
        if (in_array('user_id', $defaultFields)) {
            $newRow['user_id'] = $this->userId ?? 0;
        }
        if (in_array('created', $defaultFields)) {
            $newRow['created'] = date('Y-m-d H:i:s');
        }
        if (in_array('last_modified', $defaultFields)) {
            $newRow['last_modified'] = date('Y-m-d H:i:s');
        }
        if (in_array('last_modified_by', $defaultFields)) {
            $newRow['last_modified_by'] = $this->userId ?? 0;
        }
        if (in_array('currency', $defaultFields)) {
            $newRow['currency'] = $newRow['currency'] ?? 'USD';
        }
        if (in_array('revenue', $defaultFields)) {
            $newRow['revenue'] = $newRow['revenue'] ?? 0;
        }

        return $newRow;
    }

    protected function getFieldsDefinition($mapFields) {
        $this->fieldRules = DB::TABLE("INFORMATION_SCHEMA.COLUMNS")
            ->whereRaw("TABLE_SCHEMA = '$this->tableSchema'")
            ->whereRaw("TABLE_NAME = '{$this->defaultTables[$this->importType]['table']}'")
            ->select([
                'COLUMN_NAME',
                'DATA_TYPE',
                'IS_NULLABLE',
                'COLUMN_KEY'
            ])
            ->get();

        $fieldRulesFormatted = [];
        $notNullColumns = [];
        $optionalForeignKeys = [];

        //------ Check foreign keys and not null fields
        foreach ($this->fieldRules as $field) {
            $fieldRulesFormatted[$field->COLUMN_NAME] = $field;
            if ($field->IS_NULLABLE === "NO") {
                $notNullColumns[] = $field->COLUMN_NAME;
            }
            if (($field->COLUMN_KEY === 'MUL' ||  $field->COLUMN_KEY === 'UNI')  && $field->IS_NULLABLE === "YES") {
                $optionalForeignKeys[] = $field->COLUMN_NAME;
            }
        }

        /** system checks which fields from the line mapping are missing by removing the ones that are optionals */
        $nullColumns = array_diff($notNullColumns, $mapFields);
        $nullColumns = array_values($nullColumns);
        if (in_array('id', $nullColumns) !== false) {
            unset($nullColumns[array_search('id', $nullColumns)]);
            $nullColumns = array_values($nullColumns);
        }

        return [
            $fieldRulesFormatted,
            $notNullColumns,
            $optionalForeignKeys,
            $nullColumns
        ];
    }

    protected function makeFieldsValidation() {
        $results = [];
        if (count($this->nullColumns) > 0) {

            foreach ($this->nullColumns as $column) {
                $results['field_error'][] = [
                    'field' => $column,
                    'message' => "Missing $column column",
                    'value' => "",
                    'row' => "",
                ];

                $results['rows'][] = [
                    'status' => 'error',
                    'id' => '',
                    'custom_fields' => [],
                ];
            }
        }

        return $results;
    }

    protected function validateLine($record, $results) {

        $record['company_id'] = $this->companyId;
        $objectType = $this->defaultTables[$this->importType]['object_type'];

        foreach ($record as $key => $value) {
            if (str_contains(',', $value)) {
                $newValue = str_replace(",", "", $value);
                if (is_numeric($newValue)) {
                    $record[$key] = $newValue;
                }
            }
        }

        // ---- Check blank values in not null columns
        foreach ($this->notNullColumns as $column) {
            if ($column != 'id') {

                if (!isset($record[$column]) && $record[$column] !== 0) {
                    $results['field_error'][] = [
                        'field' => $column,
                        'message' => "Invalid $column",
                        'value' => "",
                        'row' => $this->lineIndex
                    ];
                    $this->isValid['column'] = false;
                }
            }
        }

        //---- Fill optional foreign keys
        foreach ($this->optionalForeignKeys as $key_value) {
            if (!key_exists($key_value, $record) || empty($record[$key_value])) {
                $record[$key_value] = null;
            }
        }

        //----- Check foreign records
        $foreignRecordsCheck = $this->checkForeignRecord($record, $this->defaultTables[$this->importType]['table']);

        if (!empty($foreignRecordsCheck)) {
            foreach ($foreignRecordsCheck as $foreignRecord) {

                if($objectType === 'opportunity' && $foreignRecord['field'] === 'contact_id') {
                    continue;
                } else {
                    if (!$foreignRecord['exist']) {
                        $results['field_error'][] = [
                            'field' => $foreignRecord['field'],
                            'message' => "Invalid {$foreignRecord['field']}",
                            'value' => $foreignRecord['id'],
                            'row' => $this->lineIndex
                        ];
                        $this->isValid['foreignRecord'] = false;
                    }
                }
            }
        }

        //---- Validate object type options
        $objectTypeField = $this->objectTypeOptions[$objectType]['field'];
        $objectTypeOptions = $this->objectTypeOptions[$objectType]['options'];
        if (key_exists($objectTypeField, $record)) {
            if (!in_array($record[$objectTypeField], $objectTypeOptions)) {
                $results['field_error'][] = [
                    'field' => $objectTypeField,
                    'message' => "Invalid $objectTypeField",
                    'value' => $record[$objectTypeField],
                    'row' => $this->lineIndex
                ];
                $this->isValid['typeField'] = false;
            }
        }

        foreach ($this->customFieldsInCsvData as $value) {
            if (key_exists($value['type'], $this->availableCustomFieldValues)) {
                if (!in_array($record[$value['name']], $this->availableCustomFieldValues[$value['type']])) {
                    $results['field_error'][] = [
                        'field' => $value['name'],
                        'message' => "Invalid {$value['name']}",
                        'value' => $record[$value['name']],
                        'row' => $this->lineIndex
                    ];
                    unset($record[$value['name']]);
                    $this->isValid['customFields'] = false;
                    continue;
                }
            }

            //--- Validate custom field with type = Date
            if ($value['type'] == 'Date') {
                $date = date('Y-m-d', strtotime($record[$value['name']]));
                $validateDate =  explode('-', $date)[0];
                if ($validateDate == 1970 || !$validateDate) {
                    $results['field_error'][] = [
                        'field' => $value['name'],
                        'message' => "Invalid {$value['name']} format",
                        'value' => $record[$value['name']],
                        'row' => $this->lineIndex
                    ];
                    unset($record[$value['name']]);
                    $this->isValid['dateField'] = false;
                    continue;
                }
            }

            $this->customFieldsToSave[] = [
                'name' => $value['name'],
                'value' => $record[$value['name']],
                'last_modified' => date('Y-m-d H:i:s'),
                'last_modified_by' => $record['user_id'] ?? 0
            ];

            /*if (key_exists($value['name'], $record)) {
                unset($record[$value['name']]);
            }*/
        }

        return [
            $record,
            $results
        ];
    }

    protected function checkForeignRecord($record, $table) {
        $objects = ['contact_id', 'opportunity_id', 'account_id'];
        $results = [];

        foreach ($objects as $object) {
            if (key_exists($object, $record) && !empty($record[$object])) {
                $exist = false;
                $element = DB::table("$table")
                    ->where("$object", '=', $record[$object])
                    ->where("company_id", '=', $this->companyId)
                    ->first();

                if($element) {
                    $exist = true;
                }

                $results[] = [
                    'field' => $object,
                    'exist' => $exist,
                    'id' => $record[$object]
                ];
            }
        }

        return $results;
    }

    protected function toCamelCase($phrase) {
        return str_replace(' ', '', ucwords($phrase));
    }

    protected function getMetaValuesInCsv($csvHeader) {

        $settingKey = "{$this->companyId}_{$this->defaultTables[$this->importType]['object_type']}_custom-fields";

        $customFields = DB::table("{$this->customFieldsTable}")
            ->whereRaw("setting_key = '$settingKey'")
            ->get()
            ->toArray();

        if (!empty($customFields)) {
            $customFields = reset($customFields);
        }

        $availableCustomFieldValues = [];

        // Format custom fields
        $customFieldsFormatted = [];
        if (!empty($customFields)) {
            $customFields = json_decode($customFields->setting_value);
            foreach ($customFields as $field) {
                if (!key_exists($field->type, $availableCustomFieldValues) &&
                    in_array($field->type, ['Radio', 'Dropdown', 'Checkbox', 'Multi-select'])
                ) {
                    $availableCustomFieldValues[$field->type] = $field->options;
                }
                $customFieldsFormatted[$field->unique_id] = $field;
            }
        }

        unset($customFields);

        // Get the custom fields present in csv_data
        $customFieldsInCsvData = [];
        foreach ($csvHeader as $key => $item) {
            if (key_exists($key, $customFieldsFormatted)) {
                $customFieldsInCsvData[] = [
                    'name' => $key,
                    'type' => $customFieldsFormatted[$key]->type
                ];
            }
        }

        return [$customFieldsInCsvData, $availableCustomFieldValues];
    }

    protected function updateRecord($record, $results) {
        $status = 'error';
        $updateErrors = [];
        $id = $record['id'];

        //Update opt_status with the value from the modal if field is empty
        if ($this->optData && count($this->optData) > 0 && empty($record['opt_status'])) {
            $record['opt_status'] = $this->optData['opt_status'];
        }

        switch ($this->importType) {
            case 'Contacts': {
                $row = Contact::find($id);
                $updateErrors = [
                    'field' => 'email_address',
                    'message' => 'There was an error updating a contact with this email address',
                    'value' => $record['email_address']
                ];
                break;
            }
            case 'Accounts': {
                $row = Account::find($id);
                $updateErrors = [
                    'field' => 'name',
                    'message' => 'An account with this name already exists',
                    'value' => $record['name']
                ];
                break;
            }
            case 'Opportunities': {
                $row = Opportunity::find($id);
                break;
            }
        }

        $updated = $row->update($record);

        $status = $updated ? 'updated' : $status;

        if($status === 'error') {
            if(in_array($this->importType, ['Contacts', 'Accounts'])) {
                $results['field_error'][] = [
                    'field' => $updateErrors['field'],
                    'message' => $updateErrors['message'],
                    'value' => $updateErrors['value'],
                    'row' => $this->lineIndex
                ];
            }
        }

        //---- Save / update custom fields
        $createdCustomFields = $this->saveCustomFields($record, $id);

        if (!empty($createdCustomFields)) {
            foreach ($createdCustomFields as $customField) {
                if ($customField['status'] == 'error') {
                    $this->fieldErrors[] = [
                        'field' => $customField['field'],
                        'message' => "Invalid {$customField['field']}",
                        'value' => $customField['value'],
                        'row' => $this->lineIndex
                    ];
                }
            }
        }

        $results['rows'][] = [
            'status' => $status,
            'id' => $id,
            'custom_fields' => $createdCustomFields,
        ];

        return [
            $record,
            $results
        ];
    }

    protected function insertRecord($record, $results) {
        $status = 'error';
        $objectType = $this->defaultTables[$this->importType]['object_type'];

        if (array_search($objectType, ['contact', 'opportunity'])) {
            // Check if account name exists in db to set account_id in register
            if (isset($record['account'])) {
                $accountId = Account::where('company_id', '=', $this->companyId)
                    ->whereRaw("name = '{$record['account']}'")
                    ->select('id')
                    ->first();

                if ($accountId) {
                    $record['account_id'] = $accountId;
                }
            }
        }

        if ($objectType == 'contact') {
            if ($this->optData && count($this->optData) > 0 && empty($record['opt_status'])) {
                $record['opt_status'] = $this->optData['opt_status'];
            }

            if(!isset($record['account_id'])) {
                $accountId = Account::where('company_id', '=', $this->companyId)
                    ->whereRaw("website LIKE ''")
                    ->select('id')
                    ->first();

                $record['account_id'] = $accountId;
            }
        }

        switch ($this->importType) {
            case 'Contacts': {
                $row = new Contact();
                break;
            }
            case 'Accounts': {
                $row = new Account();
                break;
            }
            case 'Opportunities': {
                $row = new Opportunity();
                break;
            }
        }

        $row->fill($record);
        $element = $row->save();

        $status = ($element) ? 'created' : $status;
        $record['íd'] = $element->id;

        //---- Save / update custom fields
        $createdCustomFields = $this->saveCustomFields($record, $record['íd']);
        if (!empty($createdCustomFields)) {
            foreach ($createdCustomFields as $key => $customField) {
                if ($customField['status'] == 'error') {
                    $this->fieldErrors[] = [
                        'field' => $customField['field'],
                        'message' => "Invalid {$customField['field']}",
                        'value' => $customField['value'],
                        'row' => $key
                    ];
                }
            }
        }

        if ($element) {
            $record['imported'] = 1;

            try {
                if ($objectType == 'contact') {
                    $this->CSVBulkImportService->ksCreateContactActivity($record, $element->id, $status, null);
                } else if ($objectType == 'account') {
                    $this->CSVBulkImportService->ksCreateAccountActivity($record, $element->id, $status);
                } else {
                    $this->CSVBulkImportService->ksCreateOpportunityActivity($record, $element->id, $status);
                }
            } catch (\Throwable $e) {
                error_log($e->getMessage());
            }
        }

        $results['rows'][] = [
            'status' => $status,
            'custom_fields' => $createdCustomFields ?? [],
            'id' => $record['íd'] ?? ''
        ];

        return [$record, $results];
    }

    protected function saveCustomFields($record, $id) {
        $results = [];

        $objectType = $this->defaultTables[$this->importType]['object_type'];
        if (count($this->customFieldsToSave) > 0) {
            foreach ($this->customFieldsToSave as &$customFieldRow) {
                $status = 'error';
                $objectKey = $objectType . '_id';
                $customFieldRow[$objectKey] = $id;

                switch ($this->importType) {
                    case 'Contacts': {
                        $row = ContactMeta::where("{$objectKey}", '=', $id)
                            ->whereRaw("name = '{$customFieldRow['name']}'")
                            ->first();
                        break;
                    }
                    case 'Accounts': {
                        $row = AccountMeta::where("{$objectKey}", '=', $id)
                            ->whereRaw("name = '{$customFieldRow['name']}'")
                            ->first();
                        break;
                    }
                    case 'Opportunities': {
                        $row = OpportunityMeta::where("{$objectKey}", '=', $id)
                            ->whereRaw("name = '{$customFieldRow['name']}'")
                            ->first();
                        break;
                    }
                }

                if (!empty($row)) {
                    if ($row->value == $customFieldRow['value']) {
                        continue;
                    }
                    $updated = $row->update($customFieldRow);

                    $status = $updated ? 'updated' : $status;
                } else {
                    if ($objectType == 'contact') {
                        $record['imported'] = 1;
                    }

                    switch ($this->importType) {
                        case 'Contacts': {
                            $row = new ContactMeta();
                            break;
                        }
                        case 'Accounts': {
                            $row = new AccountMeta();
                            break;
                        }
                        case 'Opportunities': {
                            $row = new OpportunityMeta();
                            break;
                        }
                    }

                    $row->update($customFieldRow);

                    $status = $row->id ? 'created' : $status;
                }

                $results[] = [
                    'field' => $customFieldRow['name'],
                    'status' => $status,
                    'value' => $customFieldRow['value']
                ];
            }
        }

        return $results;
    }
}

