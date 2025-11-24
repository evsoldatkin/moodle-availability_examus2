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

use availability_examus2\state;
use availability_examus2\client;
use availability_examus2\common;
use availability_examus2\condition;

/**
 * When attempt is started, update entry accordingly
 *
 * @param stdClass $event Event
 */
function availability_examus2_attempt_started_handler($event) {
    global $DB, $USER;

    $cache = cache::make_from_params(cache_store::MODE_SESSION, 'availability_examus2', 'session');
    
    $accesscode = $cache->get('accesscode');

    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);

    $course = get_course($event->courseid);
    $modinfo = get_fast_modinfo($course->id, $USER->id);
    $cmid = $event->get_context()->instanceid;
    $cm = $modinfo->get_cm($cmid);

    $condition = condition::get_examus2_condition($cm);
    if (!$condition || !$attempt) {
        return;
    }

    // We want to let previews to happen without proctoring.
    $quizobj = \quiz::create($cm->instance, $USER->id);
    if ($quizobj->is_preview_user()) {
        return;
    }

    if(!$condition->user_in_examus2ed_groups($USER->id)) {
        return;
    }

    $inhibitredirect = false;
    if ($accesscode) {
        // If we have an access code here, we are coming from Examus.
        $inhibitredirect = true;
        $entry = $DB->get_record('availability_examus2_entries', ['accesscode' => $accesscode]);
    }

    if (!empty($entry)) {
        if (empty($entry->attemptid)) {
            $entry->attemptid = $attempt->id;
            $entry->timemodified = time();
        }
        if (in_array($entry->status, ['new', 'scheduled' , 'started'])) {
            $entry->status = "started";
            $entry->timemodified = time();
        }
        $DB->update_record('availability_examus2_entries', $entry);

        if ($entry->status == "started" && $entry->attemptid != $attempt->id) {
            $entry = common::create_entry($condition, $USER->id, $cm);

            if ($accesscode) {
                // The user is coming from examus2, we can't redirect.
                // We have to let user know that they need to restart manually.
                $inhibitredirect = true;
                $cache->set('reset', true);
            } else {
                // The user is not coming from examus2.
                $inhibitredirect = false;
            }
        }
    } else {
        $entry = common::create_entry($condition, $USER->id, $cm);
        $entry->attemptid = $attempt->id;
        $entry->status = "started";
        $entry->timemodified = time();
        $DB->update_record('availability_examus2_entries', $entry);

        if ($accesscode) {
            $inhibitredirect = true;
            $cache->set('reset', true);
        } else {
            $inhibitredirect = false;
        }
    }

    if ($inhibitredirect) {
        return;
    }
}

/**
 * Finish attempt on attempt finish event.
 *
 * @param stdClass $event Event
 */
function availability_examus2_attempt_submitted_handler($event) {
    global $DB;

    $cache = cache::make_from_params(cache_store::MODE_SESSION, 'availability_examus2', 'session');

    $cmid = $event->get_context()->instanceid;
    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
    $userid = $event->userid;

    $course = get_course($event->courseid);
    $modinfo = get_fast_modinfo($course->id, $userid);
    $cm = $modinfo->get_cm($cmid);

    $entries = $DB->get_records('availability_examus2_entries', [
        'userid' => $userid,
        'courseid' => $event->courseid,
        'cmid' => $cmid,
        'status' => "started"
    ], '-id');

    $accesscode = $cache->get('accesscode');
    if ($accesscode) {
        $cache->delete('accesscode');
        $entry = $DB->get_record('availability_examus2_entries', ['accesscode' => $accesscode]);
        if ($entry) {
            $entries[] = $entry;
        }
    } else {
        return;
    }

    // We want to let previews to happen without proctoring.
    $quizobj = \quiz::create($cm->instance, $userid);
    if ($quizobj->is_preview_user()) {
        return;
    }

    $condition = condition::get_examus2_condition($cm);
    if (!$condition || !$condition->user_in_examus2ed_groups($userid)) {
        return;
    }

    foreach ($entries as $entry) {
        $entry->status = "finished";
        if (empty($entry->attemptid)) {
            $entry->attemptid = $attempt->id;
        }
        $DB->update_record('availability_examus2_entries', $entry);
    }
    $entry = reset($entries);

    $redirecturl = new moodle_url('/mod/quiz/review.php', ['attempt' => $attempt->id, 'cmid' => $cmid]);

    $client = new client(null, $userid);
    $client->finish_session($entry->accesscode, $redirecturl->out(false));
}

/**
 * Remove entries on attempt deletion
 *
 * @param stdClass $event Event
 */
function availability_examus2_attempt_deleted_handler($event) {
    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
    $cm = get_coursemodule_from_id('quiz', $event->get_context()->instanceid, $event->courseid);

    common::reset_entry([
        'cmid' => $cm->id,
        'attemptid' => $attempt->id
    ]);
}

/**
 * User enrolment deleted handles
 *
 * @param \core\event\user_enrolment_deleted $event Event
 */
function availability_examus2_user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
    $userid = $event->relateduserid;

    common::delete_empty_entries($userid, $event->courseid);
}

/**
 * Course module deleted handler
 *
 * @param \core\event\course_module_deleted $event Event
 */
function availability_examus2_course_module_deleted(\core\event\course_module_deleted $event) {
    global $DB;
    $cmid = $event->contextinstanceid;
    $DB->delete_records('availability_examus2_entries', ['cmid' => $cmid]);
}


/**
 * Attempt viewed
 *
 * @param \mod_quiz\event\attempt_viewed $event Event
 */
function availability_examus2_attempt_viewed_handler($event) {
    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
    $quiz = $event->get_record_snapshot('quiz', $attempt->quiz);

    // Storing attempt and CM for future use.
    state::$attempt = [
        'cm_id' => $event->get_context()->instanceid,
        'cm' => $event->get_context(),
        'course_id' => $event->courseid,
        'attempt_id' => $event->objectid,
        'quiz_id' => $quiz->id,
    ];
}

function availability_examus2_hash_snils($userid) {
    $userdata = profile_user_record($userid);
    if (isset($userdata->hash_snils) && $userdata->hash_snils && strpos($userdata->hash_snils, 'pbkdf2') !== 0)
    {
        $snils = preg_replace('/[^\d]/', '', $userdata->hash_snils);
        if (strlen($snils) !== 11) {
            return;
        }
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = strlen($characters);
        $salt = '';
        for ($i = 0; $i < 16; $i++) {
            $salt .= $characters[random_int(0, $length - 1)];
        }
        $iterations = 870000;
        $hash = base64_encode(hash_pbkdf2('sha256', $snils, $salt, $iterations, 0, true));
        $data = new stdClass();
        $data->id = $userid;
        $data->profile_field_hash_snils = 'pbkdf2_sha256$'.$iterations.'$'.$salt.'$'.$hash;
        profile_save_data($data);
    }
}

/**
 * User created
 *
 * @param \core\event\user_created $event Event
 */
function availability_examus2_user_created($event) {
    availability_examus2_hash_snils($event->objectid);
}

/**
 * User updated
 *
 * @param \core\event\user_updated $event Event
 */
function availability_examus2_user_updated($event) {
    availability_examus2_hash_snils($event->objectid);
}
