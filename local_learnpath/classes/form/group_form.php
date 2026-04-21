<?php
namespace local_learnpath\form;

defined('MOODLE_INTERNAL') || die();
require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Form for creating/editing a learning path group.
 * KEY: No addRule('required') on any field that uses hideIf — MDL-73242.
 * KEY: PARAM_RAW for autocomplete multi-select — MDL-71831.
 * KEY: Pass $PAGE->url as moodle_url object to constructor.
 */
class group_form extends \moodleform {

    public function definition(): void {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Name — plain text, safe to use addRule
        $mform->addElement('text', 'name', 'Path Name', ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        // Description
        $mform->addElement('textarea', 'description', 'Description', ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        // Group type
        $mform->addElement('select', 'grouptype', 'Group Type', [
            'manual'   => 'Manual course selection',
            'category' => 'By course category',
            'cohort'   => 'By cohort',
        ]);
        $mform->setType('grouptype', PARAM_ALPHA);
        $mform->setDefault('grouptype', 'manual');

        // Category selector
        $categories = $DB->get_records_menu('course_categories', null, 'name ASC', 'id, name');
        $categories = ['' => get_string('choosedots')] + $categories;
        $mform->addElement('select', 'categoryid', 'Course Category', $categories);
        $mform->setType('categoryid', PARAM_INT);
        $mform->hideIf('categoryid', 'grouptype', 'neq', 'category');

        // Cohort selector
        $cohorts = $DB->get_records_menu('cohort', null, 'name ASC', 'id, name');
        $cohorts = ['' => get_string('choosedots')] + $cohorts;
        $mform->addElement('select', 'cohortid', 'Cohort', $cohorts);
        $mform->setType('cohortid', PARAM_INT);
        $mform->hideIf('cohortid', 'grouptype', 'neq', 'cohort');

        // Course multi-select — PARAM_RAW for array values, no addRule
        $courseopts = $DB->get_records_menu('course', ['visible' => 1], 'fullname ASC', 'id, fullname');
        unset($courseopts[SITEID]);
        $mform->addElement('autocomplete', 'courseids', 'Courses', $courseopts, [
            'multiple'       => true,
            'noselectionstring' => get_string('choosedots'),
        ]);
        $mform->setType('courseids', PARAM_RAW);
        $mform->hideIf('courseids', 'grouptype', 'neq', 'manual');

        // ── Manual participant selection ──────────────────────────────────────────
        // Always visible — allows restricting who is tracked regardless of group type.
        // Uses autocomplete multi-select with PARAM_RAW (MDL-71831).
        $mform->addElement('header', 'participants_header', 'Manual Participant Selection (Optional)');
        $mform->addElement('static', 'participants_desc', '',
            '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.86rem;color:#1e40af;margin-bottom:8px">'
            . '<strong>Optional:</strong> Search and select specific learners to track in this path. '
            . 'If left empty, all enrolled users from the selected courses/category/cohort will be tracked. '
            . 'Selecting users here limits tracking to only those users.'
            . '</div>'
        );

        // User autocomplete — limited by admin-configured cap (max 500)
        $useropts = [];
        $total_users = $DB->count_records('user', ['deleted' => 0, 'confirmed' => 1, 'suspended' => 0]);
        $user_limit  = min(500, (int)(get_config('local_learnpath', 'participant_cap') ?: 500));
        $allusers = $DB->get_records_sql(
            "SELECT id, firstname, lastname, email, username,
                    firstnamephonetic, lastnamephonetic, middlename, alternatename
             FROM {user}
             WHERE deleted = 0 AND confirmed = 1 AND suspended = 0 AND id > 1
             ORDER BY lastname ASC, firstname ASC",
            [], 0, $user_limit
        );
        foreach ($allusers as $u) {
            $label = \fullname($u) . ' — ' . $u->email;
            $useropts[$u->id] = $label;
        }

        $mform->addElement('autocomplete', 'participant_userids',
            'Add Participants', $useropts, [
                'multiple'          => true,
                'noselectionstring' => 'Search by name or email address…',
                'tags'              => false,
                'casesensitive'     => false,
            ]
        );
        $mform->setType('participant_userids', PARAM_RAW);

        // Show note if site has more users than the limit
        if ($total_users > $user_limit) {
            $mform->addElement('static', 'participants_note', '',
                '<div style="background:#fefce8;border:1px solid #fde047;border-radius:8px;padding:8px 12px;font-size:.82rem;color:#854d0e">'
                . '⚠️ Your site has ' . $total_users . ' users. Only the first ' . $user_limit . ' are shown here. '
                . 'To assign more users, save this path first then use the <strong>Manage Learners</strong> button.'
                . '</div>'
            );
        }

        // Deadline
        $mform->addElement('date_selector', 'deadline', 'Completion Deadline', ['optional' => true]);
        $mform->setType('deadline', PARAM_INT);

        // Admin notes
        $mform->addElement('textarea', 'adminnotes', 'Admin Notes (private)', ['rows' => 2, 'cols' => 60]);
        $mform->setType('adminnotes', PARAM_TEXT);



        $this->add_action_buttons();
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $type   = $data['grouptype'] ?? 'manual';
        if ($type === 'category' && empty($data['categoryid'])) {
            $errors['categoryid'] = get_string('required');
        }
        if ($type === 'cohort' && empty($data['cohortid'])) {
            $errors['cohortid'] = get_string('required');
        }
        if ($type === 'manual') {
            $raw = $data['courseids'] ?? [];
            if (!is_array($raw)) {
                $raw = [$raw];
            }
            $clean = array_filter(array_map('intval', $raw), function ($v) { return $v > 0; });
            if (empty($clean)) {
                $errors['courseids'] = get_string('required');
            }
        }
        return $errors;
    }
}
