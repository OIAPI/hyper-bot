<?php
namespace Test\Github;

final class HooksFixture
{
    /** @var list<string> */
    public static array $topics = [];

    public static function onExtension(string $topic, array $context): void
    {
        self::$topics[] = $topic;
    }
}
