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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * LearnTrack — Admin & Manager Assignment
 * Assign other site users as LearnTrack admins (full access) or managers
 * (feature-level access control).
 */
require_once(__DIR__ . '/../../config.php');
require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:manage', $ctx);

// Only the Moodle site admin can assign other admins
$is_siteadmin = is_siteadmin();

global $DB, $OUTPUT, $USER, $CFG;

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$target_uid = optional_param('userid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/learnpath/managers.php'));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Manager Access');

$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// Available features a manager can have access to
$all_features = [
    'dashboard'   => '📊 Dashboard (view progress)',
    'manage'      => '⚙️ Manage Paths (create/edit paths)',
    'branding'    => '🎨 Branding Settings',
    'leaderboard' => '🏆 Leaderboard & Badges',
    'reminders'   => '🔔 Reminders & Notifications',
    'export'      => '📥 Export Reports',
    'email'       => '✉️ Email Reports',
    'certs'       => '🎓 Issue Certificates',
];

// Helper: get feature flags for a user
function lt_get_manager_features(int $uid): array {
    $raw = get_config('local_learnpath', 'mgr_features_' . $uid);
    return $raw ? json_decode($raw, true) : [];
}

// Helper: set feature flags
function lt_set_manager_features(int $uid, array $features): void {
    set_config('local_learnpath', 'mgr_features_' . $uid, json_encode($features));
}

// Helper: check if user has a LearnTrack role assigned
function lt_has_learntrack_role(int $uid): bool {
    $ctx = context_system::instance();
    $roles = get_user_roles($ctx, $uid);
    foreach ($roles as $r) {
        if ($r->shortname === 'learntrackmanager') {
            return true;
        }
    }
    return false;
}

