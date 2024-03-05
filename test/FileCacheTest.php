<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\File;
use Amp\File\FileCache;
use Amp\Sync\LocalKeyedMutex;

use function Amp\delay;
use function Amp\File\filesystem;

class FileCacheTest extends FilesystemTest
{
    protected File\Filesystem $driver;

    public function testGet(): void
    {
        $cache = $this->createCache();

        $result = $cache->get("mykey");
        self::assertNull($result);

        $cache->set("mykey", "myvalue", 10);

        $result = $cache->get("mykey");
        self::assertSame("myvalue", $result);
    }

    public function testEntryIsNotReturnedAfterTTLHasPassed(): void
    {
        $cache = $this->createCache();

        $cache->set("foo", "bar", 0);
        delay(1);

        self::assertNull($cache->get("foo"));
    }

    public function testEntryIsReturnedWhenOverriddenWithNoTimeout(): void
    {
        $cache = $this->createCache();

        $cache->set("foo", "bar", 0);
        $cache->set("foo", "bar");
        delay(1);

        self::assertNotNull($cache->get("foo"));
    }

    public function testEntryIsNotReturnedAfterDelete(): void
    {
        $cache = $this->createCache();

        $cache->set("foo", "bar");
        $cache->delete("foo");

        self::assertNull($cache->get("foo"));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = filesystem();
    }

    protected function createCache(): FileCache {
        $fixtureDir = Fixture::path();
        return new FileCache($fixtureDir, new LocalKeyedMutex, $this->driver);
    }
}
