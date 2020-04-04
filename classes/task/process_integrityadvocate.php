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
 * IntegrityAdvocate block capability setup
 *
 * @package    block_integrityadvocate
 * @copyright  IntegrityAdvocate.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_integrityadvocate\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/blocks/integrityadvocate/lib.php');

/**
 * IntegrityAdvocate block capability setup
 *
 * @package    block_integrityadvocate
 * @copyright  IntegrityAdvocate.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_integrityadvocate extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('process_integrityadvocate', INTEGRITYADVOCATE_BLOCKNAME);
    }

    /**
     * Do the job.

     * @global moodle_database $DB Moodle DB object
     * @throws Exception The job will be retried.
     */
    public function execute() {
        global $SITE;
        $debug = true;

        /*
         * To send logs to the screen instead of PHP error log:
         * --
         * global $block_integrityadvocate_log_dest;
         * $block_integrityadvocate_log_dest = INTEGRITYADVOCATE_LOGDEST_MLOG;
         */
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::Started');

        // Check if completion is setup at the site level.
        if (block_integrityadvocate_completion_setup_errors()) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::Completion is not set up at the site level, so skip this task.');
            return true;
        }

        $scheduledtask = \core\task\manager::get_scheduled_task(INTEGRITYADVOCATE_TASKNAME);
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::For $taskname=' . INTEGRITYADVOCATE_TASKNAME . ' got $scheduled_task=' . print_r($scheduledtask, true));

        // Workaround: block_integrityadvocate_to_apitimezone() returns the string for unix zero time if passed $scheduledtask->get_last_run_time() directly.
        $lastruntime = $scheduledtask->get_last_run_time();
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::For $lastruntime=' . $lastruntime);

        // We have to use the API's timezone for the field lastmodified.
        // It must be converted to the API timezone, but this is done in the foreach loop below.
        $params = array('lastmodified' => max($lastruntime, $SITE->timecreated));
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::Built params=' . print_r($params, true));

        // Gets visible blocks.
        $blockinstances = block_integrityadvocate_get_all_blocks();
        if (empty($blockinstances)) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::No integrityadvocate block instances found, so skip this task.');
            return true;
        }
        $msg = 'Found ' . count($blockinstances) . ' blockinstances total; will process those that are configured and added to an activity';
        mtrace($msg);
        if ($debug) {
            block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::' . $msg);
            //block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::blockinstances=' . print_r($blockinstances, true));
        }

        // For each IA block instance, process IA data and update the activity completion status accordingly.
        foreach ($blockinstances as $b) {
            $blockcontext = $b->context;
            $modulecontext = $blockcontext->get_parent_context();
            $coursecontext = $blockcontext->get_course_context();
            $debuglooplevel1 = 'blockinstance';
            $courseid = $coursecontext->instanceid;
            $debugblockidentifier = 'courseid=' . $courseid . '; moduleid=' . $modulecontext->instanceid . '; blockinstanceid=' . $b->instance->id;
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:Looking at {$debugblockidentifier}");

            // Check if completion is setup at the course level.
            if (block_integrityadvocate_completion_setup_errors($courseid)) {
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}:{$debuguseridentifier}: This courses completion is not setup at the course level, so skip it");
                continue;
            }

            // Only process module-level blocks.
            if ($modulecontext->contextlevel != CONTEXT_MODULE) {
                // The context may also be CONTEXT_SITE or CONTEXT_COURSE, for example.
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: This is not a module-level block, so skip it");
                continue;
            }

            // Check the block has apikey and appid.
            if (!isset($b->config) || (!isset($b->config->apikey) && !isset($b->config->appid))) {
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: This block has no apikey and appid, so skip it");
                continue;
            }

            // Get course-module object so we can...
            // (a) check activity completion is enabled and...
            // (b) update its completion state for the user.
            $modinfo = get_fast_modinfo($courseid);
            // Note the cmid = $modulecontext->instance.
            $cm = $modinfo->get_cm($modulecontext->instanceid);

            if ($cm->completion == COMPLETION_TRACKING_NONE) {
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: Completion is disabled at the module level, so skip it");
                continue;
            }

            // Get the course object so we can get the timemodified and the completion info.
            $course = get_course($courseid);

            // Call the API with this block's API key and AppId - this returns cleaned participant data.
            $msg = 'About to get remote IA data for ' . $debugblockidentifier . ' since ' . $lastruntime;
            mtrace($msg);
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::$msg");

            $params['lastmodified'] = block_integrityadvocate_to_apitimezone(max($params['lastmodified'], $b->instance->timecreated, $course->timecreated));
            $participants = block_integrityadvocate_get_ia_participant_data($b->config->apikey, $b->config->appid, $params);
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: Got participants=" . print_r($participants, true));
            if (empty($participants)) {
                $msg = 'No remote IA participant data returned';
                mtrace($msg);
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: {$msg}, so skip it");
                continue;
            }

            $usersupdatedcount = 0;

            $msg = 'About to get process IA results for ' . count($participants) . ' participants';
            mtrace($msg);
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::$msg");
            foreach ($participants as $p) {
                $usersupdatedcount += self::process_single_user($course, $cm, $modulecontext, $b, $p, $debugblockidentifier);
                if ($debug && $usersupdatedcount > 0) {
                    block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debuglooplevel1}:{$debugblockidentifier}: Updated {$usersupdatedcount} completion items");
                    $msg = "For IA participant {$p->ParticipantIdentifier} updated {$usersupdatedcount} completion items";
                    mtrace($msg);
                    $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::$msg");
                }
            }

            $msg = "For course {$course->id} updated {$usersupdatedcount} completion items";
            mtrace($msg);
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::$msg");

            // Reset the log output destination to default.
            // Disabled on purpose: $block_integrityadvocate_log_dest = INTEGRITYADVOCATE_LOGDEST_ERRORLOG;.
        }
    }

    function process_single_user(\stdClass $course, \cm_info $cm, \context $modulecontext, \block_integrityadvocate $blockinstance, \stdClass $participant, $debugblockidentifier = '') {
        global $DB;
        $debug = true;
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::Started with \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier);

        if (empty($participant->ParticipantIdentifier) || !ctype_alnum($participant->ParticipantIdentifier)) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::Invalid \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' so skip it');
            return false;
        }

        $parsedparticipantinfo = block_integrityadvocate_decode_useridentifier($participant->ParticipantIdentifier);
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: For \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' got thisuserid=' . print_r($parsedparticipantinfo, true));
        if (empty($parsedparticipantinfo)) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: For \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' is empty or an incorrect format, so skip it');
            return false;
        }

        $participantcourseid = $parsedparticipantinfo[0];
        if ($participantcourseid !== $course->id) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: For \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' this info is for a different courseid, so skip it');
            return false;
        }

        $participantuserid = $parsedparticipantinfo[1];
        $user = $DB->get_record('user', array('id' => $participantuserid));
        // Disabled on purpose: $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::For $participant->ParticipantIdentifier=' . $participant->ParticipantIdentifier . ' got $user with id=' . $user->id);.
        if (empty($user)) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: For \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' got an empty user, so skip it');
            return false;
        }
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: For \$participant->ParticipantIdentifier=" . $participant->ParticipantIdentifier . ' got a $user');

        $debuguseridentifier = 'userid=' . $user->id;
        if (!is_enrolled($modulecontext, $user)) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: This user is no longer enrolled in this course-module, so close the IA session then skip it");
            block_integrityadvocate_close_api_session($blockinstance->config->appid, $modulecontext, $user->id);
            return false;
        }

        // Close IA sessions older than INTEGRITYADVOCATE_SESS_TIMEOUT minutes,
        // but only do so a few times.
        $usercourse_lastaccess = get_last_access($user->id, $course->id);
        $time_to_close_ia_session = $usercourse_lastaccess + INTEGRITYADVOCATE_SESS_TIMEOUT * 60;
        $timenow = time();
        if ($timenow > $time_to_close_ia_session &&
                $timenow < ($time_to_close_ia_session + (4 * 60))
        ) {
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: This user last course activity is more than " . INTEGRITYADVOCATE_SESS_TIMEOUT . " minutes ago, so close the IA session");
            block_integrityadvocate_close_api_session($blockinstance->config->appid, $modulecontext, $user->id);
            // DO NOT 'break;' - we want to carry on processing.
        }


        // Get course completion object so we can manipulate activity completion status for each user.
        $completion = new \completion_info($course);

        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}: Before changes, \$cm->completion={$cm->completion}");
        $targetstate = COMPLETION_INCOMPLETE;
        switch ($reviewstatus = clean_param($participant->ReviewStatus, PARAM_TEXT)) {
            case INTEGRITYADVOCATE_API_STATUS_INPROGRESS:
                // No need to set again: $targetstate = COMPLETION_INCOMPLETE;.
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: IA status=" . $reviewstatus . ' so we should set the activity completion status to INCOMPLETE');
                break;
            case INTEGRITYADVOCATE_API_STATUS_VALID:
                // If the returned IA status is "Valid", we'd want the course marked Complete/Passed (if scored... if not scored, just "Complete" would work).
                $targetstate = COMPLETION_COMPLETE;
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: IA status=" . $reviewstatus . ' so we should set the activity completion status to COMPLETE');
                break;
            case INTEGRITYADVOCATE_API_STATUS_INVALID_ID:
                // In the case of "Invalid (ID)" status from IA, we'd want it to remain in the incomplete/pending review state (until the user submits their ID again via the link provided in the email sent out by the cron job and IA returns a different status).
                // No need to set again: $targetstate = COMPLETION_INCOMPLETE;.
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: IA status=" . $reviewstatus . ' so we should set the activity completion status to INCOMPLETE');
                break;
            case INTEGRITYADVOCATE_API_STATUS_INVALID_RULES:
                // In the case of an "Invalid (Rules)" status returned from IA, we'd want the course to be marked as Failed by the cron job.
                $targetstate = COMPLETION_COMPLETE_FAIL;
                $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: IA status=" . $reviewstatus . ' so we should set the activity completion status to COMPLETE_FAIL');
                break;
            default:
                throw new Exception("{$debugblockidentifier}:{$debuguseridentifier}: The IA API returned an invalid participant \$reviewtatus=" . $reviewstatus);
        }

        // The user to send mail from.
        $mailfrom = block_integrityadvocate_email_build_mailfrom();
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . '::Built mailfrom=' . $mailfrom->email);

        // Cannot use update_state() in several of the above cases, so dirty hack it in with internal_set_data().
        $current = $completion->get_data($cm, false, $user->id);
        if ($current->completionstate != $targetstate) {
            $current->completionstate = $targetstate;
            $current->timemodified = time();
            $current->overrideby = $user->id;
            $completion->internal_set_data($cm, $current);
            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: IA status=" . $reviewstatus . ' so did set the activity completion status; completiondata=' . print_r($completiondata = $completion->get_data($cm, false, $user->id), true));

            $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: About to call block_integrityadvocate_email_user_ia_status_update() for email={$user->email}");
            block_integrityadvocate_email_user_ia_status_update($mailfrom, $user, $participant, $course->id);
        }
        $debug && block_integrityadvocate_log(__FILE__ . '::' . __FUNCTION__ . "::{$debugblockidentifier}:{$debuguseridentifier}: Done this participant");

        return true;
    }

}
