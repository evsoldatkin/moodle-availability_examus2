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

/**
 * Availability plugin for integration with Examus.
 *
 * @package    availability_examus2
 * @copyright  2019-2022 Maksim Burnin <maksim.burnin@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => 'core\event\course_module_deleted',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'callback' => 'availability_examus2_course_module_deleted',
        'internal' => false,
    ],
    [
        'eventname' => 'core\event\user_enrolment_deleted',
        'callback' => 'availability_examus2_user_enrolment_deleted',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'core\event\user_created',
        'callback' => 'availability_examus2_user_created',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'core\event\user_updated',
        'callback' => 'availability_examus2_user_updated',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'mod_quiz\event\attempt_submitted',
        'callback' => 'availability_examus2_attempt_submitted_handler',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'mod_quiz\event\attempt_started',
        'callback' => 'availability_examus2_attempt_started_handler',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'mod_quiz\event\attempt_preview_started',
        'callback' => 'availability_examus2_attempt_started_handler',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'mod_quiz\event\attempt_deleted',
        'callback' => 'availability_examus2_attempt_deleted_handler',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
    [
        'eventname' => 'mod_quiz\event\attempt_viewed',
        'callback' => 'availability_examus2_attempt_viewed_handler',
        'includefile' => '/availability/condition/examus2/locallib.php',
        'internal' => false,
    ],
];
