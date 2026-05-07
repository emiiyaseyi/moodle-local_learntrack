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
 * LearnTrack - Manager Invite by Email
 * Sends email+in-app invites to people to manage a learning path.
 * Access: path admin page header button OR manage.php path list.
 */
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;

require_login();
$ctx = context_system::instance();

// If a non-admin lands here with a token, redirect them to the public acceptance page
$token_redirect = optional_param('token', '', PARAM_ALPHANUM);
if ($token_redirect && !has_capability('local/learnpath:manage', $ctx)) {
    redirect(new moodle_url('/local/learnpath/invite-accept.php', ['token' => $token_redirect]));
}

require_capability('local/learnpath:manage', $ctx);

global $DB, $USER, $OUTPUT, $CFG;

$groupid = required_param('groupid', PARAM_INT);
$group   = DH::get_group($groupid);
if (!$group) { throw new moodle_exception('invalidgroup', 'local_learnpath'); }

$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// Auto-expire old pending invites
$expiry_h = (int)(get_config('local_learnpath', 'invite_expiry_hours') ?: 24);
$DB->execute(
    "UPDATE {local_learnpath_mgr_invites} SET status='expired' WHERE status='pending' AND timecreated < :cutoff",
    ['cutoff' => time() - ($expiry_h * 3600)]
);

// Any token in the URL (old email links, new notification links) → go to the public acceptance page
$token_any = optional_param('token', '', PARAM_ALPHANUM);
if ($token_any) {
    redirect(new moodle_url('/local/learnpath/invite-accept.php', ['token' => $token_any]));
}

