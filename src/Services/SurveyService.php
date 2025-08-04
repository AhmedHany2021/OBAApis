<?php

namespace OBA\APIsIntegration\Services;

use OBA\APIsIntegration\Traits\SurveyRelationsHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SurveyService
{
    use SurveyRelationsHelper;
    /**
     * Get a survey by ID with all its questions and options.
     */
    public function get_survey(WP_REST_Request $request)
    {
        $survey_id = absint( $request->get_param('id') );
        if ( ! $survey_id ) {
            return new WP_REST_Response([ 'success' => false, 'message' => 'Survey ID is required.' ], 400);
        }

        global $wpdb;
        $prefix           = $wpdb->prefix . SURVEY_MAKER_DB_PREFIX;
        $survey_table     = $prefix . 'surveys';
        $questions_table  = $prefix . 'questions';
        $answers_table    = $prefix . 'answers';

        // 1. Fetch the survey record
        $survey = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$survey_table} WHERE id = %d", $survey_id), ARRAY_A );
        if ( ! $survey ) {
            return new WP_REST_Response([ 'success' => false, 'message' => 'Survey not found.' ], 404);
        }

        // 2. Read the comma‑separated question_ids
        $question_ids = array_filter( array_map( 'absint', explode( ',', $survey['question_ids'] ) ) );

        $questions = [];
        $qustion_temp = [];
        if ( $question_ids ) {
            // 3. Fetch questions by IN list
            $placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
            $questions_temp = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$questions_table} WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)",
                    array_merge( $question_ids, $question_ids )
                ),
                ARRAY_A
            );

            foreach ( $questions_temp as $question ) {
                $questions[] = ['id' => $question['id'], 'question' => $question['question'] , 'type' => $question['type'] ];
            }

            $answers_temp = [];
            // 4. For each question fetch its answers
            foreach ( $questions as &$q ) {
                $answers_temp = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM {$answers_table} WHERE question_id = %d ORDER BY ordering ASC", absint( $q['id'] )),
                    ARRAY_A
                );
                foreach ( $answers_temp as $answer ) {
                    $q['answers'][] = ['id' => $answer['id'] , 'answer' => $answer['answer']];
                }
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * Get User's survey status with product
     */
    public function get_user_survey_product( $request) {
        $survey_id = $request->get_param('survey_id');
        $product_id = $request->get_param('product_id');
        $user_id = $request->get_param('current_user')->ID;
        return new WP_REST_Response([
            'product_id' => $product_id,
            'survey_id' => $survey_id,
        ]);
        if (!$survey_id || !$product_id) {
            return $this->response_data(false , false , false , 'Survey ID or Product ID is required.' , 404);
        }

        $submission = $this->CheckUserSubmissions($user_id, $survey_id);
        $any_submission = $this->CheckUserHasAnySubmission($user_id, $survey_id);
        $existing_request = $this->CheckMedicationRequestExists($user_id, $product_id);

        if (!$submission || !$any_submission) {
            return $this->response_data(true , true , false , 'No Submission added yet' , 404);
        }
        return $this->response_data(true , false , true , 'User can add product');
    }

    private function response_data($success = true, $not_submitted = false, $can_add = false, $message = '', $status = 200) : WP_REST_Response {
        return new WP_REST_Response([
            'success' => $success,
            'message' => $message,
            'not_submitted' => $not_submitted,
            'can_add' => $can_add,
        ], $status);
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
