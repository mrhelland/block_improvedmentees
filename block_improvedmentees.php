<?php
// This file is part of Moodle - http://moodle.org/
//
// Improved Mentees Block plugin for Moodle 4.5+
// Provides enhanced mentor/mentee tracking functionality.

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/grade/querylib.php'); // ✅ add this

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
     * Get block content.
     */
    public function get_content() {
        global $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';

        // Determine which mentee is currently selected.
        

        $data = new stdClass();
        $data->mentees = [];        
        $data->selectedmenteeid = optional_param('improvedmentees_menteeid', 0, PARAM_INT);
        $data->selectedcourseid = optional_param('improvedmentees_courseid', 0, PARAM_INT);
        $selectedmenteeid = $data->selectedmenteeid;
        $selectedcourseid = $data->selectedcourseid;

        // Get available mentees.
        $mentees = $this->get_available_mentees($USER->id);
        if (empty($mentees)) {
            $this->content->text = get_string('nomentees', 'block_improvedmentees');
            return $this->content;
        }

        // Determine base URL depending on context.
        if ($this->page->context->contextlevel === CONTEXT_COURSE &&
            !empty($this->page->course->id) && $this->page->course->id > 1) {
            $baseurl = new moodle_url('/course/view.php', ['id' => $this->page->course->id]);
        } else if ($this->page->pagelayout === 'mydashboard') {
            $baseurl = new moodle_url('/my/');
        } else if ($this->page->pagelayout === 'frontpage') {
            $baseurl = new moodle_url('/index.php', $this->page->url->params());
        } else {
            $baseurl = $this->page->url;
        }

        // "Show all" link.
        $url = clone($baseurl);
        $url->param('improvedmentees_menteeid', 0);
        $url->param('improvedmentees_courseid', 0);
        $data->showall = (object)[
            'url' => $url->out(false),
            'isSelected' => ($selectedmenteeid == 0)
        ];

        // Mentee links.

        $now = time(); // Current timestamp.
        foreach ($mentees as $mentee) {
            $url = clone($baseurl);
            $url->param('improvedmentees_menteeid', $mentee->id);

            $menteedata = (object)[
                'id' => $mentee->id,
                'fullname' => fullname($mentee),
                'username' => strstr($mentee->username, '@', true),
                'url' => $url->out(false),
                'isSelected' => ($selectedmenteeid == $mentee->id),
                'courses' => []
            ];

            // Only load courses if this mentee is selected (optional, for performance).
            if ($selectedmenteeid == $mentee->id) {
                $courses = $this->get_mentee_courses($mentee->id);             

                foreach ($courses as $course) {

                    // Get missing assignments for this course/mentee.
                    $missingassignments = $this->get_assignments_due_without_submission_modassign($course->id, $mentee->id, $now);

                    print_object($missingassignments);

                    $missingassignmentdata = [];
                    foreach ($missingassignments as $assign) {
                        $missingassignmentdata[] = (object)[
                            'id' => $assign->id,
                            'name' => format_string($assign->name),
                            'url' => (new moodle_url('/mod/assign/view.php', ['id' => $assign->id]))->out(false)
                       
                        ];
                    }


                    // ✅ Get overall course grade for this user.
                    $coursegrade = grade_get_course_grade($mentee->id, $course->id);
                    $percentage = null;
                    if (!empty($coursegrade) && isset($coursegrade->grade)) {
                        $percentage = $coursegrade->grade; // This is the final grade (usually already a percentage).
                    }

                    print_object($coursegrade);

                    $menteedata->courses[] = (object)[
                        'id' => $course->id,
                        'fullname' => format_string($course->fullname),
                        'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                        'missingassignments' => $missingassignmentdata,
                        'missingassignmentcount' => count($missingassignmentdata),
                        'hasmissingassignments' => !empty($missingassignmentdata),
                        'gradepercentage' => format_float($percentage, 1)
                    ];
                }
                $menteedata->hascourses = !empty($menteedata->courses);
            }
            else {
                $menteedata->hascourses = false;
            }

            $data->mentees[] = $menteedata;            
        }



        // Render Mustache template.
        $renderer = $this->page->get_renderer('block_improvedmentees');
        $this->content->text = $renderer->render_block_content($data);

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
     * Get all courses a mentee is enrolled in, sorted by course fullname.
     *
     * @param int $menteeid The mentee's user id.
     * @return array Associative array of course stdClass objects keyed by course id.
     */
    protected function get_mentee_courses(int $menteeid): array {
        global $CFG;

        // Ensure enrol API is available.
        require_once($CFG->dirroot . '/lib/enrollib.php');

        // Get all courses the user is enrolled in (only active enrolments).
        // Returns an array of course objects (often keyed by course id).
        $courses = enrol_get_all_users_courses($menteeid, true, '*');

        if (empty($courses)) {
            return [];
        }

        // Ensure we have a numerically indexed array for sorting.
        $coursesarr = array_values($courses);

        // Defensive: ensure fullname exists and sort by case-insensitive name.
        usort($coursesarr, function($a, $b) {
            $an = isset($a->fullname) ? $a->fullname : '';
            $bn = isset($b->fullname) ? $b->fullname : '';
            return strcasecmp($an, $bn);
        });

        // Re-key the sorted list by course id for convenient lookup.
        $sorted = [];
        foreach ($coursesarr as $c) {
            // Defensive check: skip invalid entries.
            if (empty($c) || empty($c->id)) {
                continue;
            }
            $sorted[(int)$c->id] = $c;
        }

        return $sorted;
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
            $modinfo = get_fast_modinfo($courseid, $userid);

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
