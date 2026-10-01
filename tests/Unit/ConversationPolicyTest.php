<?php

namespace HappyBoard\ConvoPlus\Tests\Unit;

use HappyBoard\Misc\AutoPrune\Policy;
use PHPUnit\Framework\TestCase;

final class ConversationPolicyTest extends TestCase
{
    public function testConversationSettingsHaveNoThreadFloorAndKeepTransitionGrace(): void
    {
        $config = Policy::validateConfig([], Policy::CONVERSATION_DEFAULTS);
        self::assertSame(30, $config['inactivity_days']);
        self::assertSame(7, $config['grace_days']);
        self::assertSame('live', $config['mode']);
        self::assertArrayNotHasKey('floor', $config);
        self::assertSame(7 * 86400, Policy::extendGrace(0, 0, $config['grace_days']));
    }

    public function testAnUnknownModeCannotStartPruning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Policy::validateConfig(['mode' => 'LIVE'], Policy::CONVERSATION_DEFAULTS);
    }
}
