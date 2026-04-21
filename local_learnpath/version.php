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

// LearnTrack — Moodle Learning Path Progress Plugin
// Developer : Michael Adeniran
// Email     : michaeladeniransnr@gmail.com
// LinkedIn  : https://www.linkedin.com/in/michaeladeniran
// Country   : Nigeria
// Copyright : (C) 2025 Michael Adeniran
// License   : GNU GPL v3 or later
// Compatible: Moodle 4.5+ (PHP 8.1+)
defined('MOODLE_INTERNAL') || die();

$plugin->component  = 'local_learnpath';
$plugin->version    = 2026050102;   // v2.0.2
$plugin->requires   = 2024100700;   // Moodle 4.5 minimum
$plugin->maturity   = MATURITY_STABLE;
$plugin->release    = '2.0.2';
$plugin->supported  = [405, 501];   // 4.5 → 5.1
