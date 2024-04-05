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

        /*$fileName = "/var/Projects/littleTaller/csvSqlImport/One-Safe-Place-Former-Customer.csv";
        $customFieldValue = "Former Customer";*/
        /*$fileName = "/var/Projects/littleTaller/csvSqlImport/One-Safe-Place-Prospects.csv";
        $customFieldValue = "Prospect";*/
        /*$fileName = "/var/Projects/littleTaller/csvSqlImport/One-Safe-Place-TapeVaulting-Customer-Contact-List.csv";
        $customFieldValue = "TapeVaulting";*/
        $fileName = "/var/Projects/littleTaller/csvSqlImport/OSP-Customer-Contact-List-All-Import-to-PSP.csv";
        $customFieldValue = "Customer";

        $csvFile = fopen($fileName, "r");
        $sqlInsertFile = substr($fileName, 0, -4) . "-insert.sql";
        $sqlFile = fopen("{$sqlInsertFile}", "w+");

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
        $duplicatedIds = [];
        $emailIndex = null;

        for($i = 1; $line = fgetcsv($csvFile); $i++) {
            if($i == 1) {
                $headerFields = $line;
            } else {
                foreach ($fieldRules as $field) {
                    if($field->IS_NULLABLE === 'NO') {
                        $index = array_search($field->COLUMN_NAME, $headerFields);
                        $line[$index] = trim($line[$index]);
                        if($line[$index] === "") {
                            continue;
                        }
                        if($index !== false) {
                            if($field->DATA_TYPE === 'varchar') {
                                $value = str_replace("'", "''", $line[$index]);
                                $value = str_replace("á", "a", $value);
                                $value = str_replace("é", "e", $value);
                                $value = str_replace("í", "i", $value);
                                $value = str_replace("ó", "o", $value);
                                $value = str_replace("ú", "u", $value);
                                $record[$field->COLUMN_NAME] = "'$value'";
                            } else {
                                $record[$field->COLUMN_NAME] = $line[$index];
                            }

                            if ($field->COLUMN_NAME === 'email_address') {
                                $emailIndex = $index;
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
                        $line[$index] = trim($line[$index]);
                        if($index !== false) {
                            if($line[$index] === "") {
                                $record[$field->COLUMN_NAME] = 'NULL';
                            } else {
                                if($field->DATA_TYPE === 'varchar') {
                                    $value = str_replace("'", "''", $line[$index]);
                                    $value = str_replace("á", "a", $value);
                                    $value = str_replace("é", "e", $value);
                                    $value = str_replace("í", "i", $value);
                                    $value = str_replace("ó", "o", $value);
                                    $value = str_replace("ú", "u", $value);
                                    $record[$field->COLUMN_NAME] = "'$value'";
                                } else {
                                    $record[$field->COLUMN_NAME] = $line[$index];
                                }
                            }
                        } else {
                            if($field->COLUMN_NAME === 'user_id') {
                                $record[$field->COLUMN_NAME] = 1282;
                            }
                        }
                    }
                }

                if ($index = array_search("company", $headerFields)) {
                    if(strpos($line[$index], "'")) {
                        $name = str_replace("'", "''", $line[$index]);
                    } else {
                        $name = $line[$index];
                    }
                    $account = Account::whereRaw("name LIKE '%$name%'")
                        ->where('company_id', '=', 3347)
                        ->select('id')
                        ->first();

                    if ($account) {
                        $record['account_id'] = $account->id;
                    } else {
                        $record['account_id'] = 'NULL';
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

                $user = DB::TABLE("wp_ks_contacts")
                            ->whereRaw("email_address LIKE '%{$line[$emailIndex]}%'")
                            ->where('company_id', '=', 3347)
                            ->first();
                if($user) {
                    $duplicatedIds[] = $user->id;
                } else {
                    fwrite($sqlFile, "$message\n");
                }
            }

        }

        $duplicatedIds = implode(",", $duplicatedIds);

        $optQuery = "INSERT INTO wp_ks_contacts_opt(contact_id, user_id, full_name, company_id)
                         SELECT id, 1282, 'Rick Baird', 3347
                           FROM wp_ks_contacts
                          WHERE id > X;";
        fwrite($sqlFile, "$optQuery\n");

        $metaQuery = "DELETE FROM wp_ks_contacts_meta
                            WHERE name = 'segment'
                              AND contact_id IN ($duplicatedIds);";
        fwrite($sqlFile, "$metaQuery\n");

        $metaQuery = "INSERT INTO wp_ks_contacts_meta(contact_id, `name`, `value`, last_modified, last_modified_by)
                        SELECT id, 'segment', '$customFieldValue', CURRENT_TIMESTAMP(), 1282
                          FROM wp_ks_contacts
                         WHERE id > X;";
        fwrite($sqlFile, "$metaQuery\n");

        /** duplicated records */
        $metaQuery = "INSERT INTO wp_ks_contacts_meta(contact_id, `name`, `value`, last_modified, last_modified_by)
                        SELECT id, 'segment', '$customFieldValue', CURRENT_TIMESTAMP(), 1282
                          FROM wp_ks_contacts
                         WHERE id IN ($duplicatedIds);";
        fwrite($sqlFile, "$metaQuery\n");

    }
}
