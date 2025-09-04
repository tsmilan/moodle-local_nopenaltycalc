<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_nopenaltycalc;

use core_grades\hook\after_category_aggregation_calculated;
use grade_grade;
use stdClass;

/**
 * Hook callbacks.
 *
 * @package    local_nopenaltycalc
 * @author     Trisha Milan <trishamilan@catalyst-au.net>
 * @copyright  2025 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Calculate finalgrades (with no penalties applied) for each category in the course
     * gradebook as well as the course category.
     *
     * @param after_category_aggregation_calculated $hook
     */
    public static function after_category_aggregation_calculated(after_category_aggregation_calculated $hook): void {
        global $DB;

        $gradevaluesprelimit = $hook->gradevaluesprelimit;
        list($insql, $params) = $DB->get_in_or_equal(array_keys($gradevaluesprelimit), SQL_PARAMS_NAMED);
        $sql = "SELECT gg.itemid,
                       gg.deductedmark,
                       gi.itemtype,
                       gg.overridden,
                       gg.finalgrade
                 FROM {grade_grades} gg
           INNER JOIN {grade_items} gi ON gg.itemid = gi.id
                WHERE gg.userid = :userid AND gg.itemid $insql";
        $params['userid'] = $hook->userid;
        $gradegrades = $DB->get_records_sql($sql, $params);

        // First modify gradevalues to include deductedmarks.
        $gradevalues = [];
        $categorygradevalues = [];
        $nopenaltycategorygrades = self::get_no_penalty_category_grades($hook->gradecategory->courseid, $hook->userid);

        $penaltycount = 0;
        foreach ($gradevaluesprelimit as $itemid => $val) {
            $gradevalues[$itemid] = $val;
            if (!isset($gradegrades[$itemid]) || !empty($gradegrades[$itemid]->overridden || $gradegrades[$itemid]->finalgrade == null)) {
                continue;
            }
            // Check for user specific grade min/max overrides.
            $usergrademin = isset($hook->grademinoverrides[$itemid]) ?
                $hook->grademinoverrides[$itemid] : $hook->items[$itemid]->grademin;
            $usergrademax = isset($hook->grademaxoverrides[$itemid]) ?
                $hook->grademaxoverrides[$itemid] : $hook->items[$itemid]->grademax;
            $deductedmark = grade_grade::standardise_score((float)$gradegrades[$itemid]->deductedmark,
                $usergrademin, $usergrademax, 0, 1);

            if ($deductedmark > 0) {
                $penaltycount++;
            }

            $gradevalues[$itemid] = $val + $deductedmark;
            if (isset($nopenaltycategorygrades[$itemid][$hook->userid])) {
                $categorygradevalues[$itemid] = $nopenaltycategorygrades[$itemid][$hook->userid]->finalgrade;
            } else {
                if ($gradegrades[$itemid]->itemtype == 'category') {
                    $categorygradevalues[$itemid] = $gradegrades[$itemid]->finalgrade;
                }
            }
        }

        // Do not need to continue processing a (non-course) category
        // if there were no penalties incurred by the student.
        $iscoursecategory = $hook->gradecategory->grade_item->itemtype == 'course' ? true : false;
        if (!$iscoursecategory && $penaltycount == 0) {
            return;
        }

        $normalisedgradevalues = [];
        if (!empty($categorygradevalues)) {
            // Normalize the grades first - all will have value 0...1
            // ungraded items are not used in aggregation.
            foreach ($categorygradevalues as $itemid => $v) {
                if (isset($nopenaltycategorygrades[$itemid][$hook->userid])) {
                    $usergrademin = $nopenaltycategorygrades[$itemid][$hook->userid]->grademin;
                    $usergrademax = $nopenaltycategorygrades[$itemid][$hook->userid]->grademax;
                }

                // Check for user specific grade min/max overrides.
                $usergrademin = isset($hook->grademinoverrides[$itemid]) ?
                    $hook->grademinoverrides[$itemid] : $hook->items[$itemid]->grademin;
                $usergrademax = isset($hook->grademaxoverrides[$itemid]) ?
                    $hook->grademaxoverrides[$itemid] : $hook->items[$itemid]->grademax;
                if ($hook->gradecategory->aggregation == GRADE_AGGREGATE_SUM) {
                    // Assume that the grademin is 0 when standardising the score, to preserve negative grades.
                    $normalisedgradevalues[$itemid] = grade_grade::standardise_score($v, 0, $usergrademax, 0, 1);
                } else {
                    $normalisedgradevalues[$itemid] = grade_grade::standardise_score($v, $usergrademin, $usergrademax, 0, 1);
                }
            }
        }

        asort($gradevalues, SORT_NUMERIC);
        if ($hook->gradecategory->can_apply_limit_rules()) {
            $hook->gradecategory->apply_limit_rules($gradevalues, $hook->items);
        }

        $updatedgradevalues = $normalisedgradevalues + $gradevalues;
        $usedweights = $hook->usedweights;
        $result = $hook->gradecategory->aggregate_values_and_adjust_bounds(
            $updatedgradevalues,
            $hook->items,
            $usedweights,
            $hook->grademinoverrides,
            $hook->grademaxoverrides
        );

        if ($hook->gradecategory->aggregation == GRADE_AGGREGATE_SUM) {
            // The natural aggregation always displays the range as coming from 0 for categories.
            // However, when we bind the grade we allow for negative values.
            $result['grademin'] = 0;
        }

        $finalgrade = grade_grade::standardise_score($result['grade'], 0, 1, $result['grademin'], $result['grademax']);
        $boundedgrade = $hook->gradecategory->grade_item->bounded_grade($finalgrade);

        self::save_no_penalty_finalgrade(
            $hook->gradecategory->courseid,
            $hook->userid,
            $hook->gradecategory->grade_item->id,
            $hook->gradecategory->grade_item->itemtype,
            $result['grademin'],
            $result['grademax'],
            $boundedgrade);
    }

    public static function get_no_penalty_category_grades($courseid, $userid) {
        global $DB;

        $records = $DB->get_records('no_penalty_finalgrades', ['courseid' => $courseid, 'userid' => $userid]);
        $result = [];
        foreach ($records as $record) {
            $result[$record->itemid][$record->userid] = $record;
        }
        return $result;
    }

    public static function save_no_penalty_finalgrade($courseid, $userid, $itemid, $itemtype, $grademin, $grademax, $boundedgrade) {
        global $DB, $USER;

        $record = $DB->get_record('no_penalty_finalgrades', ['courseid' => $courseid, 'userid' => $userid, 'itemid' => $itemid]);
        if ($record) {
            $record->grademax = $grademax;
            $record->grademin = $grademin;
            $record->finalgrade = $boundedgrade;
            $record->usermodified = $USER->id;
            $DB->update_record('no_penalty_finalgrades', $record);
        } else {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->userid = $userid;
            $record->itemid = $itemid;
            $record->itemtype = $itemtype;
            $record->grademax = $grademax;
            $record->grademin = $grademin;
            $record->finalgrade = $boundedgrade;
            $record->usermodified = $USER->id;
            $DB->insert_record('no_penalty_finalgrades', $record);
        }
    }
}
