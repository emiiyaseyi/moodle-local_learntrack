<?php
namespace local_learnpath\export;

defined('MOODLE_INTERNAL') || die();

// gradelib loaded inside methods that need it — never at file level.

use local_learnpath\data\helper as data_helper;

/**
 * LearnTrack export manager — handles CSV, XLSX, PDF exports.
 * All global Moodle functions called with backslash prefix for namespace safety.
 */
class manager {

    /**
     * Export and send to browser.
     */
    public static function export(
        int    $groupid,
        string $format,
        string $viewmode,
        int    $viewerid,
        string $user_status = 'active',
        int    $from_ts = 0,
        int    $to_ts = 0
    ): void {
        global $CFG;

        $group = data_helper::get_group_with_courses($groupid);
        if (!$group) {
            throw new \moodle_exception('invalidgroup', 'local_learnpath');
        }

        [$headers, $rows, $summary] = self::build_data(
            $groupid, $viewmode, $viewerid, $user_status, $from_ts, $to_ts
        );

        $filename = \clean_filename($group->name . '_' . $viewmode . '_' . date('Y-m-d'));

        switch ($format) {
            case 'csv':
                self::export_csv($filename, $headers, $rows, $group);
                break;
            case 'pdf':
                self::export_pdf($filename, $group->name, $headers, $rows, $summary);
                break;
            case 'xlsx':
            default:
                self::export_xlsx($filename, $group->name, $headers, $rows, $summary);
                break;
        }
    }

