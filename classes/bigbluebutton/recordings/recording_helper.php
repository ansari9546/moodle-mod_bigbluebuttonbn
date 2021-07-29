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
 * The mod_bigbluebuttonbn/bigbluebutton/recordings/helper.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

namespace mod_bigbluebuttonbn\bigbluebutton\recordings;

use stdClass;
use mod_bigbluebuttonbn\bigbluebutton\recordings\recording_proxy;

defined('MOODLE_INTERNAL') || die();

/**
 * Collection of helper methods for handling recordings in Moodle.
 *
 * Utility class for meeting helper
 * @package mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class recording_helper {

    /**
     * Helper function to retrieve recordings from the BigBlueButton. The references are stored as events
     * in bigbluebuttonbn_logs.
     *
     * @param string $courseid
     * @param string $bigbluebuttonbnid
     * @param bool   $onlyfrominstance
     * @param bool   $includedeleted
     * @param bool   $includeimported
     * @param bool   $onlyimported
     *
     * @return associative array containing the recordings indexed by recordID, each recording is also a
     * non sequential associative array itself that corresponds to the actual recording in BBB
     */
    public function get_recordings($courseid = 0, $bigbluebuttonbnid = null, $onlyfrominstance = true,
        $includedeleted = false, $includeimported = false, $onlyimported = false) {
        global $DB;
        // Retrieve DB recordings.
        // TODO: These DB queries should be performed with the recording:::read helper method to guarantee consistency.
        $select = self::sql_select_for_recordings(
            $courseid, $bigbluebuttonbnid, $onlyfrominstance, $includedeleted, $includeimported, $onlyimported);
        $recs = $DB->get_records_select('bigbluebuttonbn_recordings', $select, null, 'id');
        // Fetch BBB recordings.
        $recordingsids = $DB->get_records_select_menu('bigbluebuttonbn_recordings', $select, null, 'id', 'id, recordingid');
        $bbbrecordings = recording_proxy::bigbluebutton_fetch_recordings(array_values($recordingsids));

        /* Activities set to be recorded insert a bigbluebuttonbn_recording row on create, but it does not mean that
         * the meeting was recorded. We are responding only with the ones that have a processed recording in BBB.
         */
        $recordings = array();
        foreach ($recs as $id => $rec) {
            $recordingid = $rec->recordingid;
            // If there is not a BBB recording assiciated skip the record.
            // NOTE: This verifies that the recording exists, even imported recordings.
            // If the recording doesn't exist, the imported link will no longer be shown in the list.
            if (!isset($bbbrecordings[$recordingid])) {
                continue;
            }
            // If the recording was imported, override the metadata with the value stored in the database.
            if ($rec->imported) {
                // We must convert rec->recording to array because the records directly pulled from the database.
                $rec->recording = json_decode($rec->recording, true);
                foreach ($rec->recording as $varname => $value) {
                    $varnames = explode('_', $varname);
                    if ($varnames[0] == 'meta' ) {
                        $bbbrecordings[$recordingid][$varname] = $value;
                    }
                }
            }
            // Always assign the recording value fetched from BBB.
            $rec->recording = $bbbrecordings[$recordingid];
            // Finally, add the rec to the indexed array to be returned.
            $recordings[$recordingid] = $rec;
        }
        return $recordings;
    }

    /**
     * Helper function to define the sql used for gattering the bigbluebuttonbnids whose meetingids should be included
     * in the getRecordings request
     *
     * @param string    $courseid
     * @param string    $bigbluebuttonbnid
     * @param bool      $onlyfrominstance
     * @param bool      $includedeleted
     * @param bool      $includeimported
     * @param bool      $onlyimported
     *
     * @return string containing the sql used for getting the target bigbluebuttonbn instances
     */
    public static function sql_select_for_recordings($courseid, $bigbluebuttonbnid = null, $onlyfrominstance = true,
        $includedeleted = false, $includeimported =  false, $onlyimported = false) {
        if (empty($courseid)) {
            $courseid = 0;
        }
        $select = "";
        // Start with the filters.
        if (!$includedeleted) {
            // Exclude headless recordings unless includedeleted.
            $select .= "headless = false AND ";
        }
        if (!$includeimported) {
            // Exclude imported recordings unless includedeleted.
            $select .= "imported = false AND ";
        } else if ($onlyimported) {
            // Exclude non-imported recordings.
            $select .= "imported = true AND ";
        }
        // Add the main criteria for the search.
        if (empty($bigbluebuttonbnid)) {
            // Include all recordings in given course if bigbluebuttonbnid is not included.
            return $select . "courseid = '{$courseid}'";
        }
        if ($onlyfrominstance) {
            // Include only one bigbluebutton instance if subset filter is included.
            return $select . "bigbluebuttonbnid = '{$bigbluebuttonbnid}'";
        }
        // Include only from one course and instance is used for imported recordings.
        return $select . "bigbluebuttonbnid <> '{$bigbluebuttonbnid}' AND courseid = '{$courseid}'";
    }

    /**
     * Helper function to define the sql used for gattering the bigbluebuttonbnids whose meetingids should be included
     * in the getRecordings request
     *
     * @param string $courseid
     * @param string $bigbluebuttonbnid
     * @param bool $subset
     * @param bool $includedeleted
     *
     * @return string containing the sql used for getting the target bigbluebuttonbn instances
     */
    public static function sql_select_for_imported_recordings($courseid, $bigbluebuttonbnid = null, $subset = true,
        $includedeleted = false) {
        if (empty($courseid)) {
            $courseid = 0;
        }
        $select = "imported = true AND ";
        // Start with the filters.
        if (!$includedeleted) {
            // Exclude headless recordings unless includedeleted.
            $select .= "headless = false AND ";
        }
        // Add the meain criteria for the search.
        if (empty($bigbluebuttonbnid)) {
            // Include all recordings in given course if bigbluebuttonbnid is not included.
            return $select . "courseid = '{$courseid}'";
        }
        if ($subset) {
            // Include only one bigbluebutton instance if subset filter is included.
            return $select . "bigbluebuttonbnid = '{$bigbluebuttonbnid}'";
        }
        // Include only from one course and instance is used for imported recordings.
        return $select . "bigbluebuttonbnid <> '{$bigbluebuttonbnid}' AND course = '{$courseid}'";
    }

    /**
     * Helper function to sort an array of recordings. It compares the startTime in two recording objecs.
     *
     * @param object $a
     * @param object $b
     *
     * @return array
     */
    public static function recording_build_sorter($a, $b) {
        global $CFG;
        $resultless = !empty($CFG->bigbluebuttonbn_recordings_sortorder) ? -1 : 1;
        $resultmore = !empty($CFG->bigbluebuttonbn_recordings_sortorder) ? 1 : -1;
        if ($a['startTime'] < $b['startTime']) {
            return $resultless;
        }
        if ($a['startTime'] == $b['startTime']) {
            return 0;
        }
        return $resultmore;
    }

    /**
     * Helper function iterates an array with recordings and unset those already imported.
     *
     * @param array $recordings the source recordings.
     * @param integer $courseid
     * @param integer $bigbluebuttonbnid
     *
     * @return array
     */
    public static function unset_existent_imported_recordings($recordings, $courseid, $bigbluebuttonbnid) {
        global $DB;
        // Retrieve DB imported recordings.
        $select = self::sql_select_for_imported_recordings($courseid, $bigbluebuttonbnid, true);
        $recs = $DB->get_records_select('bigbluebuttonbn_recordings', $select, null, 'id');
        // Index the $importedrecordings for the response.
        $importedrecordings = array();
        foreach ($recs as $id => $rec) {
            $importedrecordings[$rec->recordingid] = $rec;
        }
        // Unset from $recordings if recording is already imported.
        foreach ($recordings as $recordingid => $recording) {
            if (isset($importedrecordings[$recordingid])) {
                unset($recordings[$recordingid]);
            }
        }
        return $recordings;
    }

    /**
     * Helper function to parse an xml recording object and produce an array in the format used by the plugin.
     *
     * @param object $recording
     *
     * @return array
     */
    public static function parse_recording($recording) {
        // Add formats.
        $playbackarray = array();
        foreach ($recording->playback->format as $format) {
            $playbackarray[(string) $format->type] = array('type' => (string) $format->type,
                'url' => trim((string) $format->url), 'length' => (string) $format->length);
            // Add preview per format when existing.
            if ($format->preview) {
                $playbackarray[(string) $format->type]['preview'] =
                    self::parse_preview_images($format->preview);
            }
        }
        // Add the metadata to the recordings array.
        $metadataarray =
            self::parse_recording_meta(get_object_vars($recording->metadata));
        $recordingarray = array('recordID' => (string) $recording->recordID,
            'meetingID' => (string) $recording->meetingID, 'meetingName' => (string) $recording->name,
            'published' => (string) $recording->published, 'startTime' => (string) $recording->startTime,
            'endTime' => (string) $recording->endTime, 'playbacks' => $playbackarray);
        if (isset($recording->protected)) {
            $recordingarray['protected'] = (string) $recording->protected;
        }
        return $recordingarray + $metadataarray;
    }

    /**
     * Helper function to convert an xml recording metadata object to an array in the format used by the plugin.
     *
     * @param array $metadata
     *
     * @return array
     */
    public static function parse_recording_meta($metadata) {
        $metadataarray = array();
        foreach ($metadata as $key => $value) {
            if (is_object($value)) {
                $value = '';
            }
            $metadataarray['meta_' . $key] = $value;
        }
        return $metadataarray;
    }

    /**
     * Helper function to convert an xml recording preview images to an array in the format used by the plugin.
     *
     * @param object $preview
     *
     * @return array
     */
    public static function parse_preview_images($preview) {
        $imagesarray = array();
        foreach ($preview->images->image as $image) {
            $imagearray = array('url' => trim((string) $image));
            foreach ($image->attributes() as $attkey => $attvalue) {
                $imagearray[$attkey] = (string) $attvalue;
            }
            array_push($imagesarray, $imagearray);
        }
        return $imagesarray;
    }
}