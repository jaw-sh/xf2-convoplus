<?php

namespace HappyBoard\ConvoPlus\Tests\Integration;

use HappyBoard\GuestPosts\Tests\Support\CoverageManifest;
use PHPUnit\Framework\TestCase;

/** Read-only check, independent of destructive integration opt-in. */
final class SchemaCoverageTest extends TestCase
{
    public function testEveryInstalledContentIdentityHasAnExplicitPurgeClassification(): void
    {
        if (!class_exists('XF', false))
        {
            $this->markTestSkipped('Needs XF for a read-only information_schema inventory.');
        }
        $inventory = CoverageManifest::inventory(\XF::db());
        self::assertNotEmpty($inventory, 'Live schema inventory must not be silently empty.');
        $manifest = CoverageManifest::tables();
        $unknown = array_diff_key($inventory, $manifest);
        self::assertSame([], $unknown, 'Review unclassified identities: ' . json_encode($unknown));
        foreach ($inventory as $table => $identities)
        {
            self::assertContains($manifest[$table]['category'], ['core', 'handler', 'retained'], $table);
            self::assertNotSame('', $manifest[$table]['reason'], $table . ': explain source or retention.');
        }
    }
}
