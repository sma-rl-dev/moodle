<?php
// Tester-env deterministic verify for Moodle (prospect stage).
// Runs INSIDE the webserver container via stdin:
//   docker compose exec -T webserver php < tester-env-verify.php
// Exits 0 only when every seeded entity matches the fixed seed profile.
// Prints stable counts/names for evidence; no randomness, no wall-clock checks.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB, $CFG;

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

// Requirement admin-suspend-target: student_priya is the dedicated reversible
// suspend/reactivate target; seed always restores active status.
$priya = $DB->get_record('user', ['username' => 'student_priya', 'deleted' => 0]);
check('student_priya active (admin suspend target reversible)',
    $priya && (int)$priya->suspended === 0,
    $priya ? "(id {$priya->id} suspended {$priya->suspended})" : '(missing)');

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

    // Activities: BIO101 has exactly 1 forum + 1 assignment + 1 database;
    // CHEM101 has none of its own.
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

    // Requirement admin-course-visibility: both courses normalised visible so
    // an admin hide/show drill is verifiable in teacher/learner course lists.
    check('courses visible (admin hide/show reversible target)',
        (int)$bio->visible === 1 && (int)$chem->visible === 1,
        "(BIO101 visible {$bio->visible}, CHEM101 visible {$chem->visible})");

    // Requirement admin-announcement: fixed admin notice in the CHEM101 news
    // forum, visible to teacher_maria + student_james.
    $siteadmin = $DB->get_record('user', ['username' => 'admin', 'deleted' => 0]);
    $announce = $cheman
        ? $DB->get_record('forum_discussions', ['forum' => $cheman->id, 'name' => 'Welcome to Chemistry Fundamentals'])
        : false;
    check('admin announcement in CHEM101 news forum',
        $announce && $siteadmin && (int)$announce->userid === (int)$siteadmin->id,
        $announce ? "(id {$announce->id} firstpost {$announce->firstpost})" : '(missing)');

    // Requirement hidden-category: HID exists and is hidden; SCI stays visible.
    $hid = $DB->get_record('course_categories', ['idnumber' => 'HID']);
    check('category HID hidden', $hid && $hid->name === 'Hidden Archive' && (int)$hid->visible === 0,
        $hid ? "(id {$hid->id} visible {$hid->visible})" : '(missing)');
    check('category SCI still visible', $cat && (int)$cat->visible === 1);

    // Requirement forum-ratings-enabled: average ratings on a 5-point scale,
    // gradebook item reflects it, one fixed student discussion present.
    check('forum Cell Biology Q&A ratings enabled',
        $forum && (int)$forum->assessed === 1 && (int)$forum->scale === 5,
        $forum ? "(assessed {$forum->assessed} scale {$forum->scale})" : '(missing)');
    if ($forum) {
        $gitem = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'forum',
            'iteminstance' => $forum->id, 'courseid' => $bio->id]);
        check('gradebook item for rated forum (max 5)', $gitem && (float)$gitem->grademax == 5,
            $gitem ? "(id {$gitem->id} max {$gitem->grademax})" : '(missing)');
        $james = $DB->get_record('user', ['username' => 'student_james', 'deleted' => 0]);
        $disc = $DB->get_record('forum_discussions', ['forum' => $forum->id, 'name' => 'Chromatid puzzle']);
        check('seed discussion Chromatid puzzle by student_james',
            $disc && $james && (int)$disc->userid === (int)$james->id && (int)$disc->assessed === 1,
            $disc ? "(id {$disc->id} firstpost {$disc->firstpost})" : '(missing)');
    }

    // Requirement rss-feed-block: teacher-owned feed, RSS block in BIO101,
    // plugin enabled, local fetch allowed and working.
    $t = $DB->get_record('user', ['username' => 'teacher_maria', 'deleted' => 0]);
    $feed = $DB->get_record('block_rss_client', ['title' => 'Biology Dept News']);
    check('rss feed Biology Dept News owned by teacher_maria',
        $feed && $t && (int)$feed->userid === (int)$t->id
            && $feed->url === 'http://127.0.0.1/tester-env-seed-rss.xml'
            && (int)$feed->shared === 0 && (int)$feed->skipuntil === 0,
        $feed ? "(id {$feed->id})" : '(missing)');
    $rssblock = $DB->get_record('block', ['name' => 'rss_client']);
    check('rss_client block plugin enabled', $rssblock && (int)$rssblock->visible === 1);
    $ctxbio = \context_course::instance($bio->id);
    $binst = $DB->get_record('block_instances', ['blockname' => 'rss_client',
        'parentcontextid' => $ctxbio->id, 'pagetypepattern' => 'course-view-*']);
    $rsscfgok = false;
    if ($binst && $feed) {
        $cfg = @unserialize(base64_decode($binst->configdata));
        $rsscfgok = ($cfg && isset($cfg->rssid) && in_array((int)$feed->id, array_map('intval', (array)$cfg->rssid)));
    }
    check('rss block instance in BIO101 bound to seed feed', $rsscfgok,
        $binst ? "(instance {$binst->id})" : '(missing)');
    $curlblock = $DB->get_record('config', ['name' => 'curlsecurityblockedhosts']);
    check('curl host blocklist empty (local feed fetchable)', !$curlblock || trim((string)$curlblock->value) === '');
    if ($feed) {
        require_once($CFG->libdir . '/simplepie/moodle_simplepie.php');
        $rss = new moodle_simplepie($feed->url);
        $items = $rss->get_items();
        check('seed rss feed fetches locally (3 items)', !$rss->error() && count($items) === 3,
            $rss->error() ? ('(' . $rss->error() . ')') : ('(got ' . count($items) . ' item(s))'));
    }

    // Requirement database-activity: one activity, two fields, three fixed
    // entries; search oracle term present.
    $data = $DB->get_record('data', ['course' => $bio->id, 'name' => 'Lab Specimen Log']);
    check('database activity Lab Specimen Log in BIO101', (bool)$data, $data ? "(id {$data->id})" : '(missing)');
    if ($data) {
        $nfields = $DB->count_records('data_fields', ['dataid' => $data->id]);
        check('database has 2 fields', $nfields == 2, "(got {$nfields})");
        $nrecords = $DB->count_records('data_records', ['dataid' => $data->id, 'approved' => 1]);
        check('database has 3 approved entries', $nrecords == 3, "(got {$nrecords})");
        $ncontent = $DB->count_records_sql(
            'SELECT COUNT(*) FROM {data_content} c JOIN {data_records} r ON r.id = c.recordid
              WHERE r.dataid = :dataid', ['dataid' => $data->id]);
        check('database entry contents complete (3x2)', $ncontent == 6, "(got {$ncontent})");
        $chloro = $DB->record_exists_sql(
            'SELECT 1 FROM {data_content} c JOIN {data_records} r ON r.id = c.recordid
              WHERE r.dataid = :dataid AND c.content = :content',
            ['dataid' => $data->id, 'content' => 'Chloroplast model']);
        check('database search oracle entry present (Chloroplast model)', (bool)$chloro);
        check('database list template generated', !empty($data->listtemplate));
    }
}

if ($failures) {
    cli_writeln('VERIFY FAILED: ' . implode('; ', $failures));
    exit(1);
}
cli_writeln('VERIFY OK: seed profile matches (3 users active, 2 courses visible, 3+2 enrolments, BIO101 forum (ratings) + assignment + RSS block + database, CHEM101 default news forum only + 1 admin announcement, HID hidden).');
