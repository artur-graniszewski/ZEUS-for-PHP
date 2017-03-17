<?php

namespace ZeusTest\Helpers;

use PHPUnit_Framework_TestCase;

abstract class AbstractConfigTestHelper extends PHPUnit_Framework_TestCase
{
    protected $configClass;

    protected function getConfig($data = [])
    {
        $configClass = $this->configClass;

        return new $configClass($data);
    }

    abstract public function configDataProvider();

    /**
     * @param mixed[] $value
     * @param string $arrayKey
     * @param string $methodName
     * @dataProvider configDataProvider
     */
    public function testConfigConstructor($value, $arrayKey, $methodName)
    {
        $config = $this->getConfig([$arrayKey => $value]);

        $methodName = (is_bool($value) ? 'is' : 'get') . $methodName;
        $this->assertEquals($value, $config->$methodName());
        $data = $config->toArray();
        $this->assertEquals($value, $data[$arrayKey]);
    }

    /**
     * @param mixed[] $value
     * @param string $arrayKey
     * @param string $methodName
     * @dataProvider configDataProvider
     */
    public function testConfigSetters($value, $arrayKey, $methodName)
    {
        $config = $this->getConfig();
        $setterMethodName = (is_bool($value) ? 'setIs' : 'set') . $methodName;
        $config->$setterMethodName($value);

        $getterMethodName = (is_bool($value) ? 'is' : 'get') . $methodName;
        $this->assertEquals($value, $config->$getterMethodName());
    }
}