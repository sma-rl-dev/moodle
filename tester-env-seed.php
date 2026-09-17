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
// assignment window 2026-01-12 09:00 -> 2026-02-02 23:59,
// forum seed post 2026-01-15 10:00, database entries 2026-01-16 11:00+.
define('SEED_COURSE_START', gmmktime(0, 0, 0, 1, 12, 2026));
define('SEED_ASSIGN_OPEN', gmmktime(9, 0, 0, 1, 12, 2026));
define('SEED_ASSIGN_DUE', gmmktime(23, 59, 0, 2, 2, 2026));
define('SEED_FORUM_POST_TIME', gmmktime(10, 0, 0, 1, 15, 2026));
define('SEED_DATA_ENTRY_TIME', gmmktime(11, 0, 0, 1, 16, 2026));
define('SEED_BLOCK_TIME', gmmktime(9, 0, 0, 1, 13, 2026));
define('SEED_ANNOUNCE_TIME', gmmktime(9, 0, 0, 1, 19, 2026));
define('SEED_USER_PASSWORD', 'Seedpass1!');

// Stable keys for scenario seed requirements (see scenarios/moodle/seed.manifest.json).
define('SEED_RSS_FEED_TITLE', 'Biology Dept News');
define('SEED_RSS_FEED_URL', 'http://127.0.0.1/tester-env-seed-rss.xml');
define('SEED_HIDDEN_CATEGORY_IDNUMBER', 'HID');
define('SEED_DATA_ACTIVITY_NAME', 'Lab Specimen Log');
// Requirement admin-announcement: stable subject of the admin-authored
// announcement discussion in the CHEM101 Announcements news forum, visible
// to teacher_maria + student_james on the course page.
define('SEED_ADMIN_ANNOUNCE_SUBJECT', 'Welcome to Chemistry Fundamentals');

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

function seed_ensure_category($name, $idnumber, $visible = 1) {
    global $DB;
    if ($cat = $DB->get_record('course_categories', ['idnumber' => $idnumber])) {
        if ((int)$cat->visible !== (int)$visible) {
            $DB->set_field('course_categories', 'visible', $visible, ['id' => $cat->id]);
            cli_writeln("category visibility normalised: {$name} (id {$cat->id}) visible={$visible}");
            $cat = $DB->get_record('course_categories', ['id' => $cat->id]);
        } else {
            cli_writeln("category exists: {$name} (id {$cat->id})");
        }
        return $cat;
    }
    $cat = \core_course_category::create([
        'name' => $name,
        'idnumber' => $idnumber,
        'visible' => $visible,
        'description' => 'Seeded science department for tester-env UI assertions.',
        'descriptionformat' => FORMAT_HTML,
    ]);
    cli_writeln("category created: {$name} (id {$cat->id})");
    return $DB->get_record('course_categories', ['id' => $cat->id]);
}

