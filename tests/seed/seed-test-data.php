<?php
/**
 * CiviMCP test data seeder.
 *
 * Creates realistic FAKE data for trying CiviMCP on a test site:
 * ~200 individuals, 15 organisations, memberships (current, expiring soon,
 * in grace, expired, new), donations over two years, 4 events with
 * participants, activities, and a newsletter group.
 *
 * Every seeded contact gets source "civimcp-seed" and the tag
 * "CiviMCP test data", so it can be removed again.
 *
 * NEVER run this on a live or client site.
 *
 * Run from the WordPress folder:
 *   cv scr wp-content/uploads/civicrm/ext/civimcp/tests/seed/seed-test-data.php
 * Remove the seeded data again:
 *   CIVIMCP_SEED=delete cv scr wp-content/uploads/civicrm/ext/civimcp/tests/seed/seed-test-data.php
 */

use Civi\Api4\Activity;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use Civi\Api4\EntityTag;
use Civi\Api4\Event;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\Participant;
use Civi\Api4\Phone;
use Civi\Api4\Tag;

const SEED_SOURCE = 'civimcp-seed';
const SEED_TAG = 'CiviMCP test data';
const SEED_GROUP = 'CiviMCP Newsletter (test)';
const SEED_EVENT_PREFIX = '[CiviMCP test] ';

function say(string $msg): void {
  echo $msg . "\n";
}

function day(string $relative): string {
  return date('Y-m-d', strtotime($relative));
}

function pick(array $items) {
  return $items[array_rand($items)];
}

// Safety: refuse to run on a site marked Production.
$environment = \Civi::settings()->get('environment') ?: 'Production';
if ($environment === 'Production') {
  say("Refusing to run: this site's environment is \"Production\".");
  say('On a TEST site, set it first:  cv api4 Setting.set +v environment=Development');
  exit(1);
}

// ---------------------------------------------------------------------------
// Delete mode
// ---------------------------------------------------------------------------
if (getenv('CIVIMCP_SEED') === 'delete') {
  $ids = Contact::get(FALSE)->addSelect('id')->addWhere('source', '=', SEED_SOURCE)->execute()->column('id');
  say(sprintf('Deleting data for %d seeded contacts...', count($ids)));

  // CiviCRM won't permanently delete a contact that still has financial
  // records, so remove donations, memberships and registrations first.
  foreach (array_chunk($ids, 50) as $chunk) {
    $n = Contribution::delete(FALSE)->addWhere('contact_id', 'IN', $chunk)->execute()->count();
    $n += Membership::delete(FALSE)->addWhere('contact_id', 'IN', $chunk)->execute()->count();
    $n += Participant::delete(FALSE)->addWhere('contact_id', 'IN', $chunk)->execute()->count();
    if ($n) {
      say("  removed $n donations, memberships and registrations");
    }
  }
  // Activities created by the seeder target seeded contacts.
  $activityIds = Activity::get(FALSE)->addSelect('id')->addJoin('ActivityContact AS ac', 'INNER', ['ac.activity_id', '=', 'id'])
    ->addWhere('ac.contact_id', 'IN', $ids ?: [0])->addWhere('ac.record_type_id:name', '=', 'Activity Targets')
    ->execute()->column('id');
  foreach (array_chunk(array_unique($activityIds), 50) as $chunk) {
    Activity::delete(FALSE)->addWhere('id', 'IN', $chunk)->execute();
  }
  say(sprintf('  removed %d activities', count(array_unique($activityIds))));

  foreach (array_chunk($ids, 50) as $chunk) {
    Contact::delete(FALSE)->addWhere('id', 'IN', $chunk)->setUseTrash(FALSE)->execute();
  }
  say(sprintf('  removed %d contacts', count($ids)));
  Event::delete(FALSE)->addWhere('title', 'LIKE', SEED_EVENT_PREFIX . '%')->execute();
  Group::delete(FALSE)->addWhere('title', '=', SEED_GROUP)->execute();
  MembershipType::delete(FALSE)->addWhere('description', '=', SEED_SOURCE)->execute();
  Tag::delete(FALSE)->addWhere('name', '=', SEED_TAG)->execute();
  say('Done.');
  exit(0);
}

// Don't seed twice.
$already = Contact::get(FALSE)->selectRowCount()->addWhere('source', '=', SEED_SOURCE)->execute()->countMatched();
if ($already > 0) {
  say("Test data is already there ($already seeded contacts). To start again, run with CIVIMCP_SEED=delete first.");
  exit(0);
}

mt_srand(42);
srand(42);

