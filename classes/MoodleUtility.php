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
 * IntegrityAdvocate utility functions not specific to this module that interact with Moodle core.
 *
 * @package    block_integrityadvocate
 * @copyright  IntegrityAdvocate.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_integrityadvocate;

use block_integrityadvocate\Utility as ia_u;

/**
 * Utility functions not specific to this module that interact with Moodle core.
 */
class MoodleUtility {

    /**
     * Get all instances of block_integrityadvocate in the Moodle site
     * If there are multiple blocks in a single parent context just return the first from that context.
     *
     * @param string $blockname Shortname of the block to get.
     * @param bool $visibleonly Set to true to return only visible instances
     * @return array of block_integrityadvocate instances with key=block instance id
     */
    public static function get_all_blocks(string $blockname, bool $visibleonly = true): array {
        global $DB;
        $debug = true;

        // We cannot filter for if the block is visible here b/c the block_participant row is usually NULL in these cases.
        $params = array('blockname' => $blockname);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::Looking in table block_instances with params=" . var_export($params, true));
        $records = $DB->get_records('block_instances', $params);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Found $records=' . (ia_u::is_empty($records) ? '' : var_export($records, true)));
        if (ia_u::is_empty($records)) {
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::No instances of block_{$blockname} found");
            return false;
        }

        // Go through each of the block instances and check visibility.
        $blockinstances = array();
        foreach ($records as $r) {
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Looking at $br=' . var_export($r, true));

            // Check if it is visible and get the IA appid from the block instance config.
            $blockinstancevisible = self::get_block_visibility($r->parentcontextid, $r->id);
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::Found \$blockinstancevisible={$blockinstancevisible}");

            if ($visibleonly && !$blockinstancevisible) {
                continue;
            }

            if (isset($blockinstances[$r->id])) {
                $debug && self::log(__CLASS__ . '::' . __FUNCTION__ .
                                "::Multiple visible block_{$blockname} instances found in the same parentcontextid - just return the first one");
                continue;
            }

            $blockinstances[$r->id] = \block_instance_by_id($r->id);
        }

        return $blockinstances;
    }

    /**
     * Get blocks in the given contextid (not recursively)
     *
     * @param int $contextid The context id to look in
     * @param string $blockname Name of the block to get instances for.
     * @return array where key=block_instances.id; val=block_instance object.
     */
    private static function get_blocks_in_context(int $contextid, string $blockname) {
        global $DB;

        $blockinstances = array();
        $recordset = $DB->get_recordset('block_instances', array('parentcontextid' => $contextid, 'blockname' => $blockname));
        foreach ($recordset as $r) {
            $blockinstances[$r->id] = \block_instance_by_id($r->id);
        }
        $recordset->close();

        return $blockinstances;
    }

    /**
     * Get all blocks in the course and child contexts (modules) matching $blockname.
     *
     * @param int $courseid The courseid to look in.
     * @param string $blockname Name of the block to get instances for.
     * @return array where key=block_instances.id; val=block_instance object.
     */
    public static function get_all_course_blocks(int $courseid, string $blockname): array {
        $debug = false;
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Started');

        $coursecontext = \context_course::instance($courseid, MUST_EXIST);

        // Get course-level instances.
        $blockinstances = self::get_blocks_in_context($coursecontext->id, $blockname);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Found course level block count=' . count($blockinstances));

        // Look in modules for more blocks instances.
        foreach ($coursecontext->get_child_contexts() as $c) {
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::Looking at \$c->id={$c->id}; \$c->instanceid={$c->instanceid}; \$c->contextlevel={$c->contextlevel}");
            if (intval($c->contextlevel) !== intval(\CONTEXT_MODULE)) {
                continue;
            }

            $blocksinmodule = self::get_blocks_in_context($c->id, $blockname);
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Found module level block count=' . count($blocksinmodule));
            $blockinstances += $blocksinmodule;
        }

        return $blockinstances;
    }

