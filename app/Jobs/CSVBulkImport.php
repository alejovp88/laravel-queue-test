<?php

namespace App\Jobs;

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
    protected $offSet;
    protected $numberOfRecords;
    protected $companyId;
    protected $userId;
    protected $default_fields = [
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
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params)
    {
        $this->importType = $params['importType'];
        $this->fileName = $params['fileName'];
        $this->offSet = $params['offSet'];
        $this->numberOfRecords = $params['offSet'] + $params['numberOfRecords'];
        $this->fieldsMap = json_decode($params['fieldsMap']);
        $this->companyId = $params['companyId'];
        $this->userId = $params['userId'];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $csvFile = fopen("{$this->fileName}", "r");
        $successImportFile = substr($this->fileName, 0, -4) . "-success.csv";
        $failedImportFile = substr($this->fileName, 0, -4) . "-failed.csv";
        Log::info("Success File Name: $successImportFile");
        Log::info("Failed File Name: $failedImportFile");
        //$successFile = fopen("{$successImportFile}", "w+");
        $successFile = null;
        //$failedFile = fopen("{$failedImportFile}", "w+");
        $failedFile = null;

        for($lineIndex = 1; $line = fgetcsv($csvFile); $lineIndex++) {
            if($lineIndex >= $this->offSet && $lineIndex < $this->numberOfRecords) {
                switch ($this->importType) {
                    case 'Contacts': {
                        $record = $this->processContacts($line, $successFile, $failedFile);
                        break;
                    }
                    case 'Accounts': {
                        $record = $this->processAccounts($line, $successFile, $failedFile);
                        break;
                    }
                    case 'Opportunities': {
                        $record = $this->processOpportunities($line, $successFile, $failedFile);
                        break;
                    }
                }

                Log::info(json_encode($record));
            }
        }
    }

    public function processContacts($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->default_fields['Contacts']);

        return $record;
    }

    public function processAccounts($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->default_fields['Accounts']);

        return $record;
    }

    public function processOpportunities($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->default_fields['Opportunities']);

        if(isset($record['company']) && !empty($record['company'])) {
            $account = DB::TABLE("wp_ks_accounts")
                ->whereRaw("name = '{$record['company']}'")
                ->where('company_id', '=', $this->companyId)
                ->select('id')
                ->first();

            if ($account) {
                $record['account_id'] = $account->id;
            }
        }

        if(isset($record['contact']) && !empty($record['contact'])) {
            $contact = DB::TABLE("wp_ks_contacts")
                ->whereRaw("email_address = '{$record['contact']}'")
                ->where('company_id', '=', $this->companyId)
                ->select('id')
                ->first();
            if ($contact) {
                $record['contact_id'] = $contact->id;
            }
        }

        return $record;
    }

    public function fillCommonInformation($line, $defaultFields) {
        $newRow= [];


        foreach ($line as $key => $value) {
            //Log::info("fieldMap: " . $this->fieldsMap[$key] . ", line value: " . $value);
            if ($this->fieldsMap[$key] != 'Skip this field') {
                $newRow[$this->fieldsMap[$key]] = trim(str_replace('"', '', $value));
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
}

