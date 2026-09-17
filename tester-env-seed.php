<?php
// Tester-env deterministic seed for Moodle (prospect stage).
// Runs INSIDE the webserver container via stdin:
//   docker compose exec -T webserver php < tester-env-seed.php
// Idempotent: every entity is looked up by stable key (username, shortname,
// idnumber, activity name) and created only if missing, so re-running seed
// (or reset+deploy+seed) yields identical state. No randomness, no wall-clock
// dependence: all dates are fixed constants.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

global $DB, $CFG;

// Fixed timestamps (UTC container): course term starting Mon 2026-01-12,
// assignment window 2026-01-12 09:00 -> 2026-02-02 23:59.
define('SEED_COURSE_START', gmmktime(0, 0, 0, 1, 12, 2026));
define('SEED_ASSIGN_OPEN', gmmktime(9, 0, 0, 1, 12, 2026));
define('SEED_ASSIGN_DUE', gmmktime(23, 59, 0, 2, 2, 2026));
define('SEED_USER_PASSWORD', 'Seedpass1!');

// Run capability checks as the site admin.
\core\cron::setup_user();

function seed_ensure_user($username, $firstname, $lastname, $email) {
    global $DB, $CFG;
    if ($u = $DB->get_record('user', ['username' => $username, 'deleted' => 0])) {
        cli_writeln("user exists: {$username} (id {$u->id})");
        return $u;
    }
    $user = new stdClass();
    $user->username = $username;
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    $user->password = SEED_USER_PASSWORD;
    $user->auth = 'manual';
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->lang = 'en';
    $user->city = 'Springfield';
    $user->country = 'US';
    $user->maildisplay = 1;
    $id = user_create_user($user, true);
    cli_writeln("user created: {$username} (id {$id})");
    return $DB->get_record('user', ['id' => $id]);
}

function seed_ensure_category($name, $idnumber) {
    global $DB;
    if ($cat = $DB->get_record('course_categories', ['idnumber' => $idnumber])) {
        cli_writeln("category exists: {$name} (id {$cat->id})");
        return $cat;
    }
    $cat = \core_course_category::create([
        'name' => $name,
        'idnumber' => $idnumber,
        'description' => 'Seeded science department for tester-env UI assertions.',
        'descriptionformat' => FORMAT_HTML,
    ]);
    cli_writeln("category created: {$name} (id {$cat->id})");
    return $DB->get_record('course_categories', ['id' => $cat->id]);
}

function seed_ensure_course($categoryid, $fullname, $shortname, $summary) {
    global $DB;
    if ($c = $DB->get_record('course', ['shortname' => $shortname])) {
        cli_writeln("course exists: {$shortname} (id {$c->id})");
        return $c;
    }
    $data = new stdClass();
    $data->category = $categoryid;
    $data->fullname = $fullname;
    $data->shortname = $shortname;
    $data->idnumber = $shortname;
    $data->summary = $summary;
    $data->summaryformat = FORMAT_HTML;
    $data->format = 'topics';
    $data->visible = 1;
    $data->startdate = SEED_COURSE_START;
    $data->enddate = 0;
    $data->newsitems = 5;
    $c = create_course($data);
    cli_writeln("course created: {$shortname} (id {$c->id})");
    return $c;
}

function seed_ensure_enrol($courseid, $userid, $roleshortname) {
    global $DB;
    $enrolled = $DB->record_exists_sql(
        'SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.courseid = :courseid AND ue.userid = :userid AND ue.status = 0',
        ['courseid' => $courseid, 'userid' => $userid]
    );
    if ($enrolled) {
        cli_writeln("enrolment exists: user {$userid} in course {$courseid}");
        return;
    }
    $role = $DB->get_record('role', ['shortname' => $roleshortname], '*', MUST_EXIST);
    if (!enrol_try_internal_enrol($courseid, $userid, $role->id)) {
        cli_error("FAILED to enrol user {$userid} in course {$courseid} as {$roleshortname}");
    }
    cli_writeln("enrolled: user {$userid} in course {$courseid} as {$roleshortname}");
}

