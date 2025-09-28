<?php
// This file is part of Moodle - http://moodle.org/
//
// Improved Mentees Block plugin for Moodle 4.5+
// Provides enhanced mentor/mentee tracking functionality.

defined('MOODLE_INTERNAL') || die();

/**
 * Class block_improvedmentees
 *
 * This block extends the Moodle block_base class and displays
 * a dropdown list of mentees for the current user, plus details
 * of each mentee's courses and outstanding assignments.
 */
class block_improvedmentees extends block_base {

    /**
     * Initializes the block with a title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_improvedmentees');
    }

    /**
     * Allow the block to appear on dashboard and course pages.
     */
    public function applicable_formats() {
        return [
            'site-index' => true,
            'my' => true,
            'course-view' => true,
        ];
    }

    /**
     * Returns the block contents.
     *
     * @return stdClass block content object
     */
    public function get_content() {
        global $USER, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';

        $mentees = $this->get_available_mentees($USER->id);

        if (empty($mentees)) {
            $this->content->text = get_string('nomentees', 'block_improvedmentees');
            return $this->content;
        }

        $data = new stdClass();
        $data->mentees = [];
        $data->selectedid = optional_param('improvedmentees_menteeid', 0, PARAM_INT);

        foreach ($mentees as $id => $mentee) {
            $opt = new stdClass();
            $opt->id = $id;
            $opt->fullname = fullname($mentee);
            $usernameparts = explode("@",$mentee->username);
            $opt->username = $usernameparts[0];
            $opt->isSelected = ($id == $data->selectedid);
            $data->mentees[] = $opt;
        }

        if ($data->selectedid) {
            $data->selected = $this->get_user_by_id($data->selectedid);
            // later you’ll also add $data->courses etc. for template rendering
        }

        if ($this->page->context->contextlevel === CONTEXT_COURSE
            && !empty($this->page->course->id)
            && $this->page->course->id > 1) {
            // Always keep course id in form action.
            $params = $this->page->url->params();
            $params['id'] = $this->page->course->id;
            $data->formaction = new moodle_url('/course/view.php', $params);

        } else if ($this->page->pagelayout === 'mydashboard') {
            $data->formaction = new moodle_url('/my/');

        } else if ($this->page->pagelayout === 'frontpage') {
            $params = $this->page->url->params();
            $data->formaction = new moodle_url('/index.php', $params);

        } else {
            $data->formaction = $this->page->url;
        }

        $data->formaction = $data->formaction->out(false);

        // Use renderer for dropdown and course display.
        $renderer = $this->page->get_renderer('block_improvedmentees');
        $this->content->text = $renderer->render_block_content($data);

        // Debugging: log page details to see what's going on.
        $debuginfo = [
            'url'       => $this->page->url->out(false),
            'url_params'=> $this->page->url->params(),
            'context'   => $this->page->context->contextlevel ?? 'none',
            'courseid'  => $this->page->course->id ?? 'none',
            'pagelayout'=> $this->page->pagelayout ?? 'none',
        ];

        // Option 1: Write to Moodle log (developer debugging enabled).
        error_log('[ImprovedMentees Debug] ' . json_encode($debuginfo));

        // Option 2: Show inline in block (only do this in dev!).
        $this->content->text .= html_writer::tag('pre', s(print_r($debuginfo, true)));

        return $this->content;
    }

    /**
     * Retrieve available mentees for a user (mentor).
     *
     * @param int|null $userid The mentor user id (defaults to current user)
     * @return array of user records
     */
    protected function get_available_mentees($userid = null) {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Query based on role assignments in the user context.
        $sql = "SELECT u.*
                  FROM {role_assignments} ra
                  JOIN {context} c ON c.id = ra.contextid
                  JOIN {user} u ON u.id = c.instanceid
                 WHERE ra.userid = :userid
                   AND c.contextlevel = :contextlevel";
        $params = [
            'userid' => $userid,
            'contextlevel' => CONTEXT_USER,
        ];
        $records = $DB->get_records_sql($sql, $params);

        return array_values($records);
    }

    /**
     * Retrieve a user record by ID with safety checks.
     *
     * @param int $userid
     * @return stdClass|null
     */
    protected function get_user_by_id($userid) {
        global $DB;
        return $DB->get_record('user', ['id' => $userid],
            'id, firstname, lastname, username', IGNORE_MISSING);
    }

    /**
     * Use mod_assign class API to get assignments that:
     * - belong to the given course
     * - have a duedate set (optional)
     * - for which the given user has no submitted attempt ready for grading
     *
     * This implementation avoids deprecated assign_get_assignments() and uses
     * the supported assign class and get_fast_modinfo() in Moodle 4.5.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $now timestamp to compare due dates (useful for testing)
     * @return array of stdClass with fields id, name, duedate (int)
     */
    protected function get_assignments_due_without_submission_modassign($courseid, $userid, $now) {
        global $DB, $CFG;

        $assignments_out = [];

        try {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');

            // Load course modules information.
            $modinfo = get_fast_modinfo($courseid);

            // Get all assignment modules in this course.
            $assigncms = $modinfo->get_instances_of('assign');
            if (!empty($assigncms) && is_array($assigncms)) {
                foreach ($assigncms as $cm) {
                    if (isset($cm->uservisible) && !$cm->uservisible) {
                        continue; // Skip hidden activities
                    }

                    $context = context_module::instance($cm->id);
                    $assign = new assign($context, $cm, $courseid);

                    $instance = $assign->get_instance();
                    if (empty($instance)) {
                        continue;
                    }

                    if (empty($instance->duedate)) {
                        continue;
                    }

                    // Check user submission.
                    $submission = $assign->get_user_submission($userid, false);

                    $is_submitted = false;
                    if (!empty($submission)) {
                        if (defined('ASSIGN_SUBMISSION_STATUS_SUBMITTED')) {
                            if (!empty($submission->status) &&
                                $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                                $is_submitted = true;
                            }
                        } else {
                            if (!empty($submission->status) &&
                                $submission->status === 'submitted') {
                                $is_submitted = true;
                            }
                        }
                    }

                    if (!$is_submitted) {
                        $rec = new stdClass();
                        $rec->id = $instance->id;
                        $rec->name = $instance->name;
                        $rec->duedate = $rec->duedate = userdate($instance->duedate);
                        $assignments_out[] = $rec;
                    }
                }
                return $assignments_out;
            }
        } catch (Throwable $e) {
            // Fall through to fallback SQL below.
        }

        // Fallback: conservative SQL query in case of API error.
        $assigns = $DB->get_records('assign', ['course' => $courseid]);
        if (empty($assigns)) {
            return [];
        }
        foreach ($assigns as $a) {
            if (empty($a->duedate)) {
                continue;
            }
            $sql = "SELECT 1
                      FROM {assign_submission} s
                     WHERE s.assignment = :assignid
                       AND s.userid = :userid
                       AND s.status != 'new'";
            $sub = $DB->record_exists_sql($sql, ['assignid' => $a->id, 'userid' => $userid]);
            if (!$sub) {
                $rec = new stdClass();
                $rec->id = $a->id;
                $rec->name = $a->name;
                $rec->duedate = (int)$a->duedate;
                $assignments_out[] = $rec;
            }
        }
        return $assignments_out;
    }
}
