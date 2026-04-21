<?php
namespace local_learnpath\notification;

defined('MOODLE_INTERNAL') || die();

class notifier {

    public static function send_reminder(
        object $reminder,
        object $learner,
        object $group,
        array  $courses
    ): array {
        $results = ['email' => false, 'inapp' => false, 'sms' => false];

        $subject = self::render(
            $reminder->subject ?: 'Reminder: Complete your learning — {groupname}',
            $learner, $group, $courses
        );
        $plain = self::render(
            $reminder->message ?: self::default_message(),
            $learner, $group, $courses
        );
        $html = self::build_html($plain, $learner, $group, $courses);

        if ($reminder->channel_email) {
            $results['email'] = self::send_email($learner, $subject, $plain, $html);
        }
        if ($reminder->channel_inapp) {
            $results['inapp'] = self::send_inapp($learner, $subject, $plain, $group, 'learntrack_reminder');
        }
        if ($reminder->channel_sms) {
            $results['sms'] = self::send_sms($learner, $plain);
        }

        self::log($reminder->id, $learner->id, $results);
        return $results;
    }

    public static function send_cert_notification(object $learner, object $group, string $certnumber = ''): bool {
        $subject = 'Certificate Issued — ' . \format_string($group->name);
        $body    = "Dear {$learner->firstname},\n\n"
            . "Congratulations! A certificate has been issued for: \"{$group->name}\".\n"
            . ($certnumber ? "Certificate ref: {$certnumber}\n\n" : "\n")
            . "View your progress: " . self::mypath_url($group->id) . "\n\n"
            . "Well done!\n\nLearnTrack";
        $html = self::build_html($body, $learner, $group, []);
        self::send_email($learner, $subject, $body, $html);
        return self::send_inapp($learner, $subject, $body, $group, 'learntrack_cert');
    }