// ── ACTION HANDLERS ───────────────────────────────────────────────────────────
if ($action !== '' && confirm_sesskey()) {
    try {
        // Grant full admin access (assign local/learnpath:manage)
        if ($action === 'grant_admin' && $is_siteadmin && $target_uid > 0) {
            $target = $DB->get_record('user', ['id' => $target_uid, 'deleted' => 0], '*', MUST_EXIST);
            // Assign all features
            lt_set_manager_features($target_uid, array_keys($all_features));
            // Store in plugin config (list of admin UIDs)
            $admins = json_decode(get_config('local_learnpath', 'assigned_admins') ?: '[]', true);
            if (!in_array($target_uid, $admins)) {
                $admins[] = $target_uid;
                set_config('assigned_admins', json_encode($admins), 'local_learnpath');
            }
            // Assign local/learnpath:manage capability at system context
            $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
            if ($managerroleid) {
                role_assign($managerroleid, $target_uid, $ctx->id);
            }
            redirect(new moodle_url('/local/learnpath/managers.php'),
                format_string($target->firstname . ' ' . $target->lastname) . ' granted full LearnTrack admin access.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        }

        // Grant manager (feature-level) access
        if ($action === 'grant_manager' && $is_siteadmin && $target_uid > 0) {
            $target = $DB->get_record('user', ['id' => $target_uid, 'deleted' => 0], '*', MUST_EXIST);
            $features = optional_param_array('features', [], PARAM_ALPHANUMEXT);
            $valid = array_intersect($features, array_keys($all_features));
            lt_set_manager_features($target_uid, $valid);
            $managers = json_decode(get_config('local_learnpath', 'assigned_managers') ?: '[]', true);
            if (!in_array($target_uid, $managers)) {
                $managers[] = $target_uid;
                set_config('assigned_managers', json_encode($managers), 'local_learnpath');
            }
            redirect(new moodle_url('/local/learnpath/managers.php'),
                format_string($target->firstname . ' ' . $target->lastname) . ' added as LearnTrack manager.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        }

        // Update features for an existing manager
        if ($action === 'update_features' && $target_uid > 0) {
            $features = optional_param_array('features', [], PARAM_ALPHANUMEXT);
            $valid = array_intersect($features, array_keys($all_features));
            lt_set_manager_features($target_uid, $valid);
            redirect(new moodle_url('/local/learnpath/managers.php'),
                'Manager access updated.', null, \core\output\notification::NOTIFY_SUCCESS);
        }

        // Revoke access
        if ($action === 'revoke' && $is_siteadmin && $target_uid > 0) {
            // Remove from both lists
            foreach (['assigned_admins', 'assigned_managers'] as $cfg_key) {
                $list = json_decode(get_config('local_learnpath', $cfg_key) ?: '[]', true);
                $list = array_values(array_filter($list, fn($id) => $id !== $target_uid));
                set_config($cfg_key, json_encode($list), 'local_learnpath');
            }
            lt_set_manager_features($target_uid, []);
            // Unassign manager role from system context if present
            $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
            if ($managerroleid) {
                role_unassign($managerroleid, $target_uid, $ctx->id);
            }
            redirect(new moodle_url('/local/learnpath/managers.php'),
                'Access revoked.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $e_act) {
        redirect(new moodle_url('/local/learnpath/managers.php'),
            'Error: ' . $e_act->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ── RENDER ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome',
    ['style' => 'display:inline-block;margin-bottom:10px;margin-right:12px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo html_writer::link(new moodle_url('/local/learnpath/manage.php'), '← Manage Paths',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">👥 Admin &amp; Manager Access</h1>';
echo '<p class="lt-page-subtitle">Assign users as LearnTrack admins or feature-restricted managers</p>';
echo '</div></div></div>';

try {

// ── CURRENT ADMINS & MANAGERS ─────────────────────────────────────────────────
$assigned_admins   = json_decode(get_config('local_learnpath', 'assigned_admins')   ?: '[]', true);
$assigned_managers = json_decode(get_config('local_learnpath', 'assigned_managers') ?: '[]', true);

// Show current site admins who also have LearnTrack access
$siteadmin_ids = array_keys(get_admins());

echo '<div class="lt-card" style="margin-bottom:16px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">🛡️ Current LearnTrack Admins</h3>';
echo '<span style="font-size:.76rem;color:#9ca3af;font-family:var(--lt-font)">Full access — all features</span></div>';
echo '<div class="lt-card-body">';

$all_assigned = array_unique(array_merge($siteadmin_ids, $assigned_admins));
$admin_rows_shown = 0;
foreach ($all_assigned as $auid) {
    $auser = $DB->get_record('user', ['id' => $auid, 'deleted' => 0]);
    if (!$auser) continue;
    $is_sa = in_array($auid, $siteadmin_ids);
    echo '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;font-family:var(--lt-font)">';
    echo '<div style="width:38px;height:38px;border-radius:50%;background:var(--lt-accent);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.88rem;flex-shrink:0">' . strtoupper(substr($auser->firstname,0,1).substr($auser->lastname,0,1)) . '</div>';
    echo '<div style="flex:1">';
    echo '<div style="font-weight:700;font-size:.88rem;color:#111827">' . format_string($auser->firstname . ' ' . $auser->lastname) . '</div>';
    echo '<div style="font-size:.76rem;color:#6b7280">' . s($auser->email) . ($is_sa ? ' &nbsp;<span style="background:#dbeafe;color:#1e40af;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:100px">Site Admin</span>' : '') . '</div>';
    echo '</div>';
    if (!$is_sa && $is_siteadmin) {
        $revoke_url = new moodle_url('/local/learnpath/managers.php', ['action'=>'revoke','userid'=>$auid,'sesskey'=>sesskey()]);
        echo html_writer::link($revoke_url, 'Revoke', ['style'=>'font-size:.78rem;color:#ef4444;text-decoration:none;font-family:var(--lt-font);font-weight:700','onclick'=>"return confirm('Revoke LearnTrack admin access for this user?')"]);
    }
    echo '</div>';
    $admin_rows_shown++;
}
if ($admin_rows_shown === 0) {
    echo '<p style="font-family:var(--lt-font);color:#9ca3af;font-size:.84rem">No additional admins assigned.</p>';
}
echo '</div></div>';

// ── CURRENT MANAGERS ─────────────────────────────────────────────────────────
echo '<div class="lt-card" style="margin-bottom:16px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">🔑 Current Managers</h3>';
echo '<span style="font-size:.76rem;color:#9ca3af;font-family:var(--lt-font)">Feature-restricted access</span></div>';
echo '<div class="lt-card-body">';

if (empty($assigned_managers)) {
    echo '<p style="font-family:var(--lt-font);color:#9ca3af;font-size:.84rem">No managers assigned yet.</p>';
} else {
    foreach ($assigned_managers as $muid) {
        $muser = $DB->get_record('user', ['id' => $muid, 'deleted' => 0]);
        if (!$muser) continue;
        $mfeatures = lt_get_manager_features($muid);
        echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:10px;font-family:var(--lt-font)">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">';
        echo '<div style="width:38px;height:38px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.88rem;flex-shrink:0">' . strtoupper(substr($muser->firstname,0,1).substr($muser->lastname,0,1)) . '</div>';
        echo '<div style="flex:1"><div style="font-weight:700;font-size:.88rem;color:#111827">' . format_string($muser->firstname . ' ' . $muser->lastname) . '</div>';
        echo '<div style="font-size:.76rem;color:#6b7280">' . s($muser->email) . '</div></div>';
        if ($is_siteadmin) {
            $revoke_url = new moodle_url('/local/learnpath/managers.php', ['action'=>'revoke','userid'=>$muid,'sesskey'=>sesskey()]);
            echo html_writer::link($revoke_url, '🗑 Revoke', ['style'=>'font-size:.78rem;color:#ef4444;text-decoration:none;font-weight:700','onclick'=>"return confirm('Revoke all access for this manager?')"]);
        }
        echo '</div>';

        // Feature toggle form
        echo '<form method="post" style="margin:0">';
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'update_features']);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'userid','value'=>$muid]);
        echo '<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px">';
        foreach ($all_features as $fkey => $flbl) {
            $chk = in_array($fkey, $mfeatures) ? ' checked' : '';
            echo '<label style="display:flex;align-items:center;gap:5px;font-size:.78rem;cursor:pointer;padding:4px 9px;border:1.5px solid '.($chk?' var(--lt-accent)':'#e5e7eb').';border-radius:7px;background:'.($chk?'rgba(var(--lt-accent-rgb,30,58,95),.07)':'#fff').'">';
            echo '<input type="checkbox" name="features[]" value="'.$fkey.'"'.$chk.'>' . $flbl;
            echo '</label>';
        }
        echo '</div>';
        echo '<button type="submit" style="font-family:var(--lt-font);font-size:.78rem;font-weight:700;padding:5px 14px;border-radius:7px;border:none;background:var(--lt-accent);color:#fff;cursor:pointer">Save Access</button>';
        echo '</form></div>';
    }
}
echo '</div></div>';

// ── ADD NEW ADMIN / MANAGER ───────────────────────────────────────────────────
if ($is_siteadmin) {
    echo '<div class="lt-card">';
    echo '<div class="lt-card-header"><h3 class="lt-card-title">➕ Grant Access to a User</h3></div>';
    echo '<div class="lt-card-body">';

    // User search - find by name or email
    $search_q = optional_param('usersearch', '', PARAM_TEXT);
    echo '<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap">';
    echo '<input type="text" name="usersearch" value="' . s($search_q) . '" placeholder="Search by name or email…" style="font-family:var(--lt-font);font-size:.86rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;outline:none;width:280px">';
    echo '<button type="submit" style="font-family:var(--lt-font);font-size:.86rem;font-weight:700;padding:8px 16px;border-radius:8px;border:none;background:var(--lt-accent);color:#fff;cursor:pointer">Search</button>';
    echo '</form>';

    if ($search_q !== '') {
        $like = $DB->sql_like('CONCAT(u.firstname,\' \',u.lastname,\' \',u.email)', ':q', false);
        $search_users = $DB->get_records_sql(
            "SELECT u.id,u.firstname,u.lastname,u.email FROM {user} u
             WHERE u.deleted=0 AND u.id <> :me AND $like ORDER BY u.lastname,u.firstname LIMIT 20",
            ['q' => '%' . $DB->sql_like_escape($search_q) . '%', 'me' => $USER->id]
        );
        if (empty($search_users)) {
            echo '<p style="font-family:var(--lt-font);color:#9ca3af">No users found matching "' . s($search_q) . '".</p>';
        } else {
            foreach ($search_users as $su) {
                $already_admin   = in_array($su->id, array_merge($siteadmin_ids, $assigned_admins));
                $already_manager = in_array($su->id, $assigned_managers);
                echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:10px;font-family:var(--lt-font)">';
                echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">';
                echo '<div style="width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.84rem;color:#374151">' . strtoupper(substr($su->firstname,0,1).substr($su->lastname,0,1)) . '</div>';
                echo '<div><div style="font-weight:700;font-size:.88rem;color:#111827">' . format_string($su->firstname . ' ' . $su->lastname) . '</div>';
                echo '<div style="font-size:.76rem;color:#6b7280">' . s($su->email) . '</div></div>';
                if ($already_admin) {
                    echo '<span style="margin-left:auto;background:#dbeafe;color:#1e40af;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:100px">Already Admin</span>';
                } elseif ($already_manager) {
                    echo '<span style="margin-left:auto;background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:100px">Already Manager</span>';
                }
                echo '</div>';

                if (!$already_admin && !$already_manager) {
                    // Grant Admin form
                    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">';
                    $ga_url = new moodle_url('/local/learnpath/managers.php', ['action'=>'grant_admin','userid'=>$su->id,'sesskey'=>sesskey()]);
                    echo html_writer::link($ga_url, '🛡️ Grant Full Admin',
                        ['style'=>'font-family:var(--lt-font);font-size:.8rem;font-weight:700;padding:6px 14px;border-radius:8px;border:2px solid var(--lt-accent);color:var(--lt-accent);text-decoration:none;background:#fff',
                         'onclick'=>"return confirm('Grant full LearnTrack admin access to this user?')"]);
                    echo '</div>';

                    // Grant Manager form with feature checkboxes
                    echo '<form method="post">';
                    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
                    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'grant_manager']);
                    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'userid','value'=>$su->id]);
                    echo '<p style="font-size:.78rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin:0 0 8px">Grant Manager — select features:</p>';
                    echo '<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px">';
                    foreach ($all_features as $fkey => $flbl) {
                        $def_checked = in_array($fkey, ['dashboard','reminders']) ? ' checked' : '';
                        echo '<label style="display:flex;align-items:center;gap:5px;font-size:.78rem;cursor:pointer;padding:4px 9px;border:1.5px solid #e5e7eb;border-radius:7px;background:#fff">';
                        echo '<input type="checkbox" name="features[]" value="'.$fkey.'"'.$def_checked.'>' . $flbl;
                        echo '</label>';
                    }
                    echo '</div>';
                    echo '<button type="submit" style="font-family:var(--lt-font);font-size:.8rem;font-weight:700;padding:6px 14px;border-radius:8px;border:none;background:#6366f1;color:#fff;cursor:pointer">🔑 Grant Manager Access</button>';
                    echo '</form>';
                }
                echo '</div>';
            }
        }
    }
    echo '</div></div>';
}

if (!$is_siteadmin) {
    echo '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:14px 18px;font-family:var(--lt-font);color:#92400e;font-size:.86rem">';
    echo '⚠️ Only Moodle site administrators can add or remove managers. Contact your site admin to make changes.';
    echo '</div>';
}

} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran', 'LinkedIn', ['target'=>'_blank'])
    . '<span class="lt-sep">·</span><span>LearnTrack v1.0.0</span></div>';
echo $OUTPUT->footer();
