<?php
// Block upgrade — no DB tables, just version tracking
defined('MOODLE_INTERNAL') || die();

function xmldb_block_learntrack_mypath_upgrade(int $oldversion): bool {
    return true;
}
