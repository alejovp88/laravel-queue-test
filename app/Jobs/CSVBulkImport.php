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
    protected $wpDbPrefix;
    protected $defaultFields;
    protected $defaultTables;
    protected $fieldRules;
    protected $tableSchema;
    protected $lineIndex;
    protected $objectTypeOptions;
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
        $this->tableSchema = env('DB_DATABASE');
        $this->wpDbPrefix = env('DB_WP_TABLES_PREFIX', 'wp_');
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
        $successImportFile = substr($this->fileName, 0, -4) . "-success.csv";
        $failedImportFile = substr($this->fileName, 0, -4) . "-failed.csv";
        Log::info("Success File Name: $successImportFile");
        Log::info("Failed File Name: $failedImportFile");
        $successFile = fopen("{$successImportFile}", "a+");
        $failedFile = fopen("{$failedImportFile}", "a+");

        $this->getFieldsDefinition();

        for($this->lineIndex = 1; $line = fgetcsv($csvFile); $this->lineIndex++) {
            if($this->lineIndex >= $this->offSet && $this->lineIndex < $this->numberOfRecords) {
                $record = $this->fillCommonInformation($line, $this->defaultFields[$this->importType]);
                [$fieldRulesFormatted, $notNullColumns, $optionalForeignKeys, $nullColumns] = $this->getFieldsDefinition();
                $results = $this->makeFieldsValidation($nullColumns);



                switch ($this->importType) {
                    case 'Contacts': {
                        $this->processContacts($line, $record, $successFile, $failedFile);
                        break;
                    }
                    case 'Accounts': {
                        $this->processAccounts($line, $record, $successFile, $failedFile);
                        break;
                    }
                    case 'Opportunities': {
                        $this->processOpportunities($line, $record, $successFile, $failedFile);
                        break;
                    }
                }
            }
        }
    }

    protected function processContacts($line, $record, $successFile, $failedFile) {
        if(key_exists('email_address', $record) && !empty($record['email_address'])){
            $validate_email = filter_var($record['email_address'], FILTER_VALIDATE_EMAIL);
            if($validate_email == false){
                $results['field_error'][] = [
                    'field' => 'email_address',
                    'message' => 'Invalid email_address',
                    'value' => $record['email_address'],
                    'row' => $this->lineIndex
                ];
                $isValidEmailField = false;
            }
        }

        if(key_exists('opt_status', $record)) {
            $record['opt_status'] = $this->toCamelCase($record['opt_status']);
        }
    }

    protected function processAccounts($line, $record, $successFile, $failedFile) {
        $allCurrencies = $this->getAllCurrencies();

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
                $is_valid_currency_field = false;
            }
        }
    }

    protected function processOpportunities($line, $record, $successFile, $failedFile) {

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

        $customStages = DB::table("{$this->defaultTables['Opportunities']['stages_table']}")
            ->where('company_id', '=', $this->companyId)
            ->select('*')
            ->orderBy('position', 'ASC')
            ->get();

        if ($customStages) {
            $this->objectTypeOptions['opportunity']['options'] = array_map(function ($stage) {
                return $stage['stage_name'];
            }, $customStages->toArray());

            $this->objectTypeOptions['opportunity']['ids'] = array_map(function ($stage) {
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
                $is_valid_date_field = false;
            } else {
                $csv_data_item['close_date'] = date('Y-m-d', strtotime($record['close_date']));
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
            $is_valid_foreign_record = false;
        }

        //--- Validate contact_id field from opportunity
        if (!key_exists('contact_id', $record) || is_null($record['contact_id'])) {
            $results['field_error'][] =[
                'field' => 'contact_id',
                'message' => 'Invalid contact_id',
                'value' => $record['contact_id'] ?? '',
                'row' => $this->lineIndex
            ];
            $is_valid_foreign_record = false;
        }

        /** quede en esta parte del refactor*/
        if ($is_valid_foreign_record) {
            //--- Validate contact_id belongs to account_id from opportunity
            if (key_exists('contact_id', $record) && key_exists('account_id', $record)) {

                $contactId = $record['contact_id'];
                $accountId = $record['account_id'];

                $contact_account_id = $ks_contact->check_contact_by_account_id($contactId, $accountId, $this->companyId);

                if ($contact_account_id != $contactId || is_null($contactId)) {
                    $results['field_error'][] = [
                        'field' => 'contact_id',
                        'message' => 'Invalid contact_id is not related to a company',
                        'value' => $record['contact_id'],
                        'row' => $this->lineIndex
                    ];
                    $is_valid_foreign_record = false;
                }
            }
        }

        if ($object_type == 'opportunity' && key_exists('stage', $csv_data_item)) {
            $found_key = array_search($csv_data_item['stage'], $this->objectTypeOptions[$object_type]['options']);
            $csv_data_item['stage'] = $this->objectTypeOptions[$object_type]['ids'][$found_key];
        }
    }

    protected function fillCommonInformation($line, $defaultFields) {
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

    protected function getFieldsDefinition() {
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

        /** system checks which fields from the line mapping are missing by removing the one that are optionals */
        $nullColumns = array_diff($notNullColumns, $this->fieldsMap);
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

    protected function makeFieldsValidation($nullColumns) {
        $results = [];
        if (count($nullColumns) > 0) {

            foreach ($nullColumns as $column) {
                $results['field_error'][] = [
                    'field' => $column,
                    'message' => "Missing $column column",
                    'value' => "",
                    'row' => "",
                    'fileLine' => 0
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

    protected function getAllCurrencies()
    {
        return [
            "AED" => [
                "name" => "United Arab Emirates Dirham",
                "code" => "AED",
            ],
            "AFN" => [
                "name" => "Afghan Afghani",
                "code" => "AFN",
            ],
            "ALL" => [
                "name" => "Albanian Lek",
                "code" => "ALL",
            ],
            "AMD" => [
                "name" => "Armenian Dram",
                "code" => "AMD",
            ],
            "ANG" => [
                "name" => "NL Antillean Guilder",
                "code" => "ANG",
            ],
            "AOA" => [
                "name" => "Angolan Kwanza",
                "code" => "AOA",
            ],
            "ARS" => [
                "name" => "Argentine Peso",
                "code" => "ARS",
            ],
            "AUD" => [
                "name" => "Australian Dollar",
                "code" => "AUD",
            ],
            "AWG" => [
                "name" => "Aruban Florin",
                "code" => "AWG",
            ],
            "AZN" => [
                "name" => "Azerbaijani Manat",
                "code" => "AZN",
            ],
            "BAM" => [
                "name" => "Bosnia-Herzegovina Convertible Mark",
                "code" => "BAM",
            ],
            "BBD" => [
                "name" => "Barbadian Dollar",
                "code" => "BBD",
            ],
            "BDT" => [
                "name" => "Bangladeshi Taka",
                "code" => "BDT",
            ],
            "BGN" => [
                "name" => "Bulgarian Lev",
                "code" => "BGN",
            ],
            "BHD" => [
                "name" => "Bahraini Dinar",
                "code" => "BHD",
            ],
            "BIF" => [
                "name" => "Burundian Franc",
                "code" => "BIF",
            ],
            "BMD" => [
                "name" => "Bermudan Dollar",
                "code" => "BMD",
            ],
            "BND" => [
                "name" => "Brunei Dollar",
                "code" => "BND",
            ],
            "BOB" => [
                "name" => "Bolivian Boliviano",
                "code" => "BOB",
            ],
            "BRL" => [
                "name" => "Brazilian Real",
                "code" => "BRL",
            ],
            "BSD" => [
                "name" => "Bahamian Dollar",
                "code" => "BSD",
            ],
            "BTN" => [
                "name" => "Bhutanese Ngultrum",
                "code" => "BTN",
            ],
            "BWP" => [
                "name" => "Botswanan Pula",
                "code" => "BWP",
            ],
            "BYN" => [
                "name" => "Belarusian ruble",
                "code" => "BYN",
            ],
            "BYR" => [
                "name" => "Belarusian Ruble",
                "code" => "BYR",
            ],
            "BZD" => [
                "name" => "Belize Dollar",
                "code" => "BZD",
            ],
            "CAD" => [
                "name" => "Canadian Dollar",
                "code" => "CAD",
            ],
            "CDF" => [
                "name" => "Congolese Franc",
                "code" => "CDF",
            ],
            "CHF" => [
                "name" => "Swiss Franc",
                "code" => "CHF",
            ],
            "CLF" => [
                "name" => "Unidad de Fomento",
                "code" => "CLF",
            ],
            "CLP" => [
                "name" => "Chilean Peso",
                "code" => "CLP"
            ],
            "CNY" => [
                "name" => "Chinese Yuan",
                "code" => "CNY",
            ],
            "COP" => [
                "name" => "Colombian Peso",
                "code" => "COP",
            ],
            "CRC" => [
                "name" => "Costa Rican Colón",
                "code" => "CRC"
            ],
            "CUC" => [
                "name" => "Cuban Convertible Peso",
                "code" => "CUC",
            ],
            "CUP" => [
                "name" => "Cuban Peso",
                "code" => "CUP",
            ],
            "CVE" => [
                "name" => "Cape Verdean Escudo",
                "code" => "CVE",
            ],
            "CZK" => [
                "name" => "Czech Republic Koruna",
                "code" => "CZK",
            ],
            "DJF" => [
                "name" => "Djiboutian Franc",
                "code" => "DJF",
            ],
            "DKK" => [
                "name" => "Danish Krone",
                "code" => "DKK",
            ],
            "DOP" => [
                "name" => "Dominican Peso",
                "code" => "DOP",
            ],
            "DZD" => [
                "name" => "Algerian Dinar",
                "code" => "DZD",
            ],
            "EGP" => [
                "name" => "Egyptian Pound",
                "code" => "EGP",
            ],
            "ERN" => [
                "name" => "Eritrean Nakfa",
                "code" => "ERN",
            ],
            "ETB" => [
                "name" => "Ethiopian Birr",
                "code" => "ETB",
            ],
            "EUR" => [
                "name" => "Euro",
                "code" => "EUR",
            ],
            "FJD" => [
                "name" => "Fijian Dollar",
                "code" => "FJD",
            ],
            "FKP" => [
                "name" => "Falkland Islands Pound",
                "code" => "FKP",
            ],
            "GBP" => [
                "name" => "British Pound Sterling",
                "code" => "GBP",
            ],
            "GEL" => [
                "name" => "Georgian Lari",
                "code" => "GEL",
            ],
            "GGP" => [
                "name" => "Guernsey pound",
                "code" => "GGP",
            ],
            "GHS" => [
                "name" => "Ghanaian Cedi",
                "code" => "GHS",
            ],
            "GIP" => [
                "name" => "Gibraltar Pound",
                "code" => "GIP",
            ],
            "GMD" => [
                "name" => "Gambian Dalasi",
                "code" => "GMD",
            ],
            "GNF" => [
                "name" => "Guinean Franc",
                "code" => "GNF",
            ],
            "GTQ" => [
                "name" => "Guatemalan Quetzal",
                "code" => "GTQ",
            ],
            "GYD" => [
                "name" => "Guyanaese Dollar",
                "code" => "GYD",
            ],
            "HKD" => [
                "name" => "Hong Kong Dollar",
                "code" => "HKD",
            ],
            "HNL" => [
                "name" => "Honduran Lempira",
                "code" => "HNL",
            ],
            "HRK" => [
                "name" => "Croatian Kuna",
                "code" => "HRK",
            ],
            "HTG" => [
                "name" => "Haitian Gourde",
                "code" => "HTG",
            ],
            "HUF" => [
                "name" => "Hungarian Forint",
                "code" => "HUF",
            ],
            "IDR" => [
                "name" => "Indonesian Rupiah",
                "code" => "IDR",
            ],
            "ILS" => [
                "name" => "Israeli New Sheqel",
                "code" => "ILS",
            ],
            "IMP" => [
                "name" => "Manx pound",
                "code" => "IMP",
            ],
            "INR" => [
                "name" => "Indian Rupee",
                "code" => "INR",
            ],
            "IQD" => [
                "name" => "Iraqi Dinar",
                "code" => "IQD",
            ],
            "IRR" => [
                "name" => "Iranian Rial",
                "code" => "IRR",
            ],
            "ISK" => [
                "name" => "Icelandic Króna",
                "code" => "ISK",
            ],
            "JEP" => [
                "name" => "Jersey pound",
                "code" => "JEP",
            ],
            "JMD" => [
                "name" => "Jamaican Dollar",
                "code" => "JMD",
            ],
            "JOD" => [
                "name" => "Jordanian Dinar",
                "code" => "JOD",
            ],
            "JPY" => [
                "name" => "Japanese Yen",
                "code" => "JPY",
            ],
            "KES" => [
                "name" => "Kenyan Shilling",
                "code" => "KES",
            ],
            "KGS" => [
                "name" => "Kyrgystani Som",
                "code" => "KGS",
            ],
            "KHR" => [
                "name" => "Cambodian Riel",
                "code" => "KHR",
            ],
            "KMF" => [
                "name" => "Comorian Franc",
                "code" => "KMF",
            ],
            "KPW" => [
                "name" => "North Korean Won",
                "code" => "KPW",
            ],
            "KRW" => [
                "name" => "South Korean Won",
                "code" => "KRW",
            ],
            "KWD" => [
                "name" => "Kuwaiti Dinar",
                "code" => "KWD",
            ],
            "KYD" => [
                "name" => "Cayman Islands Dollar",
                "code" => "KYD",
            ],
            "KZT" => [
                "name" => "Kazakhstani Tenge",
                "code" => "KZT",
            ],
            "LAK" => [
                "name" => "Laotian Kip",
                "code" => "LAK",
            ],
            "LBP" => [
                "name" => "Lebanese Pound",
                "code" => "LBP",
            ],
            "LKR" => [
                "name" => "Sri Lankan Rupee",
                "code" => "LKR",
            ],
            "LRD" => [
                "name" => "Liberian Dollar",
                "code" => "LRD",
            ],
            "LSL" => [
                "name" => "Lesotho Loti",
                "code" => "LSL",
            ],
            "LTL" => [
                "name" => "Lithuanian Litas",
                "code" => "LTL",
            ],
            "LVL" => [
                "name" => "Latvian Lats",
                "code" => "LVL",
            ],
            "LYD" => [
                "name" => "Libyan Dinar",
                "code" => "LYD",
            ],
            "MAD" => [
                "name" => "Moroccan Dirham",
                "code" => "MAD",
            ],
            "MDL" => [
                "name" => "Moldovan Leu",
                "code" => "MDL",
            ],
            "MGA" => [
                "name" => "Malagasy Ariary",
                "code" => "MGA",
            ],
            "MKD" => [
                "name" => "Macedonian Denar",
                "code" => "MKD",
            ],
            "MMK" => [
                "name" => "Myanma Kyat",
                "code" => "MMK",
            ],
            "MNT" => [
                "name" => "Mongolian Tugrik",
                "code" => "MNT",
            ],
            "MOP" => [
                "name" => "Macanese Pataca",
                "code" => "MOP",
            ],
            "MRO" => [
                "name" => "Mauritanian ouguiya",
                "code" => "MRO",
            ],
            "MUR" => [
                "name" => "Mauritian Rupee",
                "code" => "MUR",
            ],
            "MVR" => [
                "name" => "Maldivian Rufiyaa",
                "code" => "MVR",
            ],
            "MWK" => [
                "name" => "Malawian Kwacha",
                "code" => "MWK",
            ],
            "MXN" => [
                "name" => "Mexican Peso",
                "code" => "MXN",
            ],
            "MYR" => [
                "name" => "Malaysian Ringgit",
                "code" => "MYR",
            ],
            "MZN" => [
                "name" => "Mozambican Metical",
                "code" => "MZN",
            ],
            "NAD" => [
                "name" => "Namibian Dollar",
                "code" => "NAD",
            ],
            "NGN" => [
                "name" => "Nigerian Naira",
                "code" => "NGN",
            ],
            "NIO" => [
                "name" => "Nicaraguan Córdoba",
                "code" => "NIO",
            ],
            "NOK" => [
                "name" => "Norwegian Krone",
                "code" => "NOK",
            ],
            "NPR" => [
                "name" => "Nepalese Rupee",
                "code" => "NPR",
            ],
            "NZD" => [
                "name" => "New Zealand Dollar",
                "code" => "NZD",
            ],
            "OMR" => [
                "name" => "Omani Rial",
                "code" => "OMR",
            ],
            "PAB" => [
                "name" => "Panamanian Balboa",
                "code" => "PAB",
            ],
            "PEN" => [
                "name" => "Peruvian Nuevo Sol",
                "code" => "PEN",
            ],
            "PGK" => [
                "name" => "Papua New Guinean Kina",
                "code" => "PGK",
            ],
            "PHP" => [
                "name" => "Philippine Peso",
                "code" => "PHP",
            ],
            "PKR" => [
                "name" => "Pakistani Rupee",
                "code" => "PKR",
            ],
            "PLN" => [
                "name" => "Polish Zloty",
                "code" => "PLN",
            ],
            "PYG" => [
                "name" => "Paraguayan Guarani",
                "code" => "PYG",
            ],
            "QAR" => [
                "name" => "Qatari Rial",
                "code" => "QAR",
            ],
            "RON" => [
                "name" => "Romanian Leu",
                "code" => "RON",
            ],
            "RSD" => [
                "name" => "Serbian Dinar",
                "code" => "RSD",
            ],
            "RUB" => [
                "name" => "Russian Ruble",
                "code" => "RUB",
            ],
            "RWF" => [
                "name" => "Rwandan Franc",
                "code" => "RWF",
            ],
            "SAR" => [
                "name" => "Saudi Riyal",
                "code" => "SAR",
            ],
            "SBD" => [
                "name" => "Solomon Islands Dollar",
                "code" => "SBD",
            ],
            "SCR" => [
                "name" => "Seychellois Rupee",
                "code" => "SCR",
            ],
            "SDG" => [
                "name" => "Sudanese Pound",
                "code" => "SDG",
            ],
            "SEK" => [
                "name" => "Swedish Krona",
                "code" => "SEK",
            ],
            "SGD" => [
                "name" => "Singapore Dollar",
                "code" => "SGD",
            ],
            "SHP" => [
                "name" => "Saint Helena Pound",
                "code" => "SHP",
            ],
            "SLL" => [
                "name" => "Sierra Leonean Leone",
                "code" => "SLL",
            ],
            "SOS" => [
                "name" => "Somali Shilling",
                "code" => "SOS",
            ],
            "SRD" => [
                "name" => "Surinamese Dollar",
                "code" => "SRD",
            ],
            "STD" => [
                "name" => "São Tomé and Príncipe dobra",
                "code" => "STD",
            ],
            "SVC" => [
                "name" => "Salvadoran Colón",
                "code" => "SVC",
            ],
            "SYP" => [
                "name" => "Syrian Pound",
                "code" => "SYP",
            ],
            "SZL" => [
                "name" => "Swazi Lilangeni",
                "code" => "SZL",
            ],
            "THB" => [
                "name" => "Thai Baht",
                "code" => "THB",
            ],
            "TJS" => [
                "name" => "Tajikistani Somoni",
                "code" => "TJS",
            ],
            "TMT" => [
                "name" => "Turkmenistani Manat",
                "code" => "TMT",
            ],
            "TND" => [
                "name" => "Tunisian Dinar",
                "code" => "TND",
            ],
            "TOP" => [
                "name" => "Tongan Paʻanga",
                "code" => "TOP",
            ],
            "TRY" => [
                "name" => "Turkish Lira",
                "code" => "TRY",
            ],
            "TTD" => [
                "name" => "Trinidad and Tobago Dollar",
                "code" => "TTD",
            ],
            "TWD" => [
                "name" => "New Taiwan Dollar",
                "code" => "TWD",
            ],
            "TZS" => [
                "name" => "Tanzanian Shilling",
                "code" => "TZS",
            ],
            "UAH" => [
                "name" => "Ukrainian Hryvnia",
                "code" => "UAH",
            ],
            "UGX" => [
                "name" => "Ugandan Shilling",
                "code" => "UGX",
            ],
            "USD" => [
                "name" => "US Dollar",
                "code" => "USD",
            ],
            "UYU" => [
                "name" => "Uruguayan Peso",
                "code" => "UYU",
            ],
            "UZS" => [
                "name" => "Uzbekistan Som",
                "code" => "UZS",
            ],
            "VEF" => [
                "name" => "Venezuelan Bolívar",
                "code" => "VEF",
            ],
            "VND" => [
                "name" => "Vietnamese Dong",
                "code" => "VND",
            ],
            "VUV" => [
                "name" => "Vanuatu Vatu",
                "code" => "VUV",
            ],
            "WST" => [
                "name" => "Samoan Tala",
                "code" => "WST",
            ],
            "XAF" => [
                "name" => "CFA Franc BEAC",
                "code" => "XAF",
            ],
            "XAG" => [
                "name" => "Silver Ounce",
                "code" => "XAG",
            ],
            "XAU" => [
                "name" => "Gold Ounce",
                "code" => "XAU",
            ],
            "XCD" => [
                "name" => "East Caribbean Dollar",
                "code" => "XCD",
            ],
            "XDR" => [
                "name" => "Special drawing rights",
                "code" => "XDR",
            ],
            "XOF" => [
                "name" => "CFA Franc BCEAO",
                "code" => "XOF",
            ],
            "XPF" => [
                "name" => "CFP Franc",
                "code" => "XPF",
            ],
            "YER" => [
                "name" => "Yemeni Rial",
                "code" => "YER",
            ],
            "ZAR" => [
                "name" => "South African Rand",
                "code" => "ZAR",
            ],
            "ZMK" => [
                "name" => "Zambian Kwacha",
                "code" => "ZMK",
            ],
            "ZMW" => [
                "name" => "Zambian Kwacha",
                "code" => "ZMW",
            ],
            "ZWL" => [
                "name" => "Zimbabwean dollar",
                "code" => "ZWL",
            ]
        ];
    }

    protected function processLine($record, $results, $notNullColumns, $optionalForeignKeys) {

        $isValidColumn = true;
        $isValidForeignRecord = true;
        $is_valid_user_field = true;
        $isValidTypeField = true;
        $is_valid_currency_field = true;
        $is_valid_custom_fields = true;
        $is_valid_date_field = true;
        $is_valid_email_field = true;
        $status = 'error';
        $record['company_id'] = $this->companyId;

        foreach ($record as $key => $value) {
            if (str_contains(',', $value)) {
                $newValue = str_replace(",", "", $value);
                if (is_numeric($newValue)) {
                    $record[$key] = $newValue;
                }
            }
        }

        // ---- Check blank values in not null columns
        foreach ($notNullColumns as $column) {
            if ($column != 'id') {
                if ((empty($$record[$column]) || !isset($record[$column])) && $record[$column] !== 0) {
                    $results['field_error'][] = [
                        'field' => $column,
                        'message' => "Invalid $column",
                        'value' => "",
                        'row' => $this->lineIndex
                    ];
                    $isValidColumn = false;
                }
            }
        }

        //---- Fill optional foreign keys
        foreach ($optionalForeignKeys as $key2 => $key_value) {
            if (!key_exists($key_value, $record) || empty($record[$key_value])) {
                $record[$key_value] = null;
            }
        }

        //----- Check foreign records
        $foreignRecordsCheck = $this->checkForeignRecord($record, $this->defaultTables[$this->importType]['table']);

        if (!empty($foreignRecordsCheck)) {
            foreach ($foreignRecordsCheck as $foreignRecord) {

                if($this->defaultTables[$this->importType]['object_type'] === 'opportunity' && $foreignRecord['field'] === 'contact_id') {
                    continue;
                } else {
                    if (!$foreignRecord['exist']) {
                        $results['field_error'][] = [
                            'field' => $foreignRecord['field'],
                            'message' => "Invalid {$foreignRecord['field']}",
                            'value' => $foreignRecord['id'],
                            'row' => $this->lineIndex
                        ];
                        $isValidForeignRecord = false;
                    }
                }
            }
        }

        //---- Validate object type options
        $objectTypeField = $this->objectTypeOptions[$this->importType]['field'];
        $object_type_options = $this->objectTypeOptions[$this->importType]['options'];
        if (key_exists($objectTypeField, $record)) {
            if (!in_array($record[$objectTypeField], $object_type_options)) {
                $results['field_error'][] = [
                    'field' => $objectTypeField,
                    'message' => "Invalid $objectTypeField",
                    'value' => $record[$objectTypeField],
                    'row' => $this->lineIndex
                ];
                $isValidTypeField = false;
            }
        }
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
}

