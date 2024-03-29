<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\ApiController;
use App\Jobs\SendBulkEmails;
use App\Models\MarketingEmails;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmailSendController extends ApiController
{
    protected $lowLoadAmount = 100;
    protected $midLoadAmount = 500;
    protected $emailsPerCycle;
    protected $queuesNumber = 3;
    protected $defaultEmailLimit = 5000;

    public function index($companyId, $batchID)
    {
        $query = MarketingEmails::query();
        $query->where('company_id', '=', $companyId)
            ->where('batch', '=', $batchID)
            ->where('sent_at', null)
            ->select('id', 'available_at');

        $result = $query->get();
        $emailIds = $result->pluck('id')->toArray();
        $available_at = $result[0]->available_at;
        $sendDate = Carbon::createFromFormat('Y-m-d H:i:s', $available_at);

        //Check company email limit
        $emailsSent = DB::table('wp_ks_marketing_emails', 'me')
            ->selectRaw('COUNT(*) as count')
            ->where('me.company_id', $companyId)
            ->where('me.status', 'success')
            ->whereRaw('MONTH(me.sent_at) = MONTH(NOW())')
            ->value('count');

        $emailLimit = DB::table('wp_postmeta')
            ->where('post_id', $companyId)
            ->where('meta_key', 'email_limit')
            ->value('meta_value');

        $emailLimit = $emailLimit != '' ? $emailLimit : $this->defaultEmailLimit;
        $emailsLeft = $emailLimit - $emailsSent;

        if($emailsLeft > 0) {
            $emailsCount = count($emailIds);
    
            if ($emailsCount < $this->lowLoadAmount) {
                if($emailsLeft > $emailsCount) {
                    dispatch(new SendBulkEmails($companyId, $emailIds, $batchID))->delay($sendDate)->onQueue("high");
                } else {
                    $email_jobs = array_chunk($emailIds, $emailsLeft);
                    dispatch(new SendBulkEmails($companyId, $email_jobs[0], $batchID))->delay($sendDate)->onQueue("high");
                }
            } else if ($emailsCount < $this->midLoadAmount) {
                if($emailsLeft > $emailsCount) {
                    dispatch(new SendBulkEmails($companyId, $emailIds, $batchID))->delay($sendDate)->onQueue("medium");
                } else {
                    $email_jobs = array_chunk($emailIds, $emailsLeft);
                    dispatch(new SendBulkEmails($companyId, $email_jobs[0], $batchID))->delay($sendDate)->onQueue("medium");
                }
            } else {
                if($emailsLeft > $emailsCount) {
                    $this->emailsPerCycle = ceil($emailsCount / $this->queuesNumber);
                } else {
                    $this->emailsPerCycle = ceil($emailsLeft / $this->queuesNumber);
                }
                $email_jobs = array_chunk($emailIds, $this->emailsPerCycle);
                foreach ($email_jobs as $key => $ids) {
                    dispatch(new SendBulkEmails($companyId, $ids, $batchID))->delay($sendDate)->onQueue("low-$key");
                }
            }

            return $this->responseJson(['message' => 'Emails are being sent']);
        } else {
            return $this->responseJson(['message' => 'Email limit reached, no more emails can be sent.']);
        }
    }
}
