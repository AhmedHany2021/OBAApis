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
        $survey_id = absint($request->get_param('id'));
        if (!$survey_id) {
            return new WP_REST_Response(['success' => false, 'message' => 'Survey ID is required.'], 400);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . SURVEY_MAKER_DB_PREFIX;
        $survey_table = $prefix . 'surveys';
        $questions_table = $prefix . 'questions';
        $answers_table = $prefix . 'answers';

        // 1. Fetch the survey record
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$survey_table} WHERE id = %d", $survey_id), ARRAY_A);
        if (!$survey) {
            return new WP_REST_Response(['success' => false, 'message' => 'Survey not found.'], 404);
        }

        // 2. Read the comma‑separated question_ids
        $question_ids = array_filter(array_map('absint', explode(',', $survey['question_ids'])));

        $questions = [];
        $qustion_temp = [];
        if ($question_ids) {
            // 3. Fetch questions by IN list
            $placeholders = implode(',', array_fill(0, count($question_ids), '%d'));
            $questions_temp = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$questions_table} WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)",
                    array_merge($question_ids, $question_ids)
                ),
                ARRAY_A
            );

            foreach ($questions_temp as $question) {
                $questions[] = ['id' => $question['id'], 'question' => $question['question'], 'type' => $question['type']];
            }

            $answers_temp = [];
            // 4. For each question fetch its answers
            foreach ($questions as &$q) {
                $answers_temp = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM {$answers_table} WHERE question_id = %d ORDER BY ordering ASC", absint($q['id'])),
                    ARRAY_A
                );
                foreach ($answers_temp as $answer) {
                    $q['answers'][] = ['id' => $answer['id'], 'answer' => $answer['answer']];
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
    public function get_user_survey_product(WP_REST_Request $request)
    {
        $survey_id = $request->get_param('survey_id');
        $product_id = $request->get_param('product_id');
        $user_id = $request->get_param('current_user')->ID;

        if (!$survey_id || !$product_id) {
            return $this->response_data(false, false, false, 'Survey ID or Product ID is required.', 404);
        }

        $survey = $this->GetSurvey($survey_id);

        if (!$survey) {
            return $this->response_data(false, false, false, 'Survey ID is incorrect', 404);
        }

        $submission = $this->CheckUserSubmissions($user_id, $survey_id);
        $existing_request = $this->CheckMedicationRequestExists($user_id, $product_id);

        if (!$submission) {
            return $this->response_data(true, true, false, 'No Submission added yet', 404);
        }

        if ($existing_request) {
            if ($existing_request['status'] == 'approved' || $existing_request['status'] == 'complete') {
                return $this->response_data(true, false, true, 'User request approved');
            } elseif ($existing_request['status'] == 'rejected') {
                return $this->response_data(true, false, false, 'User request rejected');
            } elseif ($existing_request['status'] == 'pending approval') {
                $conditions = json_decode($survey->conditions, true);
                $condition_met = $this->CheckConditionMet($submission, $conditions, $product_id);
                if ($condition_met) {
                    $this->UpdateRequestStatus($existing_request['id'], 'approved');
                    return $this->response_data(true, false, true, 'User can add product');

                } else {
                    return $this->response_data(true, false, false, 'User request pending approval');
                }
            }
        } else {
            $conditions = json_decode($survey->conditions, true);
            $condition_met = $this->CheckConditionMet($submission, $conditions, $product_id);
            $status = $condition_met ? 'approved' : 'pending approval';
            $medication_request = $this->HandleNewMedicationRequest($submission, $product_id, $user_id, $status);
            if (!$medication_request) {
                return $this->response_data(false, false, false, "couldn't create request in mdclara");
            }
            if ($condition_met) {
                return $this->response_data(true, false, true, "user can add product");
            } else {
                return $this->response_data(true, false, false, 'user request pending approval');
            }
        }
        return $this->response_data(false, false, false, 'un known error please contact support', 404);
    }

    private function response_data($success = true, $not_submitted = false, $can_add = false, $message = '', $status = 200): WP_REST_Response
    {
        return new WP_REST_Response([
            'success'       => $success,
            'message'       => $message,
            'not_submitted' => $not_submitted,
            'can_add'       => $can_add,
        ], $status);
    }

    /**
     * Submit a survey with answers.
     */
    public function submit_survey(WP_REST_Request $request)
    {
        $survey_id = (int)$request->get_param('survey_id');
        $user_id = $request->get_param('current_user')->ID;
        $answers = $request->get_param('answers');
        $product_id = $request->get_param('product_id');

        if (!$survey_id || !is_array($answers)) {
            return new WP_Error(
                'survey_submit_required',
                __('Survey id or answers are missing.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        if (!$product_id) {
            return new WP_Error(
                'product_id_required',
                __('product id is missing.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        global $wpdb;
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ayssurvey_surveys WHERE id = %d", $survey_id));
        if (!$survey) {
            return new WP_Error(
                'survey_not_found',
                __('Survey not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Get user info
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error(
                'user_not_found',
                __('User not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Prepare submission data
            $current_time = current_time('mysql');
            $submission_data = [
                'survey_id' => $survey_id,
                'user_id' => $user_id,
                'user_ip' => $_SERVER['REMOTE_ADDR'],
                'user_name' => $user->display_name,
                'user_email' => $user->user_email,
                'start_date' => $current_time,
                'end_date' => $current_time,
                'submission_date' => $current_time,
                'status' => 'published',
                'options' => maybe_serialize([
                    'product_id' => $product_id
                ]),
                'point' => 0,
                'changed' => 0,
                'post_id' => 0,
                'admin_note' => ''
            ];

            // Insert submission record
            $inserted = $wpdb->insert($wpdb->prefix . 'ayssurvey_submissions', $submission_data);
            $submission_id = $inserted;
            if ($inserted) {
                $submission_id = $wpdb->insert_id; // ← This is the auto-increment ID
            }
            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'submission_failed',
                    __('Failed to create survey submission.', 'oba-apis-integration'),
                    ['status' => 500]
                );
            }

            // Insert answers for each question
            foreach ($answers as $question_id => $value) {
                // If value is an array (multiple answers), join them with comma
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                
                $wpdb->insert($wpdb->prefix . 'ayssurvey_submissions_questions', [
                    'submission_id' => $submission_id,
                    'question_id' => $question_id,
                    'answer_id' => is_numeric($value) ? $value : null,
                    'user_answer' => $value,
                    'created_at' => $current_time
                ]);
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            // Handle medication request if needed
            if (!empty($survey->conditions)) {
                $conditions = json_decode($survey->conditions, true);
                $condition_met = $this->CheckConditionMet($submission_id, $conditions, $product_id);
                
                $status = $condition_met ? 'approved' : 'pending approval';
                $this->HandleNewMedicationRequest($submission_id, $product_id, $user_id, $status);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Survey submitted successfully.',
                'submission_id' => $submission_id,
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'submission_failed',
                __('Failed to submit survey: ' . $e->getMessage(), 'oba-apis-integration'),
                ['status' => 500]
            );
        }
    }
}
