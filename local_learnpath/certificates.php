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

/**
 * LearnTrack — Certificate Registry & Verification
 * View all issued certificates and verify by ref number.
 */
require_once(__DIR__ . '/../../config.php');

require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:viewdashboard', $ctx);

$verify_ref = optional_param('ref',    '',  PARAM_TEXT);
$page       = optional_param('page',   0,   PARAM_INT);
$perpage    = 25;

$PAGE->set_url(new moodle_url('/local/learnpath/certificates.php'));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Certificates');

global $DB, $OUTPUT, $USER;
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$isadmin = has_capability('local/learnpath:manage', $ctx);

$dbman = $DB->get_manager();
$has_certs = $dbman->table_exists(new xmldb_table('local_learnpath_certs'));

echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome',
    ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

try {

echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">🎓 Certificate Registry</h1>';
echo '<p class="lt-page-subtitle">All issued certificates · Verify by reference number</p>';
echo '</div></div></div>';

// ── Verification panel ────────────────────────────────────────────────────────
echo '<div class="lt-card" style="margin-bottom:20px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">🔍 Verify a Certificate</h3></div>';
echo '<div class="lt-card-body">';
echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
echo '<div>';
echo '<label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Reference Number</label>';
echo '<input type="text" name="ref" value="' . s($verify_ref) . '" placeholder="e.g. LMS-PYTHON-042026-0042"
    style="font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 14px;min-width:280px;outline:none">';
echo '</div>';
echo '<button type="submit" class="lt-btn lt-btn-primary">🔍 Verify</button>';
echo '</form>';

if ($verify_ref !== '' && $has_certs) {
    $cert = $DB->get_record('local_learnpath_certs', ['certnumber' => trim($verify_ref)]);
    if ($cert) {
        $cert_learner = $DB->get_record('user', ['id' => $cert->userid, 'deleted' => 0]);
        $cert_group   = $DB->get_record('local_learnpath_groups', ['id' => $cert->groupid]);
        $cert_issuer  = $DB->get_record('user', ['id' => $cert->issuedby, 'deleted' => 0]);
        echo '<div style="margin-top:16px;background:#d1fae5;border:1.5px solid #6ee7b7;border-radius:10px;padding:16px 20px;font-family:var(--lt-font)">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">';
        echo '<span style="font-size:1.5rem">✅</span>';
        echo '<div><div style="font-size:.9rem;font-weight:700;color:#065f46">Certificate Verified</div>';
        echo '<div style="font-size:.74rem;color:#059669">Ref: ' . s($cert->certnumber) . '</div></div></div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:.82rem">';
        echo '<div><span style="color:#374151;font-weight:600">Learner:</span> ' . ($cert_learner ? fullname($cert_learner) : '—') . '</div>';
        echo '<div><span style="color:#374151;font-weight:600">Learning Path:</span> ' . ($cert_group ? format_string($cert_group->name) : '—') . '</div>';
        echo '<div><span style="color:#374151;font-weight:600">Issued:</span> ' . userdate($cert->issuedate, get_string('strftimedatefullshort')) . '</div>';
        echo '<div><span style="color:#374151;font-weight:600">Issued by:</span> ' . ($cert_issuer ? fullname($cert_issuer) : 'System') . '</div>';
        echo '</div></div>';
    } else {
        echo '<div style="margin-top:16px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 18px;font-family:var(--lt-font)">';
        echo '<span style="font-size:1.1rem">❌</span> <strong style="color:#be123c">Certificate not found.</strong>';
        echo ' <span style="font-size:.82rem;color:#9ca3af">Check the reference number and try again.</span></div>';
    }
}
echo '</div></div>';

// ── All certificates table ────────────────────────────────────────────────────
if ($has_certs) {
    $total = (int)$DB->count_records('local_learnpath_certs');
    $certs = $DB->get_records_sql(
        "SELECT lc.*, u.firstname, u.lastname, u.email,
                lpg.name AS pathname,
                ib.firstname AS iss_first, ib.lastname AS iss_last
         FROM {local_learnpath_certs} lc
         JOIN {user} u ON u.id = lc.userid AND u.deleted = 0
         JOIN {local_learnpath_groups} lpg ON lpg.id = lc.groupid
         LEFT JOIN {user} ib ON ib.id = lc.issuedby
         ORDER BY lc.issuedate DESC",
        [], $page * $perpage, $perpage
    );

    echo '<div class="lt-card">';
    echo '<div class="lt-card-header"><h3 class="lt-card-title">🎓 All Issued Certificates</h3>';
    echo '<span style="font-family:var(--lt-font);font-size:.74rem;color:#9ca3af">' . $total . ' total</span></div>';

    if (empty($certs)) {
        echo '<div class="lt-card-body"><p style="font-family:var(--lt-font);font-size:.84rem;color:#9ca3af">No certificates have been issued yet.</p></div>';
    } else {
        echo '<div style="overflow-x:auto"><table class="lt-data-table">';
        echo '<thead><tr>';
        foreach (['Ref #', 'Learner', 'Email', 'Learning Path', 'Issue Date', 'Issued By', ''] as $h) {
            echo '<th>' . $h . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($certs as $ct) {
            $profile_url = new moodle_url('/local/learnpath/profile.php',
                ['userid' => $ct->userid, 'groupid' => $ct->groupid]);
            $verify_url  = new moodle_url('/local/learnpath/certificates.php', ['ref' => $ct->certnumber]);
            echo '<tr>';
            echo '<td><code style="font-size:.76rem;font-weight:700;color:var(--lt-accent)">' . s($ct->certnumber) . '</code></td>';
            echo '<td><a href="' . $profile_url . '" style="font-weight:700;color:var(--lt-accent);text-decoration:none">'
                . format_string($ct->firstname . ' ' . $ct->lastname) . '</a></td>';
            echo '<td style="color:#6b7280;font-size:.8rem">' . s($ct->email) . '</td>';
            echo '<td style="font-weight:600">' . format_string($ct->pathname) . '</td>';
            echo '<td style="white-space:nowrap;color:#374151">' . userdate($ct->issuedate, get_string('strftimedatefullshort')) . '</td>';
            echo '<td style="color:#6b7280">' . format_string(($ct->iss_first ?? '') . ' ' . ($ct->iss_last ?? '')) . '</td>';
            echo '<td>';
            echo html_writer::link($verify_url, '🔍 Verify',
                ['style' => 'font-size:.74rem;font-weight:700;color:var(--lt-accent);text-decoration:none;white-space:nowrap']);
            if ($isadmin) {
                $revoke_url = new moodle_url('/local/learnpath/profile.php',
                    ['userid' => $ct->userid, 'groupid' => $ct->groupid,
                     'revokecert' => 1, 'sesskey' => sesskey()]);
                echo ' &nbsp;';
                echo html_writer::link($revoke_url, '🗑 Revoke',
                    ['style' => 'font-size:.74rem;font-weight:700;color:#ef4444;text-decoration:none;white-space:nowrap',
                     'onclick' => "return confirm('Revoke this certificate?')"]);
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Pagination
        if ($total > $perpage) {
            $pages = (int)ceil($total / $perpage);
            $base  = new moodle_url('/local/learnpath/certificates.php');
            echo '<div class="lt-pagination" style="padding:12px 16px;border-top:1px solid #f3f4f6">';
            echo '<span>' . $total . ' certificates · Page ' . ($page+1) . ' of ' . $pages . '</span>';
            echo '<div style="display:flex;gap:4px;margin-left:auto">';
            if ($page > 0) {
                $prev = clone $base; $prev->param('page', $page-1);
                echo html_writer::link($prev, '← Prev', ['class'=>'lt-page-link inactive']);
            }
            for ($i = max(0, $page-2); $i <= min($pages-1, $page+2); $i++) {
                $pg = clone $base; $pg->param('page', $i);
                echo html_writer::link($pg, $i+1, ['class'=>'lt-page-link '.($i===$page?'active':'inactive')]);
            }
            if ($page < $pages-1) {
                $next = clone $base; $next->param('page', $page+1);
                echo html_writer::link($next, 'Next →', ['class'=>'lt-page-link inactive']);
            }
            echo '</div></div>';
        }
    }
    echo '</div>';
} else {
    echo '<div class="lt-empty-state"><div class="lt-empty-icon">🎓</div>';
    echo '<h3 class="lt-empty-title">Certificate system not yet active</h3>';
    echo '<p class="lt-empty-desc">Run Site Admin → Notifications to upgrade the plugin and enable certificates.</p>';
    echo '</div>';
}

} catch (\Throwable $e) {
    echo '<div style="background:#fee2e2;padding:16px;border-radius:10px;font-family:var(--lt-font)">Error: ' . s($e->getMessage()) . '</div>';
}

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank'])
    . '<span class="lt-sep">·</span><span>LearnTrack v1.0.0</span></div>';
echo $OUTPUT->footer();
