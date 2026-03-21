<?php

use PHPUnit\Framework\TestCase;

class ExampleUnitTest extends TestCase
{
    /**
     * @dataProvider dataProvider
     */
    public function testSomethingWithData(int $input): void
    {
        $this->assertNotNull($input);
        $this->assertTrue(true);
        $this->assertEquals($input, $input);
    }

    public function dataProvider(): array
    {
        return [[1], [2], [3]];
    }

    public function testBasicAssertion(): void
    {
        $this->assertTrue(true);
        $this->assertNotEmpty('hello');
        $this->assertIsString('hello');
    }
}
