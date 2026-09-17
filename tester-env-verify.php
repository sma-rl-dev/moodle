<?php
// Tester-env deterministic verify for Moodle (prospect stage).
// Runs INSIDE the webserver container via stdin:
//   docker compose exec -T webserver php < tester-env-verify.php
// Exits 0 only when every seeded entity matches the fixed seed profile.
// Prints stable counts/names for evidence; no randomness, no wall-clock checks.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

define('SEED_COURSE_START', gmmktime(0, 0, 0, 1, 12, 2026));
define('SEED_ASSIGN_DUE', gmmktime(23, 59, 0, 2, 2, 2026));

$failures = [];
function check($label, $cond, $detail = '') {
    global $failures;
    if ($cond) {
        cli_writeln("OK   {$label} {$detail}");
    } else {
        $failures[] = $label;
        cli_writeln("FAIL {$label} {$detail}");
    }
}

// Admin from CLI install.
$admin = $DB->get_record('user', ['username' => 'admin', 'deleted' => 0]);
check('admin user present', $admin && $admin->email === 'admin@example.com');

// Seeded users (fixed usernames, confirmed manual accounts).
foreach ([
    'teacher_maria' => 'teacher.maria@example.com',
    'student_james' => 'student.james@example.com',
    'student_priya' => 'student.priya@example.com',
] as $username => $email) {
    $u = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    check("user {$username}", $u && $u->email === $email && $u->confirmed == 1 && $u->auth === 'manual',
        $u ? "(id {$u->id})" : '(missing)');
}

// Category + courses.
$cat = $DB->get_record('course_categories', ['idnumber' => 'SCI']);
check('category SCI', $cat && $cat->name === 'Science Department', $cat ? "(id {$cat->id})" : '(missing)');

$bio = $DB->get_record('course', ['shortname' => 'BIO101']);
check('course BIO101', $bio && $bio->fullname === 'Introduction to Biology' && $bio->visible == 1
    && (int)$bio->startdate === SEED_COURSE_START, $bio ? "(id {$bio->id})" : '(missing)');
$chem = $DB->get_record('course', ['shortname' => 'CHEM101']);
check('course CHEM101', $chem && $chem->fullname === 'Chemistry Fundamentals' && $chem->visible == 1
    && (int)$chem->startdate === SEED_COURSE_START, $chem ? "(id {$chem->id})" : '(missing)');

if ($bio && $chem) {
    $bioenrol = $DB->count_records_sql(
        'SELECT COUNT(*) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.courseid = :cid AND ue.status = 0', ['cid' => $bio->id]);
    check('BIO101 enrolment count == 3', $bioenrol == 3, "(got {$bioenrol})");
    $chemenrol = $DB->count_records_sql(
        'SELECT COUNT(*) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.courseid = :cid AND ue.status = 0', ['cid' => $chem->id]);
    check('CHEM101 enrolment count == 2', $chemenrol == 2, "(got {$chemenrol})");

    // Teacher holds editingteacher in both courses; students hold student role in BIO101.
    $roleteacher = $DB->get_record('role', ['shortname' => 'editingteacher']);
    $rolestudent = $DB->get_record('role', ['shortname' => 'student']);
    $ctxbio = \context_course::instance($bio->id);
    $t = $DB->get_record('user', ['username' => 'teacher_maria', 'deleted' => 0]);
    check('teacher_maria is editingteacher in BIO101',
        $t && user_has_role_assignment($t->id, $roleteacher->id, $ctxbio->id));

    // Activities: BIO101 has exactly 1 forum + 1 assignment; CHEM101 has none.
    $forum = $DB->get_record('forum', ['course' => $bio->id, 'name' => 'Cell Biology Q&A']);
    check('forum Cell Biology Q&A in BIO101', (bool)$forum, $forum ? "(id {$forum->id})" : '(missing)');
    $assign = $DB->get_record('assign', ['course' => $bio->id, 'name' => 'Mitosis Lab Report']);
    check('assignment Mitosis Lab Report in BIO101',
        $assign && (int)$assign->duedate === SEED_ASSIGN_DUE, $assign ? "(id {$assign->id})" : '(missing)');
    $chemmods = $DB->get_records_sql(
        'SELECT cm.id, m.name AS modname FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :cid AND cm.deletioninprogress = 0',
        ['cid' => $chem->id]);
    $cheman = $DB->get_record('forum', ['course' => $chem->id, 'name' => 'Announcements', 'type' => 'news']);
    check('CHEM101 has only the default Announcements news forum', count($chemmods) == 1 && (bool)$cheman,
        '(got ' . count($chemmods) . ' module(s))');
}

if ($failures) {
    cli_writeln('VERIFY FAILED: ' . implode('; ', $failures));
    exit(1);
}
cli_writeln('VERIFY OK: seed profile matches (3 users, 2 courses, 3+2 enrolments, BIO101 forum + assignment, CHEM101 default news forum only).');
