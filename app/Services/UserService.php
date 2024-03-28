<?php

namespace App\Services;

use App\Models\WPPost;
use App\Models\WPUser;
use App\Models\WPUserMeta;
use Illuminate\Support\Facades\Auth;

class UserService
{
    protected $userId;

    public function __construct()
    {

    }

    public function ksCurrentUserInfo($userID = '', $allInfo = false)
    {

        //$this->ks_user_fields();

        $this->userId = $userID != '' ? $userID : $this->userId;


        $currentUser = WPUser::where('ID', '=', $this->userId)->first();
        $meta    = new WPUserMeta();
        $userMeta = $meta->getUserMeta($this->userId);

        if(isset($userMeta['company_id'])) {
            $company = WPPost::where('ID', '=', $userMeta['company_id'])
                ->whereRaw("post_type = 'company'")
                ->first();
            $companyName = $company->post_title;
        } else {
            $companyName = '';
        }

        $user = [
            'user_id'      => $this->userId ?? '',
            'user_email'   => $currentUser->user_email ?? '',
            'first_name'   => $userMeta['first_name']  ?? '',
            'last_name'    => $userMeta['last_name'] ?? '',
            'company_id'   => $userMeta['company_id'] ?? '',
            'role'         => $currentUser->roles ?? '',
            'company_name' => ($companyName !== '') ? html_entity_decode($companyName, ENT_QUOTES, 'UTF-8') : '',
            'nickname'     => $currentUser->user_nicename ?? '',
            'timezone'     => $userMeta['local_time_zone'] ?? '',
            'job_title'    => $userMeta['job_title'] ?? '',
            'cell_phone_number' => $userMeta['cell_phone_number'] ?? '',
            'email_signature' => $userMeta['email_signature'] ?? false,
            'profile_photo_signature' => $userMeta['profile_photo_signature'] ?? false,
            //'profile_photo' => isset($userMeta['profile_photo']) && is_numeric($userMeta['profile_photo']) ? wp_get_attachment_url($userMeta['profile_photo']) : false,
            'profile_photo' => false, /** in the current import CSV Process this value is not required*/
            'cm_optin_status' => $userMeta['cm_optin_status'] ?? '',
            'cm_optin_signature' => $userMeta['cm_optin_signature'] ?? '',
            'dkim_modal'  => $userMeta['dkim_modal'] ?? true,
            'domain_verified_modal' => $userMeta['domain_verified_modal'] ?? true,
            'signature_design_option' => $userMeta['signature_design_option'] ?? 'Option 1'
        ];

        return $this->ksUserCrm($user, $userMeta);
    }

    private function ksUserCrm($user, $userMeta)
    {
        $post = new WPPost();
        $companyMeta = $post->getPostMeta($user['company_id']);

        $user['company'] = [
            'type_domain'         => $companyMeta['type_domain']  ?? '',
            'custom_domain'       => $companyMeta['custom_domain']  ?? '',
            'self_hosted_domain'  =>  $companyMeta['self_hosted_domain']  ?? '',
            'primary_color'       => $companyMeta['primary_color']  ?? '',
            //'company_logo'        => wp_get_attachment_url($companyMeta['company_logo'])  ?? '',
            'company_logo'        => '', /** in the current use for CSV Import this value is not needed*/
            'dns_mail_cname_host' => $companyMeta['dns_mail_cname_host'] ?? '',
            'dns_mail_cname_value' =>  $companyMeta['dns_mail_cname_value'] ?? '',
            'dns_dkim1_host'      =>  $companyMeta['dns_dkim1_host'] ?? '',
            'dns_dkim1_value'     =>  $companyMeta['dns_dkim1_value'] ?? '',
            'dns_dkim2_host'      =>  $companyMeta['dns_dkim2_host'] ?? '',
            'dns_dkim2_value'     =>  $companyMeta['dns_dkim2_value'] ?? '',
            'phone_number_company' => $companyMeta['phone_number_company']  ?? '',
            'domain_verified'     => $companyMeta['domain_verified']  ?? '',
            'email_limit_reached' => $companyMeta['email_limit_reached']  ?? '',
            'currencies'          => $companyMeta['currencies_list']  ?? '',
            'default_currency'    => $companyMeta['company_conversion']  ?? ''
        ];

        $user['company_role'] =  $userMeta['company_role']?? '';

        $removeContactLists = $companyMeta['remove_contact_lists'];
        if ($removeContactLists && is_string($removeContactLists)) {
            $removeContactLists = unserialize($removeContactLists);
        }
        $user['remove_contact_lists'] = $removeContactLists ?? [];

        // Only show if initial steps are not completed just used on Dashboard
        if ($companyMeta['completed_setup'] != 'on') {
            $user['company_initial_setup'] = [
                'initial_setup' => $companyMeta['initial_setup']  ?? false, // No show modal
                'completed_setup' => $companyMeta['completed_setup']  ?? false, // Show Widget
                'domain_setup' => $companyMeta['domain_setup']  ?? false, // Mark Domain as ready
                'branding_setup' => $companyMeta['branding_setup']  ?? false, // Mark Branding as ready
                'marketing_setup' => $companyMeta['marketing_setup']  ?? false // Mark Marketing as ready
            ];
        }

        return $user;
    }
}
