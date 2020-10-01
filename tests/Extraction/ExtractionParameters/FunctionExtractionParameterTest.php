<?php

namespace Atom\DI\Tests\Extraction\ExtractionParameters;

use Atom\DI\Tests\BaseTestCase;
use InvalidArgumentException;
use Atom\DI\Extraction\ExtractionParameters\FunctionExtractionParameter;

class FunctionExtractionParameterTest extends BaseTestCase
{
    public function makeParameter($method, array $parameters = []): FunctionExtractionParameter
    {
        return new FunctionExtractionParameter($method, $parameters);
    }

    public function testItCanBeInstantiated()
    {
        $this->assertInstanceOf(FunctionExtractionParameter::class, $this->makeParameter("foo"));
        $this->assertInstanceOf(FunctionExtractionParameter::class, $this->makeParameter(function () {
        }));
    }

    public function testItThrowIfTheMethodIsInvalid()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeParameter(1);
    }

    public function testGetExtractionKey()
    {
        $param = $this->makeParameter("foo");
        $this->assertEquals("foo", $param->getExtractionKey());

        $param = $this->makeParameter(function () {
        });
        $this->assertStringContainsString("closure_", $param->getExtractionKey());
    }

    public function testGetMethod()
    {
        $param = $this->makeParameter("foo");
        $this->assertEquals("foo", $param->getMethod());
        $param = $this->makeParameter($closure = function () {
        });
        $this->assertEquals($param->getMethod(), $closure);
    }

    public function testGetParameters()
    {
        $param = $this->makeParameter("foo", $params = ["foo" => "bar"]);
        $this->assertEquals($param->getParameters(), $params);
    }

    public function testMethodIsString()
    {
        $param = $this->makeParameter("foo");
        $this->assertTrue($param->methodIsString());
        $param = $this->makeParameter($closure = function () {
        });
        $this->assertFalse($param->methodIsString());
    }

    public function testSetParameters()
    {
        $param = $this->makeParameter("foo");
        $this->assertEmpty($param->getParameters());
        $param->setParameters($params = ["foo"=>"bar"]);
        $this->assertEquals($params, $param->getParameters());
    }
}