    /**
     * Return if Moodle is in testing mode, e.g. Behat.
     * Checking this instead of defined('BEHAT_SITE_RUNNING') directly allow me to return an arbitrary value if I want.
     *
     * @return bool True if Moodle is in testing mode, e.g. Behat.
     */
    public static function is_testingmode(): bool {
        return defined('BEHAT_SITE_RUNNING');
    }

    /**
     * Used to compare two modules based on order on course page.
     *
     * @param object[] $a array of event information
     * @param object[] $b array of event information
     * @return int <0, 0 or >0 depending on order of modules on course page
     */
    protected static function modules_compare_events($a, $b): int {
        if ($a['section'] != $b['section']) {
            return $a['section'] - $b['section'];
        } else {
            return $a['position'] - $b['position'];
        }
    }

    /**
     * Used to compare two modules based their expected completion times
     *
     * @param object[] $a array of event information
     * @param object[] $b array of event information
     * @return int <0, 0 or >0 depending on time then order of modules.
     */
    protected static function modules_compare_times($a, $b): int {
        if ($a['expected'] != 0 && $b['expected'] != 0 && $a['expected'] != $b['expected']) {
            return $a['expected'] - $b['expected'];
        } else if ($a['expected'] != 0 && $b['expected'] == 0) {
            return -1;
        } else if ($a['expected'] == 0 && $b['expected'] != 0) {
            return 1;
        } else {
            return self::modules_compare_events($a, $b);
        }
    }

    /**
     * Returns the modules with completion set in current course
     *
     * @param int courseid The id of the course
     * @return array[modules] Modules with completion settings in the course
     */
    public static function get_modules_with_completion(int $courseid): array {
        $modinfo = \get_fast_modinfo($courseid, -1);
        // Used for sorting.
        $sections = $modinfo->get_sections();
        $modules = array();
        foreach ($modinfo->instances as $module => $instances) {
            $modulename = \get_string('pluginname', $module);
            foreach ($instances as $cm) {
                if ($cm->completion != COMPLETION_TRACKING_NONE) {
                    $modules[] = array(
                        'type' => $module,
                        'modulename' => $modulename,
                        'id' => $cm->id,
                        'instance' => $cm->instance,
                        'name' => $cm->name,
                        'expected' => $cm->completionexpected,
                        'section' => $cm->sectionnum,
                        // Used for sorting.
                        'position' => array_search($cm->id, $sections[$cm->sectionnum]),
                        'url' => method_exists($cm->url, 'out') ? $cm->url->out() : '',
                        'context' => $cm->context,
                        // Removed b/c it caused error with developer debug display on: 'icon' => $cm->get_icon_url().
                        'available' => $cm->available,
                    );
                }
            }
        }

        usort($modules, array('self', 'modules_compare_times'));

        return $modules;
    }

    /**
     * Filters modules that a user cannot see due to grouping constraints
     *
     * @param stdClass $cfg Pass in the Moodle $CFG object.
     * @param Array $modules The possible modules that can occur for modules
     * @param int $userid The user's id
     * @param string $courseid the course for filtering visibility
     * @param int[] $exclusions Assignment exemptions for students in the course
     * @return object[] The array without the restricted modules
     */
    public static function filter_for_visible(\stdClass $cfg, array $modules, int $userid, int $courseid, $exclusions): array {
        $filteredmodules = array();
        $modinfo = \get_fast_modinfo($courseid, $userid);
        $coursecontext = \CONTEXT_COURSE::instance($courseid);

        // Keep only modules that are visible.
        foreach ($modules as $m) {

            $coursemodule = $modinfo->cms[$m['id']];

            // Check visibility in course.
            if (!$coursemodule->visible && !\has_capability('moodle/course:viewhiddenactivities', $coursecontext, $userid)) {
                continue;
            }

            // Check availability, allowing for visible, but not accessible items.
            if (!empty($cfg->enableavailability)) {
                if (\has_capability('moodle/course:viewhiddenactivities', $coursecontext, $userid)) {
                    $m['available'] = true;
                } else {
                    if (isset($coursemodule->available) && !$coursemodule->available && empty($coursemodule->availableinfo)) {
                        continue;
                    }
                    $m['available'] = $coursemodule->available;
                }
            }

            // Check visibility by grouping constraints (includes capability check).
            if (!empty($cfg->enablegroupmembersonly)) {
                if (isset($coursemodule->uservisible)) {
                    if ($coursemodule->uservisible != 1 && empty($coursemodule->availableinfo)) {
                        continue;
                    }
                }
            }

            // Check for exclusions.
            if (in_array($m['type'] . '-' . $m['instance'] . '-' . $userid, $exclusions)) {
                continue;
            }

            // Save the visible event.
            $filteredmodules[] = $m;
        }
        return $filteredmodules;
    }