    public static function send_overdue_alert(object $learner, object $group, int $deadline): bool {
        $subject = 'Overdue: ' . \format_string($group->name);
        $body    = "Dear {$learner->firstname},\n\n"
            . "Your completion deadline for \"{$group->name}\" was " . \userdate($deadline) . ".\n\n"
            . "Please log in to continue: " . self::mypath_url($group->id) . "\n\n"
            . "LearnTrack";
        $html = self::build_html($body, $learner, $group, []);
        self::send_email($learner, $subject, $body, $html);
        return self::send_inapp($learner, $subject, $body, $group, 'learntrack_overdue');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function send_email(object $user, string $subject, string $plain, string $html): bool {
        $noreply = \core_user::get_noreply_user();
        $sender  = \get_config('local_learnpath', 'email_sender_name') ?: 'LearnTrack';
        $noreply->firstname = $sender;
        $noreply->lastname  = '';
        return (bool)\email_to_user($user, $noreply, $subject, $plain, $html);
    }

    private static function send_inapp(
        object $user,
        string $subject,
        string $body,
        object $group,
        string $provider
    ): bool {
        $msg                    = new \core\message\message();
        $msg->component         = 'local_learnpath';
        $msg->name              = $provider;
        $msg->userfrom          = \core_user::get_noreply_user();
        $msg->userto            = $user;
        $msg->subject           = $subject;
        $msg->fullmessage       = $body;
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->fullmessagehtml   = '<p>' . nl2br(\s($body)) . '</p>';
        $msg->smallmessage      = $subject;
        $msg->notification      = 1;
        $msg->contexturl        = self::mypath_url($group->id);
        $msg->contexturlname    = 'View: ' . \format_string($group->name);
        return (bool)\message_send($msg);
    }

    private static function send_sms(object $user, string $message): bool {
        if (!class_exists('\core_sms\manager')) {
            return false;
        }
        $phone = $user->phone1 ?? $user->phone2 ?? '';
        if (empty($phone)) {
            return false;
        }
        try {
            $mgr = \core\di::get(\core_sms\manager::class);
            $mgr->send(
                recipientnumber: $phone,
                content:         substr(strip_tags($message), 0, 160),
                component:       'local_learnpath',
                messagetype:     'learntrack_reminder',
                recipientuserid: $user->id,
                issensitive:     false,
            );
            return true;
        } catch (\Throwable $e) {
            \debugging('LearnTrack SMS: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    private static function render(string $tpl, object $learner, object $group, array $courses): string {
        $completed = 0;
        foreach ($courses as $c) {
            if (($c->status ?? '') === 'complete') {
                $completed++;
            }
        }
        $total = count($courses);
        $pct   = $total > 0 ? (int)round($completed / $total * 100) : 0;

        $vars = [
            '{{firstname}}'    => $learner->firstname,
            '{{lastname}}'     => $learner->lastname,
            '{{fullname}}'     => \fullname($learner),
            '{{groupname}}'    => \format_string($group->name),
            '{{completed}}'    => $completed,
            '{{total}}'        => $total,
            '{{progress}}'     => $pct . '%',
            '{{deadline}}'     => $group->deadline ? \userdate((int)$group->deadline) : 'No deadline set',
            '{{dashboardurl}}' => self::mypath_url($group->id),
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }

    private static function build_html(string $plain, object $learner, object $group, array $courses): string {
        $brand = \get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
        $completed = 0;
        foreach ($courses as $c) {
            if (($c->status ?? '') === 'complete') {
                $completed++;
            }
        }
        $total = count($courses);
        $pct   = $total > 0 ? (int)round($completed / $total * 100) : 0;
        $url   = self::mypath_url($group->id);

        return '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111827;max-width:600px;margin:0 auto">'
            . '<div style="background:' . $brand . ';padding:24px;border-radius:10px 10px 0 0;color:white">'
            . '<h2 style="margin:0">📚 LearnTrack Reminder</h2>'
            . '<p style="margin:4px 0 0;opacity:.8">Learning Path: ' . \format_string($group->name) . '</p>'
            . '</div>'
            . '<div style="background:#fff;padding:24px;border:1px solid #e5e7eb;border-top:none">'
            . '<p>Dear <strong>' . \s($learner->firstname) . '</strong>,</p>'
            . '<p>' . nl2br(\s($plain)) . '</p>'
            . ($total > 0 ? '<div style="background:#f8fafc;border-radius:8px;padding:14px;text-align:center;margin:16px 0">'
                . '<div style="font-size:2rem;font-weight:800;color:' . $brand . '">' . $pct . '%</div>'
                . '<div style="font-size:.8rem;color:#6b7280">Progress — ' . $completed . '/' . $total . ' courses</div>'
                . '</div>' : '')
            . '<a href="' . $url . '" style="display:inline-block;background:' . $brand . ';color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Continue Learning →</a>'
            . '</div>'
            . '<div style="padding:12px;font-size:.72rem;color:#9ca3af;text-align:center">LearnTrack by Michael Adeniran</div>'
            . '</body></html>';
    }

    private static function default_message(): string {
        return "Dear {{firstname}},\n\n"
            . "This is a friendly reminder that you have incomplete courses in the learning path \"{{groupname}}\".\n\n"
            . "Your current progress: {{progress}} ({{completed}} of {{total}} courses completed).\n\n"
            . "Please log in to continue:\n{{dashboardurl}}\n\n"
            . "Best regards,\nLearnTrack";
    }

    private static function mypath_url(int $groupid): string {
        return (new \moodle_url('/local/learnpath/mypath.php', ['groupid' => $groupid]))->out(false);
    }

    private static function log(int $reminderid, int $userid, array $results): void {
        global $DB;
        $now = time();
        foreach ($results as $channel => $ok) {
            if ($ok) {
                $DB->insert_record('local_learnpath_reminder_log', (object)[
                    'reminderid' => $reminderid,
                    'userid'     => $userid,
                    'channel'    => $channel,
                    'timesent'   => $now,
                    'status'     => 'sent',
                ]);
            }
        }
    }
}