$firstNames = ['Olivia', 'Amelia', 'Isla', 'Ava', 'Mia', 'Sophia', 'Grace', 'Lily', 'Freya', 'Emily', 'Ella', 'Poppy', 'Priya', 'Aisha', 'Fatima', 'Chloe', 'Hannah', 'Zara', 'Ruby', 'Evie',
  'Oliver', 'George', 'Noah', 'Arthur', 'Leo', 'Harry', 'Oscar', 'Jack', 'Charlie', 'Jacob', 'Thomas', 'James', 'William', 'Mohammed', 'Arjun', 'Ravi', 'Daniel', 'Samuel', 'Joseph', 'Kwame'];
$lastNames = ['Smith', 'Jones', 'Taylor', 'Brown', 'Williams', 'Wilson', 'Johnson', 'Davies', 'Patel', 'Robinson', 'Wright', 'Thompson', 'Evans', 'Walker', 'White', 'Roberts', 'Green', 'Hall',
  'Wood', 'Jackson', 'Clarke', 'Khan', 'Ahmed', 'Singh', 'Hughes', 'Edwards', 'Lewis', 'Murphy', 'Campbell', 'Kumar', 'Okafor', 'Nguyen', 'Kaur', 'Morgan', 'Price'];
$places = [
  ['London', 'EC1A 1BB'], ['Bristol', 'BS1 4DJ'], ['Manchester', 'M1 1AE'], ['Leeds', 'LS1 4AP'], ['Birmingham', 'B1 1BB'],
  ['Edinburgh', 'EH1 1YZ'], ['Cardiff', 'CF10 1EP'], ['Glasgow', 'G1 1XQ'], ['Bath', 'BA1 1LZ'], ['Exeter', 'EX1 1HB'],
];
$orgNames = ['Riverside Community Trust', 'Northgate Housing Association', 'Bright Futures Foundation', 'Harbour Arts Collective', 'Greenway Cycling Club',
  'Oakfield Medical Practice', 'Westbrook Primary School', 'Summit Legal LLP', 'Lantern Hospice', 'Meadow Lane Library Friends',
  'Pioneer Engineering Ltd', 'Coastal Wildlife Rescue', 'Elm Street Credit Union', 'Kingsway Youth Theatre', 'Silverline Software Ltd'];

// ---------------------------------------------------------------------------
// Tag, group
// ---------------------------------------------------------------------------
$tagId = Tag::create(FALSE)->setValues(['name' => SEED_TAG, 'description' => 'Fake data created by the CiviMCP seeder', 'used_for' => ['civicrm_contact']])->execute()->first()['id'];
$groupId = Group::create(FALSE)->setValues(['title' => SEED_GROUP, 'name' => 'civimcp_newsletter_test', 'group_type:name' => ['Mailing List'], 'is_active' => TRUE])->execute()->first()['id'];
say('Created tag and newsletter group.');

$tagContact = function (int $contactId) use ($tagId): void {
  EntityTag::create(FALSE)->setValues(['entity_table' => 'civicrm_contact', 'entity_id' => $contactId, 'tag_id' => $tagId])->execute();
};

// ---------------------------------------------------------------------------
// Organisations
// ---------------------------------------------------------------------------
$orgIds = [];
foreach ($orgNames as $i => $name) {
  [$city, $postcode] = $places[$i % count($places)];
  $id = Contact::create(FALSE)->setValues(['contact_type' => 'Organization', 'organization_name' => $name, 'source' => SEED_SOURCE])->execute()->first()['id'];
  $slug = preg_replace('/[^a-z]+/', '', strtolower($name));
  Email::create(FALSE)->setValues(['contact_id' => $id, 'email' => "info@{$slug}.example.org", 'is_primary' => TRUE, 'location_type_id:name' => 'Work'])->execute();
  Address::create(FALSE)->setValues(['contact_id' => $id, 'street_address' => (10 + $i) . ' High Street', 'city' => $city, 'postal_code' => $postcode, 'country_id:name' => 'GB', 'is_primary' => TRUE, 'location_type_id:name' => 'Work'])->execute();
  $tagContact($id);
  $orgIds[] = $id;
}
say(sprintf('Created %d organisations.', count($orgIds)));