    /**
     * Return whether an IA block is visible in the given context
     *
     * @param int $modulecontextid The module context id
     * @param int $blockinstanceid The block instance id
     * @return bool true if the block is visible in the given context
     */
    public static function get_block_visibility(int $modulecontextid, int $blockinstanceid): bool {
        global $DB;
        $debug = true;

        $record = $DB->get_record('block_positions', array('blockinstanceid' => $blockinstanceid, 'contextid' => $modulecontextid));
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Got $bp_record=' . (ia_u::is_empty($record) ? '' : var_export($record, true)));
        if (ia_u::is_empty($record)) {
            // There is no block_positions record, and the default is visible.
            return true;
        }

        return $record->visible;
    }

    /**
     * Check if site and optionally also course completion is enabled.
     *
     * @param int|object $course Optional courseid or course object to check.  If not specified, only site-level completion is checked.
     * @return array of error identifier strings
     */
    public static function get_completion_setup_errors($course = null): array {
        global $CFG;
        $errors = array();

        // Check if completion is enabled at site level.
        if (!$CFG->enablecompletion) {
            $errors[] = 'completion_not_enabled';
        }

        if ($course = self::get_course_as_obj($course)) {
            // Check if completion is enabled at course level.
            $completion = new \completion_info($course);
            if (!$completion->is_enabled()) {
                $errors[] = 'completion_not_enabled_course';
            }
        }

        return $errors;
    }

    /**
     * Convert course id to moodle course object into if needed.
     *
     * @param int|stdClass $course The course object or courseid to check
     * @return bool false if no course found; else Moodle course object
     * @throws InvalidArgumentException
     */
    public static function get_course_as_obj($course) {
        if (is_numeric($course)) {
            $course = \get_course(intval($course));
        }
        if (ia_u::is_empty($course)) {
            return false;
        }
        if (gettype($course) != 'object' || !isset($course->id)) {
            throw new \InvalidArgumentException('$course should be of type stdClass; got ' . gettype($course));
        }

        return $course;
    }

    /**
     * Finds gradebook exclusions for students in a course
     *
     * @param moodle_database $db Moodle DB object
     * @param int $courseid The ID of the course containing grade items
     * @return array of exclusions as module-user pairs
     */
    public static function get_gradebook_exclusions(\moodle_database $db, int $courseid): array {
        $query = "SELECT g.id, " . $db->sql_concat('i.itemmodule', "'-'", 'i.iteminstance', "'-'", 'g.userid') . " as exclusion
                   FROM {grade_grades} g, {grade_items} i
                  WHERE i.courseid = :courseid
                    AND i.id = g.itemid
                    AND g.excluded <> 0";
        $params = array('courseid' => $courseid);
        $results = $db->get_records_sql($query, $params);
        $exclusions = array();
        foreach ($results as $value) {
            $exclusions[] = $value->exclusion;
        }
        return $exclusions;
    }

