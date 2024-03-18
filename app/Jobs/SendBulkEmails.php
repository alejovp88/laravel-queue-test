<?php

namespace App\Jobs;

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

    private string $sendgridApiKey;

    private string $primaryMarketingEmail;

    private array $marketingEmailIds;

    /**
     * Create a new job instance.
     */
    public function __construct(int $companyId, array $marketingEmailIds)
    {
        // Set company ID
        $this->companyId = $companyId;

        // Set marketing email IDs
        $this->marketingEmailIds = $marketingEmailIds;

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

        // TODO: Check company email limit to see if we can send emails or just some of them (close to the limit)

        // Instance SendGrid
        $sendgrid = new \SendGrid($this->sendgridApiKey);

        // Send emails
        try {
            $emailsToSend = $this->getMarketingEmails();

            $sentEmails = [];
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
                    $sentEmails[] = $emailToSend->id;
                } else {
                    Log::error("Error sending email {$emailToSend->id} to {$emailToSend->email_address}");
                }
            }

            // Update email status (one single query)
            DB::table($this->dbPrefix . 'ks_marketing_emails')
                ->whereIn('id', $sentEmails)
                ->update(['status' => 'success', 'sent_at' => now()]);

            // TODO: Update email limit, notifications, etc. and log the result (see wordpress repository, file: wp-content/themes/CFtheme/framework/classes/Emails.php:363)
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
            ->where('me.sent_at', null)
            ->get();
    }
}
