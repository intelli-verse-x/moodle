<?php
// Seed a private Moodle conversation for Employer portal demos.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/message/lib.php');

\core\session\manager::write_close();

$employer = $DB->get_record('user', ['username' => 'employer.demo', 'deleted' => 0], '*', MUST_EXIST);
$fellow = $DB->get_record('user', ['username' => 'fellow.demo', 'deleted' => 0]);
if (!$fellow) {
    // Fallback: any other non-admin user.
    $fellow = $DB->get_record_select(
        'user',
        'deleted = 0 AND id <> :id AND username <> :guest',
        ['id' => $employer->id, 'guest' => 'guest'],
        '*',
        IGNORE_MULTIPLE
    );
}
if (!$fellow || (int) $fellow->id === (int) $employer->id) {
    cli_error('Need a second user to message employer.demo.');
}

$existing = \core_message\api::get_conversation_between_users([(int) $employer->id, (int) $fellow->id]);
if ($existing) {
    $convid = (int) $existing;
    mtrace('Existing conversation id=' . $convid);
} else {
    $conv = \core_message\api::create_conversation(
        \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
        [(int) $employer->id, (int) $fellow->id]
    );
    $convid = (int) $conv->id;
    mtrace('Created conversation id=' . $convid);
}

\core_message\api::send_message_to_conversation(
    (int) $fellow->id,
    $convid,
    'Hi Employer — welcome to the cohort. Ping me here if you need help with the Foundations activities.',
    FORMAT_PLAIN
);
\core_message\api::send_message_to_conversation(
    (int) $employer->id,
    $convid,
    'Thanks! I will open the first lesson today.',
    FORMAT_PLAIN
);
\core_message\api::send_message_to_conversation(
    (int) $fellow->id,
    $convid,
    'Great. Reply in Moodle Messages anytime — this portal shows conversation overviews.',
    FORMAT_PLAIN
);

mtrace('Seeded messages between ' . $fellow->username . ' and ' . $employer->username);
purge_all_caches();
