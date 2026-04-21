<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());
$PAGE->set_url(new moodle_url('/local/learnpath/lttest.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('Test');
global $OUTPUT;
echo $OUTPUT->header();
echo '<h1 style="font-family:sans-serif;padding:20px">✅ LearnTrack PHP is working</h1>';
echo '<p style="font-family:sans-serif;padding:0 20px">If you see this, the problem is in the use/DH autoload chain.</p>';
echo $OUTPUT->footer();