function seed_ensure_forum($courseid, $name, $intro) {
    global $DB;
    if ($f = $DB->get_record('forum', ['course' => $courseid, 'name' => $name])) {
        cli_writeln("forum exists: {$name} (id {$f->id})");
        return $f;
    }
    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'forum';
    $moduleinfo->course = $courseid;
    $moduleinfo->section = 1;
    $moduleinfo->visible = 1;
    $moduleinfo->name = $name;
    $moduleinfo->intro = $intro;
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
    $moduleinfo->type = 'general';
    $created = create_module($moduleinfo);
    cli_writeln("forum created: {$name} (cmid {$created->coursemodule})");
    return $DB->get_record('forum', ['course' => $courseid, 'name' => $name]);
}

function seed_ensure_assign($courseid, $name, $intro) {
    global $DB;
    if ($a = $DB->get_record('assign', ['course' => $courseid, 'name' => $name])) {
        cli_writeln("assignment exists: {$name} (id {$a->id})");
        return $a;
    }
    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'assign';
    $moduleinfo->course = $courseid;
    $moduleinfo->section = 1;
    $moduleinfo->visible = 1;
    $moduleinfo->name = $name;
    $moduleinfo->intro = $intro;
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
    $moduleinfo->allowsubmissionsfromdate = SEED_ASSIGN_OPEN;
    $moduleinfo->duedate = SEED_ASSIGN_DUE;
    $moduleinfo->cutoffdate = SEED_ASSIGN_DUE;
    $moduleinfo->grade = 100;
    // NOT NULL columns in m_assign have no API-side defaults; mirror the
    // mod_assign behat generator defaults (public/mod/assign/tests/generator/lib.php).
    $moduleinfo->alwaysshowdescription = 1;
    $moduleinfo->submissiondrafts = 1;
    $moduleinfo->requiresubmissionstatement = 0;
    $moduleinfo->sendnotifications = 0;
    $moduleinfo->sendstudentnotifications = 1;
    $moduleinfo->sendlatenotifications = 0;
    $moduleinfo->gradingduedate = 0;
    $moduleinfo->teamsubmission = 0;
    $moduleinfo->requireallteammemberssubmit = 0;
    $moduleinfo->teamsubmissiongroupingid = 0;
    $moduleinfo->blindmarking = 0;
    $moduleinfo->attemptreopenmethod = 'untilpass';
    $moduleinfo->maxattempts = 1;
    $moduleinfo->markingworkflow = 0;
    $moduleinfo->markingallocation = 0;
    $moduleinfo->markercount = 0;
    $moduleinfo->activityformat = 0;
    $moduleinfo->timelimit = 0;
    $created = create_module($moduleinfo);
    cli_writeln("assignment created: {$name} (cmid {$created->coursemodule})");
    return $DB->get_record('assign', ['course' => $courseid, 'name' => $name]);
}

// ---- Seed profile: Springfield Science Department ----
$teacher = seed_ensure_user('teacher_maria', 'Maria', 'Santos', 'teacher.maria@example.com');
$student1 = seed_ensure_user('student_james', 'James', 'Carter', 'student.james@example.com');
$student2 = seed_ensure_user('student_priya', 'Priya', 'Nair', 'student.priya@example.com');

$cat = seed_ensure_category('Science Department', 'SCI');

$bio = seed_ensure_course($cat->id, 'Introduction to Biology', 'BIO101',
    'Foundations of cell biology, genetics, and evolution for first-year students.');
$chem = seed_ensure_course($cat->id, 'Chemistry Fundamentals', 'CHEM101',
    'Atomic structure, bonding, and reactions. Course content is added during term.');

seed_ensure_enrol($bio->id, $teacher->id, 'editingteacher');
seed_ensure_enrol($bio->id, $student1->id, 'student');
seed_ensure_enrol($bio->id, $student2->id, 'student');
seed_ensure_enrol($chem->id, $teacher->id, 'editingteacher');
seed_ensure_enrol($chem->id, $student1->id, 'student');

seed_ensure_forum($bio->id, 'Cell Biology Q&A',
    'Ask questions about lectures and lab work. Maria monitors this forum on weekdays.');
seed_ensure_assign($bio->id, 'Mitosis Lab Report',
    'Write a 500-word report on the onion root tip mitosis experiment. Submit as PDF.');

rebuild_course_cache($bio->id, true);
rebuild_course_cache($chem->id, true);

cli_writeln('Seed complete: 3 users, 1 category, 2 courses, 5 enrolments, 1 forum + 1 assignment in BIO101.');