    /**
     * Get the student role (in the course) to show by default e.g. on the course-overview page dropdown box.
     *
     * @param context $coursecontext Course context in which to get the default role.
     * @return int the role id that is for student archetype in this course
     */
    public static function get_default_course_role(\context $coursecontext): int {
        // Sanity check.
        if (ia_u::is_empty($coursecontext) || ($coursecontext->contextlevel !== \CONTEXT_COURSE)) {
            $msg = 'Input params are invalid';
            self::log(__CLASS__ . '::' . __FUNCTION__ . "::Started with \$coursecontext->instanceid={$coursecontext->instanceid}");
            self::log(__CLASS__ . '::' . __FUNCTION__ . '::' . $msg);
            throw new \InvalidArgumentException($msg);
        }

        $sql = 'SELECT  DISTINCT r.id, r.name, r.archetype
                FROM    {role} r, {role_assignments} ra
                WHERE   ra.contextid = :contextid
                AND     r.id = ra.roleid
                AND     r.archetype = :archetype';
        $params = array('contextid' => $coursecontext->id, 'archetype' => 'student');

        global $DB;
        $studentrole = $DB->get_record_sql($sql, $params);
        if (!ia_u::is_empty($studentrole)) {
            $studentroleid = $studentrole->id;
        } else {
            $studentroleid = 0;
        }
        return $studentroleid;
    }

    /**
     * Get the first block instance matching the shortname in the given context
     *
     * @param context $modulecontext Context to find the IA block in.
     * @param context $blockname Block shortname e.g. for block_html it would be html.
     * @param bool $visibleonly Return only visible instances.
     * @param bool $rownotinstance Since the instance can be hard to deal with, this returns the DB row instead.
     * @return bool false if none found or if no visible instances found; else an instance of block_integrityadvocate.
     */
    public static function get_first_block(\context $modulecontext, string $blockname, bool $visibleonly = true, bool $rownotinstance = false) {
        $debug = true;

        // We cannot filter for if the block is visible here b/c the block_participant row is usually NULL in these cases.
        $params = array('blockname' => $blockname, 'parentcontextid' => $modulecontext->id);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::Looking in table block_instances with params=" . var_export($params, true));

        // If there are multiple blocks in this context just return the first one .
        global $DB;
        $record = $DB->get_record('block_instances', $params, '*', IGNORE_MULTIPLE);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Found blockinstance record=' . (ia_u::is_empty($record) ? '' : var_export($record, true)));
        if (ia_u::is_empty($record)) {
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::No instance of block_{$blockname} is associated with this context");
            return false;
        }

        // Check if it is visible and get the IA appid from the block instance config.
        $record->visible = self::get_block_visibility($modulecontext->id, $record->id);
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ .
                        "::For \$modulecontext->id={$modulecontext->id} and \$record->id={$record->id} found \$record->visible={$record->visible}");

        if ($visibleonly && !$record->visible) {
            $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::\$visibleonly=true and this instance is not visible so return false");
            return false;
        }

        if ($rownotinstance) {
            return $record;
        }

        $blockinstance = \block_instance_by_id($record->id);
        // Disabled on purpose: $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . "::About to return a block instance=" . var_export($blockinstance, true));.