// ---------------------------------------------------------------------------
// Individuals
// ---------------------------------------------------------------------------
$people = [];
for ($i = 1; $i <= 200; $i++) {
  $first = pick($firstNames);
  $last = pick($lastNames);
  [$city, $postcode] = pick($places);
  $values = [
    'contact_type' => 'Individual',
    'first_name' => $first,
    'last_name' => $last,
    'source' => SEED_SOURCE,
    'birth_date' => day('-' . mt_rand(18, 85) . ' years -' . mt_rand(0, 364) . ' days'),
  ];
  if (mt_rand(1, 3) === 1) {
    $values['employer_id'] = pick($orgIds);
  }
  $id = Contact::create(FALSE)->setValues($values)->execute()->first()['id'];
  Email::create(FALSE)->setValues(['contact_id' => $id, 'email' => strtolower("{$first}.{$last}{$i}@example.org"), 'is_primary' => TRUE, 'location_type_id:name' => 'Home'])->execute();
  if (mt_rand(1, 4) > 1) {
    Phone::create(FALSE)->setValues(['contact_id' => $id, 'phone' => sprintf('07700 900%03d', $i), 'is_primary' => TRUE, 'phone_type_id:name' => 'Mobile', 'location_type_id:name' => 'Home'])->execute();
  }
  Address::create(FALSE)->setValues(['contact_id' => $id, 'street_address' => mt_rand(1, 220) . ' ' . pick(['Station Road', 'Church Lane', 'Park Avenue', 'Victoria Street', 'Mill Lane', 'Queens Road']), 'city' => $city, 'postal_code' => $postcode, 'country_id:name' => 'GB', 'is_primary' => TRUE, 'location_type_id:name' => 'Home'])->execute();
  $tagContact($id);
  if (mt_rand(1, 2) === 1) {
    GroupContact::create(FALSE)->setValues(['group_id' => $groupId, 'contact_id' => $id, 'status' => 'Added'])->execute();
  }
  $people[] = $id;
  if ($i % 50 === 0) {
    say("  ...$i individuals");
  }
}
say(sprintf('Created %d individuals.', count($people)));

// ---------------------------------------------------------------------------
// Membership types (only ours; existing types are left alone)
// ---------------------------------------------------------------------------
$typeDefs = [
  ['name' => 'Individual Member (test)', 'minimum_fee' => 50],
  ['name' => 'Student Member (test)', 'minimum_fee' => 20],
  ['name' => 'Corporate Member (test)', 'minimum_fee' => 300],
];
$typeIds = [];
foreach ($typeDefs as $def) {
  $typeIds[$def['name']] = MembershipType::create(FALSE)->setValues([
    'name' => $def['name'],
    'description' => SEED_SOURCE,
    'domain_id' => 'current_domain',
    'member_of_contact_id' => 1,
    'financial_type_id:name' => 'Member Dues',
    'minimum_fee' => $def['minimum_fee'],
    'duration_unit' => 'year',
    'duration_interval' => 1,
    'period_type' => 'rolling',
    'is_active' => TRUE,
  ])->execute()->first()['id'];
}
say('Created 3 membership types.');

// ---------------------------------------------------------------------------
// Memberships: status is calculated by CiviCRM from the dates.
// ---------------------------------------------------------------------------
$profiles = [
  // weight, label, end date range (days from today)
  [45, 'current', [61, 330]],
  [15, 'expiring in next 60 days', [1, 60]],
  [10, 'recently ended (grace)', [-25, -1]],
  [20, 'expired', [-400, -40]],
  [10, 'new this month', [340, 364]],
];
$pickProfile = function () use ($profiles): array {
  $roll = mt_rand(1, 100);
  foreach ($profiles as $p) {
    $roll -= $p[0];
    if ($roll <= 0) {
      return $p;
    }
  }
  return $profiles[0];
};

$members = array_slice($people, 0, 150);
$counts = [];
foreach ($members as $contactId) {
  $profile = $pickProfile();
  $endOffset = mt_rand($profile[2][0], $profile[2][1]);
  $end = day("+$endOffset days");
  $start = date('Y-m-d', strtotime($end . ' -1 year +1 day'));
  $join = date('Y-m-d', strtotime($start . ' -' . (mt_rand(0, 3) * 365) . ' days'));
  $type = mt_rand(1, 10) <= 7 ? 'Individual Member (test)' : 'Student Member (test)';
  Membership::create(FALSE)->setValues([
    'contact_id' => $contactId,
    'membership_type_id' => $typeIds[$type],
    'join_date' => $join,
    'start_date' => $start,
    'end_date' => $end,
    'source' => SEED_SOURCE,
  ])->execute();
  $counts[$profile[1]] = ($counts[$profile[1]] ?? 0) + 1;
}
foreach (array_slice($orgIds, 0, 6) as $orgId) {
  Membership::create(FALSE)->setValues([
    'contact_id' => $orgId,
    'membership_type_id' => $typeIds['Corporate Member (test)'],
    'join_date' => day('-2 years'),
    'start_date' => day('-' . mt_rand(30, 300) . ' days'),
    'end_date' => day('+' . mt_rand(60, 330) . ' days'),
    'source' => SEED_SOURCE,
  ])->execute();
}
say(sprintf('Created %d memberships: %s, plus 6 corporate.', count($members), implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts))));

