<?php
namespace local_learnpath\form;

defined('MOODLE_INTERNAL') || die();
require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class schedule_form extends \moodleform {

    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id',      0);
        $mform->addElement('hidden', 'groupid', 0);
        $mform->setType('id',      PARAM_INT);
        $mform->setType('groupid', PARAM_INT);

        $mform->addElement('textarea', 'recipients', 'Email Recipients', ['rows' => 3, 'cols' => 60]);
        $mform->setType('recipients', PARAM_TEXT);
        $mform->addRule('recipients', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('recipients', 'schedule_recipients', 'local_learnpath');

        $mform->addElement('select', 'frequency', 'Frequency', [
            'daily'   => 'Daily',
            'weekly'  => 'Weekly',
            'monthly' => 'Monthly',
        ]);
        $mform->setDefault('frequency', 'weekly');

        $mform->addElement('select', 'format', 'File Format', [
            'xlsx' => 'Excel (.xlsx)',
            'csv'  => 'CSV',
            'pdf'  => 'PDF',
        ]);

        $mform->addElement('select', 'viewmode', 'Report View', [
            'summary' => 'Learner summary',
            'detail'  => 'Per-course detail',
        ]);

        $mform->addElement('checkbox', 'enabled', 'Active');
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons();
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $emails = array_map('trim', explode(',', $data['recipients'] ?? ''));
        foreach ($emails as $email) {
            if ($email && !\validate_email($email)) {
                $errors['recipients'] = get_string('invalidemail');
                break;
            }
        }
        return $errors;
    }
}
