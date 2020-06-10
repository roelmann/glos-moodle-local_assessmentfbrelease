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
* A scheduled task for scripted database integrations.
*
* @package    local_assessmentextensions - template
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_assessmentfbrelease\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
* A scheduled task for scripted external database integrations.
*
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
class assessmentfbrelease extends \core\task\scheduled_task {
    
    /**
    * Get a descriptive name for this task (shown to admins).
    *
    * @return string
    */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentfbrelease');
    }
    
    /**
    * Run sync.
    */
    public function execute() {
        
        global $CFG, $DB;
        $checkbackperiod = (60 * 60 * 24) * 3; // 3 days.
        
        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();
        
        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tableassm = get_string('assessmentstable', 'local_assessmentfbrelease');
        $tablegrades = get_string('stuassesstable', 'local_assessmentfbrelease');
        
        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tableassm) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $tableassm . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$tablegrades) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $tablegrades . '<br>';
        }
        echo 'Starting connection...<br>';
        
        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
                echo 'Error while communicating with external database <br>';
                return 1;
            }
            
            $extensions = array();
            // Read grades and extensions data from external table.
            /********************************************************
            * ARRAY                                                *
            *     id                                               *
            *     student_code                                     *
            *     assessment_idcode                                *
            *     student_ext_duedate                               *
            *     student_ext_duetime                              *
            *     student_fbdue_date                               *
            *     student_fbdue_time                               *
            ********************************************************/
            $sql = $externaldb->db_get_sql($tablegrades, array(), array(), true);
            if ($rs = $extdb->Execute($sql)) {
                if (!$rs->EOF) {
                    while ($fields = $rs->FetchRow()) {
                        $fields = array_change_key_case($fields, CASE_LOWER);
                        $fields = $externaldb->db_decode($fields);
                        $extensions[] = $fields;
                    }
                }
                $rs->Close();
            } else {
                // Report error if required.
                $extdb->Close();
                echo 'Error reading data from the external course table<br>';
                return 4;
            }
            // Create reference array of students - if has a linked assessement.
            $student = array();
            foreach ($extensions as $e) {
                $key = $e['student_code'].$e['assessment_idcode'];
                if ($e['assessment_idcode']) {
                    $fbts = strtotime($e['student_fbdue_date']);
                    // Only students with feedback due in the last week - extended from 24hrs in case of catching broken runs.
                    if ($fbts < time() && $fbts > (time() - $checkbackperiod)) {
                        $student[$key]['stucode'] = $e['student_code'];
                        $student[$key]['lc'] = $e['assessment_idcode'];
                        $student[$key]['fbdate'] = $e['student_fbdue_date'];
                        $student[$key]['fbtime'] = $e['student_fbdue_time'];
                    }
                }
            }
            
            // Set extensions.
            // Echo statements output to cron or when task run immediately for debugging.
            foreach ($student as $k => $v) {
                // Create array for writing values.
                $userflags = new stdClass();
                // Set user.
                $username = $student[$k]['stucode'];
                while (strlen($username) < 7){
                    $username = '0' . $username;
                }
                if (strlen($username) != 7 ) {
                    echo 'Not 7 char: ' . $username;
                }
                $username = 's' . $username;
                
                // Set username (student number).
                $userflags->userid = $DB->get_field('user', 'id', array('username' => $username));
                // Set assignment id.
                $userflags->assignment = $DB->get_field('course_modules', 'instance', array('idnumber' => $student[$k]['lc']));
                
                $gradeiteminst = $grade = $stuid = '';
                $gradegrade = array();
                // Send grade from {assign} to {grade_grades}.
                $stuid = $userflags->userid;
                $assid = $userflags->assignment;
                $gradeiteminst = $DB->get_field('grade_items', 'id', array('iteminstance' => $assid, 'itemmodule' => 'assign'));
                
                echo 'check1 ';
                echo 'user:'.$stuid.' on assignment:'.$student[$k]['lc'].' assid:'.$assid.' gradeitem:'.$gradeiteminst.'<br>';
                
                if ($DB->record_exists('assign_grades', array('assignment' => $assid, 'userid' => $stuid))) {
                    $grade = $DB->get_field('assign_grades', 'grade', array('assignment' => $assid, 'userid' => $stuid));
                    $time = $DB->get_field('assign_grades', 'timemodified', array('assignment' => $assid, 'userid' => $stuid));
                    echo 'check 2: ';
                }
                echo 'check3: Grade:'.$grade.'<br>';
                $gradegrades->rawgrade = $gradegrades->finalgrade = $grade;
                $gradegrades->timemodified = $gradegrades->created = $time;
                $gradegrades->usermodified = 0;
                if ($DB->record_exists('grade_grades', array('itemid' => $gradeiteminst, 'userid' => $stuid))) {
                    $gradegrades->id = $DB->get_field('grade_grades', 'id', array('itemid' => $gradeiteminst, 'userid' => $stuid));
                    $DB->update_record('grade_grades', $gradegrades, false);
                    echo 'Updated Grade_grades for user:'.$stuid.' on assignment:'.$student[$k]['lc'].
                    ' and grade instance:'.$gradeiteminst.'<br>';
                }
                
                if (!empty($userflags->assignment) && !empty($userflags->userid)) {
                    // Convert feedback due date and time to Unix time stamp.
                    $fbddate = $student[$k]['fbdate'];
                    $fbdtime = $student[$k]['fbtime'];
                    $fbtimestamp = strtotime($fbddate.' '.$fbdtime);
                    // If time now is later than FB release date/time.
                    if ($fbtimestamp < time()) {
                        
                        // Check if record exists already and isn't already 'released'.
                        if ($DB->record_exists('assign_user_flags',
                        array('userid' => $userflags->userid, 'assignment' => $userflags->assignment)) &&
                        $DB->get_field('assign_user_flags', 'workflowstate',
                        array('userid' => $userflags->userid, 'assignment' => $userflags->assignment)) !== 'released') {
                            
                            // Set id as unique key.
                            $userflags->id = $DB->get_field('assign_user_flags', 'id',
                            array('userid' => $userflags->userid, 'assignment' => $userflags->assignment));
                            // Set workflow as released.
                            $userflags->workflowstate = 'released';
                            // Update existing records.
                            $DB->update_record('assign_user_flags', $userflags, false);
                            echo $username.' updated<br>';
                        } else if (!$DB->record_exists('assign_user_flags',
                        array('userid' => $userflags->userid, 'assignment' => $userflags->assignment))) {
                            // Set default values for non-existing record.
                            $userflags->locked = 0;
                            $userflags->mailed = 0;
                            $userflags->extensionduedate = 0;
                            $userflags->allocatedmarker = 0;
                            // Set workflow as released.
                            $userflags->workflowstate = 'released';
                            // Write new record.
                            $DB->insert_record('assign_user_flags', $userflags, false);
                            echo $username.' created<br';
                        }
                    }
                }
            }
            
            // Free memory.
            $extdb->Close();
        }
        
    }
    