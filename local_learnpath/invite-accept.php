<?php
/**
 * LearnTrack — Manager Invite Acceptance
 *
 * Deliberately bypasses Moodle's $OUTPUT->header()/footer() page rendering
 * so that NO Moodle JavaScript (AMD, theme, plugin hooks) is loaded on this page.
 * This is the only reliable way to prevent auto-redirect caused by Moodle JS.
 *
 * Session and DB access still use Moodle's core (require_login, $DB, sesskey).
 * The page outputs pure HTML with its own <head> — no theme wrapper.
 */
require_once(__DIR__ . '/../../config.php');
require_login(null, false); // false = don't redirect guests, just check session

global $DB, $USER, $CFG;

// Hard no-cache — browser must never serve a stale version of this page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$token    = required_param('token', PARAM_ALPHANUM);
$brand    = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$bname    = get_config('local_learnpath', 'brand_name')  ?: 'LearnTrack';
$expiry_h = (int)(get_config('local_learnpath', 'invite_expiry_hours') ?: 24);

// Load invite
$invite = $DB->get_record('local_learnpath_mgr_invites', ['token' => $token]);

// Auto-expire check
if ($invite && $invite->status === 'pending') {
    if ((time() - $invite->timecreated) > ($expiry_h * 3600)) {
        $DB->update_record('local_learnpath_mgr_invites', (object)['id' => $invite->id, 'status' => 'expired']);
        $invite->status = 'expired';
    }
}

$group   = $invite ? $DB->get_record('local_learnpath_groups', ['id' => $invite->groupid]) : null;
$inviter = $invite ? $DB->get_record('user', ['id' => $invite->invitedby, 'deleted' => 0]) : null;

// ── POST handler — the ONLY way to accept or reject ───────────────────────────
// GET requests are completely inert; nothing executes on a plain page load.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_sesskey = optional_param('sesskey', '', PARAM_RAW);
    $action       = optional_param('action',  '', PARAM_ALPHA);

    if (!confirm_sesskey()) {
        // Sesskey mismatch — just show the page again
        header('Location: ' . (new moodle_url('/local/learnpath/invite-accept.php', ['token' => $token]))->out(false));
        exit;
    }

    if ($invite && $invite->status === 'pending' && $group) {
        $email_match = strcasecmp(trim($USER->email), trim($invite->email)) === 0;

        if (!$email_match) {
            $back = (new moodle_url('/local/learnpath/invite-accept.php', ['token' => $token]))->out(false);
            header('Location: ' . $back);
            exit;
        }

        if ($action === 'accept') {
            if (!$DB->record_exists('local_learnpath_managers', ['groupid' => $invite->groupid, 'userid' => $USER->id])) {
                $DB->insert_record('local_learnpath_managers', (object)[
                    'groupid' => $invite->groupid,
                    'userid'  => $USER->id,
                    'scope'   => 'all',
                ]);
            }
            $DB->update_record('local_learnpath_mgr_invites', (object)[
                'id'           => $invite->id,
                'status'       => 'accepted',
                'timeaccepted' => time(),
            ]);
            // Notify inviter
            if ($inviter) {
                try {
                    $msg = new \core\message\message();
                    $msg->component = 'local_learnpath'; $msg->name = 'learntrack_reminder';
                    $msg->userfrom = $USER; $msg->userto = $inviter;
                    $msg->subject = fullname($USER) . ' accepted manager invite: ' . format_string($group->name);
                    $msg->fullmessage = fullname($USER) . ' accepted your invitation to manage "' . format_string($group->name) . '".';
                    $msg->fullmessageformat = FORMAT_PLAIN;
                    $msg->fullmessagehtml = '<p>' . s(fullname($USER)) . ' has accepted your invitation to manage <strong>' . format_string($group->name) . '</strong>.</p>';
                    $msg->smallmessage = fullname($USER) . ' accepted manager invite';
                    $msg->notification = 1;
                    $msg->contexturl = (new moodle_url('/local/learnpath/index.php', ['groupid' => $invite->groupid]))->out(false);
                    $msg->contexturlname = 'View Dashboard';
                    message_send($msg);
                } catch (\Throwable $e) {}
            }
            header('Location: ' . (new moodle_url('/local/learnpath/index.php', ['groupid' => $invite->groupid]))->out(false));
            exit;
        }

        if ($action === 'reject') {
            $DB->update_record('local_learnpath_mgr_invites', (object)[
                'id' => $invite->id, 'status' => 'rejected',
            ]);
            // Notify inviter
            if ($inviter) {
                try {
                    $msg = new \core\message\message();
                    $msg->component = 'local_learnpath'; $msg->name = 'learntrack_reminder';
                    $msg->userfrom = $USER; $msg->userto = $inviter;
                    $msg->subject = fullname($USER) . ' declined manager invite: ' . format_string($group->name);
                    $msg->fullmessage = fullname($USER) . ' has declined your invitation to manage "' . format_string($group->name) . '".';
                    $msg->fullmessageformat = FORMAT_PLAIN;
                    $msg->fullmessagehtml = '<p>' . s(fullname($USER)) . ' has <strong>declined</strong> your invitation to manage <strong>' . format_string($group->name) . '</strong>.</p>';
                    $msg->smallmessage = fullname($USER) . ' declined manager invite';
                    $msg->notification = 1;
                    message_send($msg);
                } catch (\Throwable $e) {}
            }
            header('Location: ' . (new moodle_url('/local/learnpath/index.php'))->out(false) . '?lt_notice=invite_declined');
            exit;
        }
    }
}

