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
 * Branding page AMD module.
 *
 * Handles:
 *   - Live certificate preview (updates SVG/canvas as fields change)
 *   - Brand-colour picker → CSS custom property live preview
 *   - Tab switching within the branding accordion
 *
 * @module     local_learnpath/branding
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    var certPreview = null;

    /**
     * Read the current value of a named form field.
     *
     * @param {string} name Field name attribute
     * @returns {string}
     */
    function fieldVal(name) {
        var el = document.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    /**
     * Re-render the certificate live preview box from current field values.
     */
    function refreshCertPreview() {
        if (!certPreview) {
            return;
        }

        var learnerName   = fieldVal('cert_learner_placeholder') || 'Learner Name';
        var orgName       = fieldVal('cert_org_name')            || 'Organisation';
        var signatoryName = fieldVal('cert_signatory_name')      || 'Signatory';
        var signatoryTitle= fieldVal('cert_signatory_title')     || 'Title';
        var footerText    = fieldVal('cert_footer_text')         || '';
        var brandColor    = fieldVal('brand_color')              || '#1e3a5f';
        var logoUrl       = fieldVal('cert_logo_url')            || '';

        certPreview.querySelector('.lt-cert-org').textContent        = orgName;
        certPreview.querySelector('.lt-cert-learner').textContent    = learnerName;
        certPreview.querySelector('.lt-cert-signatory').textContent  = signatoryName;
        certPreview.querySelector('.lt-cert-sig-title').textContent  = signatoryTitle;
        certPreview.querySelector('.lt-cert-footer').textContent     = footerText;

        var header = certPreview.querySelector('.lt-cert-header');
        if (header) {
            header.style.background = brandColor;
        }

        var logoEl = certPreview.querySelector('.lt-cert-logo');
        if (logoEl) {
            logoEl.src = logoUrl;
            logoEl.style.display = logoUrl ? '' : 'none';
        }
    }

    /**
     * Attach live-preview listeners to all certificate-related fields.
     */
    function initCertPreview() {
        certPreview = document.getElementById('lt-cert-preview');
        if (!certPreview) {
            return;
        }

        var watchedFields = [
            'cert_org_name', 'cert_signatory_name', 'cert_signatory_title',
            'cert_footer_text', 'cert_learner_placeholder', 'brand_color', 'cert_logo_url'
        ];

        watchedFields.forEach(function(name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.addEventListener('input', refreshCertPreview);
                el.addEventListener('change', refreshCertPreview);
            }
        });

        refreshCertPreview();
    }

    /**
     * Sync the brand colour picker to the CSS custom property so the page
     * previews the colour in real time.
     */
    function initColourPreview() {
        var picker = document.querySelector('[name="brand_color"]');
        if (!picker) {
            return;
        }
        picker.addEventListener('input', function() {
            document.documentElement.style.setProperty('--lt-primary', picker.value);
            document.documentElement.style.setProperty('--lt-accent', picker.value);
        });
    }

    return {
        init: function() {
            initCertPreview();
            initColourPreview();
        }
    };
});
