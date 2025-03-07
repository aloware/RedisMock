<?php

namespace M6Web\Component\RedisMock;

use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use ReflectionException;

class MockPhpRedisConnector extends PhpRedisConnector
{
    /**
     * Create a new clustered PhpRedis connection.
     *
     * @param array $config
     * @param array $options
     *
     * @return PhpRedisConnection
     */
    public function connect(array $config, array $options)
    {
        $formattedOptions = array_merge(
            ['timeout' => 10.0], $options, Arr::pull($config, 'options', [])
        );
        $storage = Arr::pull($config, 'database', '1');

        $factory = new RedisMockFactory();
        $mockedRedisClass = $factory->getAdapter('Redis', true, true, $storage);

        return new MockPhpRedisConnection(new $mockedRedisClass($config, $options, $formattedOptions), null, $config);
    }

    /**
     * Create a new clustered PhpRedis connection.
     *
     * @param array $config
     * @param array $clusterOptions
     * @param array $options
     *
     * @return PhpRedisClusterConnection
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        $clusterSpecificOptions = Arr::pull($config, 'options', []);
        $storage = Arr::pull($config, 'database', '1');

        $factory = new RedisMockFactory();
        $redisMockClass = $factory->getAdapter('Redis', true, true, $storage);

        return new MockPhpRedisConnector(new $redisMockClass(array_values($config), array_merge(
            $options, $clusterOptions, $clusterSpecificOptions
        )));
    }

}
