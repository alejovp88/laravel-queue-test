<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\ApiController;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CSVtoSQLController extends ApiController
{
    public function csvToSQl() {

        $fileName = "/var/Projects/littleTaller/csvSqlImport/One-Safe-Place-Prospects.csv";
        $fileName = "/var/Projects/littleTaller/csvSqlImport/One-Safe-Place-TapeVaulting-Customer-Contact-List.csv";
        $fileName = "/var/Projects/littleTaller/csvSqlImport/OSP-Customer-Contact-List-All-Import-to-PSP.csv";

        $csvFile = fopen($fileName, "r");

        $fieldRules = DB::TABLE("INFORMATION_SCHEMA.COLUMNS")
            ->whereRaw("TABLE_SCHEMA = 'kaseyacommunitydev'")
            ->whereRaw("TABLE_NAME = 'wp_ks_contacts'")
            ->select([
                'COLUMN_NAME',
                'DATA_TYPE',
                'IS_NULLABLE',
                'COLUMN_KEY'
            ])
            ->get();

        foreach ($fieldRules as &$rule) {
            if($rule->COLUMN_NAME === "account") {
                $rule->COLUMN_NAME = "company";
            }
        }

        $messageBase = "INSERT INTO wp_ks_contacts(";
        $headerFields = [];
        $record = [];

        //Log::info(json_decode(json_encode($fieldRules), true));

        for($i = 1; $line = fgetcsv($csvFile); $i++) {
            if($i == 1) {
                $headerFields = $line;
            } else {
                foreach ($fieldRules as $field) {
                    if($field->IS_NULLABLE === 'NO') {
                        $index = array_search($field->COLUMN_NAME, $headerFields);
                        if($index !== false) {
                            if($field->DATA_TYPE === 'varchar') {
                                $record[$field->COLUMN_NAME] = "'{$line[$index]}'";
                            } else {
                                $record[$field->COLUMN_NAME] = $line[$index];
                            }

                            if ($field->COLUMN_NAME === 'email') {
                                $emailInfo = explode("@", $line[$index]);
                                $account = Account::whereRaw("website LIKE '%{$emailInfo[1]}%'")
                                    ->select('id')
                                    ->first();
                                if($account) {
                                    $record['account_id'] = $account->id;
                                }
                            }
                        } else {
                            if($field->COLUMN_NAME === 'last_modified_by') {
                                $record[$field->COLUMN_NAME] = 1282;
                            } elseif ($field->COLUMN_NAME === 'last_modified') {
                                $record[$field->COLUMN_NAME] = 'CURRENT_TIMESTAMP()';
                            } elseif ($field->COLUMN_NAME === 'company_id') {
                                $record[$field->COLUMN_NAME] = 3347;
                            }
                        }
                    } else {
                        $index = array_search($field->COLUMN_NAME, $headerFields);
                        if($index !== false) {
                            if($line[$index] === "") {
                                $record[$field->COLUMN_NAME] = 'NULL';
                            } else {
                                if($field->COLUMN_NAME === 'company') {
                                    if(strpos($line[$index], "'")) {
                                        $name = str_replace("'", "''", $line[$index]);
                                    } else {
                                        $name = $line[$index];
                                    }
                                    $account = DB::TABLE("wp_ks_accounts")
                                        ->whereRaw("name = '$name'")
                                        ->where('company_id', '=', 3347)
                                        ->select('id')
                                        ->first();

                                    if ($account) {
                                        $record['account_id'] = $account->id;
                                    } else {
                                        $record['account_id'] = 'NULL';
                                    }
                                } else {
                                    if($field->DATA_TYPE === 'varchar') {
                                        $record[$field->COLUMN_NAME] = "'{$line[$index]}'";
                                    } else {
                                        $record[$field->COLUMN_NAME] = $line[$index];
                                    }
                                }
                            }
                        } else {
                            if($field->COLUMN_NAME === 'user_id') {
                                $record[$field->COLUMN_NAME] = 1282;
                            }
                        }
                    }
                }

                $record["opt_status"] = "'Opted-In'";

                $fieldsToSql = [];
                $valuesToFieldSql = [];
                foreach ($record as $key => $value) {
                    $fieldsToSql[] = $key;
                    $valuesToFieldSql[] = $value;
                }

                $message = $messageBase;
                $message .= implode(",", $fieldsToSql);
                $message .= ") VALUES";
                $message .= "(";
                $message .= implode(",", $valuesToFieldSql);
                $message .= ");";

                Log::info($message);
            }
            if ($i == 20) {
                break;
            }
        }
    }
}