// Handle POST: send invites
$sent = 0; $errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $raw_emails = optional_param('invite_emails', '', PARAM_TEXT);
    $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw_emails)));

    foreach ($emails as $email) {
        if (!validate_email($email)) {
            $errors[] = $email . ' — invalid email address';
            continue;
        }
        // Check if already a manager
        $existing_user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        if ($existing_user && $DB->record_exists('local_learnpath_managers',
                ['groupid' => $groupid, 'userid' => $existing_user->id])) {
            $errors[] = $email . ' — already a manager for this path';
            continue;
        }
        // Create invite token
        $token_val = bin2hex(random_bytes(24));
        $invite_id = $DB->insert_record('local_learnpath_mgr_invites', (object)[
            'groupid'     => $groupid,
            'email'       => $email,
            'token'       => $token_val,
            'invitedby'   => $USER->id,
            'status'      => 'pending',
            'timecreated' => time(),
        ]);

        // Public acceptance URL — no admin capability required
        $accept_url = (new moodle_url('/local/learnpath/invite-accept.php', [
            'token' => $token_val,
        ]))->out(false);

        $brand_name  = get_config('local_learnpath', 'brand_name') ?: 'LearnTrack';
        $inviter     = fullname($USER);
        $subject     = $brand_name . ': You have been invited to manage "' . format_string($group->name) . '"';
        $expiry_note = "This invitation link expires in {$expiry_h} hours if not accepted.";
        $body_plain  = "Hi,\n\n{$inviter} has invited you to be a manager for the learning path: \"{$group->name}\".\n\nAs a manager, you can view learner progress, send reminders, and view reports for this path.\n\nClick the link below to accept:\n{$accept_url}\n\n{$expiry_note}\n\nRegards,\n{$brand_name}";
        // Branded HTML template (same look as reminder emails).
        // Pass the invitee's first name so the salutation reads "Hi John," not just "Hi,"
        $invitee_firstname = $existing_user ? $existing_user->firstname : '';
        $body_html = \local_learnpath\notification\notifier::build_invite_html(
            $inviter, format_string($group->name), $accept_url, $expiry_note, $invitee_firstname
        );

        // For Moodle users: message_send() carries proper anti-spam headers
        // (Auto-Submitted, X-Moodle-*, List-Unsubscribe) that keep it out of firewalls.
        if ($existing_user) {
            $msg = new \core\message\message();
            $msg->component         = 'local_learnpath';
            $msg->name              = 'learntrack_reminder';
            $msg->userfrom          = $USER;
            $msg->userto            = $existing_user;   // real user → email + popup
            $msg->subject           = $subject;
            $msg->fullmessage       = $body_plain;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml   = $body_html;
            $msg->smallmessage      = 'Manager invite: ' . format_string($group->name);
            $msg->notification      = 1;
            $msg->contexturl        = $accept_url;
            $msg->contexturlname    = 'Accept Invitation';
            message_send($msg);
        } else {
            // External email (not a Moodle account): fall back to email_to_user.
            $to_user            = \core_user::get_noreply_user();
            $to_user->email     = $email;
            $to_user->firstname = '';
            $to_user->lastname  = $email;
            $noreply            = \core_user::get_noreply_user();
            $noreply->firstname = $brand_name;
            $noreply->lastname  = '';
            email_to_user($to_user, $noreply, $subject, $body_plain, $body_html);
        }
        $sent++;
    }

    if ($sent > 0) {
        redirect(
            new moodle_url('/local/learnpath/manager-invite.php', ['groupid' => $groupid]),
            "{$sent} invitation(s) sent.",
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Handle revoke
$revoke_id = optional_param('revoke', 0, PARAM_INT);
if ($revoke_id && confirm_sesskey()) {
    $DB->update_record('local_learnpath_mgr_invites', (object)[
        'id' => $revoke_id, 'status' => 'revoked',
    ]);
    redirect(
        new moodle_url('/local/learnpath/manager-invite.php', ['groupid' => $groupid]),
        'Invitation revoked.'
    );
}

// Handle resend (expired or pending invites)
$resend_id = optional_param('resend', 0, PARAM_INT);
if ($resend_id && confirm_sesskey()) {
    $old_inv = $DB->get_record('local_learnpath_mgr_invites', ['id' => $resend_id, 'groupid' => $groupid]);
    if ($old_inv && in_array($old_inv->status, ['expired', 'pending', 'revoked'])) {
        $new_token = bin2hex(random_bytes(24));
        $DB->update_record('local_learnpath_mgr_invites', (object)[
            'id'          => $old_inv->id,
            'token'       => $new_token,
            'status'      => 'pending',
            'timecreated' => time(),
        ]);
        $accept_url_resend = (new moodle_url('/local/learnpath/invite-accept.php', ['token' => $new_token]))->out(false);
        $brand_name_r  = get_config('local_learnpath', 'brand_name') ?: 'LearnTrack';
        $inviter_r     = fullname($USER);
        $subject_r     = $brand_name_r . ': You have been invited to manage "' . format_string($group->name) . '"';
        $expiry_note_r = "This invitation link expires in {$expiry_h} hours if not accepted.";
        $existing_r    = $DB->get_record('user', ['email' => $old_inv->email, 'deleted' => 0]);
        $invitee_fn_r  = $existing_r ? $existing_r->firstname : '';
        $plain_r = ($invitee_fn_r ? "Hi {$invitee_fn_r},\n\n" : "Hi,\n\n")
            . "{$inviter_r} has re-sent your invitation to manage \"{$group->name}\".\n\nAccept here: {$accept_url_resend}\n\n{$expiry_note_r}\n\nRegards,\n{$brand_name_r}";
        $html_r = \local_learnpath\notification\notifier::build_invite_html(
            $inviter_r, format_string($group->name), $accept_url_resend, $expiry_note_r, $invitee_fn_r
        );
        if ($existing_r) {
            // Moodle user: message_send adds anti-spam headers, delivers email + popup.
            $msg_r = new \core\message\message();
            $msg_r->component = 'local_learnpath'; $msg_r->name = 'learntrack_reminder';
            $msg_r->userfrom = $USER; $msg_r->userto = $existing_r;
            $msg_r->subject = $subject_r; $msg_r->fullmessage = $plain_r;
            $msg_r->fullmessageformat = FORMAT_PLAIN; $msg_r->fullmessagehtml = $html_r;
            $msg_r->smallmessage = 'Manager invite (resent): ' . format_string($group->name);
            $msg_r->notification = 1; $msg_r->contexturl = $accept_url_resend;
            $msg_r->contexturlname = 'Accept Invitation';
            message_send($msg_r);
        } else {
            // External email (no Moodle account): use email_to_user directly.
            $to_user_r = \core_user::get_noreply_user();
            $to_user_r->email = $old_inv->email; $to_user_r->firstname = ''; $to_user_r->lastname = $old_inv->email;
            $noreply_r = \core_user::get_noreply_user(); $noreply_r->firstname = $brand_name_r; $noreply_r->lastname = '';
            email_to_user($to_user_r, $noreply_r, $subject_r, $plain_r, $html_r);
        }
        redirect(new moodle_url('/local/learnpath/manager-invite.php', ['groupid' => $groupid]),
            'Invitation resent to ' . s($old_inv->email) . '.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// ── Page output ───────────────────────────────────────────────────────────────
$PAGE->set_url(new moodle_url('/local/learnpath/manager-invite.php', ['groupid' => $groupid]));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('Manager Invites — ' . format_string($group->name));

echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/manage.php'), '← Manage Paths',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo html_writer::link(new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid]),
    '📊 ' . format_string($group->name),
    ['style' => 'display:inline-block;margin-bottom:14px;margin-left:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">👥 Invite Path Managers</h1>';
echo '<p class="lt-page-subtitle">' . format_string($group->name) . ' — Send email invitations to manage this path</p>';
echo '</div></div></div>';

if (!empty($errors)) {
    foreach ($errors as $err) {
        echo '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-family:var(--lt-font);font-size:.84rem;color:#991b1b">⚠ ' . htmlspecialchars($err) . '</div>';
    }
}

// Invite form
echo '<div class="lt-card" style="max-width:580px;margin-bottom:20px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">✉️ Send Invitations</h3></div>';
echo '<div class="lt-card-body">';
echo '<form method="post">';
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo '<div style="margin-bottom:12px;font-family:var(--lt-font)">';
echo '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Email Addresses</label>';
echo '<textarea name="invite_emails" rows="3" placeholder="manager@example.com, another@company.com"';
echo ' style="width:100%;font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:9px 12px;box-sizing:border-box;resize:vertical;outline:none"></textarea>';
echo '<div style="font-size:.72rem;color:#9ca3af;margin-top:4px">Separate multiple addresses with commas or line breaks. Each person will receive an email with a secure link to accept their manager role.</div>';
echo '</div>';
echo '<button type="submit" class="lt-btn lt-btn-primary">📨 Send Invitations</button>';
echo '</form></div></div>';

// Pending invites
$invites = $DB->get_records_sql(
    'SELECT mi.*, u.firstname, u.lastname
     FROM {local_learnpath_mgr_invites} mi
     LEFT JOIN {user} u ON u.email = mi.email AND u.deleted = 0
     WHERE mi.groupid = :gid
     ORDER BY mi.timecreated DESC',
    ['gid' => $groupid]
);

echo '<div class="lt-card">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">📋 Invitation History</h3></div>';
if (empty($invites)) {
    echo '<div class="lt-card-body" style="color:#9ca3af;font-family:var(--lt-font);font-size:.84rem">No invitations sent yet.</div>';
} else {
    echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
    foreach (['Email', 'Moodle User', 'Status', 'Sent', 'Accepted', 'Actions'] as $h) {
        echo '<th>' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($invites as $inv) {
        // Show expiry time for pending invites
        $expiry_h2 = (int)(get_config('local_learnpath', 'invite_expiry_hours') ?: 24);
        $expires_at = $inv->timecreated + ($expiry_h2 * 3600);
        $time_left = $expires_at - time();
        $expires_str = $time_left > 0
            ? 'Expires in ' . round($time_left/3600, 1) . 'h'
            : 'Expired';
        $status_badge = match($inv->status) {
            'accepted' => '<span style="background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:100px">✓ Accepted</span>',
            'revoked'  => '<span style="background:#f3f4f6;color:#9ca3af;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:100px">✕ Revoked</span>',
            'expired'  => '<span style="background:#fee2e2;color:#be123c;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:100px">⌛ Expired</span>',
            'pending'  => '<span style="background:#fef3c7;color:#92400e;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:100px">⏳ Pending · ' . $expires_str . '</span>',
            default    => '<span style="background:#fef3c7;color:#92400e;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:100px">⏳ Pending</span>',
        };
        $user_name = $inv->firstname ? format_string($inv->firstname . ' ' . $inv->lastname) : '—';
        $accepted  = $inv->timeaccepted ? userdate($inv->timeaccepted, get_string('strftimedatefullshort')) : '—';
        echo '<tr>';
        echo '<td style="font-weight:600">' . s($inv->email) . '</td>';
        echo '<td>' . $user_name . '</td>';
        echo '<td>' . $status_badge . '</td>';
        echo '<td style="font-size:.78rem;color:#6b7280">' . userdate($inv->timecreated, get_string('strftimedatefullshort')) . '</td>';
        echo '<td style="font-size:.78rem;color:#6b7280">' . $accepted . '</td>';
        echo '<td style="white-space:nowrap">';
        $accept_url_show = (new moodle_url('/local/learnpath/invite-accept.php', ['token' => $inv->token]))->out(false);
        if ($inv->status === 'pending') {
            $rev_url = new moodle_url('/local/learnpath/manager-invite.php', [
                'groupid' => $groupid, 'revoke' => $inv->id, 'sesskey' => sesskey(),
            ]);
            echo html_writer::link($rev_url, 'Revoke',
                ['style' => 'font-size:.76rem;color:#be123c;text-decoration:none;margin-right:6px',
                 'onclick' => "return confirm('Revoke this invitation?')"]);
            echo '<button onclick="navigator.clipboard.writeText(\'' . s($accept_url_show) . '\');this.textContent=\'Copied!\';"'
                . ' style="font-size:.72rem;background:#eff6ff;color:#1e40af;border:none;border-radius:4px;padding:2px 7px;cursor:pointer;margin-right:4px">Copy Link</button>';
        }
        if (in_array($inv->status, ['expired', 'revoked'])) {
            $resend_url = new moodle_url('/local/learnpath/manager-invite.php', [
                'groupid' => $groupid, 'resend' => $inv->id, 'sesskey' => sesskey(),
            ]);
            echo html_writer::link($resend_url, '&#128257; Resend',
                ['style' => 'font-size:.76rem;color:#1e40af;text-decoration:none;font-weight:700',
                 'onclick' => "return confirm('Resend invitation to " . s($inv->email) . "?')"]);
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

echo $OUTPUT->footer();
