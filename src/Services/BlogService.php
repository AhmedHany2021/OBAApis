<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Blog service
 *
 * @package OBA\APIsIntegration\Services
 */
class BlogService
{
    /**
     * Get blog posts
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_blog_posts(WP_REST_Request $request)
    {
     

        // Execute query
        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',

        ]);

        if ($query->have_posts()) {
            $posts = [];
            
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // Get featured image
                $thumbnail_url = '';
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if ($thumbnail_id) {
                    $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'medium');
                }

                // Get post content
                $content = get_the_content();
                $content = apply_filters('the_content', $content);
                $content = wp_strip_all_tags($content);

                // Get edit date
                $edit_date = get_the_modified_date('Y-m-d H:i:s');

                $posts[] = [
                    'id' => $post_id,
                    'title' => wp_strip_all_tags(get_the_title()),
                    'thumbnail' => $thumbnail_url,
                    'content' => $content,
                    'permalink' => get_permalink(),
                    'excerpt' => get_the_excerpt(),
                    'date' => $edit_date,
                ];
            }
            
            wp_reset_postdata();

            return new WP_REST_Response([
                'success' => true,
                'data' => $posts,
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => true,
                'data' => [],
                'message' => 'No blog posts found'
            ], 200);
        }
    }

    /**
     * Get single blog post
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_blog_post(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        
        if (!$post_id) {
            return new WP_Error(
                'missing_post_id',
                __('Post ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $post = get_post($post_id);
        
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return new WP_Error(
                'post_not_found',
                __('Post not found or not published.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Get featured image
        $thumbnail_url = '';
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'large');
        }

        // Get post content
        $content = apply_filters('the_content', $post->post_content);

        // Get edit date
        $edit_date = get_the_modified_date('Y-m-d H:i:s', $post_id);

        $post_data = [
            'id' => $post_id,
            'title' => wp_strip_all_tags($post->post_title),
            'thumbnail' => $thumbnail_url,
            'content' => $content,
            'permalink' => get_permalink($post_id),
            'excerpt' => get_the_excerpt($post_id),
            'date' => $edit_date,
        ];

        return new WP_REST_Response([
            'success' => true,
            'data' => $post_data
        ], 200);
    }
}