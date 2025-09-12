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

namespace availability_examus2;

defined('MOODLE_INTERNAL') || die();

use \stdClass;

/**
 * Collection of static methods, used throughout the code
 */
class common {
    /**
     * @var integer Time for entry to expire
     */
    const EXPIRATION_SLACK = 15 * 60;

    /**
     * Finds most recent entry
     * @param \stdClass $entry Entry
     * @return \stdClass|null entry or null
     */
    public static function most_recent_entry($entry) {
        global $DB;

        $entries = $DB->get_records('availability_examus2_entries', [
            'userid' => $entry->userid,
            'courseid' => $entry->courseid,
            'cmid' => $entry->cmid,
            'status' => 'new'
        ], '-timecreated');
        $entry = reset($entries);
        return $entry;
    }

    /**
     * create entry if not exist
     * @param condition $condition condition
     * @param integer $userid User id
     * @param integer $cm Cm id
     * @return stdClass
     */
    public static function create_entry(condition $condition, $userid, $cm) {
        global $DB;

        $courseid = $cm->course;

        $entries = $DB->get_records('availability_examus2_entries', [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cm->id,
        ], 'id');

        foreach ($entries as $entry) {
            if (in_array($entry->status, ['started', 'new'])) {
                return $entry;
            }

            if ($entry->status == 'scheduled') {
                if ($condition->autorescheduling) {
                    if (time() > $entry->timescheduled + self::EXPIRATION_SLACK) {
                        $entry->timemodified = time();
                        $entry->status = 'rescheduled';

                        $DB->update_record('availability_examus2_entries', $entry);
                        $entry = self::reset_entry(['id' => $entry->id]);
                    }
                }
                return $entry;
            }
        }

        if ($cm->modname == 'quiz') {
            $quiz = \quiz_access_manager::load_quiz_and_settings($cm->instance);
            $allowedattempts = $quiz->attempts;
            $allowedattempts = $allowedattempts > 0 ? $allowedattempts : null;
        } else {
            $allowedattempts = null;
        }

        $usedentries = 0;
        foreach ($entries as $entry) {
            if (!in_array($entry->status, ['rescheduled', 'canceled', 'force_reset'])) {
                $usedentries++;
            }
        }

        // Create new entry if not exists already.
        // Respect limited number of attempts.
        if (is_null($allowedattempts) || $usedentries < $allowedattempts) {
            $entry = condition::make_entry($courseid, $cm->id, $userid);
            $entry->id = $DB->insert_record('availability_examus2_entries', $entry);

            return $entry;
        } else {
            // Taking last entry no matter the status.
            // This is done to trigger "exam finished" page.
            $entry = end($entries);
            return $entry;
        }
    }

    /**
     * Resets user entry ensuring there is only one 'not inited' entry.
     * If there is already one not inited entry, return it(unless forse reset is requested)
     * @todo Rework this function to be more readable
     * @param array $conditions array of conditions
     * @param boolean $force
     * @return \stdClass|null entry or null
     */
    public static function reset_entry($conditions, $force = false) {
        global $DB;

        $oldentry = $DB->get_record('availability_examus2_entries', $conditions);

        $notinited = $oldentry && $oldentry->status == 'new';

        if ($oldentry && !$notinited) {
            $oldentry->status = "force_reset";
            $DB->update_record('availability_examus2_entries', $oldentry);
        }

        if ($oldentry && (!$notinited || $force)) {
            $entries = $DB->get_records('availability_examus2_entries', [
                'userid' => $oldentry->userid,
                'courseid' => $oldentry->courseid,
                'cmid' => $oldentry->cmid,
                'status' => 'new'
            ]);

            if (count($entries) == 0 || $force) {
                if ($force) {
                    foreach ($entries as $old) {
                        $old->status = 'force_reset';
                        $DB->update_record('availability_examus2_entries', $old);
                    }
                }

                $timenow = time();
                $entry = new stdClass();
                $entry->userid = $oldentry->userid;
                $entry->courseid = $oldentry->courseid;
                $entry->cmid = $oldentry->cmid;
                $entry->accesscode = md5(uniqid(rand(), 1));
                $entry->status = 'new';
                $entry->timecreated = $timenow;
                $entry->timemodified = $timenow;

                $entry->id = $DB->insert_record('availability_examus2_entries', $entry);

                return $entry;
            } else {
                return null;
            }
        }
    }

    /**
     * Deletes entries that have "new" status
     * @param integer|string $userid
     * @param integer|string $courseid
     * @param integer|string|null $cmid
     */
    public static function delete_empty_entries($userid, $courseid, $cmid = null) {
        global $DB;

        $condition = [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'new'
        ];

        if (!empty($cmid)) {
            $condition['cmid'] = $cmid;
        }

        $DB->delete_records('availability_examus2_entries', $condition);
    }