// ---------------------------------------------------------------------------
// Donations over the last two years
// ---------------------------------------------------------------------------
$donors = array_slice($people, 40, 120);
$donationCount = 0;
foreach ($donors as $contactId) {
  $gifts = mt_rand(1, 4);
  for ($g = 0; $g < $gifts; $g++) {
    $status = mt_rand(1, 20) === 1 ? 'Failed' : (mt_rand(1, 25) === 1 ? 'Pending' : 'Completed');
    Contribution::create(FALSE)->setValues([
      'contact_id' => $contactId,
      'financial_type_id:name' => 'Donation',
      'total_amount' => pick([5, 10, 10, 20, 25, 25, 50, 100, 250]),
      'receive_date' => day('-' . mt_rand(1, 730) . ' days') . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 20), mt_rand(0, 59)),
      'contribution_status_id:name' => $status,
      'payment_instrument_id:name' => pick(['Credit Card', 'Debit Card', 'EFT', 'Cash', 'Check']),
      'source' => SEED_SOURCE,
    ])->execute();
    $donationCount++;
  }
}
say("Created $donationCount donations.");

// ---------------------------------------------------------------------------
// Events and participants
// ---------------------------------------------------------------------------
$eventDefs = [
  ['Annual General Meeting 2026', 'Meeting', '-150 days', 60],
  ['Spring Fundraising Gala', 'Fundraiser', '-60 days', 40],
  ['Volunteer Training Workshop', 'Workshop', '+20 days', 25],
  ['Winter Conference 2027', 'Conference', '+75 days', 120],
];
$participantCount = 0;
foreach ($eventDefs as [$title, $type, $when, $capacity]) {
  $start = day($when) . ' 10:00:00';
  $past = strtotime($start) < time();
  $eventId = Event::create(FALSE)->setValues([
    'title' => SEED_EVENT_PREFIX . $title,
    'event_type_id:name' => $type,
    'start_date' => $start,
    'end_date' => day($when) . ' 16:00:00',
    'max_participants' => $capacity,
    'is_active' => TRUE,
    'is_public' => TRUE,
    'is_online_registration' => FALSE,
  ])->execute()->first()['id'];

  $attendees = (array) array_rand(array_flip($people), (int) round($capacity * 0.8));
  foreach ($attendees as $contactId) {
    $status = $past ? pick(['Attended', 'Attended', 'Attended', 'No-show', 'Cancelled']) : pick(['Registered', 'Registered', 'Registered', 'Cancelled']);
    Participant::create(FALSE)->setValues([
      'contact_id' => $contactId,
      'event_id' => $eventId,
      'status_id:name' => $status,
      'role_id:name' => ['Attendee'],
      'register_date' => date('Y-m-d H:i:s', strtotime($start . ' -' . mt_rand(3, 45) . ' days')),
      'source' => SEED_SOURCE,
    ])->execute();
    $participantCount++;
  }
}
say(sprintf('Created %d events with %d participants.', count($eventDefs), $participantCount));

// ---------------------------------------------------------------------------
// Activities
// ---------------------------------------------------------------------------
$me = \CRM_Core_Session::getLoggedInContactID() ?: 1;
$subjects = [
  'Phone Call' => ['Renewal reminder call', 'Asked about event dates', 'Updated contact details', 'Gift Aid question'],
  'Meeting' => ['Volunteer induction', 'Partnership catch-up', 'Membership enquiry'],
  'Email' => ['Welcome pack sent', 'Thank-you for donation', 'Event confirmation'],
];
$activityCount = 0;
for ($a = 0; $a < 150; $a++) {
  $type = pick(array_keys($subjects));
  Activity::create(FALSE)->setValues([
    'activity_type_id:name' => $type,
    'subject' => pick($subjects[$type]),
    'source_contact_id' => $me,
    'target_contact_id' => [pick($people)],
    'activity_date_time' => day('-' . mt_rand(0, 365) . ' days') . ' ' . sprintf('%02d:%02d:00', mt_rand(9, 17), mt_rand(0, 59)),
    'status_id:name' => 'Completed',
  ])->execute();
  $activityCount++;
}
say("Created $activityCount activities.");

say('');
say('All done. Everything seeded is tagged "' . SEED_TAG . '" and has source "' . SEED_SOURCE . '".');
say('To remove it: CIVIMCP_SEED=delete cv scr wp-content/uploads/civicrm/ext/civimcp/tests/seed/seed-test-data.php');
