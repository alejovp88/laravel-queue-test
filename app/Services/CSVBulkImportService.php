<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMeta;
use App\Models\Contact;
use App\Models\ContactMeta;
use App\Models\Opportunity;
use App\Models\OpportunityMeta;

class CSVBulkImportService
{
    protected $userService;
    protected $ksActivityService;
    public function __construct(UserService $userService, KSActivityService $ksActivityService) {
        $this->userService = $userService;
        $this->ksActivityService = $ksActivityService;
    }

    public function ksCreateContactActivity($contactInfo, $contactId, $action, $userId = '', $viaSync = false) {
        if (is_null($userId)) {
            $userInfo['user_id'] = 0;
            $userInfo['company_id'] = $contactInfo['company_id'] ?? 0;
        } else {
            $userInfo = $this->userService->ksCurrentUserInfo($userId);
        }

        if ($action == 'updated') {
            $value = "";
            $currentContact = Contact::find($contactId)->toArray();

            foreach ($contactInfo as $key => $item) {
                if (in_array($key, ['last_modified', 'last_modified_by', 'custom', 'autotask_id', 'user_id'])) {
                    continue;
                }

                if ($item != $currentContact[$key]) {
                    $fieldUpdated = str_replace('_', ' ', ucwords($key, '_'));
                    $label      = "{$fieldUpdated} updated";
                    $value = $item;

                    if ($key == 'contact_photo') {
                        $label = "Photo updated";
                    } else if ($key == 'account_id') {
                        $label = "Account updated";
                    } else if ($key == 'lead_stage') {
                        $colorType = '';
                        if ($value == 'Customer') {
                            $colorType = 'ks-bg--CRM-Yellow';
                        } else if ($value == 'Sales Qualified Lead') {
                            $colorType = 'ks-bg--CRM-Blue';
                        } else if ($value == 'Marketing Qualified Lead') {
                            $colorType = 'ks-bg--CRM-Green';
                        } else if ($value == 'Prospect') {
                            $colorType = 'ks-bg--CRM-Pink';
                        }
                        $label = "Moved Lead Stage to <div class=" . '"' . "color-status {$colorType}" . '"' . "></div> {$item}";
                        $value = "";
                    }

                    $info = [
                        'post_id'    => $contactId,
                        'field'      => $key,
                        'label'      => $label,
                        'value'      => $value,
                        'type'       => 'contact',
                        'created_by' => $userInfo['user_id'],
                        'company_id' => $userInfo['company_id'],
                        'action'     => $this->ksActivityService->UPDATE
                    ];
                    if ($viaSync) {
                        $info['source'] = 'Autotask';
                    }

                    $this->ksActivityService->insertCrmActivity($info);

                }
            }

            if (isset($contactInfo['custom']) && count($contactInfo['custom']) > 0) {
                $currentMetaValues = ContactMeta::where('contact_id', '=', $contactId)->get()->toArray();

                foreach ($contactInfo['custom'] as $key => $meta) {
                    if (!is_array($meta)) {
                        continue;
                    }
                    $pos = array_search($meta['name'], array_column($currentMetaValues, 'name'));

                    if ($pos !== false && $value != '0' && $meta['value'] != $currentMetaValues[$pos]['value']) {
                        $label      = "{$meta['label']} updated";
                        $value = $meta['value'];

                        if ($pos == false && ($value == "" || $value == NULL || $value == "0")) {
                            continue;
                        }

                        if (isset($meta['format'])) {
                            $newFormat = $meta['format'];
                            if ($newFormat == 'MM/DD/YYYY') {
                                $newFormat = 'm/d/Y';
                            } else if ($newFormat == 'DD/MM/YYYY') {
                                $newFormat = 'd/m/Y';
                            } else if ($newFormat == 'YYYY/MM/DD') {
                                $newFormat = 'Y/m/d';
                            }
                            $value = date($newFormat, strtotime($meta['value']));
                        }

                        $info = [
                            'post_id'    => $contactId,
                            'field'      => $meta['name'],
                            'label'      => $label,
                            'value'      => $value,
                            'type'       => 'contact',
                            'created_by' => $userInfo['user_id'],
                            'company_id' => $userInfo['company_id'],
                            'action'     => $this->ksActivityService->UPDATE
                        ];

                        $this->ksActivityService->insertCrmActivity($info);
                    }
                }
            }

            return [
                'success' => true
            ];
        } else {
            $label = 'Contact Created';
            if ($userInfo['user_id'] == 0 && isset($contactInfo['landing_page'])) {
                $label = 'Contact created via landing page ' . $contactInfo['landing_page'];
            } else if ($userInfo['user_id'] == 0 && isset($contactInfo['imported']) && $contactInfo['imported'] == 1) {
                $label = 'Contact created via import';
            }
            $info = [
                'post_id'    => $contactId,
                'field'      => "",
                'label'      => $label,
                'value'      => "",
                'type'       => 'contact',
                'created_by' => $userInfo['user_id'],
                'company_id' => $userInfo['company_id'],
                'action'     => $this->ksActivityService->CREATE
            ];
            if ($viaSync) {
                $info['source'] = 'Autotask';
            }

            return $this->ksActivityService->insertCrmActivity($info);
        }
    }