// ── Render: pure standalone HTML — zero Moodle JS on this page ───────────────
$path_name   = $group ? format_string($group->name) : '';
$inviter_name = $inviter ? fullname($inviter) : '';
$hours_left   = ($invite && $invite->status === 'pending')
    ? max(0, round(($invite->timecreated + $expiry_h * 3600 - time()) / 3600, 1))
    : 0;
$email_match  = ($invite && $invite->status === 'pending')
    ? strcasecmp(trim($USER->email), trim($invite->email)) === 0
    : true;

$form_url = (new moodle_url('/local/learnpath/invite-accept.php', ['token' => $token]))->out(false);
$sk       = sesskey();

// Hex to RGB for CSS variable
$hex = ltrim($brand, '#');
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($bname); ?> — Path Manager Invitation</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
     background:#f1f5f9;min-height:100vh;display:flex;flex-direction:column;
     align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:20px;width:100%;max-width:460px;
      box-shadow:0 8px 40px rgba(0,0,0,.12);overflow:hidden}
.card-top{background:linear-gradient(135deg,#0f172a,<?php echo $brand; ?>);
          padding:28px 28px 24px;color:#fff;text-align:center}
.card-top .icon{font-size:2.8rem;margin-bottom:10px}
.card-top h1{font-size:1.15rem;font-weight:800;margin-bottom:4px}
.card-top p{font-size:.82rem;opacity:.75}
.card-body{padding:28px}
.path-box{background:rgba(<?php echo "$r,$g,$b"; ?>,.08);
          border:1.5px solid rgba(<?php echo "$r,$g,$b"; ?>,.25);
          border-radius:12px;padding:16px 18px;margin-bottom:22px}
.path-box .pname{font-size:1rem;font-weight:800;color:#111;margin-bottom:5px}
.path-box .pmeta{font-size:.78rem;color:#6b7280;line-height:1.5}
.path-box .pexpiry{font-size:.76rem;color:#d97706;font-weight:700;margin-top:5px}
.desc{font-size:.87rem;color:#4b5563;line-height:1.55;margin-bottom:22px}
.warn{background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;
      padding:12px 15px;font-size:.8rem;color:#92400e;margin-bottom:18px;line-height:1.5}
.warn strong{display:block;margin-bottom:3px}
.btns{display:flex;gap:10px}
.btn-accept{flex:1;background:<?php echo $brand; ?>;color:#fff;border:none;
            border-radius:10px;padding:13px 10px;font-weight:800;font-size:.95rem;
            cursor:pointer;font-family:inherit;transition:opacity .15s;letter-spacing:.2px}
.btn-accept:hover{opacity:.88}
.btn-accept:active{opacity:.75}
.btn-reject{flex:1;background:#f3f4f6;color:#374151;border:1.5px solid #e5e7eb;
            border-radius:10px;padding:13px 10px;font-weight:700;font-size:.9rem;
            cursor:pointer;font-family:inherit;transition:background .15s}
.btn-reject:hover{background:#e5e7eb}
.info-msg{font-size:.88rem;color:#6b7280;line-height:1.5;margin-bottom:16px}
.info-msg strong{color:#111}
.go-btn{display:inline-block;background:<?php echo $brand; ?>;color:#fff;
        padding:11px 24px;border-radius:10px;text-decoration:none;
        font-weight:700;font-size:.88rem;margin-top:10px}
.logo{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;
      color:<?php echo $brand; ?>;margin-bottom:14px;text-align:center}
</style>
</head>
<body>
<div class="logo">&#128218; <?php echo htmlspecialchars($bname); ?></div>
<div class="card">

<?php if (!$invite || !$group): ?>
<div class="card-top"><div class="icon">&#10060;</div>
<h1>Invalid Invitation</h1><p>This link is invalid or has already been used.</p></div>
<div class="card-body"><p class="info-msg">Please contact the path administrator if you believe this is an error.</p></div>

<?php elseif ($invite->status === 'expired'): ?>
<div class="card-top"><div class="icon">&#8987;</div>
<h1>Invitation Expired</h1><p>This link is no longer valid.</p></div>
<div class="card-body">
<div class="path-box">
  <div class="pname">&#128218; <?php echo htmlspecialchars($path_name); ?></div>
  <?php if ($inviter_name): ?><div class="pmeta">Invited by <strong><?php echo htmlspecialchars($inviter_name); ?></strong></div><?php endif; ?>
</div>
<p class="info-msg">This invitation has expired. Please ask the path owner to resend the invitation from the Invitation History page.</p>
</div>

<?php elseif ($invite->status === 'accepted'): ?>
<div class="card-top"><div class="icon">&#9989;</div>
<h1>Already Accepted</h1><p>You already have manager access.</p></div>
<div class="card-body">
<p class="info-msg">You accepted this invitation and have manager access to <strong><?php echo htmlspecialchars($path_name); ?></strong>.</p>
<a href="<?php echo (new moodle_url('/local/learnpath/index.php', ['groupid' => $invite->groupid]))->out(false); ?>" class="go-btn">Go to Dashboard &rarr;</a>
</div>

<?php elseif ($invite->status === 'rejected'): ?>
<div class="card-top"><div class="icon">&#128683;</div>
<h1>Invitation Declined</h1><p>You previously declined this invitation.</p></div>
<div class="card-body"><p class="info-msg">Contact the path administrator if you changed your mind.</p></div>

<?php else: /* pending */ ?>
<div class="card-top"><div class="icon">&#128218;</div>
<h1>Path Manager Invitation</h1><p>You have been invited to manage a learning path</p></div>
<div class="card-body">

<div class="path-box">
  <div class="pname">&#128218; <?php echo htmlspecialchars($path_name); ?></div>
  <?php if ($inviter_name): ?><div class="pmeta">Invited by <strong><?php echo htmlspecialchars($inviter_name); ?></strong></div><?php endif; ?>
  <?php if ($hours_left > 0): ?><div class="pexpiry">&#9203; Expires in <?php echo $hours_left; ?>h</div><?php endif; ?>
</div>

<p class="desc">As a manager you can view learner progress, send reminders, and generate reports for this path.</p>

<?php if (!$email_match): ?>
<div class="warn">
  <strong>&#9888; Wrong account</strong>
  This invite was sent to <strong><?php echo htmlspecialchars($invite->email); ?></strong> but you are logged in as <strong><?php echo htmlspecialchars($USER->email); ?></strong>.
  <a href="<?php echo (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false); ?>" style="color:#92400e;font-weight:700">Switch account</a> to accept with the correct account.
</div>
<?php endif; ?>

<form method="post" action="<?php echo htmlspecialchars($form_url); ?>">
  <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
  <div class="btns">
    <button type="submit" name="action" value="accept" class="btn-accept"
      onclick="return confirm('Accept manager role for <?php echo addslashes(htmlspecialchars($path_name)); ?>?')">
      &#10003;&nbsp; Accept
    </button>
    <button type="submit" name="action" value="reject" class="btn-reject"
      onclick="return confirm('Decline this invitation? The path owner will be notified.')">
      &#10005;&nbsp; Decline
    </button>
  </div>
</form>

<?php endif; ?>
</div><!-- .card -->
</div><!-- .card wrapper -->
</body>
</html>
<?php
// Stop here — no Moodle footer, no extra JS injected
exit;