    /**
     * Email a report.
     */
    public static function email_report(
        int    $groupid,
        array  $recipients,
        string $format,
        string $viewmode,
        int    $viewerid
    ): bool {
        global $CFG, $DB;

        $group = data_helper::get_group_with_courses($groupid);
        if (!$group) {
            return false;
        }

        [$headers, $rows, $summary] = self::build_data($groupid, $viewmode, $viewerid);
        $filename = \clean_filename($group->name . '_progress_' . date('Y-m-d'));
        $tmpdir   = \make_temp_directory('local_learnpath');
        $tmpfile  = $tmpdir . '/' . $filename . '.' . $format;

        // Write to temp file in the requested format
        if ($format === 'csv') {
            $fp = fopen($tmpfile, 'w');
            fputcsv($fp, $headers);
            foreach ($rows as $row) { fputcsv($fp, $row); }
            fclose($fp);
        } elseif ($format === 'pdf') {
            // Generate PDF attachment using TCPDF if available, otherwise fall back to xlsx
            if (class_exists('pdf') || file_exists($CFG->libdir . '/pdflib.php')) {
                $tmpfile = $tmpdir . '/' . $filename . '.pdf';
                self::write_pdf_to_file($tmpfile, $group->name, $headers, $rows, $summary);
            } else {
                // Fallback: xlsx with .xlsx extension but named as requested
                $tmpfile = $tmpdir . '/' . $filename . '.xlsx';
                self::write_xlsx_to_file($tmpfile, $group->name, $headers, $rows, $summary);
            }
        } else {
            // xlsx
            self::write_xlsx_to_file($tmpfile, $group->name, $headers, $rows, $summary);
        }

        $sendername = \get_config('local_learnpath', 'email_sender_name') ?: 'LearnTrack';
        $noreply    = \core_user::get_noreply_user();
        $noreply->firstname = $sendername;
        $noreply->lastname  = '';

        // Use admin-configured templates if set, else fall back to lang strings
        $subject_tpl = \get_config('local_learnpath', 'email_report_subject')
            ?: \get_string('email_subject', 'local_learnpath', $group->name);
        // Build a clean default body with REAL newlines (not literal \n)
        $default_body = 'Dear Recipient,\n\nAttached is the progress report for: {groupname}.\n\nDate: {date}\nRecords: {count}\n\nThis report was generated automatically by LearnTrack.\n\nRegards,\nLearnTrack';
        $raw_tpl = \get_config('local_learnpath', 'email_report_body') ?: $default_body;

        $vars    = ['{groupname}' => $group->name, '{date}' => \userdate(time()), '{count}' => count($rows)];
        $subject = str_replace(array_keys($vars), array_values($vars), $subject_tpl);
        // Normalise: convert stored literal \n sequences to real newlines
        $body_raw = str_replace(array_keys($vars), array_values($vars), $raw_tpl);
        $body     = str_replace(['\\n', '\\r\\n'], ["\n", "\n"], $body_raw);
        $html_body = self::build_report_email_html($body, $group->name, count($rows));

        $success = true;
        foreach ($recipients as $email) {
            $email = trim($email);
            if (!\validate_email($email)) {
                continue;
            }
            $to = self::make_email_user($email);
            $result = \email_to_user($to, $noreply, $subject, $body, $html_body, $tmpfile, basename($tmpfile));
            if (!$result) {
                $success = false;
            }
        }

        // Log this send to email history
        if ($success) {
            $DB->insert_record('local_learnpath_email_log', (object)[
                'groupid'     => $groupid,
                'senderid'    => $viewerid,
                'recipients'  => implode(', ', $recipients),
                'format'      => $format,
                'viewmode'    => $viewmode,
                'recordcount' => count($rows),
                'timesent'    => time(),
            ]);
        }

        @unlink($tmpfile);
        return $success;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function build_data(
        int    $groupid,
        string $viewmode,
        int    $viewerid,
        string $user_status = 'active',
        int    $from_ts = 0,
        int    $to_ts = 0
    ): array {
        $brand = \get_config('local_learnpath', 'brand_name') ?: 'LearnTrack';
        $group = data_helper::get_group_with_courses($groupid);

        // Summary row for export header
        $summary_meta = [
            $brand . ' Export',
            'Path: ' . \format_string($group->name),
            'Generated: ' . date('Y-m-d H:i'),
            'View: ' . ucfirst($viewmode),
        ];

        if ($viewmode === 'summary') {
            $data    = data_helper::get_progress_summary($groupid, $viewerid, $user_status);
            $headers = self::summary_headers();
            $rows    = self::summary_rows($data);
        } else {
            $data    = data_helper::get_progress_detail($groupid, $viewerid, $user_status);
            // Apply date filter if set
            if ($from_ts) {
                $data = array_filter($data, function ($row) use ($from_ts, $to_ts) {
                    $ts = $row->lastaccess ?? 0;
                    if (!$ts) {
                        return false;
                    }
                    return $ts >= $from_ts && ($to_ts === 0 || $ts <= $to_ts);
                });
            }
            $headers = self::detail_headers();
            $rows    = self::detail_rows($data);
        }

        $summary = [
            'meta'             => $summary_meta,
            'total_records'    => count($rows),
            'path_name'        => \format_string($group->name),
            'date_generated'   => date('Y-m-d H:i:s'),
        ];

        return [$headers, array_values($rows), $summary];
    }

    private static function detail_headers(): array {
        return [
            'First Name', 'Last Name', 'Email', 'Username',
            'Course', 'Status', 'Progress %',
            'Activities Completed', 'Total Activities',
            'Grade', 'First Access', 'Last Access', 'Date Completed',
        ];
    }

    private static function detail_rows(array $data): array {
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row->firstname,
                $row->lastname,
                $row->email,
                $row->username,
                \format_string($row->coursename ?? ''),
                ucfirst($row->status),
                $row->progress . '%',
                $row->completed_activities,
                $row->total_activities,
                $row->grade !== null ? $row->grade . '/' . $row->maxgrade : '-',
                $row->firstaccess   ? \userdate($row->firstaccess)   : '-',
                $row->lastaccess    ? \userdate($row->lastaccess)     : '-',
                $row->timecompleted ? \userdate($row->timecompleted)  : '-',
            ];
        }
        return $rows;
    }

    private static function summary_headers(): array {
        return [
            'First Name', 'Last Name', 'Email', 'Username',
            'Total Courses', 'Completed', 'In Progress', 'Not Started',
            'Overall Progress %', 'First Access', 'Last Access',
        ];
    }

    private static function summary_rows(array $data): array {
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row->firstname,
                $row->lastname,
                $row->email,
                $row->username,
                $row->total_courses,
                $row->completed_courses,
                $row->inprogress_courses,
                $row->notstarted_courses,
                $row->overall_progress . '%',
                $row->firstaccess ? \userdate($row->firstaccess) : '-',
                $row->lastaccess  ? \userdate($row->lastaccess)  : '-',
            ];
        }
        return $rows;
    }

    private static function export_csv(string $filename, array $headers, array $rows, object $group): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        // Summary header
        fputcsv($out, ['LearnTrack Export — ' . \format_string($group->name)]);
        fputcsv($out, ['Generated: ' . date('Y-m-d H:i:s'), 'Total records: ' . count($rows)]);
        fputcsv($out, []);
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    private static function export_xlsx(
        string $filename,
        string $sheetname,
        array  $headers,
        array  $rows,
        array  $summary
    ): void {
        global $CFG;
        require_once($CFG->libdir . '/excellib.class.php');

        $workbook = new \MoodleExcelWorkbook('-');
        $workbook->send($filename . '.xlsx');

        // Main data sheet
        $sheet = $workbook->add_worksheet(\clean_filename($sheetname));
        $bold  = $workbook->add_format(['bold' => 1, 'bg_color' => '#1e3a5f', 'color' => '#ffffff']);
        $meta  = $workbook->add_format(['bold' => 1, 'color' => '#6b7280']);

        // Meta rows
        $sheet->write_string(0, 0, 'LearnTrack Export — ' . $sheetname, $meta);
        $sheet->write_string(1, 0, 'Generated: ' . $summary['date_generated'], $meta);
        $sheet->write_string(2, 0, 'Total records: ' . $summary['total_records'], $meta);

        // Headers
        foreach ($headers as $col => $h) {
            $sheet->write_string(4, $col, $h, $bold);
            $sheet->set_column($col, $col, 20);
        }

        // Data
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $sheet->write_string($ri + 5, $ci, (string)$cell);
            }
        }

        // Summary sheet
        $ssheet = $workbook->add_worksheet('Summary');
        $ssheet->write_string(0, 0, 'LearnTrack Summary', $bold);
        $ssheet->write_string(1, 0, 'Path:',              $meta);
        $ssheet->write_string(1, 1, $summary['path_name']);
        $ssheet->write_string(2, 0, 'Generated:',         $meta);
        $ssheet->write_string(2, 1, $summary['date_generated']);
        $ssheet->write_string(3, 0, 'Total records:',     $meta);
        $ssheet->write_string(3, 1, (string)$summary['total_records']);

        $workbook->close();
        exit;
    }

    private static function write_xlsx_to_file(
        string $filepath,
        string $sheetname,
        array  $headers,
        array  $rows,
        array  $summary
    ): void {
        global $CFG;
        require_once($CFG->libdir . '/excellib.class.php');

        // Use output buffering to prevent MoodleExcelWorkbook from sending
        // HTTP headers or content to the browser when writing to a file.
        ob_start();
        $workbook = new \MoodleExcelWorkbook('-');
        $sheet    = $workbook->add_worksheet(\clean_filename($sheetname));
        $bold     = $workbook->add_format(['bold' => 1]);
        $meta     = $workbook->add_format(['color' => '#6b7280']);

        // Meta rows
        $sheet->write_string(0, 0, 'LearnTrack Report — ' . $sheetname, $meta);
        $sheet->write_string(1, 0, 'Generated: ' . ($summary['date_generated'] ?? date('Y-m-d')), $meta);
        $sheet->write_string(2, 0, 'Total records: ' . ($summary['total_records'] ?? count($rows)), $meta);

        // Headers row
        foreach ($headers as $col => $h) {
            $sheet->write_string(4, $col, $h, $bold);
        }
        // Data rows
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $sheet->write_string($ri + 5, $ci, (string)$cell);
            }
        }
        $workbook->close();
        $xlsxdata = ob_get_clean();

        // Write captured output to the target file
        file_put_contents($filepath, $xlsxdata);
    }

    private static function render_pdf_document(array $headers, array $rows, array $summary, string $output_dest, string $output_path = ''): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $brand      = \get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
        $lms_name   = \get_config('local_learnpath', 'brand_name')  ?: 'LearnTrack';
        $path_name  = $summary['path_name'] ?? '';
        $creator    = $summary['creator_name'] ?? '';
        $generated  = $summary['date_generated'] ?? date('Y-m-d H:i');
        $records    = $summary['total_records'] ?? count($rows);

        // Parse brand hex to RGB
        $hex   = ltrim($brand, '#');
        $r_hdr = hexdec(substr($hex, 0, 2));
        $g_hdr = hexdec(substr($hex, 2, 2));
        $b_hdr = hexdec(substr($hex, 4, 2));

        $pdf = new \pdf('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($lms_name);
        $pdf->SetAuthor($creator ?: $lms_name);
        $pdf->SetTitle($path_name ?: $lms_name . ' Progress Report');
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();

        // ── Cover header bar ──
        $pdf->SetFillColor($r_hdr, $g_hdr, $b_hdr);
        $pdf->Rect(0, 0, 297, 28, 'F');

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 7);
        $pdf->Cell(180, 8, $lms_name . ' — Progress Report', 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(10, 17);
        $pdf->Cell(277, 5, 'Path: ' . $path_name . '   |   Generated: ' . $generated . '   |   Records: ' . $records . ($creator ? '   |   Prepared by: ' . $creator : ''), 0, 1, 'L');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(32);

        // ── Column widths — distribute evenly, min 18mm ──
        $colcount = count($headers);
        $page_w   = 277; // A4 landscape usable
        $colwidth = max(18, floor($page_w / $colcount));

        // ── Table header row ──
        $pdf->SetFillColor($r_hdr, $g_hdr, $b_hdr);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 7);
        $x_start = $pdf->GetX();
        foreach ($headers as $h) {
            $pdf->MultiCell($colwidth, 7, $h, 1, 'C', true, 0);
        }
        $pdf->Ln(7);

        // ── Data rows with text wrapping ──
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 6.5);
        $fill = false;
        foreach ($rows as $row) {
            // Calculate row height needed (MultiCell wraps text)
            $max_lines = 1;
            foreach ($row as $cell) {
                $str_width = $pdf->GetStringWidth((string)$cell);
                $lines     = max(1, (int)ceil($str_width / ($colwidth - 2)));
                if ($lines > $max_lines) $max_lines = $lines;
            }
            $row_h = $max_lines * 4.5;

            // Page break check
            if ($pdf->GetY() + $row_h > 195) {
                $pdf->AddPage();
                $pdf->SetFillColor($r_hdr, $g_hdr, $b_hdr);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('helvetica', 'B', 7);
                foreach ($headers as $h) {
                    $pdf->MultiCell($colwidth, 7, $h, 1, 'C', true, 0);
                }
                $pdf->Ln(7);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 6.5);
            }

            $fill_r = $fill ? 240 : 255;
            $fill_g = $fill ? 247 : 255;
            $fill_b = $fill ? 252 : 255;
            $pdf->SetFillColor($fill_r, $fill_g, $fill_b);
            $y_before = $pdf->GetY();
            foreach ($row as $cell) {
                $pdf->MultiCell($colwidth, $row_h, (string)$cell, 1, 'L', true, 0);
            }
            $pdf->Ln($row_h);
            $fill = !$fill;
        }

        // ── Footer on last page ──
        $pdf->SetY(-12);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(0, 5, $lms_name . ' · LearnTrack · ' . $generated, 0, 0, 'C');

        if ($output_dest === 'D') {
            $pdf->Output('progress_report.pdf', 'D');
            exit;
        } else {
            $pdf->Output($output_path, 'F');
        }
    }

    /**
     * Dispatch to format-specific export method.
     */
    private static function output(string $format, string $filename, array $headers, array $rows): void {
        switch ($format) {
            case 'pdf':
                $summary = [];
                self::render_pdf_document($headers, $rows, $summary, 'D');
                break;
            case 'csv':
                self::export_csv($filename, $headers, $rows);
                break;
            case 'xlsx':
            default:
                self::export_xlsx($filename, $headers, $rows);
                break;
        }
    }

    private static function export_pdf(
        string $filename,
        string $title,
        array  $headers,
        array  $rows,
        array  $summary
    ): void {
        self::render_pdf_document($headers, $rows, $summary, 'D');
    }

    private static function write_pdf_to_file(
        string $filepath,
        string $title,
        array  $headers,
        array  $rows,
        array  $summary
    ): void {
        self::render_pdf_document($headers, $rows, $summary, 'F', $filepath);
    }

    private static function build_report_email_html(string $body, string $groupname, int $count): string {
        $brand   = \get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
        $bname   = \get_config('local_learnpath', 'brand_name')  ?: 'LearnTrack';
        $date    = \userdate(time());
        $escaped = nl2br(\htmlspecialchars($body, ENT_QUOTES));
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:system-ui,-apple-system,sans-serif">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'
            // Header
            . '<tr><td style="background:' . $brand . ';border-radius:12px 12px 0 0;padding:28px 32px;text-align:center">'
            . '<div style="font-size:1.5rem;margin-bottom:6px">📊</div>'
            . '<div style="color:#fff;font-size:1.1rem;font-weight:800;letter-spacing:-.2px">' . \htmlspecialchars($bname) . '</div>'
            . '<div style="color:rgba(255,255,255,.75);font-size:.82rem;margin-top:4px">Progress Report</div>'
            . '</td></tr>'
            // Body
            . '<tr><td style="background:#ffffff;padding:28px 32px">'
            . '<div style="font-size:.95rem;color:#374151;line-height:1.7;margin-bottom:20px">' . $escaped . '</div>'
            // Summary card
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr>'
            . '<td style="padding:16px;text-align:center;border-right:1px solid #e5e7eb"><div style="font-size:1.4rem;font-weight:800;color:' . $brand . '">' . \htmlspecialchars($groupname) . '</div><div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Learning Path</div></td>'
            . '<td style="padding:16px;text-align:center;border-right:1px solid #e5e7eb"><div style="font-size:1.4rem;font-weight:800;color:#111827">' . $count . '</div><div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Records</div></td>'
            . '<td style="padding:16px;text-align:center"><div style="font-size:.9rem;font-weight:700;color:#111827">' . \htmlspecialchars($date) . '</div><div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Generated</div></td>'
            . '</tr></table>'
            . '<div style="font-size:.78rem;color:#9ca3af;border-top:1px solid #f3f4f6;padding-top:14px;margin-top:8px">📎 Progress report attached. Please find the detailed data in the attachment.</div>'
            . '</td></tr>'
            // Footer
            . '<tr><td style="background:#f8fafc;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center">'
            . '<div style="font-size:.74rem;color:#9ca3af">This email was sent automatically by <strong>' . \htmlspecialchars($bname) . '</strong> · LearnTrack</div>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function make_email_user(string $email): object {
        $user              = new \stdClass();
        $user->email       = $email;
        $user->firstname   = $email;
        $user->lastname    = '';
        $user->maildisplay = 1;
        $user->mailformat  = 1;
        $user->id          = -1;
        $user->auth        = 'manual';
        $user->deleted     = 0;
        $user->suspended   = 0;
        $user->username    = $email;
        $user->confirmed   = 1;
        return $user;
    }

    /**
     * Export course insights data as CSV/XLSX/PDF.
     */
    public static function export_course(int $courseid, string $format, string $date_range, int $userid): void {
        global $DB, $CFG;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
        if (!$course) { return; }

        $now     = time();
        $from_ts = match($date_range) {
            '7days' => strtotime('-7 days', $now), 'week'  => strtotime('monday this week'),
            'month' => mktime(0,0,0,(int)date('n'),1), 'year' => mktime(0,0,0,1,1,(int)date('Y')),
            default => 0,
        };

        // Build headers and rows
        $headers = ['First Name', 'Last Name', 'Email', 'Username', 'Status',
                    'Activities Done', 'Total Activities', 'Progress %', 'Grade', 'Completed Date'];

        $total_mods = (int)$DB->count_records_sql(
            "SELECT COUNT(id) FROM {course_modules} WHERE course=:cid AND completion>0 AND deletioninprogress=0",
            ['cid' => $courseid]
        );

        $mod_counts = [];
        if ($total_mods > 0) {
            $rows = $DB->get_records_sql(
                "SELECT cmc.userid, COUNT(cmc.id) AS done FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cm.id=cmc.coursemoduleid
                 WHERE cm.course=:cid AND cmc.completionstate IN (1,2) AND cm.deletioninprogress=0
                 GROUP BY cmc.userid", ['cid' => $courseid]
            );
            foreach ($rows as $r) { $mod_counts[$r->userid] = (int)$r->done; }
        }

        $completed_uids = array_keys($DB->get_records_sql(
            "SELECT DISTINCT userid FROM {course_completions} WHERE course=:cid AND timecompleted>0",
            ['cid' => $courseid]
        ));

        $grade_item = $DB->get_record('grade_items', ['courseid'=>$courseid,'itemtype'=>'course']);
        $user_grades = [];
        if ($grade_item) {
            $gr = $DB->get_records_sql(
                "SELECT userid, finalgrade FROM {grade_grades} WHERE itemid=:iid AND finalgrade IS NOT NULL",
                ['iid' => $grade_item->id]
            );
            foreach ($gr as $g) { $user_grades[$g->userid] = round((float)$g->finalgrade, 1); }
        }

        $learners = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid
             JOIN {user} u ON u.id=ue.userid AND u.deleted=0
             ORDER BY u.lastname, u.firstname", ['cid' => $courseid]
        );
        $comp_dates = $DB->get_records_sql(
            "SELECT userid, MAX(timecompleted) AS completed_ts FROM {course_completions}
             WHERE course=:cid AND timecompleted>0 GROUP BY userid", ['cid' => $courseid]
        );

        $data_rows = [];
        foreach ($learners as $l) {
            $done = $mod_counts[$l->id] ?? 0;
            $is_complete = in_array($l->id, $completed_uids) || ($total_mods > 0 && $done >= $total_mods);
            $pct = $is_complete ? 100 : ($total_mods > 0 ? (int)round($done/$total_mods*100) : 0);
            $status = $is_complete ? 'Complete' : ($pct > 0 ? 'In Progress' : 'Not Started');
            $grade  = isset($user_grades[$l->id]) ? $user_grades[$l->id] : '';
            $ct     = $comp_dates[$l->id]->completed_ts ?? null;
            $data_rows[] = [
                $l->firstname, $l->lastname, $l->email, $l->username,
                $status, $done, $total_mods, $pct . '%', $grade,
                $ct ? date('Y-m-d', (int)$ct) : '',
            ];
        }

        $filename = 'course_insights_' . clean_filename($course->shortname) . '_' . date('Ymd');
        self::output($format, $filename, $headers, $data_rows);
    }

}
