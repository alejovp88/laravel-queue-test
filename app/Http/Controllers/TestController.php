<?php

namespace App\Http\Controllers;

use App\Jobs\SendBulkEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    //
    public function index()
    {
        $company_id = 163;
        $email_ids = [198]; // 100

        if (count($email_ids) < 100) {
            dispatch(new SendBulkEmails($company_id, $email_ids))->onQueue('high');
        } else if (count($email_ids) < 1000) {
            dispatch(new SendBulkEmails($company_id, $email_ids))->onQueue('medium');
        } else {
            $email_jobs = array_chunk($email_ids, 1000); // 1000 emails per job
            foreach ($email_jobs as $ids) {
                dispatch(new SendBulkEmails($company_id, $ids))->onQueue('low');
            }
        }

        return response()->json(['message' => 'Emails are being sent']);
    }
}
