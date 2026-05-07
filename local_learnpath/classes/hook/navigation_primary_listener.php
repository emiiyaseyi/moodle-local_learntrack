<?php
namespace local_learnpath\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook listener: adds LearnTrack to the Moodle 4.3+ primary navigation (top bar).
 * Registered in db/hooks.php for core\hook\navigation\primary_extend.
 */
class navigation_primary_listener {

    /**
     * No type hint on $hook — defensive against minor API differences between
     * Moodle 4.3/4.4/4.5 without causing fatal errors.
     */
    public static function extend($hook): void {
        global $USER, $DB;

        if (!isloggedin() || isguestuser()) return;

        try {
            if (!$DB->get_manager()->table_exists('local_learnpath_groups')) return;
        } catch (\Throwable $e) { return; }

        $ctx     = \context_system::instance();
        $isadmin = has_capability('local/learnpath:manage',        $ctx);
        $canview = has_capability('local/learnpath:viewdashboard', $ctx);
        $is_path_manager = !$isadmin && $DB->record_exists(
            'local_learnpath_managers', ['userid' => $USER->id]
        );

        if (!$isadmin && !$canview && !$is_path_manager) {
            try {
                $has_path = $DB->record_exists(
                    'local_learnpath_user_assign', ['userid' => $USER->id]
                );
            } catch (\Throwable $e) { $has_path = false; }
            if (!$has_path) return;
        }

        $bname = \get_config('local_learnpath', 'brand_name') ?: 'LearnTrack';

        if ($isadmin) {
            $url = new \moodle_url('/local/learnpath/welcome.php');
        } elseif ($is_path_manager || $canview) {
            $url = new \moodle_url('/local/learnpath/index.php');
        } else {
            $url = new \moodle_url('/local/learnpath/mypath.php');
        }

        try {
            // Try both method names used across different Moodle 4.x versions.
            $primarynav = null;
            if (method_exists($hook, 'get_primarynav')) {
                $primarynav = $hook->get_primarynav();
            } elseif (method_exists($hook, 'get_primary_nav')) {
                $primarynav = $hook->get_primary_nav();
            } elseif (method_exists($hook, 'get_primary')) {
                $primarynav = $hook->get_primary();
            }

            if (!$primarynav) return;

            $node = $primarynav->add(
                $bname,
                $url,
                \navigation_node::TYPE_CUSTOM,
                null,
                'learntrack_primary',
                new \pix_icon('i/report', '')
            );
            if ($node) {
                $node->showinflatnavigation = false;
            }
        } catch (\Throwable $e) {
            \debugging('LearnTrack primary nav: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
