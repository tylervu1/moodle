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

namespace core_completion\form;

use core_grades\component_gradeitems;

/**
 * Completion trait helper, with methods to add completion elements and validate them.
 *
 * @package    core_completion
 * @since      Moodle 4.3
 * @copyright  2023 Sara Arjona (sara@moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait form_trait {

    /**
     * Called during validation.
     * Override this method to indicate, based on the data, whether a custom completion rule is selected or not.
     *
     * @param array $data Input data (not yet validated)
     * @return bool True if one or more rules are enabled; false if none are.
     */
    abstract protected function completion_rule_enabled($data);

    /**
     * Add completion elements to the form and return the list of element ids.
     *
     * @return array Array of string IDs of added items, empty array if none
     */
    abstract protected function add_completion_rules();

    /**
     * Get the form associated to this class, where the completion elements will be added.
     * This method must be overriden by the class using this trait if it doesn't include a _form property.
     *
     * @return \MoodleQuickForm
     * @throws \coding_exception If the class does not have a _form property.
     */
    protected function get_form(): \MoodleQuickForm {
        if (property_exists($this, '_form')) {
            return $this->_form;
        }

        throw new \coding_exception('This class does not have a _form property. Please, add it or override the get_form() method.');
    }

    /**
     * Get the cm (course module) associated to this class.
     * This method must be overriden by the class using this trait if it doesn't include a _cm property.
     *
     * @return \stdClass|null
     * @throws \coding_exception If the class does not have a _cm property.
     */
    protected function get_cm(): ?\stdClass {
        if (property_exists($this, '_cm')) {
            return $this->_cm;
        }

        throw new \coding_exception('This class does not have a _cm property. Please, add it or override the get_cm() method.');
    }

    /**
     * Add completion elements to the form.
     *
     * @param string|null $modname The module name (for example, 'assign'). If null and form is moodleform_mod, the parameters are
     *                             overriden with the expected values from the form.
     * @param bool $supportviews True if the module supports views and false otherwise.
     * @param bool $supportgrades True if the module supports grades and false otherwise.
     * @param bool $rating True if the rating feature is enabled and false otherwise.
     * @param bool $defaultcompletion True if the default completion is enabled and false otherwise. To review in MDL-78531.
     * @throws \coding_exception If the form is not moodleform_mod and $modname is null.
     */
    protected function add_completion_elements(
        string $modname = null,
        bool $supportviews = false,
        bool $supportgrades = false,
        bool $rating = false,
        bool $defaultcompletion = true
    ): void {
        global $CFG;

        $mform = $this->get_form();
        if ($modname === null) {
            if ($this instanceof \moodleform_mod) {
                // By default, all the modules can be initiatized with the same parameters.
                $modname = $this->_modname;
                $supportviews = plugin_supports('mod', $modname, FEATURE_COMPLETION_TRACKS_VIEWS, false);
                $supportgrades = plugin_supports('mod', $modname, FEATURE_GRADE_HAS_GRADE, false);
                $rating = $this->_features->rating;
                $defaultcompletion = $CFG->completiondefault && $this->_features->defaultcompletion;
            } else {
                throw new \coding_exception('You must specify the modname parameter if you are not using a moodleform_mod.');
            }
        }

        // Unlock button if people have completed it. The button will be removed later in definition_after_data if they haven't.
        $mform->addElement('submit', 'unlockcompletion', get_string('unlockcompletion', 'completion'));
        $mform->registerNoSubmitButton('unlockcompletion');
        $mform->addElement('hidden', 'completionunlocked', 0);
        $mform->setType('completionunlocked', PARAM_INT);

        $trackingdefault = COMPLETION_TRACKING_NONE;
        // If system and activity default completion is on, set it.
        if ($defaultcompletion) {
            $hasrules = plugin_supports('mod', $modname, FEATURE_COMPLETION_HAS_RULES, true);
            if ($hasrules || $supportviews) {
                $trackingdefault = COMPLETION_TRACKING_AUTOMATIC;
            } else {
                $trackingdefault = COMPLETION_TRACKING_MANUAL;
            }
        }

        $mform->addElement(
            'select',
            'completion',
            get_string('completion', 'completion'),
            [
                COMPLETION_TRACKING_NONE => get_string('completion_none', 'completion'),
                COMPLETION_TRACKING_MANUAL => get_string('completion_manual', 'completion'),
            ]
        );
        $mform->setDefault('completion', $trackingdefault);
        $mform->addHelpButton('completion', 'completion', 'completion');

        // Automatic completion once you view it.
        $autocompletionpossible = false;
        if ($supportviews) {
            $mform->addElement('checkbox', 'completionview', get_string('completionview', 'completion'),
                get_string('completionview_desc', 'completion'));
            $mform->hideIf('completionview', 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
            // Check by default if automatic completion tracking is set.
            if ($trackingdefault == COMPLETION_TRACKING_AUTOMATIC) {
                $mform->setDefault('completionview', 1);
            }
            $autocompletionpossible = true;
        }

        // If the activity supports grading, the grade elements must be added.
        if ($supportgrades) {
            $autocompletionpossible = true;
            $this->add_completiongrade_elements($modname, $rating);
        }

        // Automatic completion according to module-specific rules.
        $customcompletionelements = $this->add_completion_rules();
        if (property_exists($this, '_customcompletionelements')) {
            $this->_customcompletionelements = $customcompletionelements;
        }

        if ($customcompletionelements !== null) {
            foreach ($customcompletionelements as $element) {
                $mform->hideIf($element, 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
            }
            $autocompletionpossible = $autocompletionpossible || count($customcompletionelements) > 0;
        }

        // Automatic option only appears if possible.
        if ($autocompletionpossible) {
            $mform->getElement('completion')->addOption(
                get_string('completion_automatic', 'completion'),
                COMPLETION_TRACKING_AUTOMATIC);
        }

        // Completion expected at particular date? (For progress tracking).
        $mform->addElement('date_time_selector', 'completionexpected', get_string('completionexpected', 'completion'),
                ['optional' => true]);
        $mform->addHelpButton('completionexpected', 'completionexpected', 'completion');
        $mform->hideIf('completionexpected', 'completion', 'eq', COMPLETION_TRACKING_NONE);
    }

    /**
     * Add completion grade elements to the form.
     *
     * @param string $modname The name of the module (for example, 'assign').
     * @param bool $rating True if the rating feature is enabled and false otherwise.
     */
    protected function add_completiongrade_elements(
        string $modname,
        bool $rating = false
    ): void {
        $mform = $this->get_form();

        $completionelementexists = $mform->elementExists('completion');
        $component = "mod_{$modname}";
        $itemnames = component_gradeitems::get_itemname_mapping_for_component($component);
        if (count($itemnames) === 1) {
            // Only one gradeitem in this activity.
            // We use the completionusegrade field here.
            $mform->addElement(
                'checkbox',
                'completionusegrade',
                get_string('completionusegrade', 'completion'),
                get_string('completionusegrade_desc', 'completion')
            );
            $mform->addHelpButton('completionusegrade', 'completionusegrade', 'completion');

            // Complete if the user has reached the pass grade.
            $mform->addElement(
                'checkbox',
                'completionpassgrade', null,
                get_string('completionpassgrade_desc', 'completion')
            );
            $mform->disabledIf('completionpassgrade', 'completionusegrade', 'notchecked');
            $mform->addHelpButton('completionpassgrade', 'completionpassgrade', 'completion');

            if ($completionelementexists) {
                $mform->hideIf('completionpassgrade', 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
                $mform->hideIf('completionusegrade', 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
            }

            // The disabledIf logic differs between ratings and other grade items due to different field types.
            if ($rating) {
                // If using the rating system, there is no grade unless ratings are enabled.
                $mform->disabledIf('completionusegrade', 'assessed', 'eq', 0);
                $mform->disabledIf('completionpassgrade', 'assessed', 'eq', 0);
            } else {
                // All other field types use the '$gradefieldname' field's modgrade_type.
                $itemnumbers = array_keys($itemnames);
                $itemnumber = array_shift($itemnumbers);
                $gradefieldname = component_gradeitems::get_field_name_for_itemnumber($component, $itemnumber, 'grade');
                $mform->disabledIf('completionusegrade', "{$gradefieldname}[modgrade_type]", 'eq', 'none');
                $mform->disabledIf('completionpassgrade', "{$gradefieldname}[modgrade_type]", 'eq', 'none');
            }
        } else if (count($itemnames) > 1) {
            // There are multiple grade items in this activity.
            // Show them all.
            $options = [
                '' => get_string('activitygradenotrequired', 'completion'),
            ];
            foreach ($itemnames as $itemnumber => $itemname) {
                $options[$itemnumber] = get_string("grade_{$itemname}_name", $component);
            }

            $mform->addElement(
                'select',
                'completiongradeitemnumber',
                get_string('completionusegrade', 'completion'),
                $options
            );

            // Complete if the user has reached the pass grade.
            $mform->addElement(
                'checkbox',
                'completionpassgrade', null,
                get_string('completionpassgrade_desc', 'completion')
            );
            $mform->disabledIf('completionpassgrade', 'completiongradeitemnumber', 'eq', '');
            $mform->addHelpButton('completionpassgrade', 'completionpassgrade', 'completion');

            if ($completionelementexists) {
                $mform->hideIf('completiongradeitemnumber', 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
                $mform->hideIf('completionpassgrade', 'completion', 'ne', COMPLETION_TRACKING_AUTOMATIC);
            }
        }
    }

    /**
     * Perform some extra validation for completion settings.
     *
     * @param array $data Array of ["fieldname" => value] of submitted data.
     * @return array List of ["element_name" => "error_description"] if there are errors or an empty array if everything is OK.
     */
    protected function validate_completion(array $data): array {
        $errors = [];

        // Completion: Don't let them choose automatic completion without turning on some conditions.
        $automaticcompletion = array_key_exists('completion', $data) && $data['completion'] == COMPLETION_TRACKING_AUTOMATIC;
        // Ignore this check when completion settings are locked, as the options are then disabled.
        $automaticcompletion = $automaticcompletion && !empty($data['completionunlocked']);
        if ($automaticcompletion) {
            // View to complete.
            $rulesenabled = !empty($data['completionview']);

            // Use grade to complete (only one grade item).
            $rulesenabled = $rulesenabled || !empty($data['completionusegrade']) || !empty($data['completionpassgrade']);

            // Use grade to complete (specific grade item).
            if (!$rulesenabled && isset($data['completiongradeitemnumber'])) {
                $rulesenabled = $data['completiongradeitemnumber'] != '';
            }

            // Module-specific completion rules.
            $rulesenabled = $rulesenabled || $this->completion_rule_enabled($data);

            if (!$rulesenabled) {
                // No rules are enabled. Can't set automatically completed without rules.
                $errors['completion'] = get_string('badautocompletion', 'completion');
            }
        }

        return $errors;
    }

    /**
     * It should be called from the definition_after_data() to setup the completion settings in the form.
     */
    protected function definition_after_data_completion(): void {
        global $COURSE;

        $mform = $this->get_form();

        $completion = new \completion_info($COURSE);
        if ($completion->is_enabled()) {
            // If anybody has completed the activity, these options will be 'locked'.
            $cm = $this->get_cm();
            $completedcount = empty($cm) ? 0 : $completion->count_user_data($cm);
            $freeze = false;
            if (!$completedcount) {
                if ($mform->elementExists('unlockcompletion')) {
                    $mform->removeElement('unlockcompletion');
                }
                // Automatically set to unlocked. Note: this is necessary in order to make it recalculate completion once
                // the option is changed, maybe someone has completed it now.
                $mform->getElement('completionunlocked')->setValue(1);
            } else {
                // Has the element been unlocked, either by the button being pressed in this request, or the field already
                // being set from a previous one?
                if ($mform->exportValue('unlockcompletion') || $mform->exportValue('completionunlocked')) {
                    // Yes, add in warning text and set the hidden variable.
                    $completedunlockedel = $mform->createElement(
                        'static',
                        'completedunlocked',
                        get_string('completedunlocked', 'completion'),
                        get_string('completedunlockedtext', 'completion')
                    );
                    $mform->insertElementBefore($completedunlockedel, 'unlockcompletion');
                    $mform->removeElement('unlockcompletion');
                    $mform->getElement('completionunlocked')->setValue(1);
                } else {
                    // No, add in the warning text with the count (now we know it) before the unlock button.
                    $completedwarningel = $mform->createElement(
                        'static',
                        'completedwarning',
                        get_string('completedwarning', 'completion'),
                        get_string('completedwarningtext', 'completion', $completedcount)
                    );
                    $mform->insertElementBefore($completedwarningel, 'unlockcompletion');
                    $freeze = true;
                }
            }

            if ($freeze) {
                $mform->freeze('completion');
                if ($mform->elementExists('completionview')) {
                    // Don't use hardFreeze or checkbox value gets lost.
                    $mform->freeze('completionview');
                }
                if ($mform->elementExists('completionusegrade')) {
                    $mform->freeze('completionusegrade');
                }
                if ($mform->elementExists('completionpassgrade')) {
                    $mform->freeze('completionpassgrade');

                    // Has the completion pass grade completion criteria been set? If it has, then we shouldn't change
                    // the gradepass field.
                    if ($mform->exportValue('completionpassgrade')) {
                        $mform->freeze('gradepass');
                    }
                }
                if ($mform->elementExists('completiongradeitemnumber')) {
                    $mform->freeze('completiongradeitemnumber');
                }
                if (property_exists($this, '_customcompletionelements')) {
                    $mform->freeze($this->_customcompletionelements);
                }
            }
        }
    }
}
