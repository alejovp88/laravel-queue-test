<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\ApiController;
use App\Models\CSVImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CSVImportController extends ApiController
{
    protected $maxLines = 1500;
    protected $csvPendingStatus = "pending";

    function importCSV($companyId) {
        $query = CSVImport::query();

        $query->where('company_id', '=', $companyId)
            ->whereRaw("status = '{$this->csvPendingStatus}'")
            ->select([
                'field_map',
                'csv_file',
                'name',
                'type'
            ]);

        $csvFile = $query->first();

        if($csvFile) {
            $f = fopen("{$csvFile->csv_file}/{$csvFile->name}", "r");

            for($i = 1; $line = fgetcsv($f); $i++) {

            }
        } else {
            $this->responseJsonWithError('akljhalkjsd', '');
        }
    }
}
