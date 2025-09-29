<?php
defined('MOODLE_INTERNAL') || die();

class block_improvedmentees_renderer extends plugin_renderer_base {
    /**
     * Render the block content using the Mustache template.
     *
     * @param stdClass $data Data object prepared in get_content().
     * @return string HTML for output.
     */
    public function render_block_content(stdClass $data) {
        // Render the mustache template 'block_improvedmentees/main'.
        return $this->render_from_template('block_improvedmentees/main', $data);
    }
}
