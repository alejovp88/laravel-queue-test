<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\ApiController;
use App\Models\CSVImport;
use App\Jobs\CSVBulkImport;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CSVImportController extends ApiController
{
    protected $csvPendingStatus = "pending";
    protected $lowImportAmount = 500;
    protected $midImportAmount = 1500;
    protected $highImportQueuesNumber = 4;
    protected $excludeHeaderOffset = 2;

    function importCSV($companyId) {
        $lineCounter = 0;
        $query = CSVImport::query();

        $query->where('company_id', '=', $companyId)
            ->whereRaw("status = '{$this->csvPendingStatus}'")
            ->select([
                'field_map',
                'csv_file',
                'name',
                'type',
                'user_id',
                'results',
                'id'/*,
                'opt_status',
                'opt_full_name'*/
            ]);

        $record = $query->first();

        if($record) {
            //$fileName = "{$record->csv_file}/{$record->name}";
            $fileName = "{$record->csv_file}";
            $csvFile = fopen($fileName, "r");
            $userId = $record->user_id;

            for($i = 1; fgetcsv($csvFile); $i++) {
                $lineCounter++;
            }

            if($lineCounter > 0) {
                $jobParams = [
                    'importType' => $record->type,
                    'fileName' => $fileName,
                    'offSet' => $this->excludeHeaderOffset,
                    'numberOfRecords' => $lineCounter,
                    'fieldsMap' => $record->field_map,
                    'companyId' => $companyId,
                    'userId' => $userId,
                    'csvId' => $record->id,
                    'opt_status' => 'Opted-In',//$record->opt_status,
                    'opt_full_name' => 'Alejandro Vargas'//$record->opt_full_name
                ];

                Log::info("Total CSV lines: $lineCounter");
                if($lineCounter <= $this->lowImportAmount) {
                    Log::info("Low Import Queue Selected");
                    dispatch(new CSVBulkImport($jobParams))->onQueue('csv-low');
                } elseif ($lineCounter <= $this->midImportAmount) {
                    Log::info("Medium Import Queue Selected");
                    dispatch(new CSVBulkImport($jobParams))->onQueue('csv-medium');
                } else {
                    Log::info("High Import Queue Selected");
                    $recordsPerQueue = ceil($lineCounter/$this->highImportQueuesNumber);
                    for ($queue = 0; $queue < $this->highImportQueuesNumber; $queue++) {
                        $offset = ($queue * $recordsPerQueue) + $this->excludeHeaderOffset;
                        $jobParams['offSet'] = $offset;
                        dispatch(new CSVBulkImport($jobParams))->onQueue("csv-high-{$queue}");
                    }
                }
            } else {
                $record->results = json_encode(['message' => 'Invalid CSV']);
                $record->save();
            }
        } else {
            $this->responseJsonWithError('There no pending CSV import file');
        }
    }
}
