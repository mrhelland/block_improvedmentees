<?php
defined('MOODLE_INTERNAL') || die();

class block_improvedmentees_renderer extends plugin_renderer_base {
    /**
     * Render helper to pass in additional context variables to mustache template.
     */
    public function render_block_content(stdClass $data) {
        // Defensive checks: ensure data contains expected arrays.
        if (!isset($data->select) || !is_array($data->select)) {
            $data->select = [];
        }
        if (!isset($data->courses) || !is_array($data->courses)) {
            $data->courses = [];
        }
        // Add page URL for form action and selected helpers.
        global $PAGE, $OUTPUT;

        $data->PAGEURL = $PAGE->url->out(false);

        // Provide helper lambdas for select option selection if needed by template.
        // Mustache in Moodle doesn't accept lambdas the same way; instead we'll
        // augment the select options with a comparison flag before rendering in PHP.
        foreach ($data->select as $opt) {
            $opt->selected = ($opt->value == $data->selectedid);
        }

        // Format outstanding assignment duedates for display.
        foreach ($data->courses as $course) {
            foreach ($course->outstanding as $a) {
                if (!empty($a->duedate)) {
                    $a->duedateFormatted = userdate($a->duedate);
                } else {
                    $a->duedateFormatted = '';
                }
            }
        }

        // Render the mustache template 'block_improvedmentees/main'.
        return $this->render_from_template('block_improvedmentees/main', $data);
    }
}
