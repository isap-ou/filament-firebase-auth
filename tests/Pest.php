<?php

use Isapp\FilamentFirebaseAuth\Tests\Emulator\EmulatorTestCase;
use Isapp\FilamentFirebaseAuth\Tests\TestCase;

require_once __DIR__ . '/Support/FirebaseFixtures.php';

uses(TestCase::class)->in('Feature', 'Unit');

// phpunit.xml.dist excludes the group. Run it with `--group=emulator` inside `firebase emulators:exec`.
uses(EmulatorTestCase::class)->group('emulator')->in('Emulator');
