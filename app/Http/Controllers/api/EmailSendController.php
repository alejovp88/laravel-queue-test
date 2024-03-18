<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\ApiController;
use App\Jobs\SendBulkEmails;
use App\Models\MarketingEmails;

class EmailSendController extends ApiController
{
    protected $lowLoadAmount = 100;
    protected $midLoadAmount = 1000;
    protected $emailsPerCycle;
    protected $queuesNumber = 3;

    public function index($companyId = 163, $batchID = 168)
    {
        $query = MarketingEmails::query();
        $query->where('company_id', '=', $companyId)
            ->where('batch', '=', $batchID)
            ->select('id');

        $emailIds = $query->get()->toArray();

        // TODO: Check company email limit to see if we can send emails or just some of them (close to the limit)
        $emailsCount = 0;

        if ($emailsCount < $this->lowLoadAmount) {
            dispatch(new SendBulkEmails($companyId, $emailIds))->onQueue('high');
        } else if ($emailsCount < $this->midLoadAmount) {
            dispatch(new SendBulkEmails($companyId, $emailIds))->onQueue('medium');
        } else {
            $this->emailsPerCycle = ceil($emailsCount / $this->queuesNumber);
            $email_jobs = array_chunk($emailIds, $this->emailsPerCycle);
            foreach ($email_jobs as $key => $ids) {
                dispatch(new SendBulkEmails($companyId, $ids))->onQueue("low-$key");
            }
        }

        return $this->responseJson(['message' => 'Emails are being sent']);
    }
}