    public function ksCreateAccountActivity($userId, $accountInfo, $accountId, $action, $viaSync = false): array {
        $userInfo = $this->userService->ksCurrentUserInfo($userId);
        $userInfo['company_id'] = $accountInfo['company_id'] ?? $userInfo['company_id'];

        if ($action == 'updated') {
            $value = "";
            $currentAccount = Account::find($accountId)->toArray();

            foreach ($accountInfo as $key => $item) {
                if (in_array($key, ['last_modified', 'last_modified_by', 'custom', 'autotask_id'])) {
                    continue;
                }

                if ($item != $currentAccount[$key]) {
                    $fieldUpdated = str_replace('_', ' ', ucwords($key, '_'));
                    $label      = "{$fieldUpdated} updated";
                    $value = $item;
                    if ($key == 'contact_photo') {
                        $label = "Photo updated";
                    } else if ($key == 'type') {
                        $colorType = $item == 'Customer' ? 'ks-bg--CRM-Blue' : 'ks-bg--CRM-Pink';
                        $label = "Moved Account Type to <div class=" . '"' . "color-status {$colorType}" . '"' . "></div> {$item}";
                        $value = "";
                    }

                    $info = [
                        'post_id'    => $accountId,
                        'field'      => $key,
                        'label'      => $label,
                        'value'      => $value,
                        'type'       => 'account',
                        'created_by' => $userInfo['user_id'],
                        'company_id' => $userInfo['company_id'],
                        'action'     => $this->ksActivityService->UPDATE,
                    ];
                    if ($viaSync) {
                        $info['source'] = 'Autotask';
                    }

                    $this->ksActivityService->insertCrmActivity($info);
                }
            }

            if (isset($accountInfo['custom']) && count($accountInfo['custom']) > 0) {
                $currentMetaValues = AccountMeta::where('account_id', '=', $accountId)->get()->toArray();

                foreach ($accountInfo['custom'] as $key => $meta) {
                    $pos = array_search($meta['name'], array_column($currentMetaValues, 'name'));

                    if ($pos !== false && $value != '0' && $meta['value'] != $currentMetaValues[$pos]['value']) {
                        $label      = "{$meta['label']} updated";
                        $value = $meta['value'];

                        if ($pos == false && ($value == "" || $value == NULL || $value == "0")) {
                            continue;
                        }

                        if (isset($meta['format'])) {
                            $newFormat = $meta['format'];
                            if ($newFormat == 'MM/DD/YYYY') {
                                $newFormat = 'm/d/Y';
                            } else if ($newFormat == 'DD/MM/YYYY') {
                                $newFormat = 'd/m/Y';
                            } else if ($newFormat == 'YYYY/MM/DD') {
                                $newFormat = 'Y/m/d';
                            }
                            $value = date($newFormat, strtotime($meta['value']));
                        }

                        $info = [
                            'post_id'    => $accountId,
                            'field'      => $meta['name'],
                            'label'      => $label,
                            'value'      => $value,
                            'type'       => 'account',
                            'created_by' => $userInfo['user_id'],
                            'company_id' => $userInfo['company_id'],
                            'action'     => $this->ksActivityService->UPDATE,
                        ];

                        $this->ksActivityService->insertCrmActivity($info);
                    }
                }
            }

            return [
                'success' => true
            ];
        } else {
            $label = 'Account Created';
            if ($userInfo['user_id'] == 0 && isset($accountInfo['imported']) && $accountInfo['imported'] == 1) {
                $label = 'Account Created via import';
            }

            $info = [
                'post_id'    => $accountId,
                'field'      => "",
                'label'      => $label,
                'value'      => "",
                'type'       => 'account',
                'created_by' => $userInfo['user_id'],
                'company_id' => $userInfo['company_id'],
                'action'     => $this->ksActivityService->CREATE,
            ];
            if ($viaSync) {
                $info['source'] = 'Autotask';
            }

            return $this->ksActivityService->insertCrmActivity($info);
        }
    }

