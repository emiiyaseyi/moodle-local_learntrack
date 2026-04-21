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
