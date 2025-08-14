<?php

namespace OBA\APIsIntegration\Traits;

trait SurveyRelationsHelper
{
    private function CheckUserSubmissions($user_id , $survey_id)
    {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "ayssurvey_submissions";
        $submission_query = $wpdb->prepare(
            "SELECT id, questions_ids FROM $submissions_table 
            WHERE survey_id = %d AND status = %s",
            $survey_id,
            'published'
        );
        $submission_query .= $wpdb->prepare(" AND user_id = %d", $user_id);
        $submission_query .= " ORDER BY id DESC LIMIT 1";
        $submission = $wpdb->get_row($submission_query);
        return $submission;
    }

    private function CheckUserHasAnySubmission($user_id , $survey_id)
    {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "ayssurvey_submissions";
        $submission_query = $wpdb->prepare(
            "SELECT id, status FROM $submissions_table 
            WHERE survey_id = %d AND user_id = %d",
            $survey_id,
            $user_id
        );
        $submission_query .= " ORDER BY id DESC LIMIT 1";
        $submission = $wpdb->get_row($submission_query);
        return $submission;
    }

    private function CheckMedicationRequestExists($user_id , $product_id)
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . "survey_maker_woo_product_access";
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM $product_access_table  
                WHERE user_id = %d AND product_id = %d",
            $user_id,
            $product_id
        ), ARRAY_A);
        return $existing;
    }

    private function CheckConditionMet($submission , $conditions , $product_id)
    {
        if (empty($conditions)) {
            return true;
        }

        global $wpdb;

        // Get user answers from submission
        $answers = $this->GetUserAnswersFromSubmission($submission);

        // Check each condition
        foreach ($conditions as $condition) {
            // Check if this condition has WooCommerce products
            if (!isset($condition['messages']['wooproduct']) || empty($condition['messages']['wooproduct'])) {
                continue;
            }

            // Check if this product is in the condition's products
            if (!in_array($product_id, $condition['messages']['wooproduct'])) {
                continue;
            }

            // Check if all condition rules are met
            $condition_met = true;
            if (isset($condition['condition_question_add']) && !empty($condition['condition_question_add'])) {
                foreach ($condition['condition_question_add'] as $rule) {
                    $question_id = isset($rule['question_id']) ? intval($rule['question_id']) : 0;
                    if (!$question_id) {
                        continue;
                    }

                    // Find the answer for this question
                    $answer_found = false;
                    foreach ($answers as $answer) {
                        if ($answer['question_id'] == $question_id) {
                            $answer_found = true;
                            $user_answer = $answer['user_answer'];
                            $answer_id = $answer['answer_id'];

                            // Compare the answer
                            $rule_answer = isset($rule['answer']) ? $rule['answer'] : '';
                            $rule_type = isset($rule['type']) ? $rule['type'] : '';

                            // For radio/select type questions, check the answer_id
                            if ($rule_type == 'radio' || $rule_type == 'select') {
                                if ($answer_id != $rule_answer) {
                                    $condition_met = false;
                                }
                            } else {
                                if ($user_answer != $rule_answer) {
                                    $condition_met = false;
                                }
                            }
                            break;
                        }
                    }

                    if (!$answer_found) {
                        $condition_met = false;
                    }

                    if (!$condition_met) {
                        break;
                    }
                }
            }

            if ($condition_met) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get user answers from submission in a format suitable for condition checking
     */
    private function GetUserAnswersFromSubmission($submission)
    {
        global $wpdb;

        $answers = [];

        $submissions_questions_table = $wpdb->prefix . "ayssurvey_submissions_questions";
        $answers_table = $wpdb->prefix . "ayssurvey_answers";

        $query = "
        SELECT sq.question_id, sq.answer_id, sq.user_answer
        FROM $submissions_questions_table sq
        WHERE sq.submission_id = %d
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $submission->id), ARRAY_A);

        foreach ($results as $row) {
            $answer_data = [
                'question_id' => intval($row['question_id']),
                'answer_id' => $row['answer_id'] ? intval($row['answer_id']) : null,
                'user_answer' => $row['user_answer']
            ];

            // If we have an answer_id, get the actual answer text
            if ($answer_data['answer_id']) {
                $answer_text = $wpdb->get_var($wpdb->prepare(
                    "SELECT answer FROM $answers_table WHERE id = %d",
                    $answer_data['answer_id']
                ));
                if ($answer_text) {
                    $answer_data['user_answer'] = $answer_text;
                }
            }

            $answers[] = $answer_data;
        }

        return $answers;
    }

    private function HandleNewMedicationRequest($submission , $product_id , $user_id , $status)
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . "survey_maker_woo_product_access";

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->insert(
                $product_access_table,
                array(
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'status' => $status
                ),
                array('%d', '%d', '%s')
            );

            $insert_id = $wpdb->insert_id;
            if (!$insert_id) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if ($this->CreateNewMedicationRequest($submission, $product_id , $user_id , $insert_id, $status)) {
                $wpdb->query('COMMIT');
                return true;
            } else {
                $wpdb->query('ROLLBACK');
                return false;
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    private function CreateNewMedicationRequest($submission , $product_id , $user_id , $request_id , $status)
    {
        $patient_id = get_user_meta( $user_id, 'mdclara_patient_id', true );
        $survey_data = $this->GetUserAnswers($submission);

        $response = wp_remote_post(
            site_url('/wp-json/mdclara/v1/createOrUpdateMedication/?key=' . MDCLARA_KEY . '&instance_name=' . MDCLARA_INSTANCE_NAME),
            [
                'method'  => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'key' => MDCLARA_KEY,
                    'instance_name' => MDCLARA_INSTANCE_NAME,
                    'patient_id' => $patient_id,
                    'product_id' => $product_id,
                    'survey'     => $survey_data,
                    'oba_request_id' => $request_id,
                    'oba_sig_id' => 1,
                    'status' => $status
                ]),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Check if the response indicates success (2xx status codes)
        if ($response_code >= 200 && $response_code < 300) {
            return true;
        }

        // Log error for debugging
        error_log('Survey Maker Woo API Error: ' . $response_code . ' - ' . $response_body);

        return false;
    }

    private function GetUserAnswers($submission)
    {
        global $wpdb;

        $survey_data = [];

        $submissions_questions_table = $wpdb->prefix . "ayssurvey_submissions_questions";
        $questions_table = $wpdb->prefix . "ayssurvey_questions";
        $answers_table = $wpdb->prefix . "ayssurvey_answers";
        $categories_table = $wpdb->prefix . "ays_qcategories";
        $relations_table = $wpdb->prefix . "ays_qcategory_relations";

        $query = "
        SELECT q.id AS question_id, q.question, c.id AS category_id, c.name AS category_name,
               sq.answer_id, sq.user_answer
        FROM $submissions_questions_table sq
        INNER JOIN $questions_table q ON sq.question_id = q.id
        INNER JOIN $relations_table r ON q.id = r.question_id
        INNER JOIN $categories_table c ON r.category_id = c.id
        WHERE sq.submission_id = %d
    ";

        $results = $wpdb->get_results($wpdb->prepare($query, $submission->id), ARRAY_A);

        foreach ($results as $row) {
            $category_name = $row['category_name'] ?: 'Uncategorized';
            $question = $row['question'];
            $user_answer = $row['user_answer'];
            $answer_id = $row['answer_id'];

            if ($answer_id) {
                $answer_text = $wpdb->get_var($wpdb->prepare(
                    "SELECT answer FROM $answers_table WHERE id = %d",
                    $answer_id
                ));
                if ($answer_text) {
                    $user_answer = $answer_text;
                }
            }

            $survey_data[$category_name][] = [
                'question' => $question,
                'answer'   => $user_answer,
            ];
        }

        return $survey_data;
    }

    /**
     * Update the status of a medication request
     */
    private function UpdateRequestStatus($request_id, $status)
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . "survey_maker_woo_product_access";

        $result = $wpdb->update(
            $product_access_table,
            array('status' => $status),
            array('id' => $request_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Get request details by ID
     */
    private function GetRequestById($request_id)
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . "survey_maker_woo_product_access";

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $product_access_table WHERE id = %d",
            $request_id
        ), ARRAY_A);
    }

    private function GetRequestsByPatientAndProducts(int $patient_id, array $products = [])
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . "survey_maker_woo_product_access";

        if (empty($products)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($products), '%d'));

        $sql = $wpdb->prepare(
            "SELECT id FROM $product_access_table 
         WHERE user_id = %d AND product_id IN ($placeholders)",
            array_merge([$patient_id], $products)
        );

        $results = $wpdb->get_col($sql);

        return $results;
    }

    private function GetSurvey($survey_id)
    {
        global $wpdb;
        $survey_table = $wpdb->prefix . "ayssurvey_surveys";
        $survey = $wpdb->get_row($wpdb->prepare(
            "SELECT conditions FROM $survey_table WHERE id = %d",
            $survey_id
        ));
        return $survey;
    }

    private function update_user_product_access_status($user_id,$product_id,$status)
    {
        global $wpdb;
        $product_access_table = $wpdb->prefix . 'survey_maker_woo_product_access';
        $deleted = $wpdb->update(
            $product_access_table,
            array(
                'status' => $status
            ),
            array(
                'user_id' => $user_id,
                'product_id' => $product_id
            ),
            array('%s'),
            array('%d', '%d')
        );
    }
    private function delete_user_survey_submission($user_id,$survey_id)
    {
        global $wpdb;
        $submissions_table = $wpdb->prefix . 'ayssurvey_submissions';
        $updated = $wpdb->delete(
            $submissions_table,
            array(
                'survey_id' => $survey_id,
                'user_id' => $user_id
            ),
            array('%d', '%d')
        );
    }

    private function CreateRetakeMedicationRequest($submission, $oba_request_id, $status = "pending approval")
    {
        $survey_data = $this->GetUserAnswers($submission);
        $response = wp_remote_post(
            site_url('/wp-json/mdclara/v1/createOrUpdateMedication/'),
            [
                'method'  => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
//                    'key' => MDCLARA_KEY,
//                    'instance_name' => MDCLARA_INSTANCE_NAME,
                ],
                'body'    => json_encode([
                    'survey'         => $survey_data,
                    'oba_request_id' => $oba_request_id,
                    'status'         => $status,
                    'update' => true,
                ]),
                'timeout' => 15,
            ]
        );
        echo "<pre>";
    }

}