    function ksCreateOpportunityActivity($userId, $opportunityInfo, $opportunityId, $action): array
    {
        $userInfo      = $this->userService->ksCurrentUserInfo($userId);

        $opportunity = new Opportunity();
        $stage = $opportunity->getStages($opportunityInfo['stage'], $opportunityInfo['company_id'])->get(0);
        $amount = $opportunityInfo['amount'];
        $companyId = $opportunityInfo['company_id'] ?? $userInfo['company_id'];

        if ($action == 'updated') {
            $value          = "";
            $currentAccount = Opportunity::find($opportunityId)->toArray();

            foreach ($opportunityInfo as $key => $item) {
                if (in_array($key, ['last_modified', 'last_modified_by', 'custom'])) {
                    continue;
                }

                if ($item != $currentAccount[$key]) {
                    $fieldUpdated = str_replace('_', ' ', ucwords($key, '_'));
                    $label        = "{$fieldUpdated} updated";
                    $value        = $item;

                    if ($key == 'stage') {
                        $label = 'Moved opportunity stage to  <div class="ks-gap d-flex align-items-center"> <div class="ks-ellipsis" style="background-color: ' . $stage['stage_color'] . '"></div> <span class="ks-text--sm-rg ks-text--Gray-120"> ' . $stage['stage_name'] . ' </span>  </div> ';
                        $value = "";
                        if($stage['stage_value'] == 'Closed Won' || $stage['stage_value'] == 'Closed Lost'){
                            $values = [
                                'created_by' => $userInfo['user_id'],
                                "object"     => 'opportunity',
                                "object_id"  => $opportunityId,
                                "action"     => '[username] closed the opportunity: [oppName] for a revenue of [amount]',
                                "params"     => ['amount' => $amount],
                                "company_id" => $companyId
                            ];
                        }else{
                            $values = [
                                'created_by' => $userInfo['user_id'],
                                "object"     => 'opportunity',
                                "object_id"  => $opportunityId,
                                "action"     => '[username] moved the opportunity: [oppName] to '.$stage['stage_name'],
                                "company_id" => $companyId
                            ];
                        }
                        /*
                        * generate the log for the dashboard component
                        */
                        $this->ksActivityService->insertCrmActivity($values);
                    } elseif ($key == 'account_id') {
                        $label = "Account updated";
                    } elseif ($key == 'contact_id') {
                        $label = "Contact updated";

                        $resp = Contact::find($value);
                        if (isset($resp)) {
                            $value = $resp->first_name . ' ' . $resp->last_name;
                        } else {
                            $value = null;
                        }
                    }

                    if (isset($meta['format'])) {
                        $newFormat = $meta['format'];
                        if ($newFormat == 'MM/DD/YYYY') {
                            $newFormat = 'm/d/Y';
                        } elseif ($newFormat == 'DD/MM/YYYY') {
                            $newFormat = 'd/m/Y';
                        } elseif ($newFormat == 'YYYY/MM/DD') {
                            $newFormat = 'Y/m/d';
                        }
                        $value = date($newFormat, strtotime($meta['value']));
                    }

                    $info = [
                        'post_id'    => $opportunityId,
                        'field'      => $key,
                        'label'      => $label,
                        'value'      => $value,
                        'type'       => 'opportunity',
                        'created_by' => $userInfo['user_id'],
                        'company_id' => $companyId,
                        'action'     => $this->ksActivityService->UPDATE
                    ];

                    return $this->ksActivityService->insertCrmActivity($info);
                }
            }

            if (isset($opportunityInfo['custom']) && count($opportunityInfo['custom']) > 0) {
                $currentMetaValues = OpportunityMeta::where('opportunity_id', '=', $opportunityId)->get()->toArray();

                foreach ($opportunityInfo['custom'] as $key => $meta) {
                    $pos = array_search($meta['name'], array_column($currentMetaValues, 'name'));

                    if ($value != '0' && $meta['value'] != $currentMetaValues[$pos]['value']) {
                        $label = "{$meta['label']} updated";
                        $value = $meta['value'];

                        if (!$pos && ($value == "" || $value == null || $value == "0")) {
                            continue;
                        }

                        $info  = [
                            'post_id'    => $opportunityId,
                            'field'      => $meta['name'],
                            'label'      => $label,
                            'value'      => $value,
                            'type'       => 'opportunity',
                            'created_by' => $userInfo['user_id'],
                            'company_id' => $userInfo['company_id'],
                            'action'     => $this->ksActivityService->UPDATE
                        ];

                        $this->ksActivityService->insertCrmActivity($info);
                    }
                }
            }

            return [
                'success' => true
            ];
        } else {
            $label = 'Opportunity Created';
            if ($userInfo['user_id'] == 0 && isset($opportunityInfo['imported']) && $opportunityInfo['imported'] == 1) {
                $label = 'Opportunity Created via import';
            }

            $info = [
                'post_id'    => $opportunityId,
                'field'      => "",
                'label'      => $label,
                'value'      => "",
                'type'       => 'opportunity',
                'created_by' => $userInfo['user_id'],
                'company_id' => $companyId,
                'action'     => $this->ksActivityService->CREATE
            ];

            $values = [
                'created_by' => $userInfo['user_id'],
                "object"     => 'opportunity',
                "object_id"  => $opportunityId,
                "action"     => '[username] created a new opportunity: [oppName]',
                "company_id" => $companyId
            ];
            /*
            * generate the log for the dashboard component
            */
            $this->ksActivityService->insertActivityLog($values);

            return $this->ksActivityService->insertCrmActivity($info);
        }
    }

    public function getAllCurrencies()
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
}
