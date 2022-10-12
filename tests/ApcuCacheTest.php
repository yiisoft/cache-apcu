<?php

declare(strict_types=1);

namespace Yiisoft\Cache\Apcu\Tests;

use ArrayIterator;
use DateInterval;
use Exception;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use ReflectionObject;
use stdClass;
use Yiisoft\Cache\Apcu\ApcuCache;

use function array_keys;
use function array_map;
use function extension_loaded;
use function ini_get;
use function is_object;

final class ApcuCacheTest extends TestCase
{
    private ApcuCache $cache;

    public function setUp(): void
    {
        $this->cache = new ApcuCache();
    }

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('apcu')) {
            self::markTestSkipped('Required extension "apcu" is not loaded');
        }

        if (!ini_get('apc.enable_cli')) {
            self::markTestSkipped('APC is installed but not enabled. Enable with "apc.enable_cli=1" from php.ini. Skipping.');
        }
    }

    public function dataProvider(): array
    {
        $object = new stdClass();
        $object->test_field = 'test_value';
        return [
            'integer' => ['test_integer', 1],
            'double' => ['test_double', 1.1],
            'string' => ['test_string', 'a'],
            'boolean_true' => ['test_boolean_true', true],
            'boolean_false' => ['test_boolean_false', false],
            'object' => ['test_object', $object],
            'array' => ['test_array', ['test_key' => 'test_value']],
            'null' => ['test_null', null],
            'supported_key_characters' => ['AZaz09_.', 'b'],
            '64_characters_key_max' => ['bVGEIeslJXtDPrtK.hgo6HL25_.1BGmzo4VA25YKHveHh7v9tUP8r5BNCyLhx4zy', 'c'],
            'string_with_number_key' => ['111', 11],
            'string_with_number_key_1' => ['022', 22],
        ];
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testSet($key, $value): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue($this->cache->set($key, $value));
        }
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testGet($key, $value): void
    {
        $this->cache->set($key, $value);
        $valueFromCache = $this->cache->get($key, 'default');

        $this->assertEqualsCanonicalizing($value, $valueFromCache);
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testValueInCacheCannotBeChanged($key, $value): void
    {
        $this->cache->set($key, $value);
        $valueFromCache = $this->cache->get($key, 'default');

        $this->assertEqualsCanonicalizing($value, $valueFromCache);

        if (is_object($value)) {
            $originalValue = clone $value;
            $valueFromCache->test_field = 'changed';
            $value->test_field = 'changed';
            $valueFromCacheNew = $this->cache->get($key, 'default');
            $this->assertEqualsCanonicalizing($originalValue, $valueFromCacheNew);
        }
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testHas($key, $value): void
    {
        $this->cache->set($key, $value);

        $this->assertTrue($this->cache->has($key));
        // check whether exists affects the value
        $this->assertEqualsCanonicalizing($value, $this->cache->get($key));

        $this->assertTrue($this->cache->has($key));
        $this->assertFalse($this->cache->has('not_exists'));
    }

    public function testGetNonExistent(): void
    {
        $this->assertNull($this->cache->get('non_existent_key'));
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testDelete($key, $value): void
    {
        $this->cache->set($key, $value);

        $this->assertEqualsCanonicalizing($value, $this->cache->get($key));
        $this->assertTrue($this->cache->delete($key));
        $this->assertNull($this->cache->get($key));
    }

    /**
     * @dataProvider dataProvider
     *
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     */
    public function testClear($key, $value): void
    {
        foreach ($this->dataProvider() as $datum) {
            $this->cache->set($datum[0], $datum[1]);
        }

        $this->assertTrue($this->cache->clear());
        $this->assertNull($this->cache->get($key));
    }

    /**
     * @dataProvider dataProviderSetMultiple
     *
     * @param int|null $ttl
     *
     * @throws InvalidArgumentException
     */
    public function testSetMultiple(?int $ttl): void
    {
        $data = $this->getDataProviderData();
        $this->cache->setMultiple($data, $ttl);

        foreach ($data as $key => $value) {
            $this->assertEqualsCanonicalizing($value, $this->cache->get((string) $key));
        }
    }

    /**
     * @return array Testing multiSet with and without expiry.
     */
    public function dataProviderSetMultiple(): array
    {
        return [
            [null],
            [2],
        ];
    }

    public function testGetMultiple(): void
    {
        $data = $this->getDataProviderData();
        $keys = array_map('\strval', array_keys($data));

        $this->cache->setMultiple($data);

        $this->assertEqualsCanonicalizing($data, $this->cache->getMultiple($keys));
    }

    public function testGetMultipleWithKeysNotExist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['key-1' => null, 'key-2' => null],
            $this->cache->getMultiple(['key-1', 'key-2']),
        );
    }

    public function testDeleteMultiple(): void
    {
        $data = $this->getDataProviderData();
        $keys = array_map('\strval', array_keys($data));

        $this->cache->setMultiple($data);

        $this->assertEqualsCanonicalizing($data, $this->cache->getMultiple($keys));

        $this->cache->deleteMultiple($keys);

        $emptyData = array_map(static fn ($v) => null, $data);

        $this->assertEqualsCanonicalizing($emptyData, $this->cache->getMultiple($keys));
    }

    public function testNegativeTtl(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertTrue($this->cache->has('a'));
        $this->assertTrue($this->cache->has('b'));

        $this->cache->set('a', 11, -1);

        $this->assertFalse($this->cache->has('a'));
    }

    /**
     * @dataProvider dataProviderNormalizeTtl
     *
     * @throws ReflectionException
     */
    public function testNormalizeTtl(mixed $ttl, mixed $expectedResult): void
    {
        $reflection = new ReflectionObject($this->cache);
        $method = $reflection->getMethod('normalizeTtl');
        $method->setAccessible(true);
        $result = $method->invokeArgs($this->cache, [$ttl]);
        $method->setAccessible(false);

        $this->assertEqualsCanonicalizing($expectedResult, $result);
    }

    /**
     * Data provider for {@see testNormalizeTtl()}
     *
     * @throws Exception
     *
     * @return array test data
     */
    public function dataProviderNormalizeTtl(): array
    {
        return [
            [123, 123],
            ['123', 123],
            ['', -1],
            [null, 0],
            [0, -1],
            [new DateInterval('PT6H8M'), 6 * 3600 + 8 * 60],
            [new DateInterval('P2Y4D'), 2 * 365 * 24 * 3600 + 4 * 24 * 3600],
        ];
    }

    /**
     * @dataProvider iterableProvider
     *
     * @param array $array
     * @param iterable $iterable
     *
     * @throws InvalidArgumentException
     */
    public function testValuesAsIterable(array $array, iterable $iterable): void
    {
        $this->cache->setMultiple($iterable);

        $this->assertEqualsCanonicalizing($array, $this->cache->getMultiple(array_keys($array)));
    }

    public function iterableProvider(): array
    {
        return [
            'array' => [
                ['a' => 1, 'b' => 2,],
                ['a' => 1, 'b' => 2,],
            ],
            'ArrayIterator' => [
                ['a' => 1, 'b' => 2,],
                new ArrayIterator(['a' => 1, 'b' => 2,]),
            ],
            'IteratorAggregate' => [
                ['a' => 1, 'b' => 2,],
                new class () implements IteratorAggregate {
                    public function getIterator(): ArrayIterator
                    {
                        return new ArrayIterator(['a' => 1, 'b' => 2,]);
                    }
                },
            ],
            'generator' => [
                ['a' => 1, 'b' => 2,],
                (static function () {
                    yield 'a' => 1;
                    yield 'b' => 2;
                })(),
            ],
        ];
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $this->cache->set('a', 1, new DateInterval('PT1H'));
        $this->assertEqualsCanonicalizing(1, $this->cache->get('a'));

        $this->cache->setMultiple(['b' => 2]);
        $this->assertEqualsCanonicalizing(['b' => 2], $this->cache->getMultiple(['b']));
    }

    public function invalidKeyProvider(): array
    {
        return [
            'psr-reserved' => ['{}()/\@:'],
            'empty-string' => [''],
        ];
    }

    /**
     * @dataProvider invalidKeyProvider
     */
    public function testGetThrowExceptionForInvalidKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    /**
     * @dataProvider invalidKeyProvider
     *
     * @param mixed $key
     */
    public function testSetThrowExceptionForInvalidKey($key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set($key, 'value');
    }

    /**
     * @dataProvider invalidKeyProvider
     *
     * @param mixed $key
     */
    public function testDeleteThrowExceptionForInvalidKey($key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->delete($key);
    }

    /**
     * @dataProvider invalidKeyProvider
     *
     * @param mixed $key
     */
    public function testGetMultipleThrowExceptionForInvalidKeys($key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->getMultiple([$key]);
    }

    /**
     * @dataProvider invalidKeyProvider
     *
     * @param mixed $key
     */
    public function testDeleteMultipleThrowExceptionForInvalidKeys($key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteMultiple([$key]);
    }

    private function getDataProviderData(): array
    {
        $dataProvider = $this->dataProvider();
        $data = [];

        foreach ($dataProvider as $item) {
            $data[$item[0]] = $item[1];
        }

        return $data;
    }
}
