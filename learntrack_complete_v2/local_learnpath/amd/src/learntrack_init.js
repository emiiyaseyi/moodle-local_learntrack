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
 * Common initialisation for LearnTrack plugin pages.
 *
 * Wires up:
 *   - Path-selector dropdowns (redirect on change)
 *   - Delete-confirm links (data-confirm attribute)
 *
 * @module     local_learnpath/learntrack_init
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Attach onChange redirect handler to path-selector dropdowns.
     */
    function initPathSelectors() {
        document.querySelectorAll('[data-amd-init="local_learnpath/path_selector"]').forEach(function(sel) {
            var base = sel.dataset.redirectBase || '';
            sel.addEventListener('change', function() {
                if (base) {
                    window.location.href = base + encodeURIComponent(sel.value);
                }
            });
        });
    }

    /**
     * Attach click-confirm to delete links that carry data-confirm text.
     */
    function initDeleteConfirms() {
        document.querySelectorAll('a[data-confirm]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (!window.confirm(link.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    }

    return {
        init: function() {
            initPathSelectors();
            initDeleteConfirms();
        }
    };
});
