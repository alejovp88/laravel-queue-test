<?php

namespace App\Http\Controllers;

use App\Jobs\SendBulkEmails;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    protected $lowLoadAmount = 100;
    protected $midLoadAmount = 1000;
    protected $emailsPerCycle = 1000;

    public function index($companyId, $batchID)
    {
        $companyId = 163;
        $query = User::query();//168
        $query->where('company_id', '=', $companyId)
            ->where('batch', '=', $batchID)
            ->select('id');

        $emailIds = $query->get()->toArray();
        $emailsCount = count($emailIds);

        if ($emailsCount < $this->lowLoadAmount) {
            dispatch(new SendBulkEmails($companyId, $emailIds))->onQueue('high');
        } else if ($emailsCount < $this->midLoadAmount) {
            dispatch(new SendBulkEmails($companyId, $emailIds))->onQueue('medium');
        } else {
            $email_jobs = array_chunk($emailIds, $this->emailsPerCycle);
            foreach ($email_jobs as $ids) {
                dispatch(new SendBulkEmails($companyId, $ids))->onQueue('low');
            }
        }

        return response()->json(['message' => 'Emails are being sent']);
    }
}