function seed_ensure_course($categoryid, $fullname, $shortname, $summary) {
    global $DB;
    if ($c = $DB->get_record('course', ['shortname' => $shortname])) {
        // Requirement admin-course-visibility: courses are always normalised
        // back to visible so an admin hide/show drill ends in a known state.
        if ((int)$c->visible !== 1) {
            $DB->set_field('course', 'visible', 1, ['id' => $c->id]);
            cli_writeln("course visibility normalised: {$shortname} visible=1 (admin hide is reversible)");
            $c = $DB->get_record('course', ['id' => $c->id]);
        } else {
            cli_writeln("course exists: {$shortname} (id {$c->id})");
        }
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

function seed_ensure_forum_ratings($forum) {
    // Requirement forum-ratings-enabled: average-of-ratings on a 5-point
    // scale so teacher ratings land in the gradebook (MDL-83815 oracle).
    global $DB, $CFG;
    if ((int)$forum->assessed === 1 && (int)$forum->scale === 5) {
        cli_writeln("forum ratings already enabled on '{$forum->name}'");
    } else {
        $DB->set_field('forum', 'assessed', 1, ['id' => $forum->id]);
        $DB->set_field('forum', 'scale', 5, ['id' => $forum->id]);
        $DB->set_field('forum', 'assesstimestart', 0, ['id' => $forum->id]);
        $DB->set_field('forum', 'assesstimefinish', 0, ['id' => $forum->id]);
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $forumrec = $DB->get_record('forum', ['id' => $forum->id]);
        $forumrec->cmidnumber = '';
        forum_grade_item_update($forumrec);
        cli_writeln("forum ratings enabled on '{$forum->name}' (average, scale 5)");
    }
    return $DB->get_record('forum', ['id' => $forum->id]);
}

function seed_ensure_forum_discussion($forum, $author, $name, $message) {
    // One fixed student discussion so rating flows never depend on posting
    // steps. forum_add_discussion() accepts ->timenow, so timestamps are
    // frozen; discussion.assessed is normalised to match the forum (the API
    // hardcodes 0 on insert).
    global $DB, $CFG;
    if ($d = $DB->get_record('forum_discussions', ['forum' => $forum->id, 'name' => $name])) {
        cli_writeln("forum discussion exists: {$name} (id {$d->id})");
        return $d;
    }
    require_once($CFG->dirroot . '/mod/forum/lib.php');
    $discussion = new stdClass();
    $discussion->course = $forum->course;
    $discussion->forum = $forum->id;
    $discussion->name = $name;
    $discussion->message = $message;
    $discussion->messageformat = FORMAT_HTML;
    $discussion->messagetrust = 0;
    $discussion->mailnow = 0;
    $discussion->groupid = -1;
    $discussion->timestart = 0;
    $discussion->timeend = 0;
    $discussion->timenow = SEED_FORUM_POST_TIME;
    $discussion->pinned = 0;
    $id = forum_add_discussion($discussion, null, null, $author->id);
    $DB->set_field('forum_discussions', 'assessed', 1, ['id' => $id]);
    cli_writeln("forum discussion created: {$name} (id {$id})");
    return $DB->get_record('forum_discussions', ['id' => $id]);
}

function seed_ensure_rss_feed($owner) {
    // Requirement rss-feed-block: one own (non-shared) feed owned by the
    // teacher plus an RSS block instance pinned to the BIO101 course page.
    // The feed URL is a static fixture served by this same Apache
    // (public/tester-env-seed-rss.xml), so viewfeed/block rendering never
    // touches the network. Server-side SimplePie fetches honour Moodle's
    // curl host blocklist, which blocks loopback/private hosts by default;
    // this local test site therefore ships with an empty blocklist.
    global $DB;
    set_config('curlsecurityblockedhosts', '');
    $DB->set_field('block', 'visible', 1, ['name' => 'rss_client']);
    if ($feed = $DB->get_record('block_rss_client',
            ['userid' => $owner->id, 'title' => SEED_RSS_FEED_TITLE])) {
        $DB->set_field('block_rss_client', 'url', SEED_RSS_FEED_URL, ['id' => $feed->id]);
        $DB->set_field('block_rss_client', 'skiptime', 0, ['id' => $feed->id]);
        $DB->set_field('block_rss_client', 'skipuntil', 0, ['id' => $feed->id]);
        cli_writeln("rss feed exists: " . SEED_RSS_FEED_TITLE . " (id {$feed->id})");
    } else {
        $feed = new stdClass();
        $feed->userid = $owner->id;
        $feed->title = SEED_RSS_FEED_TITLE;
        $feed->preferredtitle = SEED_RSS_FEED_TITLE;
        $feed->description = 'Seeded department news feed for RSS block assertions.';
        $feed->shared = 0;
        $feed->url = SEED_RSS_FEED_URL;
        $feed->skiptime = 0;
        $feed->skipuntil = 0;
        $feed->id = $DB->insert_record('block_rss_client', $feed);
        cli_writeln("rss feed created: " . SEED_RSS_FEED_TITLE . " (id {$feed->id})");
        $feed = $DB->get_record('block_rss_client', ['id' => $feed->id]);
    }
    return $feed;
}

function seed_ensure_rss_block($courseid, $feedid) {
    // Block instance keyed by (blockname, course context, page pattern);
    // configdata mirrors the block edit form (config_* prefix stripped).
    global $DB;
    $ctx = \context_course::instance($courseid);
    $config = new stdClass();
    $config->rssid = [(int)$feedid];
    $config->title = SEED_RSS_FEED_TITLE;
    $config->display_description = 0;
    $config->shownumentries = 5;
    $config->block_rss_client_show_channel_link = 0;
    $config->block_rss_client_show_channel_image = 0;
    $configdata = base64_encode(serialize($config));
    if ($inst = $DB->get_record('block_instances', [
            'blockname' => 'rss_client',
            'parentcontextid' => $ctx->id,
            'pagetypepattern' => 'course-view-*'])) {
        if ($inst->configdata !== $configdata) {
            $DB->set_field('block_instances', 'configdata', $configdata, ['id' => $inst->id]);
            cli_writeln("rss block config normalised (instance {$inst->id})");
        } else {
            cli_writeln("rss block exists (instance {$inst->id})");
        }
        return $DB->get_record('block_instances', ['id' => $inst->id]);
    }
    $inst = new stdClass();
    $inst->blockname = 'rss_client';
    $inst->parentcontextid = $ctx->id;
    $inst->showinsubcontexts = 0;
    $inst->requiredbytheme = 0;
    $inst->pagetypepattern = 'course-view-*';
    $inst->subpagepattern = null;
    $inst->defaultregion = 'side-pre';
    $inst->defaultweight = 5;
    $inst->configdata = $configdata;
    $inst->timecreated = SEED_BLOCK_TIME;
    $inst->timemodified = SEED_BLOCK_TIME;
    $inst->id = $DB->insert_record('block_instances', $inst);
    cli_writeln("rss block created in course {$courseid} (instance {$inst->id})");
    return $DB->get_record('block_instances', ['id' => $inst->id]);
}

function seed_ensure_data_activity($courseid) {
    // Requirement database-activity: one database activity with two text
    // fields and three fixed entries (search "chloroplast" narrows to one;
    // Clear all restores three). Rows keyed by stable names/content.
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/data/lib.php');
    require_once($CFG->dirroot . '/mod/data/locallib.php');
    if ($data = $DB->get_record('data', ['course' => $courseid, 'name' => SEED_DATA_ACTIVITY_NAME])) {
        cli_writeln("database activity exists: " . SEED_DATA_ACTIVITY_NAME . " (id {$data->id})");
    } else {
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'data';
        $moduleinfo->course = $courseid;
        $moduleinfo->section = 1;
        $moduleinfo->visible = 1;
        $moduleinfo->name = SEED_DATA_ACTIVITY_NAME;
        $moduleinfo->intro = 'Seeded log of biology lab specimens for search and filter assertions.';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->introeditor = ['text' => $moduleinfo->intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->comments = 0;
        $moduleinfo->timeavailablefrom = 0;
        $moduleinfo->timeavailableto = 0;
        $moduleinfo->timeviewfrom = 0;
        $moduleinfo->timeviewto = 0;
        $moduleinfo->requiredentries = 0;
        $moduleinfo->requiredentriestoview = 0;
        $moduleinfo->maxentries = 0;
        $moduleinfo->rssarticles = 0;
        $moduleinfo->approval = 0;
        $moduleinfo->manageapproved = 1;
        $moduleinfo->scale = 0;
        $moduleinfo->assessed = 0;
        $moduleinfo->assesstimestart = 0;
        $moduleinfo->assesstimefinish = 0;
        $moduleinfo->defaultsort = 0;
        $moduleinfo->defaultsortdir = 0;
        $moduleinfo->editany = 0;
        $moduleinfo->notification = 0;
        $created = create_module($moduleinfo);
        cli_writeln("database activity created: " . SEED_DATA_ACTIVITY_NAME . " (cmid {$created->coursemodule})");
        $data = $DB->get_record('data', ['course' => $courseid, 'name' => SEED_DATA_ACTIVITY_NAME]);
    }

    foreach ([
        'Specimen' => 'Short label of the specimen (e.g. Onion root tip slide).',
        'Observation notes' => 'What students should record about the specimen.',
    ] as $fieldname => $description) {
        if ($DB->record_exists('data_fields', ['dataid' => $data->id, 'name' => $fieldname])) {
            cli_writeln("data field exists: {$fieldname}");
            continue;
        }
        $fieldrec = new stdClass();
        $fieldrec->dataid = $data->id;
        $fieldrec->type = 'text';
        $fieldrec->name = $fieldname;
        $fieldrec->description = $description;
        $fieldrec->required = 0;
        $fieldrec->param1 = '1';
        $field = data_get_field($fieldrec, $data);
        $field->insert_field();
        cli_writeln("data field created: {$fieldname}");
    }

    $fields = $DB->get_records('data_fields', ['dataid' => $data->id]);
    $byname = [];
    foreach ($fields as $f) {
        $byname[$f->name] = $f;
    }
    $entries = [
        ['author' => 'student_james',
          'Specimen' => 'Onion root tip slide',
          'Observation notes' => 'Mitosis stages visible under 400x magnification'],
        ['author' => 'student_james',
          'Specimen' => 'Chloroplast model',
          'Observation notes' => 'Thylakoid stacks highlighted in green'],
        ['author' => 'student_priya',
          'Specimen' => 'DNA extraction sample',
          'Observation notes' => 'Strawberry filtrate with visible white strands'],
    ];
    $i = 0;
    foreach ($entries as $entry) {
        $author = $DB->get_record('user', ['username' => $entry['author'], 'deleted' => 0], '*', MUST_EXIST);
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM {data_records} r JOIN {data_content} c ON c.recordid = r.id
              WHERE r.dataid = :dataid AND c.fieldid = :fieldid AND c.content = :content',
            ['dataid' => $data->id, 'fieldid' => $byname['Specimen']->id, 'content' => $entry['Specimen']]);
        if ($exists) {
            cli_writeln("data entry exists: {$entry['Specimen']}");
            continue;
        }
        $recordid = data_add_record($data, 0, $author->id, true);
        foreach (['Specimen', 'Observation notes'] as $fname) {
            $content = new stdClass();
            $content->fieldid = $byname[$fname]->id;
            $content->recordid = $recordid;
            $content->content = $entry[$fname];
            $content->content1 = null;
            $content->content2 = null;
            $content->content3 = null;
            $content->content4 = null;
            $DB->insert_record('data_content', $content);
        }
        $DB->set_field('data_records', 'timecreated', SEED_DATA_ENTRY_TIME + ($i * 60), ['id' => $recordid]);
        $DB->set_field('data_records', 'timemodified', SEED_DATA_ENTRY_TIME + ($i * 60), ['id' => $recordid]);
        cli_writeln("data entry created: {$entry['Specimen']} (record {$recordid})");
        $i++;
    }

    // Default list/single/add/search templates so list and search views
    // render without manual template setup.
    $data = $DB->get_record('data', ['id' => $data->id]);
    foreach (['singletemplate', 'listtemplate', 'addtemplate', 'asearchtemplate'] as $template) {
        if (empty($data->{$template})) {
            data_generate_default_template($data, $template, 0, false, true);
            cli_writeln("data template generated: {$template}");
        }
    }
    return $DB->get_record('data', ['id' => $data->id]);
}

function seed_ensure_news_announcement($newsforum, $author, $name, $message) {
    // Requirement admin-announcement: one fixed admin-authored discussion in
    // a news (Announcements) forum, so teacher + learner dashboards show the
    // same admin-posted notice. Keyed by stable subject; never touches the
    // module table, so empty-course module-count oracles are unaffected.
    global $DB, $CFG;
    if ($d = $DB->get_record('forum_discussions', ['forum' => $newsforum->id, 'name' => $name])) {
        cli_writeln("news announcement exists: {$name} (id {$d->id})");
        return $d;
    }
    require_once($CFG->dirroot . '/mod/forum/lib.php');
    $discussion = new stdClass();
    $discussion->course = $newsforum->course;
    $discussion->forum = $newsforum->id;
    $discussion->name = $name;
    $discussion->message = $message;
    $discussion->messageformat = FORMAT_HTML;
    $discussion->messagetrust = 0;
    $discussion->mailnow = 0;
    $discussion->groupid = -1;
    $discussion->timestart = 0;
    $discussion->timeend = 0;
    $discussion->timenow = SEED_ANNOUNCE_TIME;
    $discussion->pinned = 0;
    $id = forum_add_discussion($discussion, null, null, $author->id);
    cli_writeln("news announcement created: {$name} (id {$id})");
    return $DB->get_record('forum_discussions', ['id' => $id]);
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

// Requirement admin-suspend-target: student_priya (least-used learner) is the
// dedicated reversible suspend/reactivate target. Seed always restores all
// seed users to active so an admin suspend drill ends in a known state;
// teacher Participants lists and learner login then reflect the toggle.
foreach ([$teacher, $student1, $student2] as $seeduser) {
    if ((int)$seeduser->suspended !== 0) {
        $DB->set_field('user', 'suspended', 0, ['id' => $seeduser->id]);
        cli_writeln("user reactivated: {$seeduser->username}");
    }
}

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

// Requirement hidden-category: one hidden course category for the calendar
// subscription permission oracle (MDL-87983).
seed_ensure_category('Hidden Archive', SEED_HIDDEN_CATEGORY_IDNUMBER, 0);

// Requirement forum-ratings-enabled: ratings on the BIO101 forum plus one
// fixed student discussion for rate/clear gradebook flows (MDL-83815).
$bioforum = $DB->get_record('forum', ['course' => $bio->id, 'name' => 'Cell Biology Q&A'], '*', MUST_EXIST);
$bioforum = seed_ensure_forum_ratings($bioforum);
seed_ensure_forum_discussion($bioforum, $student1, 'Chromatid puzzle',
    '<p>During anaphase, what pulls the sister chromatids apart? I think it is the spindle fibres.</p>');

// Requirement rss-feed-block: teacher-owned feed + RSS block in BIO101
// (MDL-88586 course-context feed view oracle).
$rssfeed = seed_ensure_rss_feed($teacher);
seed_ensure_rss_block($bio->id, $rssfeed->id);

// Requirement database-activity: database activity with searchable entries
// in BIO101 (MDL-87291 search then Clear-all oracle).
seed_ensure_data_activity($bio->id);

// Requirement admin-announcement: fixed admin notice in the CHEM101
// Announcements news forum (visible to teacher_maria + student_james;
// discussion rows do not count as modules).
$chemnews = $DB->get_record('forum', ['course' => $chem->id, 'type' => 'news'], '*', MUST_EXIST);
$siteadmin = $DB->get_record('user', ['username' => 'admin', 'deleted' => 0], '*', MUST_EXIST);
seed_ensure_news_announcement($chemnews, $siteadmin, SEED_ADMIN_ANNOUNCE_SUBJECT,
    '<p>Chemistry Fundamentals starts in week 2. Please read the lab safety notes before attending.</p>');

rebuild_course_cache($bio->id, true);
rebuild_course_cache($chem->id, true);

cli_writeln('Seed complete: 3 users, 2 categories (SCI visible + HID hidden), 2 courses, 5 enrolments, BIO101 forum (ratings + 1 discussion) + assignment + RSS block + database activity, CHEM101 news forum + 1 admin announcement.');
