<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WPPost extends Model
{
    use HasFactory;

    protected $table = "wp_posts";

    protected $fillable = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count'
    ];

    public $timestamps = false;

    public function getPostMeta($postId) {
        $postMeta = [];

        $metaData = WPPostMeta::where('post_id', '=', $postId)->get();

        foreach ($metaData as $meta) {
            $postMeta[$meta->meta_key] = $meta->meta_value;
        }

        return $postMeta;
    }
}
