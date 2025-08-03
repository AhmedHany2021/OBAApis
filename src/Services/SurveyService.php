<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SurveyService
{
    /**
     * Get a survey by ID with all its questions and options.
     */
    public function get_survey(WP_REST_Request $request): WP_REST_Response
    {
        $survey_id = (int) $request->get_param('id');

        if (!$survey_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Survey ID is required.',
            ], 400);
        }

        global $wpdb;
        $survey_table = $wpdb->prefix . 'ays_survey';
        $questions_table = $wpdb->prefix . 'ays_survey_question';
        $answers_table = $wpdb->prefix . 'ays_survey_answer';

        $survey = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $survey_table WHERE id = %d", $survey_id),
            ARRAY_A
        );

        if (!$survey) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Survey not found.',
            ], 404);
        }

        $questions = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $questions_table WHERE survey_id = %d ORDER BY question_order ASC", $survey_id),
            ARRAY_A
        );

        foreach ($questions as &$question) {
            $question['answers'] = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $answers_table WHERE question_id = %d ORDER BY answer_order ASC", $question['id']),
                ARRAY_A
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'survey' => $survey,
                'questions' => $questions,
            ]
        ]);
    }

    /**
     * Submit a survey with answers.
     */
    public function submit_survey(WP_REST_Request $request)
    {
        $survey_id = (int) $request->get_param('survey_id');
        $user_id = $request->get_param('current_user')->ID;
        $answers = $request->get_param('answers');
        $product_id = $request->get_param('product_id');

        if (!$survey_id || !is_array($answers)) {
            return new WP_Error(
                'survey_submit_required',
                __( 'Survey id or answers are missing.', 'oba-apis-integration' ),
                [ 'status' => 400 ]
            );
        }

        if (!$product_id) {
            return new WP_Error(
                'product_id_required',
                __( 'product id is missing.', 'oba-apis-integration' ),
                [ 'status' => 400 ]
            );
        }

        global $wpdb;
        $results_table = $wpdb->prefix . 'ays_survey_user_answer';

        foreach ($answers as $question_id => $value) {
            $wpdb->insert($results_table, [
                'user_id' => $user_id,
                'survey_id' => $survey_id,
                'question_id' => $question_id,
                'answer' => maybe_serialize($value),
                'created_at' => current_time('mysql'),
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Survey submitted successfully.',
        ]);
    }
}