        return $blockinstance;
    }

    /**
     * Convert userid to moodle user object into if needed.
     *
     * @param int|stdClass $user The user object or id to convert
     * @return bool false if no user found; else moodle user object
     * @throws InvalidArgumentException
     */
    public static function get_user_as_obj($user) {
        $debug = false;
        $debug && self::log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $user=' . var_export($user, true));

        if (is_numeric($user)) {
            $userarr = user_get_users_by_id(array(intval($user)));
            if (empty($userarr)) {
                return false;
            }
            $user = array_pop($userarr);
        }
        if (gettype($user) != 'object') {
            throw new \InvalidArgumentException('$user should be of type stdClass; got ' . gettype($user));
        }

        return $user;
    }

    /**
     * Get user last access in course.
     *
     * @param int $userid The user id to look for.
     * @param int $courseid The course id to look in.
     * @return int User last access unix time.
     */
    public static function get_user_last_access(int $userid, int $courseid): int {
        global $DB;
        // Disabled on purpose: $debug &&self::log('Got $lastaccesses_record=' . var_export($lastaccesses_record, true));.
        return $DB->get_field('user_lastaccess', 'timeaccess', array('courseid' => $courseid, 'userid' => $userid));
    }

    /**
     * Get the UNIX timestamp for the last user access to the course.
     *
     * @param type $courseid The courseid to look in.
     * @return int User last access unix time.
     * @throws InvalidArgumentException
     */
    public static function get_course_lastaccess(int $courseid): int {
        $courseidcleaned = filter_var($courseid, FILTER_VALIDATE_INT);
        if (!is_numeric($courseidcleaned)) {
            throw new \InvalidArgumentException('Input $courseid must be an integer');
        }

        global $DB;
        $lastaccess = $DB->get_field_sql('SELECT MAX("timeaccess") lastaccess FROM {user_lastaccess} WHERE courseid=?', array($courseidcleaned), IGNORE_MISSING);

        // Convert false to int 0.
        return intval($lastaccess);
    }

    /**
     * Get the user_enrolment.id (UEID) for the given course-user combo
     * Ignores deleted and suspended users
     *
     * @param context $courserormodulecontext The context of the module the IA block is attached to.
     * @param int $userid The user id to get the ueid for
     * @return int The ueid.
     * @throws InvalidArgumentException
     */
    public static function get_ueid(\context $courserormodulecontext, int $userid): int {
        $debug = true;
        $fxn = __CLASS__ . '::' . __FUNCTION__;
        $debug && self::log($fxn . "::Started with userid={$userid}");

        // Sanity check.
        if (!in_array($courserormodulecontext->contextlevel, array(\CONTEXT_COURSE, \CONTEXT_MODULE), true)) {
            $msg = 'Input params are invalid';
            self::log($fxn . '::' . $msg);
            throw new \InvalidArgumentException($msg);
        }

        // Cache responses in a per-request cache so multiple calls in one request don't repeat the same work .
        $cache = \cache::make(\INTEGRITYADVOCATE_BLOCKNAME, 'perrequest');
        $cachekey = __CLASS__ . '_' . __FUNCTION__ . '_' . sha1($courserormodulecontext->id . '_' . $userid);
        if ($cachedvalue = $cache->get($cachekey)) {
            $debug && self::log($fxn . '::Found a cached value, so return that');
            return $cachedvalue;
        }

        // This section adapted from enrollib.php::get_enrolled_with_capabilities_join().
        // Initialize empty arrays to be filled later.
        $joins = array();
        $wheres = array();

        $enrolledjoin = get_enrolled_join($courserormodulecontext, 'u.id;', true);
        $debug && self::log($fxn . "::get_enrolled_join() returned=" . var_export($enrolledjoin, true));

        // Make the parts easier to use.
        $joins[] = $enrolledjoin->joins;
        $wheres[] = $enrolledjoin->wheres;
        $params = $enrolledjoin->params;

        // Clean up Moodle-provided joins.
        $joins = implode("\n", str_replace(';', ' ', $joins));
        // Add our critariae.
        $wheres[] = "u.suspended=0 AND u.deleted=0 AND u.id=" . intval($userid);
        $wheres = implode(' AND ', $wheres);

        // Figure out what prefix was used.
        $matches = array();
        preg_match('/ej[0-9]+_/', $joins, $matches);
        $prefix = $matches[0];
        $debug && self::log($fxn . "::Got prefix=$prefix");

        // Build the full join part of the sql.
        $sqljoin = new \core\dml\sql_join($joins, $wheres, $params);
        $debug && self::log($fxn . '::Built sqljoin=' . var_export($sqljoin, true));
        /*
         * The value of $sqljoin is something like this:
         * JOIN {user_enrolments} ej1_ue ON ej1_ue.userid = u.id;
         * JOIN {enrol} ej1_e ON (ej1_e.id = ej1_ue.enrolid AND ej1_e.courseid = :ej1_courseid)
         * [wheres] => 1 = 1
         *   AND ej1_ue.status = :ej1_active
         *   AND ej1_e.status = :ej1_enabled
         *   AND ej1_ue.timestart < :ej1_now1
         *   AND (ej1_ue.timeend = 0 OR ej1_ue.timeend > :ej1_now2)
         *
         * [params] => Array
         *       (
         *           [ej1_courseid] => 2
         *           [ej1_enabled] => 0
         *           [ej1_active] => 0
         *           [ej1_now1] => 1577401300
         *           [ej1_now2] => 1577401300
         *       )
         */
        //
        // This section adapted from enrollib.php::get_enrolled_join()
        // Build the query including our select clause.
        // Use MAX and GROUP BY in case there are multiple user-enrolments.
        $sql = "
                    SELECT  {$prefix}ue.id, max({$prefix}ue.timestart)
                    FROM    {user} u
                    {$sqljoin->joins}
                    WHERE {$sqljoin->wheres}
                    GROUP BY {$prefix}ue.id
                    ";
        $debug && self::log($fxn . "::Built sql={$sql} with params=" . var_export($params, true));

        global $DB;
        $enrolmentinfo = $DB->get_record_sql($sql, $sqljoin->params, IGNORE_MULTIPLE);
        $debug && self::log($fxn . '::Got $userEnrolmentInfo=' . (ia_u::is_empty($enrolmentinfo) ? '' : var_export($enrolmentinfo, true)));

        if (ia_u::is_empty($enrolmentinfo)) {
            self::log($fxn .
                    "::Failed to find an active user_enrolment.id for \$userid={$userid} and \$modulecontext->id={$courserormodulecontext->id} with \$sql={$sql}");
            // Return a guaranteed-invalid userid.
            $responseparsed = -1;
        } else {
            $responseparsed = $enrolmentinfo->id;
        }

        if (!$cache->set($cachekey, $responseparsed)) {
            throw new \Exception('Failed to set value in perrequest cache');
        }

        return $responseparsed;
    }

    /**
     * Log $message to HTML output, mlog, stdout, or error log
     *
     * @param string $message Message to log
     * @param string $dest One of the INTEGRITYADVOCATE_LOGDEST_* constants.
     * @return bool True on completion
     */
    public static function log(string $message, string $dest = \INTEGRITYADVOCATE_LOGDEST_ERRORLOG): bool {
        global $CFG, $blockintegrityadvocatelogdest;
        $debug = /* Do not make this true except in unusual circumstances */ false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $dest=' . $dest . "\n");

        // I did not use the PHP7.4 null coalesce b/c we want compat back to PHP5.6.
        $dest = $dest ?: $blockintegrityadvocatelogdest;
        $dest = $dest ?: INTEGRITYADVOCATE_LOGDEST_ERRORLOG;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::After cleanup, $dest=' . $dest . "\n");

        // If the file path is included, strip it.
        $cleanedmsg = str_replace(realpath($CFG->dirroot), '', $message);
        // Remove base64-encoded images.
        $cleanedmsg = preg_replace(INTEGRITYADVOCATE_REGEX_DATAURI, 'redacted_base64_image', $cleanedmsg);
        // Trim and remove blank lines.
        $cleanedmsg = trim(preg_replace('/^[ \t]*[\r\n]+/m', '', $cleanedmsg));

        switch ($dest) {
            case INTEGRITYADVOCATE_LOGDEST_HTML:
                print($cleanedmsg) . "<br />\n";
                break;
            case INTEGRITYADVOCATE_LOGDEST_MLOG:
                mtrace(html_to_text($cleanedmsg, 0, false));
                break;
            case INTEGRITYADVOCATE_LOGDEST_STDOUT:
                print(htmlentities($cleanedmsg, 0, false)) . "\n";
                break;
            case INTEGRITYADVOCATE_LOGDEST_ERRORLOG:
            default:
                error_log($cleanedmsg);
                break;
        }

        return true;
    }

    /**
     * Return true if the input $str is base64-encoded.
     *
     * @uses moodlelib:clean_param Cleans the param as PARAM_BASE64 and checks for empty.
     * @param string $str the string to test.
     * @return bool true if the input $str is base64-encoded.
     */
    public static function is_base64(string $str): bool {
        return !empty(clean_param($str, PARAM_BASE64));
    }

}
