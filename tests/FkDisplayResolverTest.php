<?php

namespace MIIM\ModelContracting\Tests;

use MIIM\ModelContracting\Services\FkDisplayResolver;
use PHPUnit\Framework\TestCase;

final class FkDisplayResolverTest extends TestCase
{
    public function testExtractNameFromExtensions(): void
    {
        $resolver = new FkDisplayResolver(new \MIIM\ModelContracting\Services\ModelRegistryService());

        $name = $resolver->extractNameFromExtensions([
            [
                'name' => 'name',
                'type' => 'string',
                'label' => 'Название',
                'value' => 'ОСАГО',
            ],
        ]);

        self::assertSame('ОСАГО', $name);
    }
}
