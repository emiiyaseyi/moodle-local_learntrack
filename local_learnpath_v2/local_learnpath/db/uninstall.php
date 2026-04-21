<?php
/**
 * LearnTrack uninstall — removes all plugin data cleanly.
 * Called automatically by Moodle when the plugin is uninstalled.
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_learnpath_uninstall(): bool {
    // All tables are dropped automatically by Moodle via install.xml.
    // This function handles any additional cleanup (config, files, etc.)

    // Remove all plugin configuration
    unset_all_config_for_plugin('local_learnpath');

    return true;
}
