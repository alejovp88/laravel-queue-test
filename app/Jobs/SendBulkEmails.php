<?php

namespace App\Jobs;

use App\Models\MarketingEmails;
use App\Models\WPPostMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBulkEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $dbPrefix;

    private int $companyId;

    private int $batchId;

    private string $sendgridApiKey;

    private string $primaryMarketingEmail;

    private array $marketingEmailIds;

    /**
     * Create a new job instance.
     */
    public function __construct(int $companyId, array $marketingEmailIds, int $batchId)
    {
        // Set company ID
        $this->companyId = $companyId;

        // Set marketing email IDs
        $this->marketingEmailIds = $marketingEmailIds;

        // Set batch ID
        $this->batchId = $batchId;

        // Get BD tables prefix (Wordpress tables)
        $this->dbPrefix = env('DB_WP_TABLES_PREFIX') ?? 'wp_';
    }

    /**
     * Execute the job.
     * @throws \Exception
     */
    public function handle(): void
    {
        // Get environment
        $env = env('APP_ENV') ?? 'dev';

        // Load company configuration
        $this->loadConfig();

        // Instance SendGrid
        $sendgrid = new \SendGrid($this->sendgridApiKey);

        // Send emails
        try {
            $emailsToSend = $this->getMarketingEmails();

            $sentEmails = [];
            $totalEmailsSend = 0;
            foreach ($emailsToSend as $emailToSend) {
                $email = new \SendGrid\Mail\Mail();

                //to
                $email->addTo($emailToSend->email_address);

                // From
                $email->setFrom($this->primaryMarketingEmail, html_entity_decode($emailToSend->company_name));
                $email->addContent('text/html', $emailToSend->content);
                $email->setSubject($emailToSend->email_subject);
                $email->addCustomArg('email_map_id', (string) $emailToSend->id);
                $email->addCustomArg('asset_id', (string) $emailToSend->asset_id);
                $email->addCustomArg('company_id', (string) $emailToSend->company_id);
                $email->addCustomArg('contact_id', (string) $emailToSend->contact_id);
                $email->addCustomArg('map_env', $env);

                $resp = $sendgrid->send($email);
                if ($resp->statusCode() == 202) {
                    Log::info("Email sent to {$emailToSend->email_address} with status code: {$resp->statusCode()}");
                    $sentEmails[] = $emailToSend->id;
                    $totalEmailsSend++;
                    $data = [
                        'data' => [
                            'id' => $emailToSend->id,
                            'email_address' => $emailToSend->email_address,
                            'asset_id' => $emailToSend->asset_id,
                            'company_id' => $emailToSend->company_id
                        ],
                        'status' => 'success',
                    ];

                } else {
                    Log::error("Error sending email {$emailToSend->id} to {$emailToSend->email_address}");
                    MarketingEmails::where('id', $emailToSend->id)
                        ->update(['sent_at' => now()]);

                    $data = [
                        'data' => [
                            'id' => $emailToSend->id,
                            'email_address' => $emailToSend->email_address,
                            'company_id' => $emailToSend->company_id
                        ],
                        'status' => 'failed',
                    ];
                }

                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL            => env('API_URL_WP')."emails/after-send",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_MAXREDIRS      => 10,
                    CURLOPT_TIMEOUT        => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST  => 'POST',
                    CURLOPT_POSTFIELDS     => json_encode($data),
                    CURLOPT_HTTPHEADER     => array(
                        'Content-Type: application/json',
                    ),
                ));

                curl_exec($curl);
                curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
            }

            MarketingEmails::whereIn('id', $sentEmails)
                ->update(['status' => 'success', 'sent_at' => now()]);

            //Update emails_sent meta
            $existingMeta = WPPostMeta::where('post_id', $emailToSend->company_id)
                ->where('meta_key', 'emails_sent')
                ->first();

            if ($existingMeta) { // if the meta exists, update it
                $sum = $existingMeta->meta_value + $totalEmailsSend;
                WPPostMeta::where('post_id', $emailToSend->company_id)
                    ->where('meta_key', 'emails_sent')
                    ->update(['meta_value' => $sum]);

            } else { // if the meta doesn't exist, create it
                $emailsSent = MarketingEmails::where('company_id', $emailToSend->company_id)
                    ->where('status', 'success')
                    ->whereRaw('MONTH(me.sent_at) = MONTH(NOW())')
                    ->count();
                $emailsSent = $emailsSent ?? 0;

                WPPostMeta::insert([
                    'post_id' => $emailToSend->company_id,
                    'meta_key' => 'emails_sent',
                    'meta_value' => $emailsSent,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error($th);
        }
    }

    /**
     * @throws \Exception
     */
    private function loadConfig(): void
    {
        // Get company SendGrid API key & Primary marketing email
        $data = DB::table($this->dbPrefix . 'postmeta')->select('meta_key', 'meta_value')
            ->where('post_id', $this->companyId)
            ->whereIn('meta_key', ['sendgrid_api_key', 'primary_contact_email'])->get();

        if ($data->count() < 2) {
            throw new \Exception("Company {$this->companyId} configuration not found");
        }

        // Set SendGrid API key & Primary marketing email
        $this->sendgridApiKey = $data->where('meta_key', 'sendgrid_api_key')->first()->meta_value;
        $this->primaryMarketingEmail = $data->where('meta_key', 'primary_contact_email')->first()->meta_value;
    }

    private function getMarketingEmails(): Collection
    {
        // Get marketing emails
        return DB::table($this->dbPrefix . 'ks_marketing_emails', 'me')
            ->selectRaw('me.*, c.email_address, p.post_title as company_name')
                ->join($this->dbPrefix . 'ks_contacts as c','me.contact_id', '=', 'c.id')
                ->join($this->dbPrefix . 'posts as p','me.company_id', '=', 'p.ID')
            ->where('me.company_id', $this->companyId)
            ->whereIn('me.id', $this->marketingEmailIds)
            ->where('me.batch', $this->batchId)
            ->where('me.sent_at', null)
            ->get();
    }
}
