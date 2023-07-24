<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_courseformat\output\local\content\cm;

use cm_info;
use section_info;
use renderable;
use stdClass;
use core\activity_dates;
use core\output\named_templatable;
use core\output\local\dropdown\dialog as dropdown_dialog;
use core_completion\cm_completion_details;
use core_course\output\activity_information;
use core_courseformat\base as course_format;
use core_courseformat\output\local\courseformat_named_templatable;

/**
 * Base class to render course module completion.
 *
 * @package   core_courseformat
 * @copyright 2023 Mikel Martin <mikel@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion implements named_templatable, renderable {

    use courseformat_named_templatable;

    /**
     * Constructor.
     *
     * @param course_format $format the course format
     * @param section_info $section the section info
     * @param cm_info $mod the course module ionfo
     */
    public function __construct(
        protected course_format $format,
        protected section_info $section,
        protected cm_info $mod,
    ) {
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \renderer_base $output typically, the renderer that's calling this function
     * @return stdClass data context for a mustache template
     */
    public function export_for_template(\renderer_base $output): ?stdClass {
        global $USER;

        $course = $this->mod->get_course();

        $showcompletionconditions = $course->showcompletionconditions == COMPLETION_SHOW_CONDITIONS;
        $completiondetails = cm_completion_details::get_instance($this->mod, $USER->id, $showcompletionconditions);

        $activitydates = [];
        if ($course->showactivitydates) {
            $activitydates = activity_dates::get_dates_for_module($this->mod, $USER->id);
        }

        // There are activity dates to be shown; or
        // Completion info needs to be displayed
        // * The activity tracks completion; AND
        // * The showcompletionconditions setting is enabled OR an activity that tracks manual
        // completion needs the manual completion button to be displayed on the course homepage.
        $showcompletioninfo = $completiondetails->has_completion() && ($showcompletionconditions ||
            (!$completiondetails->is_automatic() && $completiondetails->show_manual_completion()));
        if (!$showcompletioninfo && empty($activitydates)) {
            return null;
        }

        $activityinfodata = (object) [ 'hasdates' => false, 'hascompletion' => false ];
        $activityinfo = new activity_information($this->mod, $completiondetails, $activitydates);
        $activityinfodata = $activityinfo->export_for_template($output);

        if ($activityinfodata->isautomatic || ($activityinfodata->ismanual && !$activityinfodata->istrackeduser)) {
            $activityinfodata->completiondialog = $this->get_completion_dialog($output, $activityinfodata);
        }

        return $activityinfodata;
    }

    /**
     * Get the completion dialog.
     *
     * @param \renderer_base $output typically, the renderer that's calling this function
     * @param stdClass $completioninfo the completion info
     * @return array the completion dialog exported for template
     */
    private function get_completion_dialog(\renderer_base $output, stdClass $completioninfo): array {
        if ($completioninfo->istrackeduser) {
            $buttoncontent = get_string('todo', 'completion');
        } else {
            $buttoncontent = get_string('completionmenuitem', 'completion');
        }
        $content = $output->render_from_template('core_courseformat/local/content/cm/completion_dialog', $completioninfo);

        $completiondialog = new dropdown_dialog($buttoncontent, $content, [
            'classes' => 'completion-dropdown',
            'buttonclasses' => 'btn btn-sm btn-outline-secondary dropdown-toggle',
            'dropdownposition' => dropdown_dialog::POSITION['end'],
        ]);

        return $completiondialog->export_for_template($output);
    }
}