    /**
     * Format timestamp as YYYY.MM.DD HH:MM, converting from GMT to user's timezone
     * @param integer $timestamp Timestamp in GMT
     * @return string|null
     */
    public static function format_date($timestamp) {
        $date = $timestamp ? usergetdate($timestamp) : null;

        if ($date) {
            return (
                '<b>' .
                $date['year'] . '.' .
                str_pad($date['mon'], 2, 0, STR_PAD_LEFT) . '.' .
                str_pad($date['mday'], 2, 0, STR_PAD_LEFT) . '</b> ' .
                str_pad($date['hours'], 2, 0, STR_PAD_LEFT) . ':' .
                str_pad($date['minutes'], 2, 0, STR_PAD_LEFT)
            );
        } else {
            return null;
        }
    }

    /**
     * Get timebracket for cm of type
     * @param string $type Type of cm
     * @param \stdClass $cm Course-module
     * @return array start and end time for cm
     */
    public static function get_timebracket_for_cm($type, $cm) {
        $timebrackets = self::get_timebrackets_for_cms($type, [$cm]);
        $timebracket = reset($timebrackets);
        $timebracket = $timebracket ? $timebracket : [];

        // Fill the void.
        if (empty($timebracket['start'])) {
            $timebracket['start'] = strtotime('2022-01-01');
        }
        if (empty($timebracket['end'])) {
            $timebracket['end'] = strtotime('2032-01-01');
        }

        return $timebracket;
    }

    /**
     * Get timebracket for array of CMs of type
     * @param string $type Type of CM
     * @param array $cms List of CMs
     * @return array Two-dimentional array of start and end time for CMs
     */
    public static function get_timebrackets_for_cms($type, $cms) {
        global $DB, $USER;
        $ids = [];
        $results = [];
        foreach ($cms as $cm) {
            $ids[] = $cm->instance;
        }
        switch ($type) {
            case 'quiz':
                $quizes = $DB->get_records_list('quiz', 'id', $ids);
                foreach ($quizes as $quiz) {
                    $start_override = null;
                    $end_override = null;

                    $overrides_user = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $USER->id]);

                    if(!$overrides_user) {
                        $members = [];
                        foreach($DB->get_records('groups_members', ['userid' => $USER->id]) as $member) {
                            $members[] = $member->groupid;
                        }
                        
                        if($members) {
                            list($groupid_sql, $params) = $DB->get_in_or_equal($members, SQL_PARAMS_NAMED);
                            $params['quiz'] = $quiz->id;
                            $sql = "SELECT *
                                      FROM {quiz_overrides}
                                     WHERE quiz = :quiz
                                           AND groupid {$groupid_sql}";
                            $overrides = $DB->get_record_sql($sql, $params);
                            if($overrides) {
                                $start_override = $overrides->timeopen;
                                $end_override = $overrides->timeclose;
                            }
                        }
                    } else {
                        $start_override = $overrides_user->timeopen;
                        $end_override = $overrides_user->timeclose;
                    }
                    
                    $start = $start_override ?: $quiz->timeopen;
                    $end = $end_override ?: $quiz->timeclose;

                    if ($start == 0 || $end == 0) {
                        continue;
                    }

                    $results[$quiz->id] = [ 'start' => $start, 'end' => $end ];
                }
                break;
            
            case 'assign':
                $assigns = $DB->get_records_list('assign', 'id', $ids);
                foreach ($assigns as $assign) {
                    $start = $assign->allowsubmissionsfromdate;
                    $end = $assign->duedate;

                    if ($start == 0 || $end == 0) {
                        continue;
                    }

                    $results[$assign->id] = [ 'start' => $start, 'end' => $end ];
                }
                break;
        }

        return $results;
    }

    /**
     * Parses date string in ISO8601 ignoring fractions of a second
     * @param string $date Date-string with or without fractions
     * @return int timestamp
     **/
    public static function parse_date($date) {
        if (!empty($date)) {
            $date = preg_replace('/\.\d+/', '', $date);
            $datetime = \DateTime::createFromFormat(\DateTime::ISO8601, $date);
            return $datetime->getTimestamp();
        }

    }

    public static function get_default_proctoring_settings() {
        $json = \get_config('availability_examus2', 'default_proctoring_settings');
        $json = json_decode($json);
        if (!($json instanceof stdClass))
            $json = new stdClass();
        return $json;
    }

    public static function set_default_proctoring_settings($data){
        $json = json_encode($data);
        \set_config('default_proctoring_settings', $json, 'availability_examus2');
    }
}
