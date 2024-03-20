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
                'contact_opt' => "{$this->wpDbPrefix}ks_contacts_opt"
            ],
            'Accounts' => [
                'table' => "{$this->wpDbPrefix}ks_accounts",
                'meta_table' => "{$this->wpDbPrefix}ks_accounts_meta"
            ],
            'Opportunities' => [
                'table' => "{$this->wpDbPrefix}ks_contacts",
                'meta_table' => "{$this->wpDbPrefix}ks_contacts_meta",
                'stages_table' => "{$this->wpDbPrefix}ks_crm_opportunities_stages"
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
                switch ($this->importType) {
                    case 'Contacts': {
                        $this->processContacts($line, $successFile, $failedFile);
                        break;
                    }
                    case 'Accounts': {
                        $this->processAccounts($line, $successFile, $failedFile);
                        break;
                    }
                    case 'Opportunities': {
                        $this->processOpportunities($line, $successFile, $failedFile);
                        break;
                    }
                }
            }
        }
    }

    protected function processContacts($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->defaultFields['Contacts']);
        [$fieldRulesFormatted, $notNullColumns, $optionalForeignKeys, $nullColumns] = $this->getFieldsDefinition();
        $validLine = $this->makeFieldsValidation($nullColumns, $failedFile);

        if($validLine) {
            return $record;
        }
    }

    protected function processAccounts($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->defaultFields['Accounts']);
        [$fieldRulesFormatted, $notNullColumns, $optionalForeignKeys, $nullColumns] = $this->getFieldsDefinition();
        $validLine = $this->makeFieldsValidation($nullColumns, $failedFile);

        if($validLine) {
            $allCurrencies = $this->getAllCurrencies();
            return $record;
        }
    }

    protected function processOpportunities($line, $successFile, $failedFile) {
        $record = $this->fillCommonInformation($line, $this->defaultFields['Opportunities']);
        [$fieldRulesFormatted, $notNullColumns, $optionalForeignKeys, $nullColumns] = $this->getFieldsDefinition();
        $validLine = $this->makeFieldsValidation($nullColumns, $failedFile);

        if($validLine) {
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

            return $record;
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
        if (array_search('id', $nullColumns) !== false) {
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

    protected function makeFieldsValidation($nullColumns, $failedFile) {
        if (count($nullColumns) > 0) {
            $results = [];
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

            fputcsv($failedFile, $results);
            return false;
        }
        else {
            return true;
        }
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

    protected function processLine($record) {

        $is_valid_column = true;
        $is_valid_foreign_record = true;
        $is_valid_user_field = true;
        $is_valid_type_field = true;
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
    }
}

